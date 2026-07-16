<?php

defined( 'ABSPATH' ) || exit;

/** Echo Platform OS kernel. Adds structure while preserving current runtime behavior. */
final class Echo_OS_Kernel {
    private static ?Echo_OS_Kernel $instance = null;
    private Echo_OS_Container $container;
    private Echo_OS_Module_Registry $modules;
    private Echo_OS_Logger $logger;
    private Echo_OS_Event_Bus $events;
    private bool $booted = false;

    private function __construct() {
        $this->container = new Echo_OS_Container();
        $this->logger    = new Echo_OS_Logger();
        $this->events    = new Echo_OS_Event_Bus( $this->logger );
        $this->modules   = new Echo_OS_Module_Registry( $this->logger );
        $this->container->set( 'kernel', $this );
        $this->container->set( 'logger', $this->logger );
        $this->container->set( 'events', $this->events );
        $this->container->set( 'modules', $this->modules );
    }

    public static function instance(): Echo_OS_Kernel {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    public function container(): Echo_OS_Container { return $this->container; }
    public function modules(): Echo_OS_Module_Registry { return $this->modules; }
    public function logger(): Echo_OS_Logger { return $this->logger; }
    public function events(): Echo_OS_Event_Bus { return $this->events; }

    public function register_module( Echo_OS_Module $module ): void { $this->modules->register( $module ); }

    public function boot(): void {
        if ( $this->booted ) return;
        $this->register_legacy_modules();
        /** Allows future native modules to register before boot. */
        do_action( 'echo_os_register_modules', $this );
        $this->modules->boot_all( $this );
        $this->booted = true;
        update_option( 'echo_os_kernel_last_boot', current_time( 'mysql', true ), false );
        $this->events->emit( 'kernel_booted', array( 'version' => ECHO_MOTORWORKS_CORE_VERSION ) );
    }

    public function health(): array {
        $modules = $this->modules->health();
        $critical = count( array_filter( $modules, static fn( array $item ): bool => 'critical' === ( $item['status'] ?? '' ) ) );
        return array(
            'status'    => 0 === $critical ? 'healthy' : 'critical',
            'critical'  => $critical,
            'modules'   => $modules,
            'last_boot' => get_option( 'echo_os_kernel_last_boot', '' ),
            'php'       => PHP_VERSION,
            'wordpress' => get_bloginfo( 'version' ),
        );
    }

    private function register_legacy_modules(): void {
        $definitions = array(
            array( 'catalog', 'Catalog', array( 'Echo_Motorworks_Catalog_Manager', 'Echo_Motorworks_Catalog_Cleanup' ) ),
            array( 'suppliers', 'Suppliers', array( 'Echo_Motorworks_Supplier_Engine', 'Echo_Platform_Sync_Engine' ), array( 'catalog' ) ),
            array( 'images', 'Image Intelligence', array( 'Echo_Motorworks_Supplier_Image_Repair', 'Echo_Motorworks_Smart_Engine' ), array( 'catalog', 'suppliers' ) ),
            array( 'vehicles', 'Vehicles and Fitment', array( 'Echo_Motorworks_Garage', 'Echo_Motorworks_Fitment' ) ),
            array( 'operations', 'Operations', array( 'Echo_Motorworks_Operations', 'Echo_Platform_OS' ) ),
            array( 'platform_ui', 'Platform UI', array( 'Echo_Motorworks_Admin', 'Echo_Platform_OS_UI' ), array( 'catalog', 'suppliers', 'vehicles', 'operations' ) ),
        );
        foreach ( $definitions as $definition ) {
            $this->register_module( new Echo_OS_Legacy_Module( $definition[0], $definition[1], $definition[2], $definition[3] ?? array() ) );
        }
    }
}
