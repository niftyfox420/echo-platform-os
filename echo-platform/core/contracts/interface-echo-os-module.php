<?php

defined( 'ABSPATH' ) || exit;

/** Contract implemented by native Echo Platform OS modules. */
interface Echo_OS_Module {
    /** Stable machine-readable module ID. */
    public function id(): string;

    /** Human-readable module metadata. */
    public function manifest(): array;

    /** Register hooks and services. Must be safe to call once. */
    public function register( Echo_OS_Kernel $kernel ): void;

    /** Boot runtime behavior after all modules are registered. */
    public function boot( Echo_OS_Kernel $kernel ): void;

    /** Lightweight health checks for Mission Control and Developer Mode. */
    public function health(): array;
}
