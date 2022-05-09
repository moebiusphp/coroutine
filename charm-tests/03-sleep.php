<?php
require(__DIR__.'/../vendor/autoload.php');

use Moebius\Coroutine as Co;
use Moebius\Loop;

Co::go(function() {});
$t = microtime(true);
$a = Co::go(function() {
    Co::sleep(0);
    echo "B";
});
$b = Co::go(function() {
    Co::sleep(0);
    echo "C\n";
});
echo "A";
