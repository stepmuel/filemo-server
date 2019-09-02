<?php

function cacheGetDB() {
  static $db = null;
  if ($db === null) {
    $cachepath = conf('cachepath', '/tmp/filemo.cache.sqlite');
    $db = new PDO('sqlite:' . $cachepath);
    $db->exec("CREATE TABLE IF NOT EXISTS pack_domain (id INTEGER UNIQUE, key TEXT PRIMARY KEY)");
    $db->exec("CREATE TABLE IF NOT EXISTS pack_index (domain INTEGER, sha1 BYTEA, ts INTEGER, n INTEGER, content BYTEA, PRIMARY KEY (domain, sha1))");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_pack_index_domain_ts ON pack_index(domain, ts)");
  }
  return $db;
}

function cacheDomain($key, $create = false) {
  $db = cacheGetDB();
  $values = [$key];
  $select = $db->prepare("SELECT id FROM pack_domain WHERE key = ?");
  if ($create) {
    $insert = $db->prepare("INSERT INTO pack_domain(id, key) VALUES((SELECT COALESCE(MAX(id), 0) + 1 FROM pack_domain), ?)");
    $insert->execute($values);
  }
  $select->execute($values);
  $id = $select->fetchColumn();
  return $id === false ? null : (int) $id;
}

function cacheAdd($domain, $sha1, $content = null) {
  $db = cacheGetDB();
  $values = [$content, $domain, hex2bin($sha1)];
  $insert = $db->prepare("INSERT INTO pack_index(n, ts, content, domain, sha1) VALUES(1, null, ?, ?, ?)");
  $success = $insert->execute($values);
  if (!$success) {
    $update = $db->prepare("UPDATE pack_index SET content = ? WHERE domain = ? AND sha1 = ?");
    $update->execute($values);
  }
}

function cacheTouch($domain, $sha1) {
  $db = cacheGetDB();
  $values = [time(), $domain, hex2bin($sha1)];
  $update = $db->prepare("UPDATE pack_index SET ts = ?, n = n + 1 WHERE domain = ? AND sha1 = ?");
  $update->execute($values);
  if ($update->rowCount() === 0) {
    $insert = $db->prepare("INSERT INTO pack_index(n, ts, domain, sha1) VALUES(1, ?, ?, ?)");
    $insert->execute($values);
  }
}

function cacheSelect($domain) {
  $db = cacheGetDB();
	$stmt = $db->prepare("SELECT sha1, content FROM pack_index WHERE domain = ? ORDER BY ts DESC");
	$stmt->execute([$domain]);
  return $stmt;
}
