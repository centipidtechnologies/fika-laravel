<?php

$autoload = getenv('FIKA_TEST_AUTOLOAD') ?: __DIR__.'/../vendor/autoload.php';
if (! is_file($autoload)) {
    // Run against the host application's dependencies while developing in this repository.
    $autoload = dirname(__DIR__, 3).'/vendor/autoload.php';
}
$loader = require $autoload;
$loader->addPsr4('Fika\\Laravel\\', __DIR__.'/../src');
