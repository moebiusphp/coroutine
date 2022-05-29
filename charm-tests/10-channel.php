<?php
require(__DIR__.'/../vendor/autoload.php');

use Moebius\Coroutine as Co;
use Moebius\Coroutine\Channel;

$channel = new Channel('string');

$a = Co::go(function() use ($channel) {
    for ($i = 0; $i < 3; $i++) {
        echo "Channel listener 1:".$channel->receive();
    }
});

$b = Co::go(function() use ($channel) {
    for ($i = 0; $i < 3; $i++) {
        echo "Channel listener 2:".$channel->receive();
    }
});

$c = Co::go(function() use ($channel) {
    for ($i = 0; $i < 3; $i++) {
        echo "Channel listener 3:".$channel->receive();
    }
});

$d = Co::go(function() use ($channel) {
    $channel->send("A\n");
    $channel->send("B\n");
    Co::sleep(0.1);
    $channel->send("C\n");
    $channel->send("D\n");
    Co::sleep(0.1);
    $channel->send("E\n");
    $channel->send("F\n");
    Co::sleep(0.1);
    $channel->send("G\n");
    $channel->send("H\n");
    Co::sleep(0.1);
    $channel->send("I\n");
});

Co::await($d);
echo "DONE\n";
