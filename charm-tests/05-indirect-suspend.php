<?php

use Moebius\Coroutine as Co;
use Moebius\Coroutine\Unblocker;
use Moebius\Loop;

$fp = tmpfile();
register_shutdown_function(function() use ($fp) {
    $meta = stream_get_meta_data($fp);
    unlink($meta['uri']);
});

$fp = Unblocker::unblock($fp);

$a = Co::go(function() use ($fp) {
    // this write should cause the coroutine to become suspended
    fwrite($fp, "Hello");
    echo "C\n";
});

$b = Co::go(function() use ($fp) {
    // this coroutine runs without any interruptions immediately
    echo "A";
});

echo "B";

// Allow fwrite to finish
Co::sleep(0.1);
