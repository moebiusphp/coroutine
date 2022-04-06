<?php
require(__DIR__.'/../vendor/autoload.php');

use Moebius\Coroutine\Unblocker;

$fifoLock = tempnam(sys_get_temp_dir(), 'moebius-coroutine-test');
$fifoFile = $fifoLock.'.fifo';

posix_mkfifo($fifoFile, 0600);

// this will not block due to "n"
$readFP = fopen($fifoFile, 'rn');

// make the stream an 'unblocked stream'
$readFP = Unblocker::unblock($readFP);

// this should happen
Moebius\Loop::defer(function() {
    echo "2 (because of blocking fread)\n";
});

Moebius\Loop::setTimeout(function() {
    echo "4 (blocking read should be done)\n";
    die();
}, 0.1);

Moebius\Loop::setTimeout(function() {
    echo "Never happens because of die() called in previous timeout\n";
}, 0.2);

stream_set_blocking($readFP, false);

echo "1 (first synchronous output)\n";
$line = fread($readFP, 4096);
echo "3 (after blocking read)\n";

