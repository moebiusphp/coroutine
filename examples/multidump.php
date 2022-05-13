<?php
    require(__DIR__."/../vendor/autoload.php");

    use Moebius\Coroutine as Co;

    foreach (glob(__DIR__.'/*') as $path) {
        if (is_file($path)) {
            Co::go('print_file', $path);                    // <-- Co::go() launches a function as a "green thread"
        }
    }

    /**
     * Watch function which will monitor files for added lines
     */
    function print_file(string $filename) {
        $fp = Co::unblock(fopen($filename, "r"));           // <-- Co::unblock() makes the stream cooperation friendly
        while (!feof($fp)) {
            echo basename($filename).": ".fgets($fp, 4096);
            Co::suspend();                                  // <-- Just for show
        }
    }
