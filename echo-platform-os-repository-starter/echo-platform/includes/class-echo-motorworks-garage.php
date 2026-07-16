<?php

defined( 'ABSPATH' ) || exit;

final class Echo_Motorworks_Garage {
    private Echo_Motorworks_API $api;

    public function __construct( Echo_Motorworks_API $api ) {
        $this->api = $api;
        add_action( 'wp_ajax_echo_save_vehicle', array( $this, 'ajax_save_vehicle' ) );
        add_action( 'wp_ajax_nopriv_echo_save_vehicle', array( $this, 'ajax_save_vehicle' ) );
        add_action( 'wp_ajax_echo_remove_vehicle', array( $this, 'ajax_remove_vehicle' ) );
        add_action( 'wp_ajax_echo_set_active_vehicle', array( $this, 'ajax_set_active_vehicle' ) );
        add_action( 'wp_ajax_nopriv_echo_set_active_vehicle', array( $this, 'ajax_set_active_vehicle' ) );
        add_action( 'wp_ajax_echo_get_garage_state', array( $this, 'ajax_get_garage_state' ) );
        add_action( 'wp_ajax_nopriv_echo_get_garage_state', array( $this, 'ajax_get_garage_state' ) );
        add_action( 'wp_ajax_echo_clear_active_vehicle', array( $this, 'ajax_clear_active_vehicle' ) );
        add_action( 'wp_ajax_nopriv_echo_clear_active_vehicle', array( $this, 'ajax_clear_active_vehicle' ) );
    }

    public function ajax_save_vehicle(): void {
        check_ajax_referer( 'echo_vehicle_lookup', 'nonce' );

        $source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'epa';
        $source_vehicle_id = isset( $_POST['source_vehicle_id'] ) ? sanitize_text_field( wp_unslash( $_POST['source_vehicle_id'] ) ) : '';

        if ( 'epa' === $source ) {
            if ( ! ctype_digit( $source_vehicle_id ) ) {
                wp_send_json_error( array( 'message' => 'Invalid EPA vehicle.' ), 400 );
            }
            $vehicle = $this->api->get_vehicle( $source_vehicle_id );
        } elseif ( 'nhtsa_vin' === $source ) {
            $vin = isset( $_POST['vin'] ) ? strtoupper( preg_replace( '/[^A-HJ-NPR-Z0-9]/i', '', wp_unslash( $_POST['vin'] ) ) ) : '';
            if ( 17 !== strlen( $vin ) ) {
                wp_send_json_error( array( 'message' => 'A complete VIN is required.' ), 400 );
            }
            $vehicle = $this->api->decode_vin( $vin );
        } elseif ( 'manual' === $source ) {
            $year   = isset( $_POST['year'] ) ? absint( $_POST['year'] ) : 0;
            $make   = isset( $_POST['make'] ) ? sanitize_text_field( wp_unslash( $_POST['make'] ) ) : '';
            $model  = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
            $engine = isset( $_POST['engine'] ) ? sanitize_text_field( wp_unslash( $_POST['engine'] ) ) : '';
            $trim   = isset( $_POST['trim'] ) ? sanitize_text_field( wp_unslash( $_POST['trim'] ) ) : '';

            if ( $year < 1900 || $year > ( (int) gmdate( 'Y' ) + 2 ) || '' === $make || '' === $model ) {
                wp_send_json_error( array( 'message' => 'Year, make, and model are required.' ), 400 );
            }

            $source_vehicle_id = 'manual-' . md5( strtolower( implode( '|', array( $year, $make, $model, $engine, $trim ) ) ) );
            $vehicle = array(
                'source'            => 'manual',
                'source_vehicle_id' => $source_vehicle_id,
                'year'              => $year,
                'make'              => $make,
                'model'             => $model,
                'option_label'      => trim( implode( ' · ', array_filter( array( $trim, $engine ) ) ) ),
                'submodel'          => $trim,
                'generation'        => '',
                'chassis'           => '',
                'engine'            => $engine,
                'engine_code'       => '',
                'cylinders'         => '',
                'displacement'      => '',
                'transmission'      => '',
                'drivetrain'        => '',
                'body_style'        => '',
                'vehicle_type'      => '',
                'fuel_type'         => '',
                'raw'               => array( 'manual' => true ),
            );
        } else {
            wp_send_json_error( array( 'message' => 'Unsupported vehicle source.' ), 400 );
        }

        if ( is_wp_error( $vehicle ) ) {
            wp_send_json_error( array( 'message' => $vehicle->get_error_message() ), 502 );
        }

        $vehicle_id = $this->upsert_vehicle( $vehicle );
        if ( ! $vehicle_id ) {
            wp_send_json_error( array( 'message' => 'The vehicle could not be saved.' ), 500 );
        }

        $vehicle['id'] = $vehicle_id;
        if ( is_user_logged_in() ) {
            $this->save_to_user_garage( get_current_user_id(), $vehicle );
        }
        $this->set_active_cookie( $vehicle_id );

        wp_send_json_success(
            array(
                'vehicle' => $this->public_vehicle( $vehicle ),
                'garage'  => is_user_logged_in() ? $this->get_user_garage( get_current_user_id() ) : array(),
            )
        );
    }

    public function ajax_remove_vehicle(): void {
        check_ajax_referer( 'echo_vehicle_lookup', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Sign in to edit the account garage.' ), 403 );
        }

        $vehicle_id = isset( $_POST['vehicle_id'] ) ? absint( $_POST['vehicle_id'] ) : 0;
        $garage     = $this->get_user_garage( get_current_user_id() );
        $garage     = array_values( array_filter( $garage, static fn( array $vehicle ): bool => (int) ( $vehicle['id'] ?? 0 ) !== $vehicle_id ) );
        update_user_meta( get_current_user_id(), '_echo_garage_vehicles', $garage );

        $active         = (int) get_user_meta( get_current_user_id(), '_echo_active_vehicle', true );
        $cookie_active  = ! empty( $_COOKIE['echo_active_vehicle_id'] ) ? absint( $_COOKIE['echo_active_vehicle_id'] ) : 0;
        $removed_active = $active === $vehicle_id || $cookie_active === $vehicle_id;

        if ( $active === $vehicle_id ) {
            delete_user_meta( get_current_user_id(), '_echo_active_vehicle' );
        }
        if ( $cookie_active === $vehicle_id ) {
            $this->clear_active_cookie();
        }

        $current_active = $this->get_active_vehicle();
        wp_send_json_success(
            array(
                'garage'        => $garage,
                'removedActive' => $removed_active,
                'activeVehicle' => $current_active ? $this->public_vehicle( $current_active ) : null,
            )
        );
    }

    public function ajax_set_active_vehicle(): void {
        check_ajax_referer( 'echo_vehicle_lookup', 'nonce' );
        $vehicle_id = isset( $_POST['vehicle_id'] ) ? absint( $_POST['vehicle_id'] ) : 0;
        $vehicle    = $this->get_vehicle( $vehicle_id );
        if ( ! $vehicle ) {
            wp_send_json_error( array( 'message' => 'Vehicle not found.' ), 404 );
        }
        if ( is_user_logged_in() ) {
            update_user_meta( get_current_user_id(), '_echo_active_vehicle', $vehicle_id );
        }
        $this->set_active_cookie( $vehicle_id );
        wp_send_json_success( array( 'vehicle' => $this->public_vehicle( $vehicle ) ) );
    }

    public function ajax_get_garage_state(): void {
        // Read-only state endpoint. Avoid a cached-page nonce preventing stale
        // vehicle cleanup after a plugin or CDN purge.
        nocache_headers();

        $active = $this->get_active_vehicle();
        wp_send_json_success(
            array(
                'isLoggedIn'    => is_user_logged_in(),
                'activeVehicle' => $active ? $this->public_vehicle( $active ) : null,
                'garage'        => is_user_logged_in() ? $this->get_user_garage( get_current_user_id() ) : array(),
                'version'       => ECHO_MOTORWORKS_CORE_VERSION,
            )
        );
    }

    public function ajax_clear_active_vehicle(): void {
        check_ajax_referer( 'echo_vehicle_lookup', 'nonce' );
        nocache_headers();

        $remove_from_garage = ! empty( $_POST['remove_from_garage'] );
        $active_id          = 0;

        if ( is_user_logged_in() ) {
            $user_id   = get_current_user_id();
            $active_id = (int) get_user_meta( $user_id, '_echo_active_vehicle', true );

            if ( $remove_from_garage && $active_id ) {
                $garage = array_values(
                    array_filter(
                        $this->get_user_garage( $user_id ),
                        static fn( array $vehicle ): bool => (int) ( $vehicle['id'] ?? 0 ) !== $active_id
                    )
                );
                update_user_meta( $user_id, '_echo_garage_vehicles', $garage );
            }

            delete_user_meta( $user_id, '_echo_active_vehicle' );
        }

        $this->clear_active_cookie();

        wp_send_json_success(
            array(
                'clearedVehicleId' => $active_id,
                'activeVehicle'    => null,
                'garage'           => is_user_logged_in() ? $this->get_user_garage( get_current_user_id() ) : array(),
                'version'          => ECHO_MOTORWORKS_CORE_VERSION,
            )
        );
    }

    public function upsert_vehicle( array $vehicle ): int {
        global $wpdb;
        $table = Echo_Motorworks_DB::vehicles_table();
        $now   = current_time( 'mysql', true );

        $source = sanitize_key( $vehicle['source'] ?? 'epa' );
        $source_vehicle_id = sanitize_text_field( $vehicle['source_vehicle_id'] ?? '' );
        if ( '' === $source_vehicle_id ) {
            return 0;
        }

        $existing = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE source = %s AND source_vehicle_id = %s LIMIT 1",
                $source,
                $source_vehicle_id
            )
        );

        $data = array(
            'source'             => $source,
            'source_vehicle_id'  => $source_vehicle_id,
            'year'               => absint( $vehicle['year'] ?? 0 ),
            'make'               => sanitize_text_field( $vehicle['make'] ?? '' ),
            'model'              => sanitize_text_field( $vehicle['model'] ?? '' ),
            'option_label'       => sanitize_text_field( $vehicle['option_label'] ?? '' ),
            'submodel'           => sanitize_text_field( $vehicle['submodel'] ?? '' ),
            'generation'         => sanitize_text_field( $vehicle['generation'] ?? '' ),
            'chassis'            => sanitize_text_field( $vehicle['chassis'] ?? '' ),
            'engine'             => sanitize_text_field( $vehicle['engine'] ?? '' ),
            'engine_code'        => sanitize_text_field( $vehicle['engine_code'] ?? '' ),
            'cylinders'          => sanitize_text_field( $vehicle['cylinders'] ?? '' ),
            'displacement'       => sanitize_text_field( $vehicle['displacement'] ?? '' ),
            'transmission'       => sanitize_text_field( $vehicle['transmission'] ?? '' ),
            'drivetrain'         => sanitize_text_field( $vehicle['drivetrain'] ?? '' ),
            'body_style'         => sanitize_text_field( $vehicle['body_style'] ?? '' ),
            'vehicle_type'       => sanitize_text_field( $vehicle['vehicle_type'] ?? '' ),
            'fuel_type'          => sanitize_text_field( $vehicle['fuel_type'] ?? '' ),
            'normalized_make'    => Echo_Motorworks_DB::normalize( (string) ( $vehicle['make'] ?? '' ) ),
            'normalized_model'   => Echo_Motorworks_DB::normalize( (string) ( $vehicle['model'] ?? '' ) ),
            'normalized_engine'      => Echo_Motorworks_DB::normalize( (string) ( $vehicle['engine'] ?? '' ) ),
            'normalized_submodel'    => Echo_Motorworks_DB::normalize( (string) ( $vehicle['submodel'] ?? '' ) ),
            'normalized_transmission'=> Echo_Motorworks_DB::normalize( (string) ( $vehicle['transmission'] ?? '' ) ),
            'normalized_drivetrain'  => Echo_Motorworks_DB::normalize( (string) ( $vehicle['drivetrain'] ?? '' ) ),
            'raw_json'           => wp_json_encode( $vehicle['raw'] ?? array() ),
            'updated_at'         => $now,
        );

        if ( $existing ) {
            $wpdb->update( $table, $data, array( 'id' => $existing ) );
            return $existing;
        }

        $data['created_at'] = $now;
        $inserted = $wpdb->insert( $table, $data );
        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    public function get_vehicle( int $vehicle_id ): ?array {
        global $wpdb;
        if ( ! $vehicle_id ) {
            return null;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . Echo_Motorworks_DB::vehicles_table() . ' WHERE id = %d', $vehicle_id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function get_active_vehicle(): ?array {
        $vehicle_id = 0;
        $from_query = false;

        if ( isset( $_GET['echo_vehicle_id'] ) ) {
            $vehicle_id = absint( $_GET['echo_vehicle_id'] );
            $from_query = $vehicle_id > 0;
        }

        if ( ! $vehicle_id && is_user_logged_in() ) {
            $user_id    = get_current_user_id();
            $vehicle_id = (int) get_user_meta( $user_id, '_echo_active_vehicle', true );

            if ( $vehicle_id ) {
                $garage_ids = array_map(
                    'absint',
                    wp_list_pluck( $this->get_user_garage( $user_id ), 'id' )
                );
                if ( ! in_array( $vehicle_id, $garage_ids, true ) ) {
                    delete_user_meta( $user_id, '_echo_active_vehicle' );
                    $this->clear_active_cookie();
                    $vehicle_id = 0;
                }
            }
        }

        // A signed-in account garage is authoritative. Browser cookies are only
        // used for guests, so a removed account vehicle cannot be resurrected.
        if ( ! $vehicle_id && ! is_user_logged_in() && ! empty( $_COOKIE['echo_active_vehicle_id'] ) ) {
            $vehicle_id = absint( $_COOKIE['echo_active_vehicle_id'] );
        }

        $vehicle = $vehicle_id ? $this->get_vehicle( $vehicle_id ) : null;
        if ( ! $vehicle && $vehicle_id && ! $from_query ) {
            if ( is_user_logged_in() ) {
                delete_user_meta( get_current_user_id(), '_echo_active_vehicle' );
            }
            $this->clear_active_cookie();
        }

        return $vehicle;
    }

    public function get_user_garage( int $user_id ): array {
        $garage = get_user_meta( $user_id, '_echo_garage_vehicles', true );
        return is_array( $garage ) ? array_values( $garage ) : array();
    }

    private function save_to_user_garage( int $user_id, array $vehicle ): void {
        $garage = $this->get_user_garage( $user_id );
        $public = $this->public_vehicle( $vehicle );
        $found  = false;

        foreach ( $garage as $index => $item ) {
            if ( (int) ( $item['id'] ?? 0 ) === (int) $public['id'] ) {
                $garage[ $index ] = $public;
                $found = true;
                break;
            }
        }
        if ( ! $found ) {
            array_unshift( $garage, $public );
        }
        $garage = array_slice( $garage, 0, 10 );

        update_user_meta( $user_id, '_echo_garage_vehicles', $garage );
        update_user_meta( $user_id, '_echo_active_vehicle', (int) $public['id'] );
    }

    private function set_active_cookie( int $vehicle_id ): void {
        if ( headers_sent() || $vehicle_id < 1 ) {
            return;
        }

        setcookie(
            'echo_active_vehicle_id',
            (string) $vehicle_id,
            array(
                'expires'  => time() + YEAR_IN_SECONDS,
                'path'     => COOKIEPATH ?: '/',
                'domain'   => COOKIE_DOMAIN ?: '',
                'secure'   => is_ssl(),
                'httponly' => false,
                'samesite' => 'Lax',
            )
        );
        $_COOKIE['echo_active_vehicle_id'] = (string) $vehicle_id;
    }

    private function clear_active_cookie(): void {
        if ( ! headers_sent() ) {
            $paths = array_unique( array_filter( array( '/', COOKIEPATH ?: '/', defined( 'SITECOOKIEPATH' ) ? SITECOOKIEPATH : '/' ) ) );
            $domains = array_unique( array( '', COOKIE_DOMAIN ?: '' ) );

            foreach ( $paths as $path ) {
                foreach ( $domains as $domain ) {
                    setcookie(
                        'echo_active_vehicle_id',
                        '',
                        array(
                            'expires'  => time() - YEAR_IN_SECONDS,
                            'path'     => $path,
                            'domain'   => $domain,
                            'secure'   => is_ssl(),
                            'httponly' => false,
                            'samesite' => 'Lax',
                        )
                    );
                }
            }
        }
        unset( $_COOKIE['echo_active_vehicle_id'] );
    }

    public function public_vehicle( array $vehicle ): array {
        return array(
            'id'                => absint( $vehicle['id'] ?? 0 ),
            'source'            => sanitize_key( $vehicle['source'] ?? 'epa' ),
            'source_vehicle_id' => sanitize_text_field( $vehicle['source_vehicle_id'] ?? '' ),
            'year'              => absint( $vehicle['year'] ?? 0 ),
            'make'              => sanitize_text_field( $vehicle['make'] ?? '' ),
            'model'             => sanitize_text_field( $vehicle['model'] ?? '' ),
            'option_label'      => sanitize_text_field( $vehicle['option_label'] ?? '' ),
            'engine'            => sanitize_text_field( $vehicle['engine'] ?? '' ),
            'transmission'      => sanitize_text_field( $vehicle['transmission'] ?? '' ),
            'drivetrain'        => sanitize_text_field( $vehicle['drivetrain'] ?? '' ),
            'label'             => trim( sprintf( '%d %s %s', absint( $vehicle['year'] ?? 0 ), sanitize_text_field( $vehicle['make'] ?? '' ), sanitize_text_field( $vehicle['model'] ?? '' ) ) ),
        );
    }
}
