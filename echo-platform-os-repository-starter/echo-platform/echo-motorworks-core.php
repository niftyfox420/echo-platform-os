<?php
/**
 * Plugin Name: Echo Platform
 * Plugin URI: https://echomotorworks.com/
 * Description: Unified Echo Motorworks catalog, supplier, image, fitment, garage, support, reporting, and settings platform for WooCommerce.
 * Version: 4.2.0
 * Author: Echo Motorworks
 * Text Domain: echo-motorworks-core
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 10.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Echo Motorworks Core does not read or write WooCommerce order records.
 * Declare compatibility with High-Performance Order Storage (HPOS) so
 * WooCommerce does not flag the plugin as incompatible.
 */
add_action( 'before_woocommerce_init', static function (): void {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );

define( 'ECHO_MOTORWORKS_CORE_VERSION', '4.2.0' );
define( 'ECHO_MOTORWORKS_CORE_FILE', __FILE__ );
define( 'ECHO_MOTORWORKS_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'ECHO_MOTORWORKS_CORE_URL', plugin_dir_url( __FILE__ ) );

require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-motorworks-db.php';
require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-motorworks-platform.php';
require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-motorworks-api.php';
require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-motorworks-garage.php';
require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-motorworks-fitment.php';
require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-motorworks-frontend.php';
require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-motorworks-admin.php';
require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-motorworks-leistune-builder.php';
require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-motorworks-eldoc-builder.php';
require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-motorworks-flf-builder.php';
require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-motorworks-mabotech-builder.php';
require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-motorworks-evilenergy-builder.php';
require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-motorworks-pds-builder.php';
require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-motorworks-supplier-brand-repair.php';
require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-motorworks-supplier-image-repair.php';
require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-motorworks-supplier-engine.php';
require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-motorworks-catalog-manager.php';
require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-motorworks-supplier-intake.php';
require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-motorworks-diagnostics.php';
require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-motorworks-catalog-cleanup.php';
require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-motorworks-operations.php';
require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-motorworks-smart-engine.php';
require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-platform-os.php';
require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-platform-os-ui.php';
require_once ECHO_MOTORWORKS_CORE_DIR . 'includes/class-echo-platform-sync-engine.php';

register_activation_hook( __FILE__, array( 'Echo_Motorworks_Platform', 'activate' ) );
add_filter( 'cron_schedules', array( 'Echo_Platform_OS', 'add_schedules' ) );

/** Deactivate retired standalone Echo plugins after this replacement is active. */
add_action( 'admin_init', static function (): void {
    if ( ! current_user_can( 'activate_plugins' ) || ! function_exists( 'deactivate_plugins' ) ) {
        return;
    }
    $legacy = array(
        'echo-catalog-cleanup/echo-catalog-cleanup.php',
        'echo-platform/echo-platform.php',
        'echo-platform-v2.7.0/echo-platform.php',
    );
    foreach ( $legacy as $plugin ) {
        if ( is_plugin_active( $plugin ) ) {
            deactivate_plugins( $plugin, true );
        }
    }
} );

final class Echo_Motorworks_Core {
    private static ?Echo_Motorworks_Core $instance = null;

    public Echo_Motorworks_Platform $platform;
    public Echo_Motorworks_API $api;
    public Echo_Motorworks_Garage $garage;
    public Echo_Motorworks_Fitment $fitment;
    public Echo_Motorworks_Frontend $frontend;
    public Echo_Motorworks_Admin $admin;
    public Echo_Motorworks_Leistune_Builder $leistune_builder;
    public Echo_Motorworks_ElDoc_Builder $eldoc_builder;
    public Echo_Motorworks_FLF_Builder $flf_builder;
    public Echo_Motorworks_Mabotech_Builder $mabotech_builder;
    public Echo_Motorworks_EvilEnergy_Builder $evilenergy_builder;
    public Echo_Motorworks_PDS_Builder $pds_builder;
    public Echo_Motorworks_Supplier_Brand_Repair $supplier_brand_repair;
    public Echo_Motorworks_Supplier_Image_Repair $supplier_image_repair;
    public Echo_Motorworks_Supplier_Engine $supplier_engine;
    public Echo_Motorworks_Catalog_Manager $catalog_manager;
    public Echo_Motorworks_Supplier_Intake $supplier_intake;
    public Echo_Motorworks_Diagnostics $diagnostics;
    public Echo_Motorworks_Catalog_Cleanup $catalog_cleanup;
    public Echo_Motorworks_Operations $operations;
    public Echo_Motorworks_Smart_Engine $smart_engine;
    public Echo_Platform_OS $os;
    public Echo_Platform_Sync_Engine $sync_engine;

    public static function instance(): Echo_Motorworks_Core {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'plugins_loaded', array( $this, 'boot' ), 20 );
    }

    public function boot(): void {
        Echo_Motorworks_Platform::migrate();
        $this->platform = new Echo_Motorworks_Platform();
        $this->api      = new Echo_Motorworks_API();
        $this->garage  = new Echo_Motorworks_Garage( $this->api );
        $this->fitment = new Echo_Motorworks_Fitment( $this->garage );
        $this->frontend = new Echo_Motorworks_Frontend( $this->api, $this->garage, $this->fitment );
        $this->admin    = new Echo_Motorworks_Admin( $this->api, $this->garage );
        $this->leistune_builder = new Echo_Motorworks_Leistune_Builder( $this->api, $this->garage );
        $this->eldoc_builder = new Echo_Motorworks_ElDoc_Builder( $this->api, $this->garage );
        $this->flf_builder = new Echo_Motorworks_FLF_Builder();
        $this->mabotech_builder = new Echo_Motorworks_Mabotech_Builder( $this->api, $this->garage );
        $this->supplier_brand_repair = new Echo_Motorworks_Supplier_Brand_Repair();
        $this->supplier_image_repair = new Echo_Motorworks_Supplier_Image_Repair();
        $this->supplier_engine = new Echo_Motorworks_Supplier_Engine();
        $this->catalog_manager = new Echo_Motorworks_Catalog_Manager();
        $this->supplier_intake = new Echo_Motorworks_Supplier_Intake();
        $this->diagnostics = new Echo_Motorworks_Diagnostics();
        $this->catalog_cleanup = new Echo_Motorworks_Catalog_Cleanup();
        $this->operations = new Echo_Motorworks_Operations();
        $this->smart_engine = new Echo_Motorworks_Smart_Engine();
        $this->os = new Echo_Platform_OS();
        $this->sync_engine = new Echo_Platform_Sync_Engine();
        $this->evilenergy_builder = new Echo_Motorworks_EvilEnergy_Builder();
        $this->pds_builder = new Echo_Motorworks_PDS_Builder();

        do_action( 'echo_motorworks_core_loaded', $this );
    }
}

function echo_motorworks_core(): Echo_Motorworks_Core {
    return Echo_Motorworks_Core::instance();
}

echo_motorworks_core();
