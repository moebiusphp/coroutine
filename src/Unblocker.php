<?php
namespace Moebius\Coroutine;

use function M\{
    interrupt,
    suspend,
    readable,
    writable
};

/**
 * This class will proxy a stream to ensure automatic context switching
 * between coroutines whenever the coroutine would be performing blocking
 * operations on the stream resource.
 */
class Unblocker {

    public $context;

    protected int $id;
    protected $fp;
    protected string $mode;
    protected int $options;
    protected ?float $readTimeout = null;
    protected string $writeBuffer = '';
    protected bool $pretendNonBlocking = false;

    protected static $resources = [];
    protected static $results = [];

    /**
     * Check if the provided resource is an unblocked proxy stream, or if
     * it is a raw stream resource.
     */
    public static function isUnblocked($resource): bool {
        self::_interrupt();
        if (!is_resource($resource)) {
            throw new \InvalidArgumentException("Expected a stream resource, got ".get_debug_type($resource));
        }
        $id = get_resource_id($resource);
        return isset(self::$resources[$id]);
    }

    public static function unblock($resource): mixed {
        self::_interrupt();

        if (!is_resource($resource)) {
            return $resource;
        }
        $id = get_resource_id($resource);
        if (isset(self::$resources[$id])) {
            // trying to unblock the same stream resource twice will
            // return the already unblocked stream resource.
            return self::$results[$id];
        }

        $meta = stream_get_meta_data($resource);
        self::register($meta['uri'] ? STREAM_IS_URL : 0);
        self::$resources[$id] = $resource;
        $result = fopen('moebius-unblocker://'.$id, $meta['mode']);
        self::$results[$id] = $result;
        self::unregister();
        return $result;
    }

    protected static function register(int $flags): void {
        stream_wrapper_register('moebius-unblocker', self::class, $flags);
    }

    protected static function unregister(): void {
        stream_wrapper_unregister('moebius-unblocker');
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool {
        self::_interrupt();
        $this->id = intval(substr($path, 20));
        if (!isset(self::$resources[$this->id])) {
            return false;
        }

        $this->fp = self::$resources[$this->id];
        $this->mode = $mode;
        $this->options = $options;
        stream_set_blocking($this->fp, false);
        return true;
    }

    public function stream_close(): void {
        fclose($this->fp);
        unset(self::$resources[$this->id]);
        unset(self::$results[$this->id]);
        self::_interrupt();
    }

    public function stream_eof(): bool {
        self::_interrupt();
        return feof($this->fp);
    }

    public function stream_flush(): bool {
        self::_interrupt();
        return fflush($this->fp);
    }

    public function stream_lock(int $operation): bool {
        if ($this->pretendNonBlocking) {
            suspend();
            return flock($this->fp, $operation | LOCK_NB);
        }
        while (!flock($this->fp, $operation | LOCK_NB, $would_block)) {
            if (!$would_block) {
                // if the function would not block, we'll not block either
                self::_interrupt();
                return false;
            }
            suspend();
        }
        return true;
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool {
        throw new \Exception("stream_metadata not implemented");
    }

    public function stream_read(int $count): string|false {
        if ($this->pretendNonBlocking) {
            if (!readable($this->fp, 0)) {
                return '';
            }
            return fread($this->fp, $count);
        }
        if (!readable($this->fp, $this->readTimeout)) {
            // timed out
            return false;
        }
        return fread($this->fp, $count);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool {
        self::_interrupt();
        return fseek($this->fp, $whence);
    }

    public function stream_set_option(int $option, int $arg1=null, int $arg2=null): bool {
        self::_interrupt();
        switch ($option) {
            case STREAM_OPTION_BLOCKING:
                // The method was called in response to stream_set_blocking()
                $this->pretendNonBlocking = $arg1 === 0;
                return true;

            case STREAM_OPTION_READ_TIMEOUT:
                // The method was called in response to stream_set_timeout()
                $this->readTimeout = $arg1 + ($arg2 / 1000000);

                return true;

            case STREAM_OPTION_WRITE_BUFFER:
                // The method was called in response to stream_set_write_buffer()
                if (0 === stream_set_write_buffer($this->fp, $arg2)) {
                    return true;
                }
                return false;

            case STREAM_OPTION_READ_BUFFER:
                if (0 === stream_set_read_buffer($this->fp, $arg2)) {
                    return true;
                }
                return false;

            default :
                echo "stream_set_option: unsupported option $option $arg1 $arg2\n";
                return false;
        }
        return false;
    }

    public function stream_stat(): array|false {
        self::_interrupt();
        return fstat($this->fp);
    }

    public function stream_tell(): int {
        self::_interrupt();
        return ftell($this->fp);
    }

    public function stream_truncate(int $new_size): bool {
        self::_interrupt();
        return ftruncate($this->fp, $new_size);
    }

    public function stream_write(string $data): int {
        if ($this->pretendNonBlocking) {
            if (!writable($this->fp, 0)) {
                return 0;
            }
            return fread($this->fp, $count);
        }
        if (!writable($this->fp, $this->readTimeout)) {
            // timed out
            return 0;
        }
        return fwrite($this->fp, $data);
    }

    protected static function _interrupt(): void {
        static $counter = 0;
        if (20 === $counter++) {
            $counter = 0;
            interrupt();
        }
    }
}
