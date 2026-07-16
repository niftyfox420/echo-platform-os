<?php

defined( 'ABSPATH' ) || exit;

final class Echo_Motorworks_API {
    private const EPA_BASE = 'https://www.fueleconomy.gov/ws/rest/';
    private const NHTSA_BASE = 'https://vpic.nhtsa.dot.gov/api/vehicles/';

    public function __construct() {
        add_action( 'wp_ajax_echo_vehicle_menu', array( $this, 'ajax_vehicle_menu' ) );
        add_action( 'wp_ajax_nopriv_echo_vehicle_menu', array( $this, 'ajax_vehicle_menu' ) );
        add_action( 'wp_ajax_echo_vehicle_details', array( $this, 'ajax_vehicle_details' ) );
        add_action( 'wp_ajax_nopriv_echo_vehicle_details', array( $this, 'ajax_vehicle_details' ) );
        add_action( 'wp_ajax_echo_decode_vin', array( $this, 'ajax_decode_vin' ) );
        add_action( 'wp_ajax_nopriv_echo_decode_vin', array( $this, 'ajax_decode_vin' ) );
    }

    public function ajax_vehicle_menu(): void {
        check_ajax_referer( 'echo_vehicle_lookup', 'nonce' );

        $level = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : 'year';
        $year  = isset( $_GET['year'] ) ? absint( $_GET['year'] ) : 0;
        $make  = isset( $_GET['make'] ) ? sanitize_text_field( wp_unslash( $_GET['make'] ) ) : '';
        $model = isset( $_GET['model'] ) ? sanitize_text_field( wp_unslash( $_GET['model'] ) ) : '';

        $result = $this->get_menu( $level, $year, $make, $model );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 );
        }

        wp_send_json_success( array( 'items' => $result ) );
    }

    public function ajax_vehicle_details(): void {
        check_ajax_referer( 'echo_vehicle_lookup', 'nonce' );
        $epa_id = isset( $_GET['vehicle_id'] ) ? sanitize_text_field( wp_unslash( $_GET['vehicle_id'] ) ) : '';
        if ( '' === $epa_id || ! ctype_digit( $epa_id ) ) {
            wp_send_json_error( array( 'message' => 'A valid EPA vehicle ID is required.' ), 400 );
        }

        $result = $this->get_vehicle( $epa_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 );
        }
        wp_send_json_success( array( 'vehicle' => $result ) );
    }

    public function ajax_decode_vin(): void {
        check_ajax_referer( 'echo_vehicle_lookup', 'nonce' );
        $vin = isset( $_GET['vin'] ) ? strtoupper( preg_replace( '/[^A-HJ-NPR-Z0-9]/i', '', wp_unslash( $_GET['vin'] ) ) ) : '';
        if ( 17 !== strlen( $vin ) ) {
            wp_send_json_error( array( 'message' => 'Enter a complete 17-character VIN.' ), 400 );
        }

        $result = $this->decode_vin( $vin );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 );
        }
        wp_send_json_success( array( 'vehicle' => $result ) );
    }

    public function get_menu( string $level, int $year = 0, string $make = '', string $model = '' ) {
        $allowed = array( 'year', 'make', 'model', 'options' );
        if ( ! in_array( $level, $allowed, true ) ) {
            return new WP_Error( 'echo_invalid_level', 'Unsupported vehicle menu level.' );
        }

        $path = 'vehicle/menu/' . $level;
        $args = array();

        if ( 'make' === $level ) {
            if ( $year < 1984 || $year > ( (int) gmdate( 'Y' ) + 2 ) ) {
                return new WP_Error( 'echo_invalid_year', 'Choose a valid model year.' );
            }
            $args['year'] = $year;
        } elseif ( 'model' === $level ) {
            if ( ! $year || '' === $make ) {
                return new WP_Error( 'echo_missing_fields', 'Year and make are required.' );
            }
            $args = array( 'year' => $year, 'make' => $make );
        } elseif ( 'options' === $level ) {
            if ( ! $year || '' === $make || '' === $model ) {
                return new WP_Error( 'echo_missing_fields', 'Year, make, and model are required.' );
            }
            $args = array( 'year' => $year, 'make' => $make, 'model' => $model );
        }

        $cache_key = 'echo_epa_' . md5( $level . '|' . wp_json_encode( $args ) );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        $url      = add_query_arg( $args, self::EPA_BASE . $path );
        $response = $this->remote_get( $url );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $items = $this->extract_menu_items( $response );
        if ( is_wp_error( $items ) ) {
            return $items;
        }

        if ( 'year' === $level ) {
            usort( $items, static fn( array $a, array $b ): int => (int) $b['value'] <=> (int) $a['value'] );
        } else {
            usort( $items, static fn( array $a, array $b ): int => strnatcasecmp( $a['text'], $b['text'] ) );
        }

        set_transient( $cache_key, $items, 'year' === $level ? DAY_IN_SECONDS : 30 * DAY_IN_SECONDS );
        return $items;
    }

    public function get_vehicle( string $epa_id ) {
        $cache_key = 'echo_epa_vehicle_' . md5( $epa_id );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        $response = $this->remote_get( self::EPA_BASE . 'vehicle/' . rawurlencode( $epa_id ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $record = $this->extract_record( $response );
        if ( is_wp_error( $record ) ) {
            return $record;
        }

        $engine_bits = array_filter(
            array(
                ! empty( $record['cylinders'] ) ? $record['cylinders'] . ' cyl' : '',
                ! empty( $record['displ'] ) ? $record['displ'] . 'L' : '',
                $record['eng_dscr'] ?? '',
                ! empty( $record['tCharger'] ) ? 'Turbo' : '',
                ! empty( $record['sCharger'] ) ? 'Supercharged' : '',
            )
        );

        $option_bits = array_filter(
            array(
                $record['trany'] ?? '',
                $record['drive'] ?? '',
                $record['fuelType1'] ?? '',
            )
        );

        $vehicle = array(
            'source'            => 'epa',
            'source_vehicle_id' => (string) ( $record['id'] ?? $epa_id ),
            'year'              => absint( $record['year'] ?? 0 ),
            'make'              => sanitize_text_field( $record['make'] ?? '' ),
            'model'             => sanitize_text_field( $record['model'] ?? '' ),
            'option_label'      => implode( ' · ', $option_bits ),
            'submodel'          => sanitize_text_field( $record['basemodel'] ?? '' ),
            'generation'        => '',
            'chassis'           => '',
            'engine'            => implode( ' ', $engine_bits ),
            'engine_code'       => sanitize_text_field( $record['engId'] ?? '' ),
            'cylinders'         => sanitize_text_field( $record['cylinders'] ?? '' ),
            'displacement'      => sanitize_text_field( $record['displ'] ?? '' ),
            'transmission'      => sanitize_text_field( $record['trany'] ?? '' ),
            'drivetrain'        => sanitize_text_field( $record['drive'] ?? '' ),
            'body_style'        => sanitize_text_field( $record['VClass'] ?? '' ),
            'vehicle_type'      => sanitize_text_field( $record['VClass'] ?? '' ),
            'fuel_type'         => sanitize_text_field( $record['fuelType1'] ?? '' ),
            'raw'               => $record,
        );

        if ( ! $vehicle['year'] || '' === $vehicle['make'] || '' === $vehicle['model'] ) {
            return new WP_Error( 'echo_incomplete_vehicle', 'The government data source returned an incomplete vehicle record.' );
        }

        set_transient( $cache_key, $vehicle, 90 * DAY_IN_SECONDS );
        return $vehicle;
    }

    public function decode_vin( string $vin ) {
        $cache_key = 'echo_nhtsa_vin_' . md5( $vin );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        $url      = self::NHTSA_BASE . 'DecodeVinValuesExtended/' . rawurlencode( $vin ) . '?format=json';
        $response = $this->remote_get( $url );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $decoded = json_decode( $response, true );
        $record  = $decoded['Results'][0] ?? array();
        if ( empty( $record ) || empty( $record['ModelYear'] ) || empty( $record['Make'] ) || empty( $record['Model'] ) ) {
            $message = ! empty( $record['ErrorText'] ) ? sanitize_text_field( $record['ErrorText'] ) : 'NHTSA could not identify this VIN.';
            return new WP_Error( 'echo_vin_not_found', $message );
        }

        $engine_bits = array_filter(
            array(
                $record['DisplacementL'] ?? '',
                $record['EngineCylinders'] ?? '',
                $record['EngineModel'] ?? '',
                $record['EngineConfiguration'] ?? '',
            )
        );

        unset( $record['VIN'], $record['SuggestedVIN'] );

        $vehicle = array(
            'source'            => 'nhtsa_vin',
            'source_vehicle_id' => hash( 'sha256', $vin ),
            'vin_last8'         => substr( $vin, -8 ),
            'year'              => absint( $record['ModelYear'] ),
            'make'              => sanitize_text_field( $record['Make'] ),
            'model'             => sanitize_text_field( $record['Model'] ),
            'option_label'      => sanitize_text_field( trim( ( $record['Trim'] ?? '' ) . ' ' . ( $record['Series'] ?? '' ) ) ),
            'submodel'          => sanitize_text_field( $record['Trim'] ?? '' ),
            'generation'        => '',
            'chassis'           => sanitize_text_field( $record['BodyClass'] ?? '' ),
            'engine'            => implode( ' ', $engine_bits ),
            'engine_code'       => sanitize_text_field( $record['EngineModel'] ?? '' ),
            'cylinders'         => sanitize_text_field( $record['EngineCylinders'] ?? '' ),
            'displacement'      => sanitize_text_field( $record['DisplacementL'] ?? '' ),
            'transmission'      => sanitize_text_field( trim( ( $record['TransmissionStyle'] ?? '' ) . ' ' . ( $record['TransmissionSpeeds'] ?? '' ) ) ),
            'drivetrain'        => sanitize_text_field( $record['DriveType'] ?? '' ),
            'body_style'        => sanitize_text_field( $record['BodyClass'] ?? '' ),
            'vehicle_type'      => sanitize_text_field( $record['VehicleType'] ?? '' ),
            'fuel_type'         => sanitize_text_field( $record['FuelTypePrimary'] ?? '' ),
            'raw'               => $record,
        );

        set_transient( $cache_key, $vehicle, 180 * DAY_IN_SECONDS );
        return $vehicle;
    }

    public function clear_cache(): int {
        global $wpdb;
        $like = $wpdb->esc_like( '_transient_echo_' ) . '%';
        $timeout_like = $wpdb->esc_like( '_transient_timeout_echo_' ) . '%';
        $count = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $like,
                $timeout_like
            )
        );
        return (int) $count;
    }

    private function remote_get( string $url ) {
        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'     => 15,
                'redirection' => 3,
                'headers'     => array(
                    'Accept'     => 'application/json, application/xml;q=0.9, text/xml;q=0.8',
                    'User-Agent' => 'EchoMotorworksCore/' . ECHO_MOTORWORKS_CORE_VERSION . '; ' . home_url( '/' ),
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            Echo_Motorworks_DB::log( 'error', 'vehicle_api', $response->get_error_message(), array( 'url' => $url ) );
            return new WP_Error( 'echo_vehicle_api_unavailable', 'The vehicle data service is temporarily unavailable. Please try again.' );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        if ( $code < 200 || $code >= 300 || '' === trim( $body ) ) {
            Echo_Motorworks_DB::log( 'error', 'vehicle_api', 'Unexpected API response.', array( 'url' => $url, 'code' => $code ) );
            return new WP_Error( 'echo_vehicle_api_response', 'The vehicle data service returned an unexpected response.' );
        }
        return $body;
    }

    private function extract_menu_items( string $body ) {
        $json = json_decode( $body, true );
        if ( JSON_ERROR_NONE === json_last_error() && is_array( $json ) ) {
            $raw = $json['menuItem'] ?? $json['menuItems']['menuItem'] ?? array();
            if ( isset( $raw['text'] ) ) {
                $raw = array( $raw );
            }
            return array_values(
                array_filter(
                    array_map(
                        static function ( $item ): array {
                            return array(
                                'text'  => sanitize_text_field( (string) ( $item['text'] ?? '' ) ),
                                'value' => sanitize_text_field( (string) ( $item['value'] ?? '' ) ),
                            );
                        },
                        is_array( $raw ) ? $raw : array()
                    ),
                    static fn( array $item ): bool => '' !== $item['text'] && '' !== $item['value']
                )
            );
        }

        if ( function_exists( 'simplexml_load_string' ) ) {
            libxml_use_internal_errors( true );
            $xml = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
            if ( false !== $xml ) {
                $items = array();
                foreach ( $xml->xpath( '//menuItem' ) ?: array() as $item ) {
                    $text  = sanitize_text_field( (string) $item->text );
                    $value = sanitize_text_field( (string) $item->value );
                    if ( '' !== $text && '' !== $value ) {
                        $items[] = array( 'text' => $text, 'value' => $value );
                    }
                }
                if ( $items ) {
                    return $items;
                }
            }
        }

        $items = array();
        if ( preg_match_all( '/<menuItem\b[^>]*>(.*?)<\/menuItem>/si', $body, $matches ) ) {
            foreach ( $matches[1] as $block ) {
                preg_match( '/<text\b[^>]*>(.*?)<\/text>/si', $block, $text_match );
                preg_match( '/<value\b[^>]*>(.*?)<\/value>/si', $block, $value_match );
                $text  = sanitize_text_field( html_entity_decode( wp_strip_all_tags( $text_match[1] ?? '' ), ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
                $value = sanitize_text_field( html_entity_decode( wp_strip_all_tags( $value_match[1] ?? '' ), ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
                if ( '' !== $text && '' !== $value ) {
                    $items[] = array( 'text' => $text, 'value' => $value );
                }
            }
        }

        return $items ?: new WP_Error( 'echo_vehicle_parse', 'The vehicle data could not be read.' );
    }

    private function extract_record( string $body ) {
        $json = json_decode( $body, true );
        if ( JSON_ERROR_NONE === json_last_error() && is_array( $json ) ) {
            if ( isset( $json['vehicle'] ) && is_array( $json['vehicle'] ) ) {
                return $json['vehicle'];
            }
            return $json;
        }

        if ( function_exists( 'simplexml_load_string' ) ) {
            libxml_use_internal_errors( true );
            $xml = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
            if ( false !== $xml ) {
                $record = array();
                foreach ( $xml->children() as $key => $value ) {
                    $record[ (string) $key ] = trim( (string) $value );
                }
                if ( $record ) {
                    return $record;
                }
            }
        }

        $record = array();
        if ( preg_match( '/<vehicle\b[^>]*>(.*?)<\/vehicle>/si', $body, $vehicle_match ) ) {
            if ( preg_match_all( '/<([A-Za-z0-9_:-]+)\b[^>]*>(.*?)<\/\1>/si', $vehicle_match[1], $matches, PREG_SET_ORDER ) ) {
                foreach ( $matches as $match ) {
                    $key = preg_replace( '/^.*:/', '', $match[1] );
                    $record[ $key ] = trim( html_entity_decode( wp_strip_all_tags( $match[2] ), ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
                }
            }
        }

        return $record ?: new WP_Error( 'echo_vehicle_parse', 'The vehicle record could not be read.' );
    }
}
