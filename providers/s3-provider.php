<?php

require_once('common.php');
require_once('cache.php');

function masterHash($computerID, $bucketID) {
  $server = conf('s3server');
  $url = s3PresignedURL($server, 'GET', "{$computerID}/bucketdata/{$bucketID}/refs/heads/master");
  $master = file_get_contents($url);
  return rtrim($master, 'Y');
}

function serveObjects($computerID, $bucketID, $hashes, $isTree) {
  $pointers = array_map(function($hash) use ($computerID, $bucketID, $isTree) {
    return objectPointer($computerID, $bucketID, $hash, $isTree);
  }, $hashes);
  $length = array_reduce($pointers, function($acc, $ptr) { return $acc + $ptr['length'] + 8; }, 0);
  header("Content-Type: application/octet-stream");
  header("Content-Length: $length");
  foreach ($pointers as $pointer) {
    echo pack('J', $pointer['length']);
    servePointer($pointer);
  }
}

function serveComputers() {
  $server = conf('s3server');
  foreach (computerIDs($server) as $computerID) {
    $url = s3PresignedURL($server, 'GET', "{$computerID}/computerinfo");
    $bin = file_get_contents($url);
    echoLenghPrefixed($computerID);
    echoLenghPrefixed($bin);
  }
}

function serveBuckets($computerID) {
  $server = conf('s3server');
  foreach (bucketIDs($server, $computerID) as $bucketID) {
    $url = s3PresignedURL($server, 'GET', "{$computerID}/buckets/{$bucketID}");
    $bin = file_get_contents($url);
    echoLenghPrefixed($bucketID);
    echoLenghPrefixed($bin);
  }
}

function serveKeys($computerID) {
  $server = conf('s3server');
  $url = s3PresignedURL($server, 'GET', "{$computerID}/encryptionv3.dat");
  echo file_get_contents($url);
}

// Private functions

function computerIDs($server) {
  $out = [];
  $query = [
    'list-type' => 2,
    'delimiter' => '/',
  ];
  $url = s3PresignedURL($server, 'GET', '', $query);
  $xml = file_get_contents($url);
  $result = new SimpleXMLElement($xml);
  foreach ($result->CommonPrefixes as $prefix) {
    $uuid = basename((string) $prefix->Prefix);
    if (validUUID($uuid, false) === null) continue;
    $out []= $uuid;
  }
  return $out;
}

function bucketIDs($server, $computerID) {
  $out = [];
  $query = [
    'list-type' => 2,
    'delimiter' => '/',
    'prefix' => "{$computerID}/bucketdata/",
  ];
  $url = s3PresignedURL($server, 'GET', '', $query);
  $xml = file_get_contents($url);
  $result = new SimpleXMLElement($xml);
  foreach ($result->CommonPrefixes as $prefix) {
    $uuid = basename((string) $prefix->Prefix);
    if (validUUID($uuid, false) === null) continue;
    $out []= $uuid;
  }
  return $out;
}

function objectPointer($computerID, $bucketID, $hash, $isTree) {
  $server = conf('s3server');
  $hash_bin = hex2bin($hash);
  if (!$isTree) {
    // Search object files
		$objKey = "{$computerID}/objects/" . bin2hex($hash_bin);
    $size = getFileSize($server, $objKey);
    if ($size !== null) {
      return [
        'url' => s3PresignedURL($server, 'GET', $objKey),
        'length' => $size,
      ];
    }
  }
  // Search packsets
  $typeSuffix = $isTree ? 'trees' : 'blobs';
  $key = "{$computerID}/packsets/{$bucketID}-{$typeSuffix}/";
  $domain = cacheDomain($key, false);
  if ($domain === null) {
    if (!in_array($computerID, computerIDs($server))) throw new Exception("Computer not found: {$computerID}", 404);
    if (!in_array($bucketID, bucketIDs($server, $computerID))) throw new Exception("Folder not found: {$bucketID}", 404);
    $domain = cacheDomain($key, true);
  }
  $info = packFind($server, $domain, $key, $hash_bin);
  if ($info !== null) {
    $objKey = $key . $info['pack'] . '.pack';
    $info['url'] = s3PresignedURL($server, 'GET', $objKey);
    return $info;
  }
  // Not found
  throw new Exception("Object not found: {$hash}", 404);
}

function servePointer($pointer, $packHeaderSize = 10) {
  $headerSize = isset($pointer['pack']) ? $packHeaderSize : 0;
  if (isset($pointer['offset'])) {
    $range = [$pointer['offset'], $pointer['offset'] + $pointer['length'] + $headerSize - 1];
    $context = stream_context_create(['http' => ['header' => ["Range: bytes={$range[0]}-{$range[1]}"]]]);
    $h = fopen($pointer['url'], 'r', false, $context);
  } else {
    $h = fopen($pointer['url'], 'r');
  }
  if (isset($pointer['pack'])) {
    $header = fReadObjHeader($h);
    if (ftell($h) !== $packHeaderSize) throw new Exception('Unexpected pack header length');
  }
  fpassthru($h);
  if (ftell($h) !== $pointer['length'] + $headerSize) throw new Exception('Unexpected response length');
  fclose($h);
}

function packFind($server, $domain, $key, $hash) {
  $info = null;
  $cached = [];
  $stmt = cacheSelect($domain);
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($row['content'] === null) continue;
    $pack = bin2hex($row['sha1']);
    $cached[$pack] = true;
    $info = scanIndex($row['content'], $hash, $pack);
    if ($info !== null) break;
  }
  $stmt->closeCursor();
  if ($info === null) {
    $info = fetchIndexes($server, $key, $cached, function($bin, $pack) use ($domain, $hash) {
      cacheAdd($domain, $pack, $bin);
      return scanIndex($bin, $hash, $pack);
    });
  }
  if ($info !== null) {
    cacheTouch($domain, $info['pack']);
  }
  return $info;
}

function fetchIndexes($server, $key, $cached, $cbk) {
  $query = [
    'list-type' => 2,
    'max-keys' => 250,
    'delimiter' => '/',
    'prefix'  => $key,
  ];
  $indexRequests = curl_multi_init();
  $listRequests = curl_multi_init();
  $nParallel = 20;
  $handlePaths = [];
  $info = null;
  // Add initial request
  $url = s3PresignedURL($server, 'GET', '', $query);
  curl_multi_add_url($listRequests, $url);
  do {
    $ready = curl_multi_info_wait($listRequests);
    $xml = curl_multi_getcontent($ready['handle']);
    curl_multi_remove_handle($listRequests, $ready['handle']);
    $result = new SimpleXMLElement($xml);
    $query['continuation-token'] = (string) $result->NextContinuationToken;
    if ($query['continuation-token']) {
      // Start next request
      $url = s3PresignedURL($server, 'GET', '', $query);
      curl_multi_add_url($listRequests, $url);
    }
    foreach ($result->Contents as $contents) {
      $path = (string) $contents->Key;
      $pathinfo = pathinfo($path);
      if (!isset($pathinfo['extension']) || $pathinfo['extension'] !== 'index') continue;
      if (isset($cached[$pathinfo['filename']])) continue;
      //echo "* $path\n";
      // Scan Index
      $url = s3PresignedURL($server, 'GET', $path);
      $handle = curl_multi_add_url($indexRequests, $url);
      $handlePaths[(int)$handle] = $path;
      while ($info === null && count($handlePaths) >= $nParallel) {
        $info = dispatchIndexScanQueue($indexRequests, $handlePaths, $cbk);
        if ($info !== null) break;
      }
      if ($info !== null) break;
    }
  } while ($info === null && $query['continuation-token']);
  // Scan remaining indexes
  while ($info === null && count($handlePaths) > 0) {
    $info = dispatchIndexScanQueue($indexRequests, $handlePaths, $cbk);
    if ($info !== null) break;
  }
  // Close remainding handles
  curl_multi_close($indexRequests);
  curl_multi_close($listRequests);
  return $info;
}

function dispatchIndexScanQueue($mh, &$handlePaths, $cbk) {
  $ready = curl_multi_info_wait($mh);
  while ($ready !== false) {
    $handle = $ready['handle'];
    $bin = curl_multi_getcontent($handle);
    $path = $handlePaths[(int)$handle];
    unset($handlePaths[(int)$handle]);
    curl_multi_remove_handle($mh, $handle);
    $pack = basename($path, '.index');
    $info = $cbk($bin, $pack);
    if ($info !== null) return $info;
    $ready = curl_multi_info_read($mh);
  }
  return null;
}

function curl_multi_add_url($mh, $url) {
  $handle = curl_init($url);
  curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
  curl_multi_add_handle($mh, $handle);
  curl_multi_exec($mh, $nActive);
  return $handle;
}

function curl_multi_info_wait($mh) {
  do {
      curl_multi_exec($mh, $nActive);
      $nReady = curl_multi_select($mh);
      // Add sleep since curl_multi_select doesn't seem to sleep on macos
      if ($nReady === 0) usleep(10);
      $info = curl_multi_info_read($mh);
      if ($info !== false) return $info;
  } while ($nActive > 0);
  return false;
}

function getFileSize($server, $objKey) {
  $url = s3PresignedURL($server, 'HEAD', $objKey);
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_NOBODY, true);
  curl_setopt($ch, CURLOPT_HEADER, true);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $header = curl_exec($ch);
  $res = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if ($res !== 200) return null;
  foreach (explode("\r\n", $header) as $line) {
    $kv = explode(":", $line, 2);
    if (strtolower($kv[0]) !== 'content-length') continue;
    return (int) $kv[1];
  }
  throw new Exception("Missing 'Content-Length' header");
}

function s3PresignedURL($server, $method, $key, $query = []) {
  $now = new DateTime('UTC');
  $date = $now->format('Ymd');
  $timeStamp = $now->format('Ymd\THis\Z');
  
  $keyId = $server['keyID'];
  $secret = $server['secret'];
  $region = $server['region'];
  $scope = "$date/$region/s3/aws4_request";

  $signingKey = 'AWS4' . $secret;
  foreach (explode('/', $scope) as $data) {
    $signingKey = hash_hmac('sha256', $data, $signingKey, true);
  }

  $url = $server['url'] . $key;
  $info = parse_url($url);
  $host = $info['host'];
  $path = $info['path'];
  
  $query['X-Amz-Algorithm'] = "AWS4-HMAC-SHA256";
  $query['X-Amz-Credential'] = "$keyId/$scope";
  $query['X-Amz-Date'] = $timeStamp;
  $query['X-Amz-Expires'] = "86400";
  $query['X-Amz-SignedHeaders'] = "host";
  
  ksort($query);
  $queryList = http_build_query($query, null, '&', PHP_QUERY_RFC3986);
  $canonical = "$method\n$path\n$queryList\nhost:$host\n\nhost\nUNSIGNED-PAYLOAD";
  $toSign = "AWS4-HMAC-SHA256\n$timeStamp\n$scope\n" . hash('sha256', $canonical);
  $signature = hash_hmac('sha256', $toSign, $signingKey);

  return "$url?$queryList&X-Amz-Signature=$signature";
}
