<?php
namespace Moebius\Coroutine;

use Moebius\Promise;

class Channel extends Kernel {

    protected string $type;
    protected int $bufferSize;
    protected array $pendingReaders = [];
    protected array $pendingWriters = [];
    protected array $bufferedWriters = [];
    protected int $nextReaderId = 0;
    protected int $nextWriterId = 0;

    public function __construct(string $type, int $buffer=0) {
        /**
         * Validate that the type is serializable, so that we can support
         * channels communicating across processes.
         */
        if (!$this->isValidType($type)) {
            throw new LogicException("Channels can only send messages that are serializable (scalar values or classes implementing the Serializable or JsonSerializable interfaces)");
        }
        if ($buffer < 0) {
            throw new InvalidArgumentException("Argument 2 must be an integer >= 0");
        }
        $this->type = $type;
        $this->buffer = $buffer;
        $this->canFill = new Promise();
        $this->canFill->resolve();
        $this->canDrain = new Promise();
    }

    public function send(mixed $value): void {
        if (!$this->isValidType($value)) {
            throw new InvalidArgumentException("Channel only supports values of type ".$this->type.". Value of type ".get_debug_type($value)." provided.");
        }

        if (count($this->pendingReaders) > 0) {
            // we have a pending reader, so we take it
            $reader = array_shift($this->pendingReaders);
            $reader->resolve($value);
            return;
        } elseif (count($this->buffer) < $this->bufferSize) {
            // we are allowed to buffer this write
            $reader = new Promise();
            $reader->resolve($value);
            $this->buffer[] = $reader;
            return;
        } else {
            // writing must block until we get a reader
        }
        $reader = array_shift($this->p

        $this->buffer[$this->nextWriterId] = $promise = new Promise();

        $this->writers[] = new Promise(function($write) use (&$value) {
            $write($value);
        });
        while (!isset($this->buffer[$this->nextReaderId])) {
            // we have no readers
        if ($this->nextReaderId < $this->nextWriterId) {
            $this->buffer[$this->
        if ($this->nextWriterId - $this->nextReaderId >= $this->bufferSize) {

        ]
    }

    public function receive(): mixed {
        $this->awaitCanDrain();
        $message = array_shift($this->buffer);
        return array_shift($this->buffer);

    }

    protected function awaitCanFill(): void {
        while (count($this->buffer) < $this->bufferSize) {
            do {
                self::$modules["core.promises"]->awaitThenable($this->canFill);
            } while ($this->canFill->status() !== Promise::PENDING);
        }
    }

    protected function awaitCanDrain(): void {
        do {
            self::$modules["core.promises"]->awaitThenable($this->canDrain);
        } while ($this->canFill->status() !== Promise::PENDING);
    }

    protected function isValidType(mixed $value): bool {
        if (get_debug_type($value) !== $this->type) {
            if (is_object($value) && class_exists($this->type)) {
                $type = $this->type;
                if ($value instanceof $type) {
                    return true;
                }
            }
            return false;
        }
        return true;
    }

    protected static function isSerializableType(string $type): bool {
        switch($type) {
            case "null" :
            case "bool" :
            case "int" :
            case "float" :
            case "string" :
            case "array" :
                return true;

            default:
                if (class_exists($type)) {
                    if (
                        class_implements($type, \JsonSerializable::class) ||
                        class_implements($type, \Serializable::classs)
                    } {
                        return false;
                    }
                    return true;
                }
                break;
        }
        return false;
    }
}
