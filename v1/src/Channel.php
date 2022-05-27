<?php
namespace Moebius\Coroutine;

use Moebius\Promise;
use Moebius\Coroutine as Co;

/**
 * A Channel is a communications channel between coroutines. A coroutine
 * that writes to a channel will block until a coroutine receives the
 * message.
 *
 * There are two types of channels;
 * - serializable channels and
 * - single process channels.
 *
 * A serializable channel can be shared between different processes via
 * a fifo file. It is possible to implement other backends for sending
 * and receiving messages.
 *
 * When a coroutine tries to receive a message from a channel, that
 * coroutine will block until a message becomes available. Messages 
 * can only be received by a single coroutine.
 *
 * When a coroutine tries to write to a channel, that coroutine will
 * block until a reader becomes available.
 *
 * If the channel is buffered, the message will be received immediately
 * without blocking the sender - as long as the buffer is not full.
 */
class Channel extends Kernel {

    protected string $type;
    protected int $bufferSize;
    protected array $buffer = [];

    // array of callbacks which will release a blocked reader
    protected array $readers = [];

    // array of callbacks which will release a blocked writer
    protected array $writers = [];

    public function __construct(string $type, int $buffer=0) {
        /**
         * Validate that the type is serializable, so that we can support
         * channels communicating across processes.
         */
        if (!$this->isSupportedType($type)) {
            throw new LogicException("Channels can only send messages that are serializable (scalar values or classes implementing the Serializable or JsonSerializable interfaces)");
        }
        if ($buffer < 0) {
            throw new InvalidArgumentException("Buffer size can't be negative");
        }
        $this->type = $type;
        $this->bufferSize = $buffer;
    }

    public function send(mixed $value): void {
        if (!$this->isSendable($value)) {
            throw new InvalidArgumentException("The provided value is not sendable (type must be '".$this->type."')");
        }

        $message = serialize($value);
        $value = null;

        $promise = new Promise();
        $this->writers[] = function() use ($message, $promise) {
            $promise->resolve(null);
            return $message;
        };
        $this->process();
        Co::await($promise);
    }

    public function receive(): mixed {
        $promise = new Promise();
        $this->readers[] = function(string $message) use ($promise) {
            $promise->resolve($message);
        };
        $this->process();
        return unserialize(Co::await($promise));
    }

    private function process(): void {
        // fill the buffer to (number_of_readers + buffer_size) if possible
        while (count($this->writers) > 0 && count($this->buffer) < (count($this->readers) + $this->bufferSize)) {
            $writer = array_shift($this->writers);
            $this->buffer[] = self::invoke($writer);
        }

        // empty the buffer until we have no more readers or no more buffer
        while (count($this->buffer) > 0 && count($this->readers) > 0) {
            $message = array_shift($this->buffer);
            $reader = array_shift($this->readers);
            self::invoke($reader, $message);
        }
    }

    /**
     * Check if a value is sendable via this channel
     */
    public function isSendable(mixed $value): bool {
        // Arrays need special handling because they can
        // contain anything
        if ($this->type === 'array') {
            if (!is_array($value)) {
                return false;
            }

            // Recursively check every member of the array
            foreach ($value as $k => $v) {
                if (is_scalar($v)) {
                    continue;
                }
                if (is_object($v) && $v instanceof \Serializable) {
                    continue;
                }
                if (is_array($v)) {
                    if (!$this->isSendable($v)) {
                        return false;
                    }
                    continue;
                }
                return false;
            }
            return true;
        }

        // Ordinary type checking
        $type = \get_debug_type($value);
        if ($type === $this->type) {
            return true;
        }
        if ($this->type === 'float' && is_int($value)) {
            return true;
        }
        if ($this->type === 'int' && is_float($value) && $value == floor($value)) {
            return true;
        }
        if (is_object($value) && class_exists($this->type) && $value instanceof $this->type) {
            return true;
        }

        return false;
    }

    /**
     * Unblock writers that were blocked by a full buffer
     */
    private function refillBuffer(): void {
        while (count($this->writers) > 0 && count($this->buffer) < $this->bufferSize) {
            $writer = array_shift($this->writers);
            $this->buffer[] = self::invoke($writer);
        }
    }

    /**
     * Check if the type string is an allowed type for a channel.
     */
    protected function isSupportedType(string $type): bool {
        switch($type) {
            case "null" :
            case "bool" :
            case "int" :
            case "float" :
            case "string" :
            case "array" :
                return true;

            case "object" :
                if (class_exists($type)) {
                    if (
                        class_implements($type, \Serializable::classs)
                    ) {
                        return true;
                    }
                }
                break;

        }
        return false;
    }
}
