<?php

defined( 'ABSPATH' ) || exit;

final class Echo_Motorworks_Fitment {
    private Echo_Motorworks_Garage $garage;
    private array $match_cache = array();

    public function __construct( Echo_Motorworks_Garage $garage ) {
        $this->garage = $garage;
        add_action( 'pre_get_posts', array( $this, 'filter_shop_query' ), 30 );
        add_filter( 'woocommerce_product_query_meta_query', array( $this, 'filter_universal_products' ), 20, 2 );
    }

    public function filter_shop_query( WP_Query $query ): void {
        if ( is_admin() || ! $query->is_main_query() || ! $this->is_product_archive_query( $query ) ) {
            return;
        }

        $vehicle_id = isset( $_GET['echo_vehicle_id'] ) ? absint( $_GET['echo_vehicle_id'] ) : 0;
        if ( ! $vehicle_id ) {
            return;
        }

        $vehicle = $this->garage->get_vehicle( $vehicle_id );
        if ( ! $vehicle ) {
            $query->set( 'post__in', array( 0 ) );
            return;
        }

        $scope = isset( $_GET['em_fitment_scope'] ) ? sanitize_key( wp_unslash( $_GET['em_fitment_scope'] ) ) : 'all';
        if ( ! in_array( $scope, array( 'all', 'exact', 'could', 'universal' ), true ) ) {
            $scope = 'all';
        }

        if ( 'exact' === $scope ) {
            $product_ids = $this->get_vehicle_product_ids_by_status( $vehicle, array( 'confirmed' ) );
        } elseif ( 'could' === $scope ) {
            $product_ids = $this->get_vehicle_product_ids_by_status( $vehicle, array( 'conditional' ) );
        } elseif ( 'universal' === $scope ) {
            $product_ids = $this->get_universal_product_ids();
        } else {
            $product_ids = $this->get_matching_product_ids( $vehicle );
        }

        $query->set( 'post__in', $product_ids ?: array( 0 ) );
        $query->set( 'echo_vehicle_filtered', 1 );
        $query->set( 'echo_fitment_scope', $scope );
    }

    public function filter_universal_products( array $meta_query, $query ): array {
        if ( isset( $_GET['em_fitment'] ) && 'universal' === sanitize_key( wp_unslash( $_GET['em_fitment'] ) ) ) {
            $meta_query[] = array(
                'key'     => '_echo_fitment_type',
                'value'   => 'universal',
                'compare' => '=',
            );
        }
        return $meta_query;
    }

    public function get_matching_product_ids( array $vehicle ): array {
        $cache_key = 'all|' . md5( wp_json_encode( $vehicle ) );
        if ( isset( $this->match_cache[ $cache_key ] ) ) {
            return $this->match_cache[ $cache_key ];
        }
        $vehicle_ids   = $this->get_vehicle_product_ids_by_status( $vehicle, array( 'confirmed', 'conditional' ) );
        $universal_ids = $this->get_universal_product_ids();
        $this->match_cache[ $cache_key ] = array_values( array_unique( array_merge( $vehicle_ids, $universal_ids ) ) );
        return $this->match_cache[ $cache_key ];
    }

    public function get_vehicle_product_ids_by_status( array $vehicle, array $statuses ): array {
        global $wpdb;
        $table = Echo_Motorworks_DB::fitment_table();
        $allowed = array_values( array_intersect( array_map( 'sanitize_key', $statuses ), array( 'confirmed', 'conditional' ) ) );
        if ( ! $allowed ) return array();

        $year         = absint( $vehicle['year'] ?? 0 );
        $make         = Echo_Motorworks_DB::normalize( (string) ( $vehicle['make'] ?? '' ) );
        $model        = Echo_Motorworks_DB::normalize( (string) ( $vehicle['model'] ?? '' ) );
        $engine       = Echo_Motorworks_DB::normalize( (string) ( $vehicle['engine'] ?? '' ) );
        $submodel     = Echo_Motorworks_DB::normalize( (string) ( $vehicle['submodel'] ?? '' ) );
        $transmission = Echo_Motorworks_DB::normalize( (string) ( $vehicle['transmission'] ?? '' ) );
        $drivetrain   = Echo_Motorworks_DB::normalize( (string) ( $vehicle['drivetrain'] ?? '' ) );
        $vehicle_id   = absint( $vehicle['id'] ?? 0 );
        if ( ! $year || '' === $make || '' === $model ) return array();

        $cache_key = implode( '|', array( implode(',', $allowed), $vehicle_id, $year, $make, $model, $engine, $submodel, $transmission, $drivetrain ) );
        if ( isset( $this->match_cache[ $cache_key ] ) ) return $this->match_cache[ $cache_key ];

        $placeholders = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );
        $sql = "SELECT DISTINCT product_id FROM {$table}
            WHERE fitment_status IN ({$placeholders}) AND (
                vehicle_id = %d OR (
                    (vehicle_id IS NULL OR vehicle_id = 0)
                    AND (year_start IS NULL OR year_start = 0 OR year_start <= %d)
                    AND (year_end IS NULL OR year_end = 0 OR year_end >= %d)
                    AND normalized_make = %s
                    AND (normalized_model = %s OR LOCATE(CONCAT(normalized_model, ' '), CONCAT(%s, ' ')) = 1 OR LOCATE(CONCAT(%s, ' '), CONCAT(normalized_model, ' ')) = 1)
                    AND (normalized_engine = '' OR (%s <> '' AND LOCATE(normalized_engine, %s) > 0))
                    AND (normalized_submodel = '' OR (%s <> '' AND normalized_submodel = %s))
                    AND (normalized_transmission = '' OR (%s <> '' AND normalized_transmission = %s))
                    AND (normalized_drivetrain = '' OR (%s <> '' AND normalized_drivetrain = %s))
                )
            )";
        $args = array_merge( $allowed, array( $vehicle_id, $year, $year, $make, $model, $model, $model, $engine, $engine, $submodel, $submodel, $transmission, $transmission, $drivetrain, $drivetrain ) );
        $prepared = $wpdb->prepare( $sql, $args );
        $this->match_cache[ $cache_key ] = array_values( array_unique( array_map( 'absint', $wpdb->get_col( $prepared ) ) ) );
        return $this->match_cache[ $cache_key ];
    }

    public function get_universal_product_ids(): array {
        $ids = get_posts(
            array(
                'post_type'              => 'product',
                'post_status'            => 'publish',
                'posts_per_page'         => -1,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_query'             => array(
                    array(
                        'key'     => '_echo_fitment_type',
                        'value'   => array( 'universal', 'universal-fit', 'universal_fit', 'restricted-universal', 'restricted_universal' ),
                        'compare' => 'IN',
                    ),
                ),
            )
        );

        return array_values( array_unique( array_map( 'absint', $ids ) ) );
    }

    public function get_verified_product_ids( array $vehicle ): array {
        return $this->get_vehicle_product_ids_by_status( $vehicle, array( 'confirmed' ) );
    }

    public function get_product_status( int $product_id, ?array $vehicle = null ): array {
        $type = sanitize_key( (string) get_post_meta( $product_id, '_echo_fitment_type', true ) );
        if ( in_array( $type, array( 'universal', 'universal-fit', 'universal_fit' ), true ) ) {
            return array( 'status' => 'universal', 'label' => 'Universal Product' );
        }
        if ( in_array( $type, array( 'restricted-universal', 'restricted_universal' ), true ) ) {
            return array( 'status' => 'conditional', 'label' => 'Universal — Verify Size' );
        }

        $vehicle = $vehicle ?: $this->garage->get_active_vehicle();
        if ( ! $vehicle ) {
            if ( in_array( $type, array( 'vehicle-specific', 'vehicle_specific', 'engine-specific', 'engine_specific' ), true ) ) {
                return array( 'status' => 'vehicle_specific', 'label' => 'Vehicle Specific' );
            }
            return array( 'status' => 'unknown', 'label' => 'Fitment Not Confirmed' );
        }

        $match_status = $this->get_product_match_status( $product_id, $vehicle );
        if ( 'confirmed' === $match_status ) {
            return array( 'status' => 'fits', 'label' => 'Fits Your Vehicle' );
        }
        if ( 'conditional' === $match_status ) {
            return array( 'status' => 'conditional', 'label' => 'Verify Transmission Fitment' );
        }

        if ( $this->is_explicitly_excluded( $product_id, $vehicle ) ) {
            return array( 'status' => 'does_not_fit', 'label' => 'Does Not Fit' );
        }

        return array( 'status' => 'unknown', 'label' => 'Fitment Not Confirmed' );
    }

    private function get_product_match_status( int $product_id, array $vehicle ): string {
        global $wpdb;
        $table = Echo_Motorworks_DB::fitment_table();
        $vehicle_id = absint( $vehicle['id'] ?? 0 );
        if ( ! $vehicle_id ) {
            return '';
        }
        $status = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT fitment_status
                 FROM {$table}
                 WHERE product_id = %d
                   AND vehicle_id = %d
                   AND fitment_status IN ('confirmed','conditional')
                 ORDER BY CASE fitment_status WHEN 'confirmed' THEN 0 ELSE 1 END
                 LIMIT 1",
                $product_id,
                $vehicle_id
            )
        );
        return in_array( $status, array( 'confirmed', 'conditional' ), true ) ? $status : '';
    }

    private function is_explicitly_excluded( int $product_id, array $vehicle ): bool {
        global $wpdb;
        $table = Echo_Motorworks_DB::fitment_table();
        $vehicle_id = absint( $vehicle['id'] ?? 0 );
        if ( ! $vehicle_id ) {
            return false;
        }
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE product_id = %d AND vehicle_id = %d AND fitment_status = 'excluded' LIMIT 1",
                $product_id,
                $vehicle_id
            )
        );
    }

    private function is_product_archive_query( WP_Query $query ): bool {
        return $query->is_post_type_archive( 'product' ) || $query->is_tax( array( 'product_cat', 'product_tag', 'product_brand' ) ) || ( isset( $query->query_vars['post_type'] ) && 'product' === $query->query_vars['post_type'] );
    }
}
