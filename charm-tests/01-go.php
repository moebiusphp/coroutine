<?php

use Moebius\Coroutine as Co;
use Moebius\Loop;

Co::go(function() {
    echo "A";
});

Co::go(function() {
    echo "B";
});

echo "C\n";
