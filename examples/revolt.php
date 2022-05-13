<?php
    require(__DIR__."/../vendor/autoload.php");

    use Moebius\Coroutine as Co;
    use Revolt\EventLoop as Revolt;

    Revolt::defer(function() {
        echo "A";
        $coro = Co::go(function() {
            echo "B";
            Co::sleep(0.01);
            echo "D\n";
        });
        Co::await($coro);
        echo "C";
    });

