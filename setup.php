<?php

set_error_handler(function($severity , $message, $file, $line) {
  throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e) {
  //echo $e, "\n"; // TODO: Only if DEBUG
  $code = $e->getCode();
  if (!headers_sent()) {
    http_response_code($code ? $code : 500);
    header('Content-Type: application/json');
  }
  $info = ['message' => $e->getMessage()];
  if ($code === 500) {
    $info['file'] = $e->getFile();
    $info['line'] = $e->getLine();
  }
  echo json_encode($info), "\n";
});

function conf($key, $def = null) {
  static $conf = null;
  if ($conf === null) {
    $load = function() {
      include('config.php');
      return get_defined_vars();
    };
    $conf = file_exists('config.php') ? $load() : [];
  }
  return isset($conf[$key]) ? $conf[$key] : $def;
}

if (conf('fspath') !== null) {
  require_once('providers/fs-provider.php');
} elseif (conf('s3server') !== null) {
  require_once('providers/s3-provider.php');
} else {
  throw new Exception("Missing provider configuration");
}
