<?php
require(__DIR__.'/../vendor/autoload.php');

use Moebius\Coroutine as Co;
use Moebius\Loop;

echo Co::await(function() {
    return "A";
});

echo Co::await(function() {
    echo "B";
    return Co::await(function() {
        Co::suspend();
        return "C";
    });
});

echo Co::await(function() {
    echo "D";
    Co::await(function() {
        Co::suspend();
        echo "E";
    });
    echo "F";
});

echo "G\n";
