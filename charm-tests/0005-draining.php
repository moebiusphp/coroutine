<?php // Tests that exceptions are thrown by await()
require(__DIR__.'/../vendor/autoload.php');

use Moebius\Coroutine as Co;

$run = true;

$stopper = Co::go(function() use (&$run) {
    Co::sleep(2);
    $run = false;
});

$thread1 = Co::go(function() use (&$run) {
    while ($run) {
        echo ".";Co::sleep(0.1);
    }
});

$thread2 = Co::go(function() use (&$run) {
    Co::sleep(1);
    echo "Other coroutine calls Co::drain()\n";
    Co::drain();
    echo "Other coroutine drained\n";
});

$thread3 = Co::go(function() use (&$run) {

    Co::sleep(0.55);
    echo "Calling drain\n";
    Co::go(function() use (&$run) {
        echo "Co co calls drain\n";
        Co::drain();
        echo "Co co drained\n";
        $run = false;
    });
    Co::drain();
    echo "Drain complete\n";
    for ($i = 0; $i < 10; $i++) {
        echo "$i\n";
        Co::sleep(0.02);
    }
});

Co::drain();
