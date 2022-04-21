<?php
namespace Moebius\Coroutine;

class Exception extends \Exception {}

class LogicException extends \LogicException {}
class InternalLogicException extends LogicException {}
class IncorrectUsageException extends LogicException {}
class UnknownFiberException extends LogicException {}

class InvalidArgumentException extends \InvalidArgumentException {}
class CoroutineExpectedException extends InvalidArgumentException {}

class RuntimeException extends \RuntimeException {}
class PromiseResolvedException extends RuntimeException {}
class RejectedException extends RuntimeException {

    public readonly mixed $reason;

    public function __construct(mixed $reason) {
        $this->reason = $reason;

        parent::__construct("Promise rejected without an exception. The value is available via the 'reason' property of this exception.");
    }
}
