<?php

defined( 'ABSPATH' ) || exit;

/**
 * Read-only operational diagnostics for Echo Motorworks.
 *
 * This class intentionally performs SELECT/count operations only. It does not
 * update products, fitment rows, vehicles, options, users, or supplier data.
 */
final class Echo_Motorworks_Diagnostics {
    public function summary(): array {
        global $wpdb;

        $fitment_table = Echo_Motorworks_DB::fitment_table();
        $vehicle_table = Echo_Motorworks_DB::vehicles_table();

        $products = post_type_exists( 'product' ) ? (int) wp_count_posts( 'product' )->publish : 0;
        $universal = $this->count_products_by_fitment_types(
            array( 'universal', 'universal-fit', 'universal_fit', 'restricted-universal', 'restricted_universal' )
        );
        $review = $this->count_products_by_fitment_types( array( 'needs_review', 'unknown', '' ) );
        $missing_images = $this->count_missing_featured_images();
        $missing_brand = $this->count_missing_brand();
        $missing_category = $this->count_missing_category();
        $missing_description = $this->count_missing_description();
        $duplicate_skus = $this->duplicate_sku_count();

        $fitment_rows = $this->table_exists( $fitment_table )
            ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$fitment_table}" )
            : 0;
        $fitment_products = $this->table_exists( $fitment_table )
            ? (int) $wpdb->get_var( "SELECT COUNT(DISTINCT product_id) FROM {$fitment_table} WHERE fitment_status IN ('confirmed','conditional')" )
            : 0;
        $vehicles = $this->table_exists( $vehicle_table )
            ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$vehicle_table}" )
            : 0;

        return array(
            'products'            => $products,
            'universal'           => $universal,
            'needs_review'        => $review,
            'missing_images'      => $missing_images,
            'missing_brand'       => $missing_brand,
            'missing_category'    => $missing_category,
            'missing_description' => $missing_description,
            'duplicate_skus'      => $duplicate_skus,
            'fitment_rows'        => $fitment_rows,
            'fitment_products'    => $fitment_products,
            'vehicles'            => $vehicles,
        );
    }

    public function supplier_health(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT COALESCE(NULLIF(pm.meta_value,''), 'Unassigned') AS supplier,
                    COUNT(DISTINCT p.ID) AS products,
                    SUM(CASE WHEN thumb.meta_value IS NULL OR thumb.meta_value = '' OR thumb.meta_value = '0' THEN 1 ELSE 0 END) AS missing_images,
                    SUM(CASE WHEN p.post_content IS NULL OR TRIM(p.post_content) = '' THEN 1 ELSE 0 END) AS missing_descriptions,
                    SUM(CASE WHEN ft.meta_value IS NULL OR TRIM(ft.meta_value) = '' THEN 1 ELSE 0 END) AS missing_fitment
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_echo_supplier'
             LEFT JOIN {$wpdb->postmeta} thumb ON thumb.post_id = p.ID AND thumb.meta_key = '_thumbnail_id'
             LEFT JOIN {$wpdb->postmeta} ft ON ft.post_id = p.ID AND ft.meta_key = '_echo_fitment_type'
             WHERE p.post_type = 'product' AND p.post_status = 'publish'
             GROUP BY COALESCE(NULLIF(pm.meta_value,''), 'Unassigned')
             ORDER BY products DESC, supplier ASC",
            ARRAY_A
        );

        foreach ( $rows as &$row ) {
            $row['products'] = (int) $row['products'];
            $row['missing_images'] = (int) $row['missing_images'];
            $row['missing_descriptions'] = (int) $row['missing_descriptions'];
            $row['missing_fitment'] = (int) $row['missing_fitment'];
            $complete = max( 0, ( $row['products'] * 3 ) - $row['missing_images'] - $row['missing_descriptions'] - $row['missing_fitment'] );
            $row['health_pct'] = $row['products'] > 0 ? round( 100 * $complete / ( $row['products'] * 3 ) ) : 0;
        }
        unset( $row );

        return $rows;
    }

    public function vehicle_search( array $args ): array {
        global $wpdb;
        $table = Echo_Motorworks_DB::vehicles_table();
        if ( ! $this->table_exists( $table ) ) {
            return array();
        }

        $where = array( '1=1' );
        $params = array();
        if ( ! empty( $args['year'] ) ) {
            $where[] = 'year = %d';
            $params[] = absint( $args['year'] );
        }
        if ( ! empty( $args['make'] ) ) {
            $where[] = 'normalized_make = %s';
            $params[] = Echo_Motorworks_DB::normalize( $args['make'] );
        }
        if ( ! empty( $args['model'] ) ) {
            $where[] = '(normalized_model = %s OR normalized_model LIKE %s OR %s LIKE CONCAT(normalized_model, %s))';
            $model = Echo_Motorworks_DB::normalize( $args['model'] );
            $params[] = $model;
            $params[] = '%' . $wpdb->esc_like( $model ) . '%';
            $params[] = $model;
            $params[] = '%';
        }
        if ( ! empty( $args['engine'] ) ) {
            $where[] = '(normalized_engine = %s OR normalized_engine LIKE %s)';
            $engine = Echo_Motorworks_DB::normalize( $args['engine'] );
            $params[] = $engine;
            $params[] = '%' . $wpdb->esc_like( $engine ) . '%';
        }

        $sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY year DESC, make, model, engine LIMIT 100';
        if ( $params ) {
            $sql = $wpdb->prepare( $sql, $params );
        }
        return $wpdb->get_results( $sql, ARRAY_A ) ?: array();
    }

    public function inspect_vehicle( array $vehicle ): array {
        global $wpdb;
        $table = Echo_Motorworks_DB::fitment_table();
        if ( ! $this->table_exists( $table ) ) {
            return array( 'exact' => array(), 'could' => array(), 'rejected' => array(), 'universal_count' => 0 );
        }

        $year = absint( $vehicle['year'] ?? 0 );
        $make = Echo_Motorworks_DB::normalize( (string) ( $vehicle['make'] ?? '' ) );
        $model = Echo_Motorworks_DB::normalize( (string) ( $vehicle['model'] ?? '' ) );
        if ( ! $year || '' === $make || '' === $model ) {
            return array( 'exact' => array(), 'could' => array(), 'rejected' => array(), 'universal_count' => 0 );
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT f.*, p.post_title, sku.meta_value AS product_sku, p.post_status
                 FROM {$table} f
                 LEFT JOIN {$wpdb->posts} p ON p.ID = f.product_id
                 LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = f.product_id AND sku.meta_key = '_sku'
                 WHERE (f.year_start IS NULL OR f.year_start = 0 OR f.year_start <= %d)
                   AND (f.year_end IS NULL OR f.year_end = 0 OR f.year_end >= %d)
                   AND f.normalized_make = %s
                   AND (f.normalized_model = %s
                        OR LOCATE(CONCAT(f.normalized_model, ' '), CONCAT(%s, ' ')) = 1
                        OR LOCATE(CONCAT(%s, ' '), CONCAT(f.normalized_model, ' ')) = 1)
                 ORDER BY f.fitment_status, p.post_title
                 LIMIT 500",
                $year,
                $year,
                $make,
                $model,
                $model,
                $model
            ),
            ARRAY_A
        ) ?: array();

        $result = array(
            'exact' => array(),
            'could' => array(),
            'rejected' => array(),
            'universal_count' => $this->count_products_by_fitment_types(
                array( 'universal', 'universal-fit', 'universal_fit', 'restricted-universal', 'restricted_universal' )
            ),
        );

        foreach ( $rows as $row ) {
            $evaluation = $this->evaluate_fitment_row( $row, $vehicle );
            $row['diagnostic_reason'] = $evaluation['reason'];
            $row['diagnostic_checks'] = $evaluation['checks'];
            $bucket = $evaluation['bucket'];
            $result[ $bucket ][] = $row;
        }

        return $result;
    }

    public function inspect_product( string $query ): array {
        global $wpdb;
        $query = trim( $query );
        if ( '' === $query ) {
            return array();
        }

        if ( ctype_digit( $query ) ) {
            $product_id = absint( $query );
        } else {
            $product_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s LIMIT 1",
                    $query
                )
            );
        }

        if ( ! $product_id ) {
            return array();
        }

        $post = get_post( $product_id );
        if ( ! $post || 'product' !== $post->post_type ) {
            return array();
        }

        $table = Echo_Motorworks_DB::fitment_table();
        $fitment = $this->table_exists( $table )
            ? $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM {$table} WHERE product_id = %d ORDER BY year_start, make, model", $product_id ),
                ARRAY_A
            )
            : array();

        return array(
            'id' => $product_id,
            'sku' => (string) get_post_meta( $product_id, '_sku', true ),
            'name' => get_the_title( $product_id ),
            'status' => $post->post_status,
            'supplier' => (string) get_post_meta( $product_id, '_echo_supplier', true ),
            'fitment_type' => (string) get_post_meta( $product_id, '_echo_fitment_type', true ),
            'has_image' => has_post_thumbnail( $product_id ),
            'has_description' => '' !== trim( (string) $post->post_content ),
            'fitment_rows' => $fitment ?: array(),
        );
    }

    private function evaluate_fitment_row( array $row, array $vehicle ): array {
        $checks = array();
        $failures = array();
        $unknowns = array();

        $compare = array(
            'engine' => array( 'row' => 'normalized_engine', 'vehicle' => 'engine' ),
            'submodel' => array( 'row' => 'normalized_submodel', 'vehicle' => 'submodel' ),
            'transmission' => array( 'row' => 'normalized_transmission', 'vehicle' => 'transmission' ),
            'drivetrain' => array( 'row' => 'normalized_drivetrain', 'vehicle' => 'drivetrain' ),
        );

        foreach ( $compare as $label => $keys ) {
            $required = trim( (string) ( $row[ $keys['row'] ] ?? '' ) );
            $actual = Echo_Motorworks_DB::normalize( (string) ( $vehicle[ $keys['vehicle'] ] ?? '' ) );
            if ( '' === $required ) {
                $checks[ $label ] = 'not restricted';
                continue;
            }
            if ( '' === $actual ) {
                $checks[ $label ] = 'vehicle detail missing';
                $unknowns[] = $label;
                continue;
            }
            if ( $this->normalized_values_match( $required, $actual, 'engine' === $label ) ) {
                $checks[ $label ] = 'matched';
            } else {
                $checks[ $label ] = 'conflict';
                $failures[] = $label;
            }
        }

        if ( $failures || 'excluded' === ( $row['fitment_status'] ?? '' ) ) {
            return array(
                'bucket' => 'rejected',
                'reason' => $failures ? 'Rejected because ' . implode( ', ', $failures ) . ' conflicts with the saved vehicle.' : 'This row explicitly excludes the selected vehicle.',
                'checks' => $checks,
            );
        }

        if ( 'conditional' === ( $row['fitment_status'] ?? '' ) || $unknowns ) {
            return array(
                'bucket' => 'could',
                'reason' => $unknowns
                    ? 'Vehicle and model match, but ' . implode( ', ', $unknowns ) . ' must be confirmed.'
                    : 'Supplier row is marked conditional: ' . trim( (string) ( $row['fitment_notes'] ?? '' ) ),
                'checks' => $checks,
            );
        }

        return array(
            'bucket' => 'exact',
            'reason' => 'Year, make, model, and every restricted vehicle detail match this confirmed fitment row.',
            'checks' => $checks,
        );
    }

    private function normalized_values_match( string $required, string $actual, bool $engine = false ): bool {
        if ( $required === $actual || false !== strpos( $actual, $required ) || false !== strpos( $required, $actual ) ) {
            return true;
        }

        if ( $engine ) {
            $required_size = $this->engine_size( $required );
            $actual_size = $this->engine_size( $actual );
            if ( null !== $required_size && null !== $actual_size && abs( $required_size - $actual_size ) <= 0.06 ) {
                return true;
            }
        }
        return false;
    }

    private function engine_size( string $value ): ?float {
        if ( preg_match( '/(?:^|\s)(\d+(?:\.\d+)?)\s*(?:l|liter|litre)(?:\s|$)/i', $value, $match ) ) {
            return (float) $match[1];
        }
        if ( preg_match( '/(?:^|\s)(\d{3,4})\s*(?:cc|cm3)(?:\s|$)/i', $value, $match ) ) {
            return (float) $match[1] / 1000;
        }
        return null;
    }

    private function count_products_by_fitment_types( array $types ): int {
        global $wpdb;
        $types = array_values( array_filter( array_map( 'strval', $types ) ) );
        if ( ! $types ) {
            return 0;
        }
        $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $sql = "SELECT COUNT(DISTINCT p.ID)
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_echo_fitment_type'
                WHERE p.post_type = 'product' AND p.post_status = 'publish'
                  AND pm.meta_value IN ({$placeholders})";
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $types ) );
    }

    private function count_missing_featured_images(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id'
             WHERE p.post_type = 'product' AND p.post_status = 'publish'
               AND (pm.meta_value IS NULL OR pm.meta_value = '' OR pm.meta_value = '0')"
        );
    }

    private function count_missing_description(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'product' AND post_status = 'publish'
               AND (post_content IS NULL OR TRIM(post_content) = '')"
        );
    }

    private function count_missing_brand(): int {
        global $wpdb;
        if ( ! taxonomy_exists( 'product_brand' ) ) {
            return 0;
        }
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type = 'product' AND p.post_status = 'publish'
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->term_relationships} tr
                   JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                   WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_brand'
               )"
        );
    }

    private function count_missing_category(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type = 'product' AND p.post_status = 'publish'
               AND NOT EXISTS (
                   SELECT 1 FROM {$wpdb->term_relationships} tr
                   JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                   JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                   WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_cat' AND t.slug <> 'uncategorized'
               )"
        );
    }

    private function duplicate_sku_count(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM (
                SELECT meta_value FROM {$wpdb->postmeta}
                WHERE meta_key = '_sku' AND meta_value <> ''
                GROUP BY meta_value HAVING COUNT(*) > 1
             ) duplicates"
        );
    }

    private function table_exists( string $table ): bool {
        global $wpdb;
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }
}
