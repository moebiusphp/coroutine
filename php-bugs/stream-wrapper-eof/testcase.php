<?php
/**
 * Function logs return value with function name and arguments
 */
function log_return(string $method, array $args, $res) {
    echo "                                              CALL ".$method."( ".implode(" , ", $args)." ) RETURNED  ".substr(json_encode($res), 0, 30)."\n";
    return $res;
}

function log_stream_info($fp) {
    $info = [
        'fstat[size]' => fstat($fp)['size'],
        'feof' => feof($fp),
        'stream_get_meta_data[eof]' => stream_get_meta_data($fp)['eof'],
        'ftell' => ftell($fp),
    ];
    print_r($info);
    return;
    echo " - fstat[size]:               ".fstat($fp)['size']."\n";
    echo " - feof:                      ".json_encode(feof($fp))."\n";
    echo " - stream_get_meta_data[eof]: ".json_encode(stream_get_meta_data($fp)['eof'])."\n";
    echo " - ftell:                     ".ftell($fp)."\n";
}

/**
 * This stream wrapper provides files which initially contains the string "Hello World\n", and then they
 * are appended with a new line character every second.
 *
 * Any file name is treated the same way
 *
 * This simulates a file being appended to by another process
 */
class TestWrapper {
    private static $files = [];

    private $path, $mode, $options, $opened_path;

    private function getContents(): string {
        return self::$files[$this->path]['contents']();
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool {
        $this->time = time();
        $this->offset = 0;

        $this->path = $path;
        $this->mode = $mode;
        $this->options = $options;
        $this->opened_path = $opened_path;

        // The function generates a string which is longer every second that passes
        if (!isset(self::$files[$this->path])) {
            $timestamp = microtime(true);
            self::$files[$this->path] = [
                'inode' => count(self::$files),
                'contents' => function() use ($timestamp) {
                    $contents = "Hello World\n";
                    $elapsed = floor(microtime(true) - $timestamp);
                    for ($i = 0; $i < $elapsed; $i++) {
                        $contents .= "$i\n";
                    }
                    return $contents;
                },
                'ctime' => time(),
                'reader_count' => 0
            ];
        }
        // count how many open instances of this file we have
        ++self::$files[$this->path]['reader_count'];
        return log_return(__METHOD__, func_get_args(), true);
    }

    public function stream_close() {
        --self::$files[$this->path]['reader_count'];
        if (self::$files[$this->path]['reader_count'] === 0) {
            unset(self::$files[$this->path]);
        }
        return log_return(__METHOD__, func_get_args(), null);
    }

    public function stream_eof(): bool {
echo "strlen = ".strlen($this->getContents())."\n";
        return log_return(__METHOD__, func_get_args(), $this->offset === strlen($this->getContents()));
    }

    public function stream_flush(): bool {
        return log_return(__METHOD__, func_get_args(), true);
    }

    public function stream_lock(): bool {
        return log_return(__METHOD__, func_get_args(), false);
    }

    public function stream_metadata($path, $option, $value): bool {
        return log_return(__METHOD__, func_get_args(), false);
    }

    public function stream_read($count): string|false {
        $contents = $this->getContents();
        $chunk = substr($contents, $this->offset, $count);
        $this->offset += strlen($chunk);
        return log_return(__METHOD__, func_get_args(), $chunk);
    }

    public function stream_seek($offset, $whence = SEEK_SET): bool {
        $previousOffset = $this->offset;
        $length = strlen($this->getContents());
        switch ($whence) {
            case SEEK_SET: $this->offset = $offset; break;
            case SEEK_CUR: $this->offset += $offset; break;
            case SEEK_END: $this->offset = $length + $offset; break;
        }
        $this->offset = max(0, $this->offset);
        $this->offset = min($length, $this->offset);
        return log_return(__METHOD__, func_get_args(), $this->offset !== $previousOffset);
    }

    public function stream_set_option($option, $arg1, $arg2) {
        return log_return(__METHOD__, func_get_args(), false);
    }

    public function stream_stat() {
        $n = [];
        $s = [];
        $size = strlen($this->getContents());
        $strs = [
            'dev' => 0,
            'ino' => self::$files[$this->path]['inode'],
            'mode' => 0100000,
            'nlink' => 0,
            'uid' => getmyuid(),
            'gid' => getmygid(),
            'rdev' => 0,
            'size' => $size,
            'atime' => time(),
            'mtime' => time(),
            'ctime' => self::$files[$this->path]['ctime'],
            'blksize' => 4096,
            'blocks' => ceil($size / 512),
        ];
        $res = array_merge(array_values($strs), $strs);
        return log_return(__METHOD__, func_get_args(), $res);
    }

    public function stream_tell() {
        return log_return(__METHOD__, func_get_args(), $this->offset);
    }

    public function stream_truncate() {
        return log_return(__METHOD__, func_get_args(), false);
    }

    public function stream_write(string $data) {
        return log_return(__METHOD__, func_get_args(), 0);
    }

    public function url_stat(string $path, int $flags) {
        $fp = fopen($path, 'r');
        return log_return(__METHOD__, func_get_args(), fstat($fp));
    }
}

stream_wrapper_register('test', TestWrapper::class, 0);

$fp = fopen('test://hello-world', 'r');
echo "1:\n";
log_stream_info($fp);
echo "fgets: ".json_encode(fgets($fp, 4096))."\n";
log_stream_info($fp);

echo "--------------------- sleep 1 second ------------------\n";
sleep(1);

fseek($fp, ftell($fp));
/*
$tmp = ftell($fp);
fseek($fp, 0);
fseek($fp, $tmp);
*/
echo "2:\n";
log_stream_info($fp);
echo "fgets: ".json_encode(fgets($fp, 4096))."\n";
log_stream_info($fp);
die();

echo "3:\n";
echo "fgets: ".json_encode(fgets($fp, 4096))."\n";
echo "ftell: ".ftell($fp)."\n";
echo "stream_get_meta_data['eof']: ".json_encode(stream_get_meta_data($fp)['eof'])."\n";

