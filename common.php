<?php

function scanIndex($raw, $hash, $pack) {
  if (bin2hex(substr($raw, 0, 8)) !== 'ff744f6300000002') throw new Exception("invalid index file");
  $fanout = unpack('N256', substr($raw, 8, 4 * 256));
  $fanout[0] = 0;
  $h0 = ord($hash{0});
  for ($i = $fanout[$h0]; $i < $fanout[$h0 + 1]; $i++) {
    $o = 8 + 1024 + $i * 40;
    if (substr($raw, $o + 16, 20) !== $hash) continue;
    $info = unpack('Joffset/Jlength', substr($raw, $o, 16));
    $info['pack'] = $pack;
    return $info;
  }
  return null;
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

function validHash(&$str) {
  if (preg_match('/^\w{40}$/', $str)) return $str;
  throw new Exception("Expected valid hash: '{$str}'", 400);
}

function validUUID(&$str, $throw = true) {
  if (preg_match('/^\w{8}-\w{4}-\w{4}-\w{4}-\w{12}$/', $str)) return $str;
  if (!$throw) return null;
  throw new Exception("Expected valid UUID: '{$str}'", 400);
}

function echoLenghPrefixed($str) {
  echo pack('J', strlen($str)), $str;
}

function sendJSON($obj, $code = 200) {
  if (!headers_sent()) {
    http_response_code($code);
    header('Content-Type: application/json');
  }
  echo json_encode($obj), "\n";
}
