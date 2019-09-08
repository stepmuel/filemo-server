<?php

// TODO: Check error behavior for different issues (e.g. not found response from s3)

require_once('setup.php');

function getAuthToken() {
  $headers = apache_request_headers();
  $header = isset($headers['Authorization']) ? $headers['Authorization'] : null;
  return preg_match('/Bearer (.+)/', $header, $m) ? $m[1] : null;
}

$accesstoken = conf('accesstoken');
if ($accesstoken === null) throw new Exception("Missing access token configuration", 500);
if ($accesstoken !== false && getAuthToken() !== $accesstoken) throw new Exception("Invalid access token", 401);

$get = isset($_GET['get']) ? $_GET['get'] : 'status';

if ($get === 'status') {
  test();
  $info = ['status' => 'OK'];
  sendJSON($info);
  exit;
}

if ($get === 'computers') {
  serveComputers();
  exit;
}

if ($get === 'buckets') {
  $computerID = validUUID($_GET['computer']);
  serveBuckets($computerID);
  exit;
}

if ($get === 'keys') {
  $computerID = validUUID($_GET['computer']);
  serveKeys($computerID);
  exit;
}

if ($get === 'blob' || $get === 'tree' || $get === 'master') {
  $computerID = validUUID($_GET['computer']);
  $bucketID = validUUID($_GET['bucket']);
  $hashes = isset($_GET['hashes']) ? $_GET['hashes'] : "";
  if ($get === 'master') {
    $hashes = masterHash($computerID, $bucketID);
  }
  $hashes = array_map('validHash', explode(',', $hashes));
  $isTree = $get !== 'blob';
  $found = serveObjects($computerID, $bucketID, $hashes, $isTree);
  exit;
}

throw new Exception("Bad request", 400);
