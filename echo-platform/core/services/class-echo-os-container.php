<?php

defined( 'ABSPATH' ) || exit;

/** Small dependency container; avoids global construction order dependencies. */
final class Echo_OS_Container {
    private array $services = array();
    private array $factories = array();

    public function set( string $id, object $service ): void {
        $this->services[ sanitize_key( $id ) ] = $service;
    }

    public function factory( string $id, callable $factory ): void {
        $this->factories[ sanitize_key( $id ) ] = $factory;
    }

    public function has( string $id ): bool {
        $id = sanitize_key( $id );
        return isset( $this->services[ $id ] ) || isset( $this->factories[ $id ] );
    }

    public function get( string $id ): object {
        $id = sanitize_key( $id );
        if ( isset( $this->services[ $id ] ) ) {
            return $this->services[ $id ];
        }
        if ( ! isset( $this->factories[ $id ] ) ) {
            throw new RuntimeException( sprintf( 'Echo OS service not found: %s', $id ) );
        }
        $service = ( $this->factories[ $id ] )( $this );
        if ( ! is_object( $service ) ) {
            throw new RuntimeException( sprintf( 'Echo OS factory did not return an object: %s', $id ) );
        }
        $this->services[ $id ] = $service;
        return $service;
    }
}
