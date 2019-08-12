<?php

function getBlobHandle($pointer, $cbk) {
	$h = fopen($pointer['path'], 'r');
	fseek($h, $pointer['offset']);
  if (isset($pointer['pack'])) {
    $header = fReadObjHeader($h);
    $pointer['length'] = $header['data_length'];
  }
  $ans = $cbk($h, $pointer['length']);
	fclose($h);
  return $ans;
}

function getBlobPointer($computerId, $bucketId, $hash, $isTree) {
  global $arqpath, $cachepath;
	$hash_raw = hex2bin($hash);
	if (!$isTree) {
		$pre = bin2hex($hash_raw{0});
		$hash_hex = bin2hex(substr($hash_raw, 1));
		$objPath = "{$arqpath}/{$computerId}/objects/{$pre}/{$hash_hex}";
		if (file_exists($objPath)) {
      return [
        'path' => $objPath,
        'offset' => 0,
        'length' => filesize($objPath),
      ];
		}
	}
	$dirPath = "{$arqpath}/{$computerId}/packsets/{$bucketId}-" . ($isTree ? 'trees' : 'blobs');
  if (!file_exists($dirPath)) throw new Exception("packset not found");
	$info = null;
	if (isset($cachepath)) {
		$info = indexCache($dirPath, $hash_raw, $isTree);
	} else {
		$info = indexScan($dirPath, $hash_raw);
	}
	if ($info === null) return null;
  $info['path'] = "{$dirPath}/{$info['pack']}.pack";
  return $info;
}

function fReadObjHeader($h) {
	$out = [];
  $out['mimetype'] = fReadString($h);
  $out['name'] = fReadString($h);
  $out['data_length'] = fReadUInt64($h);
	return $out;
}

function fReadString($h) {
	$notNull = ord(fgetc($h));
	if ($notNull === 0) return null;
	$len = fReadUInt64($h);
	return fread($h, $len);
}

function fReadUInt64($h) {
	$b = fread($h, 8);
	return unpack('J', $b)[1];
}

function indexScan($dirPath, $hash, $priority = null) {
  if ($priority !== null) {
    foreach ($priority as $pack) {
      $path = "{$dirPath}/{$pack}.index";
      if (!file_exists($path)) continue;
  		$info = indexScanSingle($path, $hash);
      if ($info !== null) return $info;
    }
  }
	$dir = new DirectoryIterator($dirPath);
	foreach ($dir as $file) {
		if ($file->getExtension() !== 'index') continue;
		$path = $file->getPathname();
		$info = indexScanSingle($path, $hash);
    if ($info !== null) return $info;
	}
	return null;
}

function indexScanSingle($path, $hash) {
	$raw = file_get_contents($path);
	if (bin2hex(substr($raw, 0, 8)) !== 'ff744f6300000002') throw new Exception("invalid index file");
	$fanout = unpack('N256', substr($raw, 8, 4 * 256));
	$fanout[0] = 0;
  $h0 = ord($hash{0});
	for ($i = $fanout[$h0]; $i < $fanout[$h0 + 1]; $i++) {
		$o = 8 + 1024 + $i * 40;
		if (substr($raw, $o + 16, 20) !== $hash) continue;
		$info = unpack('Joffset/Jlength', substr($raw, $o, 16));
		$info['pack'] = basename($path, '.index');
		return $info;
	}
  return null;
}

function indexCache($dirPath, $hash, $cacheInfo = true) {
	global $cachepath;
	static $db = null;
	if ($db === null) {
		$needsInit = !file_exists($cachepath);
		$db = new PDO("sqlite:{$cachepath}");
		if ($needsInit) {
      $db->exec("CREATE TABLE index_domain (id INTEGER UNIQUE, path STRING PRIMARY KEY)");
      $db->exec("CREATE TABLE index_pack (domain INTEGER, sha1 BLOB, ts INTEGER, n INTEGER, PRIMARY KEY (domain, sha1))");
		}
	}
  // Get domain
  $values = [$dirPath];
  $select = $db->prepare("SELECT id FROM index_domain WHERE path = ?");
  $select->execute($values);
  $id = $select->fetchColumn();
  if ($id === false) {
    $insert = $db->prepare("INSERT OR IGNORE INTO index_domain(id, path) VALUES((SELECT IFNULL(MAX(id), 0) + 1 FROM index_domain), ?)");
    $insert->execute($values);
    $select->execute($values);
    $id = $select->fetchColumn();
  }
  $domain = $id === false ? null : (int) $id;
  // Get most recent pack files for domain
	$stm = $db->prepare("SELECT sha1 FROM index_pack WHERE domain = ? ORDER BY ts DESC LIMIT 32");
	$stm->execute([$domain]);
  $recent = array_map('bin2hex', $stm->fetchAll(PDO::FETCH_COLUMN, 'sha1'));
  $info = indexScan($dirPath, $hash, $recent);
  if ($info === null) return null;
  // Bump pack usage timestamp
  $values = [time(), $domain, hex2bin($info['pack'])];
  $update = $db->prepare("UPDATE index_pack SET ts = ?, n = n + 1 WHERE domain = ? AND sha1 = ?");
  $update->execute($values);
  if ($update->rowCount() === 0) {
    $insert = $db->prepare("INSERT OR IGNORE INTO index_pack(n, ts, domain, sha1) VALUES(1, ?, ?, ?)");
    $insert->execute($values);
  }
	return $info;
}
