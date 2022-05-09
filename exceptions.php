<?php
namespace Moebius\Coroutine;

class Exception extends \Exception {}

/**
 * Exception for when we detect a flaw in the program logic
 * which for example can lead to an application sleeping
 * forever.
 */
class LogicException extends \LogicException {}

/**
 * Exception for when a function is being used incorrectly.
 */
class IncorrectUsageException extends LogicException {}

/**
 * Exception when something internal to the coroutine library must be wrong.
 */
class InternalLogicException extends LogicException {}

/**
 * Exception thrown when an unexpected fiber is encountered.
 */
class UnknownFiberException extends LogicException {}

/**
 * Exception thrown when an invalid argument was passed.
 */
class InvalidArgumentException extends \InvalidArgumentException {}

/**
 * Exception when a coroutine was expected as an argument
 */
class CoroutineExpectedException extends InvalidArgumentException {}

class NoCoroutineContextException extends LogicException {}

/**
 * Generic runtime exceptions
 */
class RuntimeException extends \RuntimeException {}

/**
 * Generic runtime IO error
 */
class IOException extends RuntimeException {}

/**
 * Exception when a promise was expected.
 */
class PromiseExpectedException extends RuntimeException {}

/**
 * Exception when a promise-like object was expected.
 */
class ThenableExpectedException extends PromiseExpectedException {}

/**
 * Exception when a promise is already resolved, and an unresolved
 * promise was expected.
 */
class PromiseResolvedException extends RuntimeException {}

/**
 * Exception when a promise is rejected without an exception. These
 * rejection reasons can't be thrown so this exception is a wrapper
 * intended to throw such exceptions.
 */
class RejectedException extends RuntimeException {
    public readonly mixed $reason;

    public function __construct(mixed $reason) {
        $this->reason = $reason;

        parent::__construct("Promise rejected without an exception. The value is available via the 'reason' property of this exception.");
    }
}
