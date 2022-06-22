<?php
namespace Moebius\Coroutine;

use function get_resource_id, is_resource, fwrite, stream_set_read_buffer, stream_set_write_buffer,
    stream_set_blocking, stream_get_meta_data, fclose, fopen, stream_wrapper_register, stream_wrapper_unregister,
    intval, fstat, ftell, fseek, fflush, trigger_error, fread, ftruncate;

use Moebius\Coroutine as Co;
use Moebius\Loop;
use Psr\Log\LoggerInterface;
use Charm\FallbackLogger;

/**
 * This class will proxy a stream to ensure automatic context switching
 * between coroutines whenever the coroutine would be performing blocking
 * operations on the stream resource.
 */
class Unblocker {

    /**
     * Becomes true when Unblocker::__destruct() is called, and serves to prevent
     * that we do stuff on the stream after it is closed because of event loop
     * semantics.
     */
    private bool $destroyed = false;

    public $context;
    public $resource;

    protected int $id;
    protected $internalFP;
    protected $externalFP;
    protected string $mode;
    protected int $options;
    protected ?float $readTimeout = null;
    protected string $writeBuffer = '';
    protected bool $pretendNonBlocking = false;

    protected static $resources = [];
    protected static $results = [];
    protected static $instances = [];
    protected static ?LoggerInterface $logger = null;

    protected static bool $registered = false;

    /**
     * Check if the provided resource is an unblocked proxy stream, or if
     * it is a raw stream resource.
     */
    public static function isUnblocked($resource): bool {
        if (!is_resource($resource)) {
            throw new \InvalidArgumentException("Expected a stream resource, got ".get_debug_type($resource));
        }
        $id = get_resource_id($resource);
        return isset(self::$resources[$id]);
    }

    public static function unblock($resource): mixed {
        if (!is_resource($resource)) {
            return $resource;
        }
        $id = get_resource_id($resource);
        if (isset(self::$resources[$id])) {
            // trying to unblock the same stream resource twice will
            // return the already unblocked stream resource.
            return self::$results[$id];
        }

        self::register(); // $meta['uri'] ? STREAM_IS_URL : 0);
        $meta = stream_get_meta_data($resource);
        self::$resources[$id] = $resource;
        $result = fopen('moebius-unblocker://'.$id, $meta['mode']);
        stream_set_read_buffer($result, 0);
        stream_set_write_buffer($result, 0);
        self::$results[$id] = $result;
//var_dump(self::$instances[$id]);
        return $result;
    }

    protected static function register(): void {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        stream_wrapper_register('moebius-unblocker', self::class, \STREAM_IS_URL);
    }

    protected static function unregister(): void {
        if (!self::$registered) {
            return;
        }
        self::$registered = false;
        stream_wrapper_unregister('moebius-unblocker');
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool {
        MOEBIUS_DEBUG and self::debugLogFunc(__METHOD__);
        $this->id = intval(substr($path, 20));
        if (!isset(self::$resources[$this->id])) {
            return false;
        }

        self::$instances[$this->id] = $this;

        $this->internalFP = self::$resources[$this->id];
        $this->mode = $mode;
        $this->options = $options;
        stream_set_blocking($this->internalFP, false);
        return true;
    }

    public function __destruct() {
        $this->destroyed = true;
    }

    public function stream_close(): void {
        MOEBIUS_DEBUG and self::debugLogFunc(__METHOD__);
        fclose($this->internalFP);
        unset(self::$resources[$this->id]);
        unset(self::$results[$this->id]);
    }

    public function stream_eof(): bool {
        MOEBIUS_DEBUG and self::debugLogFunc(__METHOD__);
        if (feof($this->internalFP)) {
            $id = $this->id;
            Loop::queueMicrotask(static function() use ($id) {
                /**
                 * Handle a bug or annoying "feature" where PHP caches forever a positive EOF.
                 * This is mostly important for FIFO files.
                 */
                if (isset(self::$results[$id])) {
                    fseek(self::$results[$id], 0, \SEEK_CUR);
                }
            });
            return true;
        }
        return false;
        $size = fstat($this->internalFP)['size'];
        $tell = ftell($this->internalFP);

        $res = $tell === $size;

        $res = ftell($this->internalFP) === fstat($this->internalFP)['size'];

        if ($res) {
            /**
             * Handle a bug or annoying "feature" where PHP caches forever a positive EOF
             */
            Loop::defer(function() {
                if (isset(self::$results[$this->id])) {
                    fseek(self::$results[$this->id], 0, \SEEK_CUR);
                }
            });
        }

        return feof($this->internalFP);

        return $res;
    }

    public function stream_flush(): bool {
        MOEBIUS_DEBUG and self::debugLogFunc(__METHOD__);
        if (!$this->writable($this->internalFP)) {
            return false;
        }
        return fflush($this->internalFP);
    }

    public function stream_lock(int $operation): bool {
        MOEBIUS_DEBUG and self::debugLogFunc(__METHOD__);
        $blocking = !($operation & LOCK_NB);
        while (is_resource($this->internalFP) && !flock($this->internalFP, $operation | LOCK_NB, $wouldBlock)) {
            if (!$blocking || !$wouldBlock) {
                $this->suspend();
                return false;
            }
            $this->suspend();
        }
        if (!is_resource($this->internalFP)) {
            trigger_error("Stream resource is invalid", E_USER_ERROR);
            return false;
        }
        return true;
    }

    public function stream_read(int $count): string|false {
        MOEBIUS_DEBUG and self::debugLogFunc(__METHOD__);
        if ($this->pretendNonBlocking) {
            if (!$this->readable($this->internalFP, 0)) {
                $result = '';
            } else {
                $result = fread($this->internalFP, $count);
            }
        } else {
            if (!$this->readable($this->internalFP, $this->readTimeout)) {
                // timed out
                return false;
            }
            $result = fread($this->internalFP, $count);
        }
        //echo strtr($result, [ "\n" => "\n <<< " ]);
        return $result;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool {
        MOEBIUS_DEBUG and self::debugLogFunc(__METHOD__);
        $res = @fseek($this->internalFP, $offset, $whence);
        return $res === 0;
    }

    public function stream_set_option(int $option, int $arg1=null, int $arg2=null): bool {
        MOEBIUS_DEBUG and self::debugLogFunc(__METHOD__);
        switch ($option) {
            case \STREAM_OPTION_BLOCKING:
                // The method was called in response to stream_set_blocking()
                $this->pretendNonBlocking = $arg1 === 0;
                return true;

            case \STREAM_OPTION_READ_TIMEOUT:
                // The method was called in response to stream_set_timeout()
                $this->readTimeout = $arg1 + ($arg2 / 1000000);
                return true;

            case \STREAM_OPTION_WRITE_BUFFER:
                // The method was called in response to stream_set_write_buffer()
                if (0 === stream_set_write_buffer($this->internalFP, $arg2)) {
                    return true;
                }
                return false;

            case \STREAM_OPTION_READ_BUFFER:
                if (0 === stream_set_read_buffer($this->internalFP, $arg2)) {
                    return true;
                }
                return false;

            default :
                trigger_error("Unknown stream option $option with arguments ".json_encode($arg1)." and ".json_encode($arg2), E_USER_ERROR);
                return false;
        }
        return false;
    }

    public function stream_stat(): array|false {
        MOEBIUS_DEBUG and self::debugLogFunc(__METHOD__);
        return fstat($this->internalFP);
    }

    public function stream_tell(): int {
        MOEBIUS_DEBUG and self::debugLogFunc(__METHOD__);
        return ftell($this->internalFP);
    }

    public function stream_cast(int $cast_as) {
        MOEBIUS_DEBUG and self::debugLogFunc(__METHOD__);
        return $this->internalFP;
    }

    public function stream_truncate(int $new_size): bool {
        MOEBIUS_DEBUG and self::debugLogFunc(__METHOD__);
        $this->writable();
        return ftruncate($this->internalFP, $new_size);
    }

    public function stream_write(string $data): int {
        MOEBIUS_DEBUG and self::debugLogFunc(__METHOD__);
//echo strtr($data, [ "\n" => "\n >>> " ]);
        if ($this->pretendNonBlocking) {
            if (!$this->writable($this->internalFP, 0)) {
                return 0;
            }
            return fwrite($this->internalFP, $count);
        }
        if (!$this->writable($this->internalFP, $this->readTimeout)) {
            // timed out
            return 0;
        }
        return fwrite($this->internalFP, $data);
    }

    protected function readable(mixed $stream, float $timeout=null): bool {
        if ($this->destroyed) {
            return false;
        }
        MOEBIUS_DEBUG and self::debugLog("Await readable");
        return Co::readable($stream, $timeout);
    }

    protected function writable(mixed $stream, float $timeout=null): bool {
        if ($this->destroyed) {
            return false;
        }
        MOEBIUS_DEBUG and self::debugLog("Await writable");
        return Co::writable($stream, $timeout);
    }

    protected function suspend(): void {
        if (!$this->destroyed) {
            MOEBIUS_DEBUG and self::debugLog("Suspend");
            Co::suspend();
        }
    }

    private static function debugLogFunc(string $method, array $args=[]): void {
        self::debugLog($method."(".implode(", ", $args).")");
    }

    private static function debugLog(string $message, array $context=[]): void {
        if (!MOEBIUS_DEBUG) {
            return;
        }

        $prefix = 'Unblocker: ';

        self::getLogger()->debug($prefix.$message, $context);
    }

    protected static function getLogger(): LoggerInterface {
        if (null === self::$logger) {
            self::$logger = FallbackLogger::get();
        }

        return self::$logger;
    }

    public static function setLogger(LoggerInterface $logger) {
        self::$logger = $logger;
    }
}
