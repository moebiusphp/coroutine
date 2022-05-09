<?php
require(__DIR__.'/../vendor/autoload.php');

use Moebius\Coroutine as Co;
use Moebius\Loop;

echo Co::run(function() {
    return "A";
});

Co::run(function() {
    echo "B";
});

echo "C\n";
