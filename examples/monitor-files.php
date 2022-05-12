<?php
/**
 * A complete example of using Moebius to watch a lot of files for new
 * lines.
 */
    require(__DIR__."/../vendor/autoload.php");

    use Moebius\Coroutine as Co;
    use Evenement\EventEmitter;

    $emitter = new EventEmitter();

    // Launch the coroutines that watch the files
    foreach (glob('/var/log/*.log') as $logFile) {
        /* Launch a coroutine for every log file */
        Co::go('watch_file', $logFile, $emitter, 'append');
    }

    // Listen for new lines
    $emitter->on('append', function($filename, $line) {
        echo basename($filename).": ".$line."\n";
    });

    $emitter->on('truncate', function($filename) {
        echo basename($filename)." was truncated\n";
    });

    $emitter->on('close', function($filename) {
        echo basename($filename)." closed\n";
    });

    $i = 0;
    while (true) {
        echo "\r".gmdate('Y-m-d H:i:s')." monitoring ".'-\|/'[$i++ % 4]." ";
        Co::sleep(0.25);
    }


    /**
     * Watch function which will monitor files for added lines
     */
    function watch_file(string $filename, EventEmitter $emitter) {
        $fp = fopen($filename, "r");
        $fp = Co::unblock($fp);         // <-- important for concurrency
        fseek($fp, 0, \SEEK_END);
        $offset = ftell($fp);

        // Start reading the file
        while ($fp) {
            $filesize = fstat($fp)['size'];
            if ($filesize < $offset)  {
                fseek($fp, 0);
                $offset = 0;
            } elseif ($filesize === $offset) {
                Co::sleep(0.5);         // <-- Save some CPU time
            } elseif ($filesize > $offset) {
                $line = fgets($fp);
                $offset = ftell($fp);
                $emitter->emit('append', [$filename, trim($line)]);
            }
        }
        $emitter->emit('close', [$filename]);
    }
