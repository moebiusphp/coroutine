<?php
require(__DIR__.'/../vendor/autoload.php');

use function M\sleep;
use Moebius\Coroutine;
use Moebius\Loop;

$a = Coroutine::create(function() {
    for ($i = 0; $i < 10; $i++) {
        echo "A: $i\n";
        sleep(0.1);
    }
});

$b = Coroutine::create(function() {
    for ($i = 0; $i < 10; $i++) {
        echo "B: $i\n";
        sleep(0.009);
    }
});
