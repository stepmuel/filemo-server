<?php

/**
 * Server access token (required)
 * Use `openssl rand -hex 8` to generate one at random.
 * Set to `false` to use no token. 
 */

// $accesstoken = '78341152667a11ad';

/**
 * Path to cache file 
 * The cache file and the folder containing it must be writable by the web user.
 * '/tmp/filemo.cache.sqlite' is used as default value.
 */

// $cachepath = 'local/cache.sqlite';

/**
 * Provider configuration:
 * Either $fspath or $s3server have to be configured.
 */

/**
 * Path to Arq backup folder
 * (Backup folders contain a README.TXT and subfolders for each computer)
 */

// $fspath = '/home/example/backups';

/**
 * Configuration for S3 hosted backup 
 */

// $s3server = [
//  'url' => 'https://s3.eu-central-1.wasabisys.com/todo/',
//  'keyID' => 'todo',
//  'secret' => 'todo',
//  'region' => 'us-east-1',
// ];
