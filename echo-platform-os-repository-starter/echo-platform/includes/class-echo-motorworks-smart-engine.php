<?php

defined( 'ABSPATH' ) || exit;

/**
 * Universal intelligence layer for catalog quality, source mapping and image recovery.
 * Rules first, confidence scoring second, approval before catalog changes.
 */
final class Echo_Motorworks_Smart_Engine {
    private const IMAGE_STATE = 'echo_platform_image_intelligence_v1';
    private const ADVICE_STATE = 'echo_platform_smart_advice_v1';
    private const MAP_STATE = 'echo_platform_mapping_suggestions_v1';
    private const IMAGE_CRON = 'echo_platform_image_scan_batch';

    public function __construct() {
        add_action( 'admin_post_echo_platform_start_image_scan', array( $this, 'start_image_scan' ) );
        add_action( 'admin_post_echo_platform_apply_image_candidate', array( $this, 'apply_image_candidate' ) );
        add_action( 'admin_post_echo_platform_ignore_image_candidate', array( $this, 'ignore_image_candidate' ) );
        add_action( 'admin_post_echo_platform_refresh_advice', array( $this, 'refresh_advice' ) );
        add_action( 'admin_post_echo_platform_analyze_mapping', array( $this, 'analyze_mapping' ) );
        add_action( self::IMAGE_CRON, array( $this, 'run_image_batch' ), 10, 2 );
    }

    public static function image_state(): array {
        return wp_parse_args( get_option( self::IMAGE_STATE, array() ), array(
            'status' => 'idle', 'started_at' => '', 'finished_at' => '', 'total' => 0,
            'processed' => 0, 'missing' => 0, 'found' => 0, 'high_confidence' => 0,
            'review' => 0, 'poor_quality' => 0, 'candidates' => array(), 'ignored' => array(),
        ) );
    }

    public static function advice(): array {
        $saved = get_option( self::ADVICE_STATE, array() );
        return is_array( $saved ) && $saved ? $saved : self::build_advice();
    }

    public static function mapping_suggestions( string $supplier ): array {
        $all = get_option( self::MAP_STATE, array() );
        return is_array( $all ) && isset( $all[ $supplier ] ) && is_array( $all[ $supplier ] ) ? $all[ $supplier ] : array();
    }

    public function start_image_scan(): void {
        $this->guard( 'echo_platform_start_image_scan' );
        $supplier = sanitize_key( $_POST['supplier'] ?? '' );
        $query = array(
            'post_type' => 'product', 'post_status' => array( 'publish', 'draft', 'private' ),
            'fields' => 'ids', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC',
        );
        if ( $supplier ) {
            $query['meta_query'] = array( array( 'key' => '_echo_supplier', 'value' => $supplier, 'compare' => '=' ) );
        }
        $ids = get_posts( $query );
        $state = array(
            'status' => 'queued', 'started_at' => current_time( 'mysql' ), 'finished_at' => '',
            'supplier' => $supplier, 'total' => count( $ids ), 'processed' => 0, 'missing' => 0,
            'found' => 0, 'high_confidence' => 0, 'review' => 0, 'poor_quality' => 0,
            'candidates' => array(), 'ignored' => array(), 'queue' => array_map( 'absint', $ids ),
        );
        update_option( self::IMAGE_STATE, $state, false );
        wp_schedule_single_event( time() + 2, self::IMAGE_CRON, array( 0, 40 ) );
        $this->redirect( 'images', 'Image Intelligence scan queued. It will continue in safe background batches.' );
    }

    public function run_image_batch( int $offset = 0, int $limit = 40 ): void {
        $state = self::image_state();
        $queue = array_values( array_filter( array_map( 'absint', $state['queue'] ?? array() ) ) );
        if ( empty( $queue ) ) {
            $state['status'] = 'complete'; $state['finished_at'] = current_time( 'mysql' );
            unset( $state['queue'] ); update_option( self::IMAGE_STATE, $state, false ); return;
        }
        $state['status'] = 'running';
        $batch = array_splice( $queue, 0, max( 5, min( 100, $limit ) ) );
        foreach ( $batch as $product_id ) {
            $state['processed']++;
            if ( has_post_thumbnail( $product_id ) ) {
                $attachment_id = get_post_thumbnail_id( $product_id );
                $meta = wp_get_attachment_metadata( $attachment_id );
                $width = (int) ( $meta['width'] ?? 0 ); $height = (int) ( $meta['height'] ?? 0 );
                if ( $width && $height && min( $width, $height ) < 500 ) $state['poor_quality']++;
                continue;
            }
            $state['missing']++;
            $candidate = $this->best_image_candidate( $product_id );
            if ( $candidate ) {
                $state['found']++;
                if ( (int) $candidate['confidence'] >= 90 ) $state['high_confidence']++; else $state['review']++;
                $state['candidates'][ (string) $product_id ] = $candidate;
            }
        }
        $state['queue'] = $queue;
        if ( empty( $queue ) ) {
            $state['status'] = 'complete'; $state['finished_at'] = current_time( 'mysql' ); unset( $state['queue'] );
        } else {
            wp_schedule_single_event( time() + 5, self::IMAGE_CRON, array( 0, $limit ) );
        }
        update_option( self::IMAGE_STATE, $state, false );
        update_option( self::ADVICE_STATE, self::build_advice(), false );
    }

    private function best_image_candidate( int $product_id ): array {
        $product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
        $sku = $product ? trim( (string) $product->get_sku() ) : '';
        $part = $this->part_number( $product_id );
        $title = get_the_title( $product_id );
        $candidates = array();

        // Existing media library exact identifier match.
        foreach ( array_filter( array( $sku, $part ) ) as $identifier ) {
            $media = get_posts( array(
                'post_type' => 'attachment', 'post_mime_type' => 'image', 'post_status' => 'inherit',
                'posts_per_page' => 6, 's' => $identifier, 'orderby' => 'date', 'order' => 'DESC',
            ) );
            foreach ( $media as $attachment ) {
                $confidence = 86;
                $haystack = strtolower( $attachment->post_title . ' ' . get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ) );
                if ( false !== strpos( preg_replace('/[^a-z0-9]/', '', $haystack), preg_replace('/[^a-z0-9]/', '', strtolower( $identifier ) ) ) ) $confidence = 98;
                $candidates[] = array( 'type' => 'attachment', 'attachment_id' => $attachment->ID, 'url' => wp_get_attachment_url( $attachment->ID ), 'confidence' => $confidence, 'reason' => 'Exact SKU or part-number match in the Media Library' );
            }
        }

        // Trusted product metadata image URLs from imports/connectors.
        $url_keys = array( '_echo_image_url', '_supplier_image_url', '_product_image_url', '_remote_image_url', '_echo_source_image' );
        foreach ( $url_keys as $key ) {
            $url = esc_url_raw( (string) get_post_meta( $product_id, $key, true ) );
            if ( $url && $this->public_url( $url ) ) $candidates[] = array( 'type' => 'remote', 'url' => $url, 'confidence' => 96, 'reason' => 'Image URL supplied by the product source' );
        }

        // Product description image is useful but needs review.
        $content = (string) get_post_field( 'post_content', $product_id );
        if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $match ) ) {
            $url = esc_url_raw( html_entity_decode( $match[1] ) );
            if ( $url && $this->public_url( $url ) ) $candidates[] = array( 'type' => 'remote', 'url' => $url, 'confidence' => 82, 'reason' => 'Image embedded in the product description' );
        }

        // Public supplier product page metadata.
        foreach ( array( '_echo_source_url', '_supplier_product_url', '_source_url', 'source_url' ) as $key ) {
            $page_url = esc_url_raw( (string) get_post_meta( $product_id, $key, true ) );
            if ( ! $page_url || ! $this->public_url( $page_url ) ) continue;
            $response = wp_safe_remote_get( $page_url, array( 'timeout' => 8, 'redirection' => 3, 'user-agent' => 'EchoPlatform/3.4; ' . home_url( '/' ) ) );
            if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) continue;
            $body = (string) wp_remote_retrieve_body( $response );
            if ( preg_match( '/<meta[^>]+(?:property|name)=["\'](?:og:image|twitter:image)["\'][^>]+content=["\']([^"\']+)["\']/i', $body, $m ) || preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\'](?:og:image|twitter:image)["\']/i', $body, $m ) ) {
                $url = esc_url_raw( html_entity_decode( $m[1] ) );
                if ( $url && $this->public_url( $url ) ) $candidates[] = array( 'type' => 'remote', 'url' => $url, 'confidence' => ( $sku || $part ) ? 91 : 76, 'reason' => 'Primary image detected on the supplier product page' );
            }
            break;
        }

        if ( ! $candidates ) return array();
        usort( $candidates, static fn( $a, $b ) => (int) $b['confidence'] <=> (int) $a['confidence'] );
        $best = $candidates[0];
        $best['product_id'] = $product_id; $best['sku'] = $sku; $best['part_number'] = $part; $best['title'] = $title;
        return $best;
    }

    public function apply_image_candidate(): void {
        $this->guard( 'echo_platform_apply_image_candidate' );
        $product_id = absint( $_POST['product_id'] ?? 0 );
        $state = self::image_state(); $candidate = $state['candidates'][ (string) $product_id ] ?? null;
        if ( ! $product_id || ! is_array( $candidate ) ) $this->redirect( 'images', 'Image candidate not found. Run the scan again.', 'error' );
        $attachment_id = 0;
        if ( 'attachment' === ( $candidate['type'] ?? '' ) ) $attachment_id = absint( $candidate['attachment_id'] ?? 0 );
        elseif ( ! empty( $candidate['url'] ) && $this->public_url( $candidate['url'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/image.php';
            $attachment_id = media_sideload_image( $candidate['url'], $product_id, get_the_title( $product_id ), 'id' );
            if ( is_wp_error( $attachment_id ) ) $this->redirect( 'images', 'Image download failed: ' . $attachment_id->get_error_message(), 'error' );
        }
        if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) $this->redirect( 'images', 'The selected file is not a valid image.', 'error' );
        set_post_thumbnail( $product_id, $attachment_id );
        unset( $state['candidates'][ (string) $product_id ] ); update_option( self::IMAGE_STATE, $state, false );
        $this->redirect( 'images', 'Product image approved and applied.' );
    }

    public function ignore_image_candidate(): void {
        $this->guard( 'echo_platform_ignore_image_candidate' );
        $product_id = absint( $_POST['product_id'] ?? 0 ); $state = self::image_state();
        if ( $product_id ) { unset( $state['candidates'][ (string) $product_id ] ); $state['ignored'][] = $product_id; update_option( self::IMAGE_STATE, $state, false ); }
        $this->redirect( 'images', 'Candidate ignored. The product was not changed.' );
    }

    public function refresh_advice(): void {
        $this->guard( 'echo_platform_refresh_advice' );
        update_option( self::ADVICE_STATE, self::build_advice(), false );
        $this->redirect( 'smart', 'Smart Action Queue refreshed.' );
    }

    public static function build_advice(): array {
        $health = class_exists( 'Echo_Motorworks_Operations' ) ? Echo_Motorworks_Operations::health_snapshot() : array();
        if ( empty( $health ) && class_exists( 'Echo_Motorworks_Operations' ) ) $health = Echo_Motorworks_Operations::calculate_health();
        $images = self::image_state(); $connections = class_exists( 'Echo_Motorworks_Operations' ) ? Echo_Motorworks_Operations::connections() : array();
        $items = array();
        $push = static function( string $priority, string $title, string $detail, string $tab, string $action ) use ( &$items ): void { $items[] = compact( 'priority','title','detail','tab','action' ); };
        if ( (int) ( $health['missing_prices'] ?? 0 ) ) $push( 'critical', 'Products are missing prices', (int) $health['missing_prices'] . ' products cannot be sold correctly.', 'health', 'Review prices' );
        if ( (int) ( $health['missing_images'] ?? 0 ) ) $push( 'high', 'Recover missing product images', (int) $health['missing_images'] . ' products need a featured image.', 'images', 'Run Image Intelligence' );
        if ( (int) ( $health['missing_fitment'] ?? 0 ) ) $push( 'high', 'Complete product fitment', (int) $health['missing_fitment'] . ' products are not classified as universal or vehicle-specific.', 'diagnostics', 'Open Fitment Auditor' );
        if ( (int) ( $health['missing_sku'] ?? 0 ) ) $push( 'medium', 'Add SKUs or part numbers', (int) $health['missing_sku'] . ' products are harder to sync and match safely.', 'health', 'Review identifiers' );
        if ( 'complete' === ( $images['status'] ?? '' ) && (int) ( $images['high_confidence'] ?? 0 ) ) $push( 'high', 'Approve high-confidence image matches', (int) $images['high_confidence'] . ' strong matches are ready for review.', 'images', 'Review matches' );
        $auto = 0; $untested = 0;
        foreach ( $connections as $connection ) { if ( ! empty( $connection['auto_enabled'] ) ) $auto++; if ( empty( $connection['last_test_status'] ) && ! empty( $connection['base_url'] ) ) $untested++; }
        if ( $untested ) $push( 'medium', 'Test supplier connections', $untested . ' configured source(s) have not been tested.', 'api', 'Test connections' );
        if ( ! $auto && $connections ) $push( 'low', 'Consider scheduled supplier syncs', 'All configured suppliers are currently manual or on-demand.', 'sync', 'Review schedules' );
        if ( ! $items ) $push( 'good', 'Store looks healthy', 'No urgent tasks were detected. Run regular scans to keep it that way.', 'dashboard', 'View dashboard' );
        $order = array( 'critical'=>0, 'high'=>1, 'medium'=>2, 'low'=>3, 'good'=>4 );
        usort( $items, static fn( $a,$b ) => ( $order[$a['priority']] ?? 9 ) <=> ( $order[$b['priority']] ?? 9 ) );
        return array( 'generated_at' => current_time( 'mysql' ), 'items' => $items );
    }

    public function analyze_mapping(): void {
        $this->guard( 'echo_platform_analyze_mapping' );
        $supplier = sanitize_key( $_POST['supplier'] ?? '' );
        if ( ! $supplier || ! class_exists( 'Echo_Motorworks_Operations' ) ) $this->redirect( 'mapping', 'Choose a supplier.', 'error' );
        $connection = Echo_Motorworks_Operations::connection( $supplier );
        $url = esc_url_raw( $connection['base_url'] ?? '' );
        if ( ! $url || ! $this->public_url( $url ) ) $this->redirect( 'mapping', 'Save a public JSON or REST source URL first.', 'error' );
        $response = wp_safe_remote_get( $url, array( 'timeout' => 15, 'redirection' => 3 ) );
        if ( is_wp_error( $response ) ) $this->redirect( 'mapping', 'Source analysis failed: ' . $response->get_error_message(), 'error' );
        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        $sample = $this->first_record( $data );
        if ( ! is_array( $sample ) ) $this->redirect( 'mapping', 'No readable product record was found. XML and CSV mapping remain available through manual import.', 'error' );
        $aliases = array(
            'sku'=>array('sku','product_sku','variant_sku','code'), 'part_number'=>array('mpn','part_number','partnumber','manufacturer_part_number','vendor_sku'),
            'name'=>array('name','title','product_name'), 'description'=>array('description','body_html','content','long_description'),
            'price'=>array('price','regular_price','msrp','map_price'), 'sale_price'=>array('sale_price','special_price'),
            'stock'=>array('stock','quantity','inventory_quantity','qty'), 'image'=>array('image','image_url','featured_image','src'),
            'brand'=>array('brand','vendor','manufacturer'), 'categories'=>array('categories','category','product_type'),
            'fitment'=>array('fitment','vehicle_fitment','applications','vehicles'),
        );
        $flat = $this->flatten_keys( $sample ); $suggestions = array();
        foreach ( $aliases as $target => $names ) {
            $best = ''; $score = 0;
            foreach ( $flat as $key ) {
                $norm = strtolower( preg_replace('/[^a-z0-9]+/', '_', $key ) );
                foreach ( $names as $alias ) { if ( $norm === $alias ) { $best=$key; $score=100; break 2; } if ( false !== strpos( $norm, $alias ) && $score < 80 ) { $best=$key; $score=80; } }
            }
            $suggestions[$target] = array( 'source' => $best, 'confidence' => $score );
        }
        $all = get_option( self::MAP_STATE, array() ); if ( ! is_array( $all ) ) $all = array();
        $all[$supplier] = array( 'analyzed_at'=>current_time('mysql'), 'source_url'=>$url, 'fields'=>$flat, 'suggestions'=>$suggestions );
        update_option( self::MAP_STATE, $all, false );
        $this->redirect( 'mapping', 'Source fields analyzed. Review the suggested WooCommerce mappings.' );
    }

    private function first_record( $data ) {
        if ( ! is_array( $data ) ) return null;
        foreach ( array('products','items','data','results') as $key ) if ( isset($data[$key]) && is_array($data[$key]) ) return $this->first_record($data[$key]);
        if ( $this->is_list( $data ) ) return isset($data[0]) && is_array($data[0]) ? $data[0] : null;
        return $data;
    }
    private function flatten_keys( array $data, string $prefix='' ): array { $keys=array(); foreach($data as $k=>$v){$path=$prefix?"$prefix.$k":(string)$k;$keys[]=$path;if(is_array($v)&&!$this->is_list($v))$keys=array_merge($keys,$this->flatten_keys($v,$path));} return array_values(array_unique($keys)); }
    private function is_list( array $value ): bool { if ( array() === $value ) return true; return array_keys( $value ) === range( 0, count( $value ) - 1 ); }
        private function part_number( int $id ): string { foreach(array('_echo_part_number','_mpn','mpn','part_number','manufacturer_part_number','_supplier_sku','_vendor_sku') as $k){$v=trim((string)get_post_meta($id,$k,true));if($v!=='')return $v;}return ''; }
    private function public_url( string $url ): bool { $p=wp_parse_url($url);if(!is_array($p)||!in_array(strtolower($p['scheme']??''),array('http','https'),true)||empty($p['host']))return false;$host=strtolower($p['host']);return !in_array($host,array('localhost','127.0.0.1','::1'),true); }
    private function guard( string $action ): void { if(!current_user_can('manage_woocommerce'))wp_die('Permission denied.');check_admin_referer($action); }
    private function redirect( string $tab, string $message, string $type='success' ): void { wp_safe_redirect(add_query_arg(array('page'=>'echo-catalog-manager','tab'=>$tab,'echo_notice'=>rawurlencode($message),'echo_notice_type'=>$type),admin_url('admin.php')));exit; }
}
