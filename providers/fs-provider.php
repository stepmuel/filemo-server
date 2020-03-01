<?php

require_once('common.php');
require_once('cache.php');

function masterHash($computerID, $bucketID) {
  $base = conf('fspath');
  $master = file_get_contents("{$base}/{$computerID}/bucketdata/{$bucketID}/refs/heads/master");
  return rtrim($master, 'Y');
}

function serveObjects($computerID, $bucketID, $hashes, $isTree) {
  $pointers = array_map(function ($hash) use ($computerID, $bucketID, $isTree) {
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
  $base = conf('fspath');
  foreach (computerIDs($base) as $computerID) {
    $bin = file_get_contents("{$base}/{$computerID}/computerinfo");
    echoLenghPrefixed($computerID);
    echoLenghPrefixed($bin);
  }
}

function serveBuckets($computerID) {
  $base = conf('fspath');
  foreach (bucketIDs($base, $computerID) as $bucketID) {
    $bin = file_get_contents("{$base}/{$computerID}/buckets/{$bucketID}");
    echoLenghPrefixed($bucketID);
    echoLenghPrefixed($bin);
  }
}

function serveKeys($computerID) {
  $base = conf('fspath');
  readfile("{$base}/{$computerID}/encryptionv3.dat");
}

function test() {
  $base = conf('fspath');
  $ids = computerIDs($base);
  if (count($ids) === 0) throw new Exception("No backups found at '{$base}'", 500);
  $domain = cacheDomain('dbtest', true);
  if ($domain === null) throw new Exception("Unable to write to database", 500);
}

// Private functions

function computerIDs($base) {
  $out = [];
  foreach (glob("{$base}/*", GLOB_ONLYDIR | GLOB_NOSORT) as $path) {
    $uuid = basename($path);
    if (validUUID($uuid, false) === null) continue;
    $out []= $uuid;
  }
  return $out;
}

function bucketIDs($base, $computerID) {
  $out = [];
  foreach (glob("{$base}/$computerID/bucketdata/*", GLOB_ONLYDIR | GLOB_NOSORT) as $path) {
    $uuid = basename($path);
    if (validUUID($uuid, false) === null) continue;
    $out []= $uuid;
  }
  return $out;
}

function objectPointer($computerID, $bucketID, $hash, $isTree) {
  $base = conf('fspath');
  $hash_bin = hex2bin($hash);
  if (!$isTree) {
    // Search object files
    $pre = bin2hex($hash_bin{0});
    $hash_hex = bin2hex(substr($hash_bin, 1));
    $objPath = "{$base}/{$computerID}/objects/{$pre}/{$hash_hex}";
    if (file_exists($objPath)) {
      return [
        'url' => $objPath,
        'length' => filesize($objPath),
      ];
    }
  }
  // Search packsets
  $typeSuffix = $isTree ? 'trees' : 'blobs';
  $key = "{$computerID}/packsets/{$bucketID}-{$typeSuffix}/";
  $domain = cacheDomain($key, false);
  if ($domain === null) {
    if (!in_array($computerID, computerIDs($base))) throw new Exception("Computer not found: {$computerID}", 404);
    if (!in_array($bucketID, bucketIDs($base, $computerID))) throw new Exception("Folder not found: {$bucketID}", 404);
    $domain = cacheDomain($key, true);
  }
  $info = packFind($base, $domain, $key, $hash_bin);
  if ($info !== null) {
    $path = "{$base}/{$key}/{$info['pack']}.pack";
    $info['url'] = $path;
    return $info;
  }
  // Not found
  throw new Exception("Object not found: {$hash}", 404);
}

function servePointer($pointer) {
  $h = fopen($pointer['url'], 'r');
  if (isset($pointer['offset'])) {
    fseek($h, $pointer['offset']);
  }
  if (isset($pointer['pack'])) {
    $header = fReadObjHeader($h);
    if ($pointer['length'] !== $header['data_length']) throw new Exception('Unexpected data length');
  }
  $left = $pointer['length'];
  while ($left > 0) {
    $len = min($left, 4096);
    echo fread($h, $len);
    $left -= $len;
  }
  fclose($h);
}

function packFind($base, $domain, $key, $hash) {
  $info = null;
  $cached = [];
  $stmt = cacheSelect($domain);
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $pack = bin2hex($row['sha1']);
    $cached[$pack] = true;
    $path = "{$base}/{$key}/{$pack}.index";
    if (!file_exists($path)) continue;
    $content = file_get_contents($path);
    $info = scanIndex($content, $hash, $pack);
    if ($info !== null) break;
  }
  $stmt->closeCursor();
  if ($info === null) {
    $info = fetchIndexes($base, $key, $cached, function($bin, $pack) use ($domain, $hash) {
      return scanIndex($bin, $hash, $pack);
    });
  }
  if ($info !== null) {
    cacheTouch($domain, $info['pack']);
  }
  return $info;
}

function fetchIndexes($base, $key, $cached, $cbk) {
  $info = null;
  $dir = new DirectoryIterator("{$base}/{$key}");
  foreach ($dir as $file) {
    if ($file->getExtension() !== 'index') continue;
    $pack = basename($file->getFilename(), '.index');
    if (isset($cached[$pack])) continue;
    $path = $file->getPathname();
    $bin = file_get_contents($path);
    $info = $cbk($bin, $pack);
    if ($info !== null) break;
  }
  return $info;
}
