<?php

defined( 'ABSPATH' ) || exit;

/** Registers, validates, orders and boots OS modules. */
final class Echo_OS_Module_Registry {
    private array $modules = array();
    private array $booted = array();
    private Echo_OS_Logger $logger;

    public function __construct( Echo_OS_Logger $logger ) { $this->logger = $logger; }

    public function register( Echo_OS_Module $module ): void {
        $id = $module->id();
        if ( '' === $id ) {
            throw new InvalidArgumentException( 'Echo OS modules require an ID.' );
        }
        if ( isset( $this->modules[ $id ] ) ) {
            throw new RuntimeException( sprintf( 'Duplicate Echo OS module ID: %s', $id ) );
        }
        $this->modules[ $id ] = $module;
    }

    public function all(): array { return $this->modules; }

    public function manifests(): array {
        return array_map( static fn( Echo_OS_Module $module ): array => $module->manifest(), $this->modules );
    }

    public function boot_all( Echo_OS_Kernel $kernel ): void {
        foreach ( $this->ordered_ids() as $id ) {
            $module = $this->modules[ $id ];
            try {
                $module->register( $kernel );
                $module->boot( $kernel );
                $this->booted[ $id ] = true;
                $this->logger->log( 'info', 'module', 'Module booted', array( 'module' => $id ) );
            } catch ( Throwable $error ) {
                $this->booted[ $id ] = false;
                $this->logger->log( 'critical', 'module', $error->getMessage(), array( 'module' => $id ) );
                do_action( 'echo_os_module_boot_failed', $id, $error );
            }
        }
    }

    public function health(): array {
        $results = array();
        foreach ( $this->modules as $id => $module ) {
            try {
                $result = $module->health();
            } catch ( Throwable $error ) {
                $result = array( 'status' => 'critical', 'message' => $error->getMessage(), 'details' => array() );
            }
            $result['booted'] = $this->booted[ $id ] ?? false;
            $results[ $id ] = $result;
        }
        return $results;
    }

    private function ordered_ids(): array {
        $ordered = array();
        $visiting = array();
        $visit = function ( string $id ) use ( &$visit, &$ordered, &$visiting ): void {
            if ( in_array( $id, $ordered, true ) ) return;
            if ( isset( $visiting[ $id ] ) ) throw new RuntimeException( 'Circular module dependency: ' . $id );
            if ( ! isset( $this->modules[ $id ] ) ) throw new RuntimeException( 'Unknown module dependency: ' . $id );
            $visiting[ $id ] = true;
            foreach ( (array) ( $this->modules[ $id ]->manifest()['dependencies'] ?? array() ) as $dependency ) {
                $visit( sanitize_key( (string) $dependency ) );
            }
            unset( $visiting[ $id ] );
            $ordered[] = $id;
        };
        foreach ( array_keys( $this->modules ) as $id ) $visit( $id );
        return $ordered;
    }
}
