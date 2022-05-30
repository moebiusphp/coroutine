<?php

use Moebius\Coroutine as Co;
use Moebius\Loop;

echo Co::await(Co::go(function() {
    return "A";
}));

echo Co::await(Co::go(function() {
    echo "B";
    return Co::await(Co::go(function() {
        Co::suspend();
        return "C";
    }));
}));

echo Co::await(Co::go(function() {
    echo "D";
    Co::await(Co::go(function() {
        Co::suspend();
        echo "E";
    }));
    echo "F";
}));

echo "G\n";
