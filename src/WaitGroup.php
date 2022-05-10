<?php
namespace Moebius\Coroutine;

final class WaitGroup extends Kernel {

    private int $value = 0;
    private array $waiting = [];

    public function add(int $delta): void {
        $this->value += $delta;
        if ($this->value <= 0) {
            $this->value = 0;
            $this->release();
        }
    }

    public function done(): void {
        if ($this->value === 0) {
            throw new LogicException("Unexpected call to WaitGroup::done(). Use WaitGroup::add(1) to increase the number of permitted calls.");
        }
        $this->value--;
        if ($this->value <= 0) {
            $this->value = 0;
            $this->release();
        }
    }

    public function wait(): void {
        if ($co = self::getCurrent()) {
            /**
             * Remove coroutine from loop, it will be reinserted when
             * WaitGroup finishes.
             */
            $this->waiting[$co->id] = $co;
            self::$coroutines->deactivate($co);
            self::suspend();
        } else {
            /**
             * We're in the global routine, so we'll let coroutines
             * progress until the waitgroup resolves. If no coroutines
             * are running, then the waitgroup can never be resolved.
             */
            while ($this->value > 0) {
                self::suspend();
                if (self::getActivityLevel() === 0) {
                    throw new LogicException("Can't wait for this wait group when no coroutines are active");
                }
            }
        }
    }

    private function release(): void {
        foreach ($this->waiting as $id => $co) {
            self::$coroutines->activate($co);
        }
        $this->waiting = [];
    }
}
