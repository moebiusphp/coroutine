<?php
namespace Moebius\Coroutine\Kernel\Timers;

final class MinHeap extends \SplMinHeap {

    protected function compare($left, $right): int {
        if ($left->timeout < $right->timeout) {
            return 1;
        } elseif ($left->timeout > $right->timeout) {
            return -1;
        } else {
            return 0;
        }
    }

}
