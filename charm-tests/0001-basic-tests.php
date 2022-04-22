<?php
require(__DIR__.'/../vendor/autoload.php');

use function M\{
    sleep,
    go,
    await,
    suspend,
    interrupt
};
use Moebius\Coroutine;
use Moebius\Loop;

$a = go(function() {
    for ($i = 0; $i < 10; $i++) {
        echo "A: $i\n";
        sleep(0.05);
    }
    return "From A";
});

$b = go(function() {
    for ($i = 0; $i < 10; $i++) {
        echo "B: $i\n";
        sleep(0.025);
    }
    return "From B";
});

$c = go(function() {
    for ($i = 0; $i < 10; $i++) {
        echo "C: $i\n";
        suspend();
    }
    return "From C";
});

$d = go(function() {
    for ($i = 0; $i < 10; $i++) {
        echo "D: $i\n";
        interrupt();
    }
    return "From D";
});

echo "Waiting for A: ".await($a)."\n";

echo "Waiting for B: ".await($b)."\n";

echo "Waiting for C: ".await($c)."\n";

echo "Waiting for D: ".await($d)."\n";
