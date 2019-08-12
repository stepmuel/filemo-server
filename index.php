<?php

set_error_handler(function($severity , $message, $file, $line) {
  throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e) {
  $code = $e->getCode();
  http_response_code($code ? $code : 500);
  header('Content-Type: application/json');
  echo json_encode(['message' => $e->getMessage()]);
});

require_once("config.php");

require_once("store.php");

function getAuthToken() {
  $headers = apache_request_headers();
  $header = isset($headers['Authorization']) ? $headers['Authorization'] : null;
  return preg_match('/Bearer (.+)/', $header, $m) ? $m[1] : null;
}

if (!isset($accesstoken)) throw new Exception("Missing access token configuration", 500);
if ($accesstoken !== false && getAuthToken() !== $accesstoken) throw new Exception("Invalid access token", 401);

$uuidExpr = '/^\w{8}-\w{4}-\w{4}-\w{4}-\w{12}$/';
$sha1Expr = '/^\w{40}$/';

function validHash(&$str) {
  global $sha1Expr;
  if (preg_match($sha1Expr, $str)) return $str;
  throw new Exception("Invalid hash: '{$str}'", 400);
}

function validComputer(&$str) {
  global $arqpath, $uuidExpr;
  $path = "{$arqpath}/{$str}";
  if (preg_match($uuidExpr, $str) && file_exists($path)) return $str;
  throw new Exception("Computer not found: '{$str}'", 404); 
}

function validBucket($compId, &$str) {
  global $arqpath, $uuidExpr;
  $path = "{$arqpath}/{$compId}/bucketdata/{$str}";
  if (preg_match($uuidExpr, $str) && file_exists($path)) return $str;
  throw new Exception("Bucket not found: '{$str}'", 404); 
}

$get = isset($_GET['get']) ? $_GET['get'] : null;

if ($get === 'info') {
  $computers = [];
  foreach (glob("{$arqpath}/*", GLOB_ONLYDIR | GLOB_NOSORT) as $compPath) {
    $compId = basename($compPath);
    if (!preg_match($uuidExpr, $compId)) continue;
    $computers []= (object) [
      'id' => $compId,
      'info' => file_get_contents("{$arqpath}/{$compId}/computerinfo"),
    ];
  }
  $out = (object) [
    'computers' => $computers
  ];
  header('Content-Type: application/json');
  echo json_encode($out);
  exit;
}

if ($get === 'buckets') {
  // Serve Folder Configuration Files
  $compId = validComputer($_GET['computer']);
  header("Content-type: application/octet-stream");
  foreach (glob("{$arqpath}/{$compId}/bucketdata/*", GLOB_ONLYDIR | GLOB_NOSORT) as $buckPath) {
    $buckId = basename($buckPath);
    if (!preg_match($uuidExpr, $buckId)) continue;
    $path = "{$arqpath}/{$compId}/buckets/{$buckId}";
    echo pack('J', filesize($path));
    readfile($path);
  }
  exit;
}

if ($get === 'keys') {
  $compId = validComputer($_GET['computer']);
  $blob = file_get_contents("{$arqpath}/{$compId}/encryptionv3.dat");
  echo $blob;
  exit;
}

if ($get === 'blob' || $get === 'tree' || $get === 'commit') {
  $compId = validComputer($_GET['computer']);
  $buckId = validBucket($compId, $_GET['bucket']);
  $hashes = isset($_GET['hashes']) ? $_GET['hashes'] : "";
  if ($get === 'commit') {
    $hashes = rtrim(file_get_contents("{$arqpath}/{$compId}/bucketdata/{$buckId}/refs/heads/master"), 'Y');
  }
  $isTree = $get !== 'blob';
  $hashes = explode(',', $hashes);
  $length = 0;
  $pointers = [];
  foreach ($hashes as $unsafeHash) {
    $hash = validHash($unsafeHash);
    $ptr = getBlobPointer($compId, $buckId, $hash, $isTree);
    if ($ptr === null) throw new Exception("Object not found: {$hash}", 404); 
    $pointers []= $ptr;
    $length += $ptr['length'];
  }
  $length += 8 * count($pointers);
  header("Content-type: application/octet-stream");
  header("Content-Length: {$length}");
  foreach ($pointers as $ptr) {
    getBlobHandle($ptr, function($h, $s) {
      echo pack('J', $s);
      $left = $s;
      while ($left > 0) {
        $len = min($left, 4096);
        echo fread($h, $len);
        $left -= $len;
      }
    });
  }
  exit;
}

throw new Exception("Bad request", 400);
