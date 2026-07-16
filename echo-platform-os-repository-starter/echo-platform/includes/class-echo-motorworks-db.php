<?php

defined( 'ABSPATH' ) || exit;

final class Echo_Motorworks_DB {
    public static function vehicles_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'echo_vehicles';
    }

    public static function fitment_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'echo_product_fitment';
    }

    public static function notes_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'echo_fitment_notes';
    }

    public static function logs_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'echo_logs';
    }

    public static function activate(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $vehicles        = self::vehicles_table();
        $fitment         = self::fitment_table();
        $notes           = self::notes_table();
        $logs            = self::logs_table();

        $sql_vehicles = "CREATE TABLE {$vehicles} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source varchar(40) NOT NULL DEFAULT 'epa',
            source_vehicle_id varchar(100) NOT NULL,
            year smallint(5) unsigned NOT NULL,
            make varchar(120) NOT NULL,
            model varchar(160) NOT NULL,
            option_label varchar(255) NOT NULL DEFAULT '',
            submodel varchar(160) NOT NULL DEFAULT '',
            generation varchar(120) NOT NULL DEFAULT '',
            chassis varchar(80) NOT NULL DEFAULT '',
            engine varchar(180) NOT NULL DEFAULT '',
            engine_code varchar(80) NOT NULL DEFAULT '',
            cylinders varchar(20) NOT NULL DEFAULT '',
            displacement varchar(40) NOT NULL DEFAULT '',
            transmission varchar(160) NOT NULL DEFAULT '',
            drivetrain varchar(120) NOT NULL DEFAULT '',
            body_style varchar(120) NOT NULL DEFAULT '',
            vehicle_type varchar(120) NOT NULL DEFAULT '',
            fuel_type varchar(120) NOT NULL DEFAULT '',
            normalized_make varchar(120) NOT NULL DEFAULT '',
            normalized_model varchar(160) NOT NULL DEFAULT '',
            normalized_engine varchar(180) NOT NULL DEFAULT '',
            normalized_submodel varchar(160) NOT NULL DEFAULT '',
            normalized_transmission varchar(160) NOT NULL DEFAULT '',
            normalized_drivetrain varchar(120) NOT NULL DEFAULT '',
            raw_json longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_vehicle (source, source_vehicle_id),
            KEY ymm (year, normalized_make(80), normalized_model(100)),
            KEY engine (normalized_engine)
        ) {$charset_collate};";

        $sql_fitment = "CREATE TABLE {$fitment} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            vehicle_id bigint(20) unsigned NULL,
            year_start smallint(5) unsigned NULL,
            year_end smallint(5) unsigned NULL,
            make varchar(120) NOT NULL DEFAULT '',
            model varchar(160) NOT NULL DEFAULT '',
            submodel varchar(160) NOT NULL DEFAULT '',
            generation varchar(120) NOT NULL DEFAULT '',
            chassis varchar(80) NOT NULL DEFAULT '',
            engine varchar(180) NOT NULL DEFAULT '',
            engine_code varchar(80) NOT NULL DEFAULT '',
            transmission varchar(160) NOT NULL DEFAULT '',
            drivetrain varchar(120) NOT NULL DEFAULT '',
            body_style varchar(120) NOT NULL DEFAULT '',
            normalized_make varchar(120) NOT NULL DEFAULT '',
            normalized_model varchar(160) NOT NULL DEFAULT '',
            normalized_engine varchar(180) NOT NULL DEFAULT '',
            normalized_submodel varchar(160) NOT NULL DEFAULT '',
            normalized_transmission varchar(160) NOT NULL DEFAULT '',
            normalized_drivetrain varchar(120) NOT NULL DEFAULT '',
            fitment_status varchar(30) NOT NULL DEFAULT 'confirmed',
            fitment_notes text NULL,
            supplier varchar(160) NOT NULL DEFAULT '',
            source varchar(160) NOT NULL DEFAULT '',
            source_key varchar(64) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_key (source_key),
            KEY product_id (product_id),
            KEY vehicle_id (vehicle_id),
            KEY matcher (year_start, year_end, normalized_make(80), normalized_model(100)),
            KEY status (fitment_status)
        ) {$charset_collate};";

        $sql_notes = "CREATE TABLE {$notes} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            vehicle_id bigint(20) unsigned NULL,
            note_type varchar(40) NOT NULL DEFAULT 'fitment',
            note_text text NOT NULL,
            source varchar(160) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY product_id (product_id),
            KEY vehicle_id (vehicle_id)
        ) {$charset_collate};";

        $sql_logs = "CREATE TABLE {$logs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL DEFAULT 'info',
            context varchar(80) NOT NULL DEFAULT 'core',
            message text NOT NULL,
            data longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY level (level),
            KEY context (context),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta( $sql_vehicles );
        dbDelta( $sql_fitment );
        dbDelta( $sql_notes );
        dbDelta( $sql_logs );

        add_rewrite_endpoint( 'my-garage', EP_ROOT | EP_PAGES );
        flush_rewrite_rules( false );

        update_option( 'echo_motorworks_core_db_version', ECHO_MOTORWORKS_CORE_VERSION, false );
        update_option( 'echo_motorworks_vehicle_provider', 'fueleconomy_gov', false );
    }

    public static function normalize( string $value ): string {
        $value = remove_accents( wp_strip_all_tags( $value ) );
        $value = strtolower( trim( $value ) );
        // Make common model spellings comparable: F150 = F-150, 2500HD = 2500 HD, etc.
        $value = preg_replace( '/([a-z])([0-9])/i', '$1 $2', $value );
        $value = preg_replace( '/([0-9])([a-z])/i', '$1 $2', $value );
        $value = preg_replace( '/[^a-z0-9]+/', ' ', $value );
        return trim( preg_replace( '/\s+/', ' ', (string) $value ) );
    }

    public static function log( string $level, string $context, string $message, array $data = array() ): void {
        global $wpdb;
        $wpdb->insert(
            self::logs_table(),
            array(
                'level'      => sanitize_key( $level ),
                'context'    => sanitize_key( $context ),
                'message'    => sanitize_textarea_field( $message ),
                'data'       => $data ? wp_json_encode( $data ) : null,
                'created_at' => current_time( 'mysql', true ),
            ),
            array( '%s', '%s', '%s', '%s', '%s' )
        );
    }
}
