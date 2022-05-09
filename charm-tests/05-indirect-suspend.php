<?php
require(__DIR__.'/../vendor/autoload.php');

use Moebius\Coroutine as Co;
use Moebius\Coroutine\Unblocker;
use Moebius\Loop;

$fp = tmpfile();
$fp = Unblocker::unblock($fp);

$a = Co::go(function() use ($fp) {
    // this write should cause the coroutine to become suspended
    fwrite($fp, "Hello");
    echo "C\n";
});

$b = Co::go(function() use ($fp) {
    // this coroutine runs without any interruptions immediately
    echo "A";
});

echo "B";
