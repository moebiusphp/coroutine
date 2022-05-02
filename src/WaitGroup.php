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
        if ($self = self::getCurrent()) {

            $this->waiting[$self->id] = $self;
            unset(self::$coroutines[$self->id]);
            self::suspend();

        } else {
            while ($this->value > 0) {
                if (self::tick() === 0) {
                    throw new LogicException("Can't wait for this wait group when no coroutines are active");
                }
            }
        }
    }

    private function release(): void {
        foreach ($this->waiting as $id => $co) {
            self::$coroutines[$id] = $co;
        }
        $this->waiting = [];
    }
}
