<?php
require(__DIR__.'/../vendor/autoload.php');

use Moebius\Coroutine as Co;
use Moebius\Loop;

$a = Co::go(function() {
    Co::suspend();
    echo "B";
});

$b = Co::go(function() {
    Co::suspend();
    echo "C\n";
});

echo "A";
