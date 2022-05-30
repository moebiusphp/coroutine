<?php

use Moebius\Coroutine as Co;

//$fn = tempnam(sys_get_temp_dir(), 'charm-coroutine-test-07');
$fn = __FILE__.'.tmp';
register_shutdown_function(unlink(...), $fn);

if (file_exists($fn)) {
    unlink($fn);
}

if (!posix_mkfifo($fn, 0600)) {
    fwrite(STDERR, "Unable to create fifo file\n");
    exit(1);
}
$done = false;
clearstatcache();

Co::go(function() use ($fn, &$done) {
    echo "A";
    $read = Co::unblock(fopen($fn, 'rn'));
    echo "C";
    while (!feof($read)) {
        echo trim(fgets($read, 4096));
        $done = true;
        return;
    }
});
Co::go(function() use ($fn) {
    echo "B";
    $write = Co::unblock(fopen($fn, 'r+'));
    echo "D";
    fwrite($write, "E\n");
});

Co::await(Co::go(function() use (&$done) {
    Co::sleep(0.1);
    echo "F\n";
}));
