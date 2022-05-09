<?php
require(__DIR__.'/../vendor/autoload.php');

use Moebius\Coroutine as Co;

//$fn = tempnam(sys_get_temp_dir(), 'charm-coroutine-test-07');
$fn = __FILE__.'.tmp';
register_shutdown_function(unlink(...), $fn);
if (file_exists($fn)) {
    unlink($fn);
}

echo "Filename for fifo: $fn\n";
if (!posix_mkfifo($fn, 0600)) {
    fwrite(STDERR, "Unable to create fifo file\n");
    exit(1);
}
$done = false;
clearstatcache();
Co::go(function() use ($fn, &$done) {
    echo "Open for read\n";
    $read = Co::unblock(fopen($fn, 'rn'));
    echo "Opened for read\n";
    while (!feof($read)) {
        echo "read from fifo: '".fgets($read, 4096)."'\n";
        $done = true;
        return;
    }
});
Co::go(function() use ($fn) {
    echo "Open for write\n";
    $write = Co::unblock(fopen($fn, 'r+'));
    echo "Opened for write\n";
    fwrite($write, "This was written\n");
});

Co::await(function() use (&$done) {
    while (!$done) {
        echo ".";
        Co::sleep(0.1);
    }
});
