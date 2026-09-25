<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/helpers.php';

$config = load_config();

if (!$config) {
    die("Could not load configuration file.");
}

if (php_sapi_name() !== "cli") {
    if (array_key_exists('key', $_GET) === false || $_GET['key'] !== $config['global']['cache_expiry_key']) {
        die('Cache expiry key missing or incorrect.');
    }
}
// Allow flushing all cache items when run from CLI with --all, -a, or "all"
$force = false;
if (php_sapi_name() === "cli") {
    global $argv;
    if (in_array("--all", $argv, true) || in_array("-a", $argv, true) || in_array("all", $argv, true)) {
        $force = true;
    }
}

$files = glob(dirname(__FILE__) . "/cache/*.pdf");
$now = time();

foreach ($files as $file) {
    if (is_file($file)) {
        if ($force || $now - filemtime($file) >= 60 * 60 * 24 * $config['global']['cache_expiry_after']) {
            unlink($file);
        }
    }
}

echo "Cache purged." . PHP_EOL;