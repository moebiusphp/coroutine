<?php // Tests that exceptions are thrown by await()
require(__DIR__.'/../vendor/autoload.php');

use Moebius\Coroutine as Co;
use Moebius\Coroutine\WaitGroup as WG;

$wg = new WG();
$wg->add(2);
$a1 = Co::go(function() use (&$wg) {
    echo "A1: Waiting\n";
    $wg->wait();
    echo "A1: WaitGroup finished\n";
});

$a2 = Co::go(function() use (&$wg) {
    echo "A2: Waiting\n";
    $wg->wait();
    echo "A2: WaitGroup finished\n";
});

$b = Co::go(function() use (&$wg) {
    echo "B: Sleeping 0.5\n";
    Co::sleep(0.5);
    $wg->done();
    echo "B: Done\n";
});

$c = Co::go(function() use (&$wg) {
    echo "C: Sleeping 1 sec\n";
    Co::sleep(1);
    $wg->done();
    echo "C: Done\n";
});

echo "Main thread waiting\n";
$wg->wait();
echo "Main thread done\n";
