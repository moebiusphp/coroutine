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

// this should happen
Co::go(function() {
    echo "Since we've unblocked the stream, this should get printed\n";
});

Co::go(function() {
    Co::sleep(0.2);
    echo "Prevent the test from running forever\n";
    die();
});

$line = fread($readFP, 4096);

var_dump($line);

echo "This should not get printed\n";
