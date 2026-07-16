<?php

defined( 'ABSPATH' ) || exit;

/** Adapter that describes current legacy services without changing their behavior. */
final class Echo_OS_Legacy_Module implements Echo_OS_Module {
    private string $module_id;
    private string $name;
    private string $version;
    private array $dependencies;
    private array $classes;

    public function __construct( string $module_id, string $name, array $classes, array $dependencies = array(), string $version = ECHO_MOTORWORKS_CORE_VERSION ) {
        $this->module_id    = sanitize_key( $module_id );
        $this->name         = $name;
        $this->version      = $version;
        $this->classes      = $classes;
        $this->dependencies = array_map( 'sanitize_key', $dependencies );
    }

    public function id(): string { return $this->module_id; }

    public function manifest(): array {
        return array(
            'id'           => $this->module_id,
            'name'         => $this->name,
            'version'      => $this->version,
            'dependencies' => $this->dependencies,
            'legacy'       => true,
        );
    }

    public function register( Echo_OS_Kernel $kernel ): void {}
    public function boot( Echo_OS_Kernel $kernel ): void {}

    public function health(): array {
        $missing = array_values( array_filter( $this->classes, static fn( string $class ): bool => ! class_exists( $class ) ) );
        return array(
            'status'  => empty( $missing ) ? 'healthy' : 'critical',
            'message' => empty( $missing ) ? 'All required classes are available.' : 'Required classes are missing.',
            'details' => array( 'missing_classes' => $missing ),
        );
    }
}
