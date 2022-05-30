<?php

use Moebius\Coroutine as Co;
use Moebius\Coroutine\WaitGroup;

$t = microtime(true);
$wg = new WaitGroup();
$wg->add(3);

Co::go(function() use ($wg) {
    Co::sleep(0.03);
    echo "C";
    $wg->done();
});
Co::go(function() use ($wg) {
    Co::sleep(0.02);
    echo "B";
    $wg->done();
});
Co::go(function() use ($wg) {
    Co::sleep(0.01);
    echo "A";
    $wg->done();
});
Co::go(function() use ($wg, $t) {
    $wg->wait();
    if (0.03 < (microtime(true) - $t)) {
        echo "D";
    }
    echo "\n";
});

