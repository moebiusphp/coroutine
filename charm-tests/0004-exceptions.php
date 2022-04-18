<?php // Tests that exceptions are thrown by await()
require(__DIR__.'/../vendor/autoload.php');

use function M\{
    sleep, go, await, unblock, run
};

$thread1 = go(function() {
    echo "Throwing a RuntimeException from go()\n";
    throw new \RuntimeException("An exception 1");}
);

try {
    $result = await($thread1);
} catch (\RuntimeException $e) {
    if ($e->getMessage() === 'An exception 1') {
        echo "RuntimeException caught!\n";
    }
}

try {
    $result = run(function() {
       echo "Throwing a RuntimeException from run()\n";
        throw new \RuntimeException("An exception 2");
    });
} catch (\RuntimeException $e) {
    if ($e->getMessage() === 'An exception 2') {
        echo "RuntimeException caught!\n";
    }
}

echo "Tests finished\n";
