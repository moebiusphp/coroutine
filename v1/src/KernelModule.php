<?php
namespace Moebius\Coroutine;

/**
 * Class for implementing modules to extend the capabilities of
 * the kernel. These classes are singleton instances which are
 * constructed when the kernel bootstraps and can hook into
 * core operations in the kernel.
 */
abstract class KernelModule extends Kernel {

    /**
     * A name identifying the module
     */
    public static string $name;

    /**
     * Modules can't use the constructor to initialize.
     */
    public final function __construct() {}

    /**
     * Modules must install all hooks when this function
     * is called.
     */
    abstract public function start(): void;

    /**
     * Modules must remove all installed hooks, and allow their
     * instance to be garbage collected when this function is
     * called.
     *
     * This operation is mandatory, even if there are pending
     * tasks.
     */
    abstract public function stop(): void;

    /**
     * Modules must return true if the event loop should
     * continue running.
     */
    public function isPending(): bool {
        return false;
    }

}
