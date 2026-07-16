<?php

defined( 'ABSPATH' ) || exit;

/**
 * Platform-level registry, migrations, and diagnostics for Echo Platform 2.x.
 * This layer deliberately sits above the proven feature classes so releases
 * can be upgraded without rewriting catalog, garage, fitment, or import data.
 */
final class Echo_Motorworks_Platform {
    private const DB_VERSION_OPTION = 'echo_platform_db_version';
    private const INSTALLED_VERSION_OPTION = 'echo_platform_installed_version';

    public static function activate(): void {
        if ( class_exists( 'Echo_Motorworks_DB' ) ) {
            Echo_Motorworks_DB::activate();
        }
        self::migrate();
        if ( class_exists( 'Echo_Platform_OS' ) ) { Echo_Platform_OS::activate(); }
        if ( class_exists( 'Echo_Platform_Sync_Engine' ) ) { Echo_Platform_Sync_Engine::activate(); }
        flush_rewrite_rules( false );
    }

    public static function migrate(): void {
        $installed = (string) get_option( self::INSTALLED_VERSION_OPTION, '0.0.0' );

        if ( version_compare( $installed, '2.0.0', '<' ) ) {
            // Preserve all historical options and records. The v2 migration is
            // intentionally additive: it creates a platform settings envelope
            // and records the schema level without deleting legacy data.
            $settings = get_option( 'echo_platform_settings', array() );
            if ( ! is_array( $settings ) ) {
                $settings = array();
            }
            $settings = wp_parse_args(
                $settings,
                array(
                    'support_email' => get_option( 'echo_request_destination_email', 'accounts@echomotorworks.com' ),
                    'response_time' => 'Within 1 business day',
                    'universal_results' => true,
                    'restricted_universal_results' => true,
                )
            );
            update_option( 'echo_platform_settings', $settings, false );
            update_option( self::DB_VERSION_OPTION, '2.0.0', false );
        }

        update_option( self::INSTALLED_VERSION_OPTION, ECHO_MOTORWORKS_CORE_VERSION, false );
    }

    public static function modules(): array {
        return array(
            'catalog'   => array( 'label' => 'Catalog Engine', 'class' => 'Echo_Motorworks_Catalog_Manager' ),
            'suppliers' => array( 'label' => 'Supplier Engine', 'class' => 'Echo_Motorworks_Supplier_Engine' ),
            'images'    => array( 'label' => 'Image Engine', 'class' => 'Echo_Motorworks_Supplier_Image_Repair' ),
            'fitment'   => array( 'label' => 'Fitment Engine', 'class' => 'Echo_Motorworks_Fitment' ),
            'garage'    => array( 'label' => 'Garage Engine', 'class' => 'Echo_Motorworks_Garage' ),
            'support'   => array( 'label' => 'Support Engine', 'class' => 'Echo_Motorworks_Catalog_Manager' ),
            'api'       => array( 'label' => 'Vehicle Data API', 'class' => 'Echo_Motorworks_API' ),
        );
    }

    public static function diagnostics(): array {
        global $wpdb;

        $modules = array();
        foreach ( self::modules() as $key => $module ) {
            $modules[ $key ] = array(
                'label' => $module['label'],
                'ready' => class_exists( $module['class'] ),
            );
        }

        $uploads = wp_upload_dir();
        $products = post_type_exists( 'product' )
            ? (int) wp_count_posts( 'product' )->publish
            : 0;

        return array(
            'wordpress' => get_bloginfo( 'version' ),
            'php' => PHP_VERSION,
            'woocommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
            'products' => $products,
            'uploads_writable' => empty( $uploads['error'] ) && wp_is_writable( $uploads['basedir'] ),
            'tables_ready' => self::tables_ready( $wpdb ),
            'modules' => $modules,
            'installed_version' => (string) get_option( self::INSTALLED_VERSION_OPTION, ECHO_MOTORWORKS_CORE_VERSION ),
            'db_version' => (string) get_option( self::DB_VERSION_OPTION, 'legacy' ),
        );
    }

    private static function tables_ready( wpdb $wpdb ): bool {
        $candidates = array(
            $wpdb->prefix . 'echo_vehicles',
            $wpdb->prefix . 'echo_fitment',
        );
        $found = 0;
        foreach ( $candidates as $table ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
                $found++;
            }
        }
        // Older versions may use only one of these tables. One valid Echo table
        // is enough to confirm that the installed database was preserved.
        return $found > 0;
    }
}
