<?php
require('../vendor/autoload.php');

use Moebius\Coroutine as Co;

$wg = new Moebius\Coroutine\WaitGroup();
$wg->add(10);

Co::go(function() use ($wg) {

    while (true) {
        echo "\r".gmdate('Y-m-d H:i:s');
        Co::sleep(0.1);
        $wg->done();
    }

});


$wg->wait();
echo "DONE\n";
