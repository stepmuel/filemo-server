<?php

$accesstoken = $_SERVER['ACCESS_TOKEN'];

$s3server = [
  'url' => $_SERVER['S3_URL'],
  'keyID' => $_SERVER['S3_KEY_ID'],
  'secret' => $_SERVER['S3_SECRET'],
  'region' => $_SERVER['S3_REGION'],
];
