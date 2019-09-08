<?php

require_once('common.php');

set_error_handler(function($severity , $message, $file, $line) {
  throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e) {
  $code = $e->getCode();
  $info = ['message' => $e->getMessage()];
  sendJSON($info, $code ? $code : 500);
  if (defined('DEBUG') && DEBUG) {
    echo $e, "\n";
  }
});

function conf($key, $def = null) {
  static $conf = null;
  if ($conf === null) {
    $path = isset($_SERVER['CONFIG_PATH']) ? $_SERVER['CONFIG_PATH'] : 'config.php';
    $conf = loadVars($path);
  }
  return isset($conf[$key]) ? $conf[$key] : $def;
}

function loadVars($_path) {
  if (!file_exists($_path)) return [];
  include($_path);
  unset($_path);
  return get_defined_vars();
}

if (conf('fspath') !== null) {
  require_once('providers/fs-provider.php');
} elseif (conf('s3server') !== null) {
  require_once('providers/s3-provider.php');
} else {
  throw new Exception("No provider configured");
}
