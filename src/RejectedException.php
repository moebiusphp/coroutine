<?php
namespace Moebius\Coroutine;

class RejectedException extends \Exception {
    public mixed $reason;

    public function __construct($reason) {
        parent::__construct("Promise rejected without an exception");
        $this->reason = $reason;
    }
}
