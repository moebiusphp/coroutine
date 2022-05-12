<?php
namespace Moebius\Coroutine\Kernel;

use Moebius\Coroutine\Kernel;
use Moebius\Coroutine;
use Closure;

final class EventHandler extends Kernel {
    private static $nextId = 0;

    public readonly int $id;
    public mixed $value;
    public mixed $extra;
    public readonly ?float $timeout;
    private int $status = 0;

    public function __construct(mixed $value, float $timeout=null, mixed $extra=null) {
        $this->id = self::$nextId++;

        $this->value = $value;
        $this->timeout = $timeout !== null ? self::getRealTime() + $timeout : null;
        $this->extra = $extra;
    }

    public function __destruct() {
        if ($this->status === 0) {
            self::logWarning("[EventHandler {id}] Garbage collected while pending", ['id' => $this->id]);
        }
    }

    public function result(): ?bool {
        if ($this->status === 0) {
            return null;
        }
        return $this->status === 1;
    }

    public function activated(): void {
        if ($this->status !== 0) {
            throw new InternalLogicException("Event handler already has status ".$this->status);
        }
        $this->status = 1;
        $this->value = null;
        $this->extra = null;
    }

    public function cancelled(): void {
        if ($this->status !== 0) {
            throw new InternalLogicException("Event handler already has status ".$this->status);
        }
        $this->status = 2;
        $this->value = null;
        $this->extra = null;

    }
}
