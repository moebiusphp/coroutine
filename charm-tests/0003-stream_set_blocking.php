<?php
require(__DIR__.'/../vendor/autoload.php');

use Moebius\Coroutine\Unblocker;
use Moebius\Coroutine as Co;

$fifoLock = tempnam(sys_get_temp_dir(), 'moebius-coroutine-test');
$fifoFile = $fifoLock.'.fifo';

posix_mkfifo($fifoFile, 0600);

register_shutdown_function(function() use ($fifoLock, $fifoFile) {
    unlink($fifoLock);
    unlink($fifoFile);
});

// this will not block due to "n"
$readFP = fopen($fifoFile, 'rn');

// make the stream an 'unblocked stream'
$readFP = Unblocker::unblock($readFP);

// this should happen immediately
Co::go(function() {
    echo "1 (because of blocking fread)\n";
});

Co::go(function() {
    Co::sleep(0.5);
    echo "4 (blocking read should be done)\n";
    die();
});

Co::go(function() {
    Co::sleep(1.0);
    echo "Never happens because of die() called in previous timeout\n";
});

stream_set_blocking($readFP, false);

echo "2 (first synchronous output)\n";
$line = fread($readFP, 4096);
echo "3 (after blocking read)\n";

