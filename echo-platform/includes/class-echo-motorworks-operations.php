<?php

defined( 'ABSPATH' ) || exit;

/**
 * Operations Center foundation for catalog health, supplier connections,
 * schedules, manual syncs and audit history.
 */
final class Echo_Motorworks_Operations {
    private const CONNECTIONS_OPTION = 'echo_platform_supplier_connections_v1';
    private const JOBS_OPTION = 'echo_platform_jobs_v1';
    private const HEALTH_OPTION = 'echo_platform_health_snapshot_v1';
    private const DISCOVERY_OPTION = 'echo_platform_discovery_results_v1';
    private const CRON_HOOK = 'echo_platform_auto_sync_event';

    public function __construct() {
        add_action( 'admin_post_echo_platform_run_health_scan', array( $this, 'run_health_scan' ) );
        add_action( 'admin_post_echo_platform_save_connection', array( $this, 'save_connection' ) );
        add_action( 'admin_post_echo_platform_test_connection', array( $this, 'test_connection' ) );
        add_action( 'admin_post_echo_platform_sync_supplier', array( $this, 'sync_supplier' ) );
        add_action( 'admin_post_echo_platform_sync_all', array( $this, 'sync_all' ) );
        add_action( 'admin_post_echo_platform_discover_catalog', array( $this, 'discover_catalog' ) );
        add_action( 'admin_post_echo_platform_use_discovery_source', array( $this, 'use_discovery_source' ) );
        add_action( self::CRON_HOOK, array( $this, 'run_scheduled_syncs' ) );
        add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
        $this->ensure_schedule();
    }

    public function cron_schedules( array $schedules ): array {
        $schedules['echo_every_six_hours'] = array(
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => 'Every six hours',
        );
        return $schedules;
    }

    private function ensure_schedule(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
        }
    }

    public static function connections(): array {
        $value = get_option( self::CONNECTIONS_OPTION, array() );
        return is_array( $value ) ? $value : array();
    }

    public static function connection( string $supplier ): array {
        $all = self::connections();
        return wp_parse_args(
            $all[ $supplier ] ?? array(),
            array(
                'mode'             => 'manual',
                'connection_type'  => 'csv',
                'base_url'         => '',
                'auth_type'        => 'none',
                'api_key'          => '',
                'username'         => '',
                'password'         => '',
                'schedule'         => 'daily',
                'auto_enabled'     => false,
                'update_prices'    => true,
                'update_inventory' => true,
                'update_images'    => false,
                'update_content'   => false,
                'new_products'     => true,
                'auto_publish'     => false,
                'disable_missing'  => false,
                'last_test'        => '',
                'last_test_status' => '',
                'last_sync'        => '',
            )
        );
    }

    public static function jobs(): array {
        $jobs = get_option( self::JOBS_OPTION, array() );
        return is_array( $jobs ) ? array_values( $jobs ) : array();
    }

    public static function health_snapshot(): array {
        $snapshot = get_option( self::HEALTH_OPTION, array() );
        return is_array( $snapshot ) ? $snapshot : array();
    }


    public static function discovery_results(): array {
        $results = get_option( self::DISCOVERY_OPTION, array() );
        return is_array( $results ) ? $results : array();
    }

    public static function discovery_result( string $supplier ): array {
        $all = self::discovery_results();
        return isset( $all[ $supplier ] ) && is_array( $all[ $supplier ] ) ? $all[ $supplier ] : array();
    }

    public function run_health_scan(): void {
        $this->guard( 'echo_platform_run_health_scan' );
        $snapshot = self::calculate_health();
        update_option( self::HEALTH_OPTION, $snapshot, false );
        $this->add_job( 'Catalog health scan', 'catalog', 'complete', $snapshot['products'] . ' products scanned', $snapshot );
        $this->redirect( 'health', 'Catalog health scan completed.' );
    }

    public static function calculate_health(): array {
        global $wpdb;
        $published = (int) wp_count_posts( 'product' )->publish;
        $drafts = (int) wp_count_posts( 'product' )->draft;
        $products = $published + $drafts + (int) wp_count_posts( 'product' )->private;
        if ( $products < 1 ) {
            return array(
                'scanned_at' => current_time( 'mysql' ), 'products' => 0, 'score' => 100,
                'missing_images' => 0, 'missing_prices' => 0, 'missing_sku' => 0,
                'missing_categories' => 0, 'missing_brand' => 0, 'missing_fitment' => 0,
                'drafts' => 0, 'healthy' => 0, 'review' => 0, 'critical' => 0,
            );
        }
        $ids = get_posts( array(
            'post_type' => 'product', 'post_status' => array( 'publish', 'draft', 'private' ),
            'fields' => 'ids', 'numberposts' => -1, 'suppress_filters' => true,
        ) );
        $missing = array_fill_keys( array( 'images','prices','sku','categories','brand','fitment' ), 0 );
        $healthy = 0; $review = 0; $critical = 0;
        foreach ( $ids as $id ) {
            $issues = 0; $critical_issues = 0;
            if ( ! has_post_thumbnail( $id ) ) { $missing['images']++; $issues++; }
            $product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
            if ( ! $product || '' === (string) $product->get_price() ) { $missing['prices']++; $issues++; $critical_issues++; }
            if ( ! $product || '' === trim( (string) $product->get_sku() ) ) { $missing['sku']++; $issues++; }
            $cats = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'ids' ) );
            if ( is_wp_error( $cats ) || empty( $cats ) ) { $missing['categories']++; $issues++; }
            $brand = get_post_meta( $id, '_echo_brand', true );
            $supplier = get_post_meta( $id, '_echo_supplier', true );
            if ( ! $brand && ! $supplier ) { $missing['brand']++; $issues++; }
            $fitment_type = get_post_meta( $id, '_echo_fitment_type', true );
            if ( ! in_array( $fitment_type, array( 'universal', 'vehicle-specific', 'engine-specific' ), true ) ) { $missing['fitment']++; $issues++; }
            if ( 0 === $issues ) { $healthy++; }
            elseif ( $critical_issues > 0 ) { $critical++; }
            else { $review++; }
        }
        $weighted = $missing['prices'] * 3 + $missing['images'] * 2 + $missing['sku'] + $missing['categories'] + $missing['brand'] + $missing['fitment'];
        $maximum = max( 1, $products * 9 );
        $score = max( 0, min( 100, (int) round( 100 - ( $weighted / $maximum * 100 ) ) ) );
        return array(
            'scanned_at' => current_time( 'mysql' ), 'products' => $products, 'score' => $score,
            'missing_images' => $missing['images'], 'missing_prices' => $missing['prices'],
            'missing_sku' => $missing['sku'], 'missing_categories' => $missing['categories'],
            'missing_brand' => $missing['brand'], 'missing_fitment' => $missing['fitment'],
            'drafts' => $drafts, 'healthy' => $healthy, 'review' => $review, 'critical' => $critical,
        );
    }

    public function save_connection(): void {
        $this->guard( 'echo_platform_save_connection' );
        $supplier = sanitize_key( $_POST['supplier'] ?? '' );
        if ( ! $supplier ) { $this->redirect( 'api', 'Choose a supplier.', 'error' ); }
        $all = self::connections();
        $existing = self::connection( $supplier );
        $api_key = trim( (string) wp_unslash( $_POST['api_key'] ?? '' ) );
        $password = trim( (string) wp_unslash( $_POST['password'] ?? '' ) );
        $all[ $supplier ] = array(
            'mode' => in_array( $_POST['mode'] ?? '', array( 'manual', 'ondemand', 'automatic' ), true ) ? sanitize_key( $_POST['mode'] ) : 'manual',
            'connection_type' => in_array( $_POST['connection_type'] ?? '', array( 'csv','xml','json','rest','graphql','sftp','webhook' ), true ) ? sanitize_key( $_POST['connection_type'] ) : 'csv',
            'base_url' => esc_url_raw( wp_unslash( $_POST['base_url'] ?? '' ) ),
            'auth_type' => in_array( $_POST['auth_type'] ?? '', array( 'none','bearer','header','basic' ), true ) ? sanitize_key( $_POST['auth_type'] ) : 'none',
            'api_key' => '' !== $api_key ? $this->encrypt_secret( $api_key ) : ( $existing['api_key'] ?? '' ),
            'username' => sanitize_text_field( wp_unslash( $_POST['username'] ?? '' ) ),
            'password' => '' !== $password ? $this->encrypt_secret( $password ) : ( $existing['password'] ?? '' ),
            'schedule' => in_array( $_POST['schedule'] ?? '', array( 'hourly','six_hours','daily','weekly' ), true ) ? sanitize_key( $_POST['schedule'] ) : 'daily',
            'auto_enabled' => ! empty( $_POST['auto_enabled'] ),
            'update_prices' => ! empty( $_POST['update_prices'] ),
            'update_inventory' => ! empty( $_POST['update_inventory'] ),
            'update_images' => ! empty( $_POST['update_images'] ),
            'update_content' => ! empty( $_POST['update_content'] ),
            'new_products' => ! empty( $_POST['new_products'] ),
            'auto_publish' => ! empty( $_POST['auto_publish'] ),
            'disable_missing' => ! empty( $_POST['disable_missing'] ),
            'last_test' => $existing['last_test'] ?? '', 'last_test_status' => $existing['last_test_status'] ?? '',
            'last_sync' => $existing['last_sync'] ?? '',
        );
        update_option( self::CONNECTIONS_OPTION, $all, false );
        $this->redirect( 'api', 'Supplier connection settings saved.' );
    }

    public function test_connection(): void {
        $this->guard( 'echo_platform_test_connection' );
        $supplier = sanitize_key( $_POST['supplier'] ?? '' );
        $connection = self::connection( $supplier );
        $status = 'manual'; $message = 'Manual connection ready. No remote test is required.';
        if ( in_array( $connection['connection_type'], array( 'rest','graphql','json','xml' ), true ) && $connection['base_url'] ) {
            $args = array( 'timeout' => 15, 'redirection' => 3, 'headers' => $this->auth_headers( $connection ) );
            $response = wp_remote_get( $connection['base_url'], $args );
            if ( is_wp_error( $response ) ) { $status = 'failed'; $message = $response->get_error_message(); }
            else {
                $code = (int) wp_remote_retrieve_response_code( $response );
                $status = $code >= 200 && $code < 400 ? 'connected' : 'failed';
                $message = 'HTTP ' . $code;
            }
        }
        $all = self::connections();
        $all[ $supplier ] = array_merge( $connection, array( 'last_test' => current_time( 'mysql' ), 'last_test_status' => $status ) );
        update_option( self::CONNECTIONS_OPTION, $all, false );
        $this->add_job( 'Connection test', $supplier, 'failed' === $status ? 'failed' : 'complete', $message );
        $this->redirect( 'api', 'Connection test: ' . $message, 'failed' === $status ? 'error' : 'success' );
    }

    public function discover_catalog(): void {
        $this->guard( 'echo_platform_discover_catalog' );
        $supplier = sanitize_key( $_POST['supplier'] ?? '' );
        $website  = esc_url_raw( trim( (string) wp_unslash( $_POST['website'] ?? '' ) ) );
        if ( ! $supplier || ! $website ) {
            $this->redirect( 'discovery', 'Choose a supplier and enter its public website URL.', 'error' );
        }
        if ( ! $this->is_public_url( $website ) ) {
            $this->redirect( 'discovery', 'That URL is not a safe public HTTP or HTTPS address.', 'error' );
        }

        $result = $this->scan_catalog_sources( $website );
        $result['supplier']   = $supplier;
        $result['website']    = $website;
        $result['scanned_at'] = current_time( 'mysql' );

        $all = self::discovery_results();
        $all[ $supplier ] = $result;
        update_option( self::DISCOVERY_OPTION, $all, false );

        $count = count( $result['sources'] ?? array() );
        $this->add_job( 'Catalog discovery', $supplier, $count ? 'complete' : 'review', $count . ' possible catalog source(s) found', $result );
        $this->redirect( 'discovery', $count ? 'Discovery complete. Review the detected sources below.' : 'Discovery finished, but no usable public catalog source was confirmed.', $count ? 'success' : 'error' );
    }

    public function use_discovery_source(): void {
        $this->guard( 'echo_platform_use_discovery_source' );
        $supplier = sanitize_key( $_POST['supplier'] ?? '' );
        $source_index = absint( $_POST['source_index'] ?? -1 );
        $result = self::discovery_result( $supplier );
        $sources = $result['sources'] ?? array();
        if ( ! isset( $sources[ $source_index ] ) || ! is_array( $sources[ $source_index ] ) ) {
            $this->redirect( 'discovery', 'The selected source is no longer available. Run discovery again.', 'error' );
        }
        $source = $sources[ $source_index ];
        $all = self::connections();
        $existing = self::connection( $supplier );
        $all[ $supplier ] = array_merge( $existing, array(
            'connection_type' => $this->map_discovery_type( (string) ( $source['type'] ?? '' ) ),
            'base_url'         => esc_url_raw( (string) ( $source['url'] ?? '' ) ),
            'last_test'        => current_time( 'mysql' ),
            'last_test_status' => (string) ( $source['status'] ?? 'detected' ),
        ) );
        update_option( self::CONNECTIONS_OPTION, $all, false );
        $this->redirect( 'api', 'Detected catalog source copied into API Connections. Review authentication and field mapping before syncing.' );
    }

    private function scan_catalog_sources( string $website ): array {
        $base = untrailingslashit( $website );
        $homepage = $this->safe_fetch( $base, 8 );
        $body = is_wp_error( $homepage ) ? '' : (string) wp_remote_retrieve_body( $homepage );
        $headers = is_wp_error( $homepage ) ? array() : wp_remote_retrieve_headers( $homepage );
        $sources = array();
        $clues = array();

        $generator = '';
        if ( preg_match( '/<meta[^>]+name=["\']generator["\'][^>]+content=["\']([^"\']+)/i', $body, $m ) ) {
            $generator = sanitize_text_field( $m[1] );
            $clues[] = 'Generator: ' . $generator;
        }
        $server = is_object( $headers ) && method_exists( $headers, 'offsetGet' ) ? (string) $headers->offsetGet( 'server' ) : '';
        if ( $server ) $clues[] = 'Server: ' . sanitize_text_field( $server );

        $is_shopify = false !== stripos( $body, 'cdn.shopify.com' ) || false !== stripos( $body, 'Shopify.theme' ) || false !== stripos( $generator, 'shopify' );
        $is_woocommerce = false !== stripos( $body, 'woocommerce' ) || false !== stripos( $body, 'wc-blocks' ) || false !== stripos( $generator, 'wordpress' );

        if ( $is_shopify ) {
            $this->probe_source( $sources, 'Shopify products JSON', $base . '/products.json?limit=1', 'json', 95, 'Public Shopify catalog endpoint' );
            $this->probe_source( $sources, 'Shopify product sitemap', $base . '/sitemap_products_1.xml', 'xml', 78, 'Product sitemap; file number may vary' );
            $clues[] = 'Shopify storefront detected';
        }
        if ( $is_woocommerce ) {
            $this->probe_source( $sources, 'WooCommerce Store API', $base . '/wp-json/wc/store/v1/products?per_page=1', 'rest', 96, 'Public WooCommerce Store API' );
            $this->probe_source( $sources, 'WordPress REST products', $base . '/wp-json/wp/v2/product?per_page=1', 'rest', 70, 'WordPress REST product endpoint' );
            $clues[] = 'WordPress or WooCommerce clues detected';
        }

        $common = array(
            array( 'OpenAPI document', '/openapi.json', 'json', 90, 'OpenAPI specification' ),
            array( 'Swagger document', '/swagger.json', 'json', 88, 'Swagger specification' ),
            array( 'GraphQL endpoint', '/graphql', 'graphql', 82, 'GraphQL endpoint; authentication may be required' ),
            array( 'Product sitemap', '/product-sitemap.xml', 'xml', 76, 'Product URL discovery fallback' ),
            array( 'Sitemap index', '/sitemap_index.xml', 'xml', 66, 'Sitemap index fallback' ),
            array( 'Standard sitemap', '/sitemap.xml', 'xml', 60, 'General sitemap fallback' ),
            array( 'Products JSON feed', '/products.json', 'json', 74, 'Public JSON product feed' ),
            array( 'Catalog JSON feed', '/catalog.json', 'json', 72, 'Public JSON catalog feed' ),
            array( 'Products XML feed', '/products.xml', 'xml', 70, 'Public XML product feed' ),
            array( 'Product CSV feed', '/products.csv', 'csv', 68, 'Public CSV product feed' ),
        );
        foreach ( $common as $candidate ) {
            if ( count( $sources ) >= 8 ) break;
            $this->probe_source( $sources, $candidate[0], $base . $candidate[1], $candidate[2], $candidate[3], $candidate[4] );
        }

        if ( $body ) {
            $patterns = array(
                'OpenAPI or Swagger link' => '/https?:\\/\\/[^"\'<> ]+(?:openapi|swagger)[^"\'<> ]*/i',
                'JSON feed link' => '/https?:\\/\\/[^"\'<> ]+\\.json(?:\\?[^"\'<> ]*)?/i',
                'XML feed link' => '/https?:\\/\\/[^"\'<> ]+(?:feed|product|catalog)[^"\'<> ]+\\.xml(?:\\?[^"\'<> ]*)?/i',
                'CSV feed link' => '/https?:\\/\\/[^"\'<> ]+(?:feed|product|catalog)[^"\'<> ]+\\.csv(?:\\?[^"\'<> ]*)?/i',
            );
            foreach ( $patterns as $label => $pattern ) {
                if ( preg_match_all( $pattern, $body, $matches ) ) {
                    foreach ( array_slice( array_unique( $matches[0] ), 0, 2 ) as $url ) {
                        if ( count( $sources ) >= 10 || ! $this->is_public_url( $url ) ) break;
                        $type = str_contains( $url, '.xml' ) ? 'xml' : ( str_contains( $url, '.csv' ) ? 'csv' : 'json' );
                        $this->probe_source( $sources, $label, html_entity_decode( $url ), $type, 80, 'Discovered in supplier website HTML' );
                    }
                }
            }
        }

        usort( $sources, static fn( $a, $b ) => (int) $b['confidence'] <=> (int) $a['confidence'] );
        return array(
            'status'  => empty( $sources ) ? 'review' : 'detected',
            'clues'   => array_values( array_unique( $clues ) ),
            'sources' => array_values( $sources ),
            'notes'   => 'Discovery checks public endpoints only. A detected source may still require supplier permission, credentials, rate-limit settings, and field mapping.',
        );
    }

    private function probe_source( array &$sources, string $label, string $url, string $type, int $confidence, string $notes ): void {
        if ( ! $this->is_public_url( $url ) ) return;
        foreach ( $sources as $source ) {
            if ( ( $source['url'] ?? '' ) === $url ) return;
        }
        $response = $this->safe_fetch( $url, 5 );
        if ( is_wp_error( $response ) ) return;
        $code = (int) wp_remote_retrieve_response_code( $response );
        $content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( $code < 200 || $code >= 400 ) return;
        if ( '' === trim( $body ) && ! in_array( $code, array( 204, 206 ), true ) ) return;

        $looks_valid = true;
        if ( 'json' === $type || 'rest' === $type ) {
            json_decode( $body, true );
            $looks_valid = JSON_ERROR_NONE === json_last_error() || str_contains( $content_type, 'json' );
        } elseif ( 'xml' === $type ) {
            $looks_valid = str_contains( $content_type, 'xml' ) || str_starts_with( ltrim( $body ), '<?xml' ) || str_contains( $body, '<urlset' ) || str_contains( $body, '<sitemapindex' );
        } elseif ( 'csv' === $type ) {
            $looks_valid = str_contains( $content_type, 'csv' ) || ( str_contains( $body, ',' ) && str_contains( strtolower( substr( $body, 0, 500 ) ), 'product' ) );
        } elseif ( 'graphql' === $type ) {
            $looks_valid = $code < 400;
        }
        if ( ! $looks_valid ) return;

        $sources[] = array(
            'label'       => $label,
            'url'         => $url,
            'type'        => $type,
            'status'      => 'public',
            'http_code'   => $code,
            'content_type'=> sanitize_text_field( $content_type ),
            'confidence'  => max( 1, min( 100, $confidence ) ),
            'notes'       => $notes,
        );
    }

    private function safe_fetch( string $url, int $timeout = 6 ) {
        if ( ! $this->is_public_url( $url ) ) return new WP_Error( 'echo_unsafe_url', 'Unsafe URL.' );
        return wp_safe_remote_get( $url, array(
            'timeout' => $timeout,
            'redirection' => 2,
            'limit_response_size' => 512 * 1024,
            'user-agent' => 'EchoPlatform/' . ECHO_MOTORWORKS_CORE_VERSION . '; ' . home_url( '/' ),
            'headers' => array( 'Accept' => 'application/json, application/xml, text/csv, text/html;q=0.8, */*;q=0.5' ),
        ) );
    }

    private function is_public_url( string $url ): bool {
        if ( ! wp_http_validate_url( $url ) ) return false;
        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) return false;
        $host = strtolower( (string) $parts['host'] );
        if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) || str_ends_with( $host, '.local' ) ) return false;
        $ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );
        if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
        }
        return true;
    }

    private function map_discovery_type( string $type ): string {
        return in_array( $type, array( 'csv', 'xml', 'json', 'rest', 'graphql' ), true ) ? $type : 'rest';
    }

    public function sync_supplier(): void {
        $this->guard( 'echo_platform_sync_supplier' );
        $supplier = sanitize_key( $_POST['supplier'] ?? '' );
        $this->queue_supplier_sync( $supplier, 'manual' );
        $this->redirect( 'sync', 'Supplier sync queued. Preview and connector processing will run safely in the background.' );
    }

    public function sync_all(): void {
        $this->guard( 'echo_platform_sync_all' );
        $suppliers = array_keys( self::connections() );
        foreach ( $suppliers as $supplier ) { $this->queue_supplier_sync( $supplier, 'manual' ); }
        $this->redirect( 'sync', count( $suppliers ) . ' supplier sync job(s) queued.' );
    }

    public function run_scheduled_syncs(): void {
        foreach ( self::connections() as $supplier => $connection ) {
            if ( ! empty( $connection['auto_enabled'] ) && 'automatic' === $connection['mode'] ) {
                $this->queue_supplier_sync( $supplier, 'automatic' );
            }
        }
    }

    private function queue_supplier_sync( string $supplier, string $trigger ): void {
        if ( ! $supplier ) return;
        $connection = self::connection( $supplier );
        $summary = ucfirst( $trigger ) . ' ' . $connection['connection_type'] . ' sync queued; changes require preview approval.';
        $this->add_job( 'Supplier sync', $supplier, 'queued', $summary, array( 'trigger' => $trigger ) );
        do_action( 'echo_platform_supplier_sync_queued', $supplier, $connection, $trigger );
    }

    private function add_job( string $name, string $supplier, string $status, string $summary, array $details = array() ): void {
        $jobs = self::jobs();
        array_unshift( $jobs, array(
            'id' => wp_generate_uuid4(), 'name' => $name, 'supplier' => $supplier,
            'status' => $status, 'summary' => $summary, 'details' => $details,
            'created_at' => current_time( 'mysql' ),
        ) );
        update_option( self::JOBS_OPTION, array_slice( $jobs, 0, 100 ), false );
    }

    private function auth_headers( array $connection ): array {
        $headers = array( 'Accept' => 'application/json, application/xml;q=0.9, */*;q=0.8' );
        $key = $this->decrypt_secret( $connection['api_key'] ?? '' );
        if ( 'bearer' === $connection['auth_type'] && $key ) $headers['Authorization'] = 'Bearer ' . $key;
        if ( 'header' === $connection['auth_type'] && $key ) $headers['X-API-Key'] = $key;
        if ( 'basic' === $connection['auth_type'] ) {
            $password = $this->decrypt_secret( $connection['password'] ?? '' );
            $headers['Authorization'] = 'Basic ' . base64_encode( (string) $connection['username'] . ':' . $password );
        }
        return $headers;
    }

    private function encrypt_secret( string $secret ): string {
        if ( '' === $secret ) return '';
        if ( function_exists( 'openssl_encrypt' ) ) {
            $key = hash( 'sha256', wp_salt( 'auth' ), true );
            $iv = substr( hash( 'sha256', wp_salt( 'secure_auth' ), true ), 0, 16 );
            $encrypted = openssl_encrypt( $secret, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
            if ( false !== $encrypted ) return 'enc:' . base64_encode( $encrypted );
        }
        return 'b64:' . base64_encode( $secret );
    }

    private function decrypt_secret( string $secret ): string {
        if ( str_starts_with( $secret, 'enc:' ) && function_exists( 'openssl_decrypt' ) ) {
            $key = hash( 'sha256', wp_salt( 'auth' ), true );
            $iv = substr( hash( 'sha256', wp_salt( 'secure_auth' ), true ), 0, 16 );
            $decoded = base64_decode( substr( $secret, 4 ), true );
            $value = false === $decoded ? false : openssl_decrypt( $decoded, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
            return false === $value ? '' : (string) $value;
        }
        if ( str_starts_with( $secret, 'b64:' ) ) return (string) base64_decode( substr( $secret, 4 ) );
        return $secret;
    }

    private function guard( string $action ): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Permission denied.' );
        check_admin_referer( $action );
    }

    private function redirect( string $tab, string $message, string $type = 'success' ): void {
        wp_safe_redirect( add_query_arg( array(
            'page' => 'echo-catalog-manager', 'tab' => $tab,
            'echo_notice' => rawurlencode( $message ), 'echo_notice_type' => $type,
        ), admin_url( 'admin.php' ) ) );
        exit;
    }
}
