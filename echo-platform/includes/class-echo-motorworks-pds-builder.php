<?php

defined( 'ABSPATH' ) || exit;

/**
 * Pure Drivetrain Solutions catalog builder.
 *
 * Workflow:
 * 1. Discover product URLs from the supplier sitemap (one supplier request).
 * 2. Build/resume products in tiny AJAX batches from saved URLs.
 * 3. Download missing images separately so catalog creation remains resilient.
 * 4. Export a fitment-review CSV using conservative transmission/application scopes.
 */
final class Echo_Motorworks_PDS_Builder {
    private const URLS_OPTION   = 'echo_pds_urls_v1';
    private const BUILD_OPTION  = 'echo_pds_build_state_v1';
    private const IMAGE_OPTION  = 'echo_pds_image_state_v1';
    private const SOURCE_KEY    = 'pds_squarespace_v1';
    private const SUPPLIER      = 'Pure Drivetrain Solutions';
    private const BATCH_SIZE    = 2;
    private const TIME_BUDGET   = 18.0;

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'admin_menu' ), 67 );
        add_action( 'wp_ajax_echo_pds_discover', array( $this, 'ajax_discover' ) );
        add_action( 'wp_ajax_echo_pds_build', array( $this, 'ajax_build' ) );
        add_action( 'wp_ajax_echo_pds_images', array( $this, 'ajax_images' ) );
        add_action( 'admin_post_echo_pds_export_fitment', array( $this, 'export_fitment' ) );
        add_action( 'admin_post_echo_pds_export_products', array( $this, 'export_products' ) );
    }

    public function admin_menu(): void {
        add_submenu_page(
            class_exists( 'WooCommerce' ) ? 'woocommerce' : 'tools.php',
            'Pure Drivetrain Solutions',
            'Pure Drivetrain',
            'manage_woocommerce',
            'echo-pds',
            array( $this, 'page' )
        );
    }

    public function page(): void {
        $urls   = $this->urls();
        $build  = $this->build_state( count( $urls ) );
        $images = $this->image_state();
        ?>
        <div class="wrap">
            <h1>Pure Drivetrain Solutions — Catalog & Fitment Builder</h1>
            <p>This builder saves the supplier product URLs first, then creates or refreshes WooCommerce products in small resumable batches. Existing PDS products are matched by source URL and will not be duplicated.</p>
            <div class="notice notice-info inline"><p><strong>Fitment protection:</strong> drivetrain products are never marked universally compatible. The builder records transmission/application evidence and keeps uncertain products out of exact Year/Make/Model results until reviewed.</p></div>

            <h2>1. Discover supplier products</h2>
            <p><button class="button button-primary" id="echo-pds-discover">Discover / Refresh Product URLs</button></p>
            <p id="echo-pds-discover-status"><strong><?php echo esc_html( count( $urls ) . ' saved product URLs.' ); ?></strong></p>

            <h2>2. Build WooCommerce catalog</h2>
            <p>
                <button class="button button-primary" id="echo-pds-build" <?php disabled( empty( $urls ) ); ?>>Build / Resume</button>
                <button class="button" id="echo-pds-build-restart" <?php disabled( empty( $urls ) ); ?>>Restart Progress</button>
                <button class="button" id="echo-pds-build-stop" disabled>Pause</button>
            </p>
            <?php $this->progress_box( 'build', $build ); ?>

            <h2>3. Download missing product images</h2>
            <p>
                <button class="button button-primary" id="echo-pds-images">Download / Resume Images</button>
                <button class="button" id="echo-pds-images-restart">Restart Image Pass</button>
                <button class="button" id="echo-pds-images-stop" disabled>Pause</button>
            </p>
            <?php $this->progress_box( 'images', $images ); ?>

            <h2>Backups and fitment review</h2>
            <p>
                <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=echo_pds_export_products' ), 'echo_pds_export_products' ) ); ?>">Download PDS Product CSV</a>
                <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=echo_pds_export_fitment' ), 'echo_pds_export_fitment' ) ); ?>">Download PDS Fitment CSV</a>
            </p>
        </div>
        <script>
        jQuery(function($){
            $('#echo-pds-discover').on('click', function(){
                const $b=$(this), $s=$('#echo-pds-discover-status'); $b.prop('disabled',true); $s.text('Reading the supplier sitemap…');
                $.post(ajaxurl,{action:'echo_pds_discover',nonce:<?php echo wp_json_encode( wp_create_nonce( 'echo_pds_discover' ) ); ?>})
                 .done(function(r){ if(r.success){$s.text(r.data.message); $('#echo-pds-build,#echo-pds-build-restart').prop('disabled',false);} else {$s.text((r.data&&r.data.message)||'Discovery failed.');} })
                 .fail(function(x){$s.text('HTTP '+(x.status||'error')+'. No catalog data was changed.');})
                 .always(function(){$b.prop('disabled',false);});
            });
            function runner(prefix, action, nonce, delay){
                let running=false, stopped=false;
                const $start=$('#echo-pds-'+prefix),$restart=$('#echo-pds-'+prefix+'-restart'),$stop=$('#echo-pds-'+prefix+'-stop'),$box=$('#echo-pds-'+prefix+'-progress'),$bar=$('#echo-pds-'+prefix+'-bar'),$text=$('#echo-pds-'+prefix+'-text'),$log=$('#echo-pds-'+prefix+'-log');
                function append(t){$log.val(($log.val()? $log.val()+'\n':'')+t);$log.scrollTop($log[0].scrollHeight);}
                function finish(t){running=false;$start.prop('disabled',false);$restart.prop('disabled',false);$stop.prop('disabled',true);if(t)append(t);}
                function request(reset){if(stopped){finish('Paused. Progress is saved.');return;} $.ajax({url:ajaxurl,method:'POST',timeout:55000,data:{action:action,nonce:nonce,reset:reset?1:0}}).done(function(r){if(!r.success){finish((r.data&&r.data.message)||'Server error.');return;}const d=r.data;$box.show();$bar.css('width',(d.progress_pct||0)+'%');$text.text(d.progress_text||'Progress saved');append(d.message||'Saved.');if(d.warnings) d.warnings.forEach(x=>append('Warning: '+x));if(d.done)finish('Complete.');else setTimeout(()=>request(false),delay);}).fail(function(x){finish('Paused after HTTP '+(x.status||'error')+'. Click Build / Resume to continue.');});}
                function begin(reset){if(running)return;running=true;stopped=false;$start.add($restart).prop('disabled',true);$stop.prop('disabled',false);$box.show();if(reset){$log.val('');$bar.css('width','0');}request(reset);}
                $start.on('click',()=>begin(false));$restart.on('click',()=>{if(confirm('Restart saved progress? Existing products will be updated, not duplicated.'))begin(true);});$stop.on('click',()=>{stopped=true;$stop.prop('disabled',true);});
            }
            runner('build','echo_pds_build',<?php echo wp_json_encode( wp_create_nonce( 'echo_pds_build' ) ); ?>,400);
            runner('images','echo_pds_images',<?php echo wp_json_encode( wp_create_nonce( 'echo_pds_images' ) ); ?>,1400);
        });
        </script>
        <?php
    }

    private function progress_box( string $name, array $state ): void {
        $total = max( 0, (int) ( $state['total'] ?? 0 ) );
        $done  = max( 0, (int) ( $state['processed'] ?? $state['index'] ?? 0 ) );
        $pct   = $total ? min( 100, round( 100 * $done / $total, 1 ) ) : 0;
        ?>
        <div id="echo-pds-<?php echo esc_attr( $name ); ?>-progress" style="max-width:800px;margin:10px 0 22px;">
            <div style="height:18px;background:#ddd;border-radius:9px;overflow:hidden"><div id="echo-pds-<?php echo esc_attr( $name ); ?>-bar" style="height:100%;width:<?php echo esc_attr( (string) $pct ); ?>%;background:#2271b1"></div></div>
            <p id="echo-pds-<?php echo esc_attr( $name ); ?>-text"><?php echo esc_html( $done . ' / ' . $total ); ?></p>
            <textarea id="echo-pds-<?php echo esc_attr( $name ); ?>-log" readonly style="width:100%;height:150px"></textarea>
        </div>
        <?php
    }

    public function ajax_discover(): void {
        $this->guard( 'echo_pds_discover' );
        $urls = $this->discover_urls();
        if ( is_wp_error( $urls ) ) wp_send_json_error( array( 'message' => $urls->get_error_message() ) );
        update_option( self::URLS_OPTION, $urls, false );
        update_option( self::BUILD_OPTION, $this->empty_build_state( count( $urls ) ), false );
        wp_send_json_success( array( 'message' => sprintf( 'Saved %d Pure Drivetrain Solutions product URLs. The local build is ready.', count( $urls ) ) ) );
    }

    public function ajax_build(): void {
        $this->guard( 'echo_pds_build' );
        if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Product_Simple' ) ) wp_send_json_error( array( 'message' => 'WooCommerce must be active.' ) );
        $urls = $this->urls();
        if ( ! $urls ) wp_send_json_error( array( 'message' => 'Discover the supplier product URLs first.' ) );
        $state = ! empty( $_POST['reset'] ) ? $this->empty_build_state( count( $urls ) ) : $this->build_state( count( $urls ) );
        if ( ! empty( $_POST['reset'] ) ) update_option( self::BUILD_OPTION, $state, false );
        if ( ! empty( $state['completed'] ) ) wp_send_json_success( $this->response( $state, 'The PDS catalog build is already complete.' ) );

        $started = microtime( true ); $worked = 0; $messages = array(); $warnings = array();
        while ( $worked < self::BATCH_SIZE && ( microtime( true ) - $started ) < self::TIME_BUDGET ) {
            $index = (int) $state['index'];
            if ( ! isset( $urls[ $index ] ) ) { $state['completed'] = true; break; }
            $url = $urls[ $index ];
            $source = $this->fetch_product( $url );
            if ( is_wp_error( $source ) ) {
                $state['failed']++;
                $warnings[] = $url . ': ' . $source->get_error_message();
            } else {
                $result = $this->sync_product( $source );
                if ( is_wp_error( $result ) ) { $state['failed']++; $warnings[] = $result->get_error_message(); }
                else { $state[ $result['created'] ? 'created' : 'updated' ]++; $messages[] = $result['name'] . ' — ' . ( $result['created'] ? 'created' : 'refreshed' ) . '; fitment: ' . $result['scope'] . '.'; }
            }
            $state['index']++; $state['processed']++; $worked++;
            if ( $state['index'] >= count( $urls ) ) $state['completed'] = true;
            update_option( self::BUILD_OPTION, $state, false );
        }
        if ( ! $messages ) $messages[] = 'Progress saved.';
        wp_send_json_success( $this->response( $state, implode( "\n", $messages ), $warnings ) );
    }

    public function ajax_images(): void {
        $this->guard( 'echo_pds_images' );
        $ids = $this->supplier_product_ids();
        $state = ! empty( $_POST['reset'] ) ? $this->empty_image_state() : $this->image_state();
        if ( ! empty( $_POST['reset'] ) ) update_option( self::IMAGE_OPTION, $state, false );
        $state['total'] = count( $ids );
        if ( (int) $state['index'] >= count( $ids ) ) { $state['completed'] = true; update_option( self::IMAGE_OPTION, $state, false ); wp_send_json_success( $this->response( $state, 'The PDS image pass is complete.' ) ); }
        $id = (int) $ids[ (int) $state['index'] ]; $name = get_the_title( $id ); $warnings = array();
        if ( has_post_thumbnail( $id ) ) { $state['skipped']++; $message = $name . ' — existing image preserved.'; }
        else {
            $url = esc_url_raw( (string) get_post_meta( $id, '_echo_source_image_url', true ) );
            if ( ! $url ) { $state['skipped']++; $message = $name . ' — no source image URL.'; }
            else { $attachment = $this->sideload_image( $url, $id, $name ); if ( is_wp_error( $attachment ) ) { $state['failed']++; $warnings[] = $attachment->get_error_message(); $message = $name . ' — image failed.'; } else { set_post_thumbnail( $id, $attachment ); $state['downloaded']++; $message = $name . ' — image downloaded.'; } }
        }
        $state['index']++; $state['processed']++;
        if ( $state['index'] >= count( $ids ) ) $state['completed'] = true;
        update_option( self::IMAGE_OPTION, $state, false );
        wp_send_json_success( $this->response( $state, $message, $warnings ) );
    }

    private function discover_urls() {
        $candidates = array(
            'https://www.puredrivetrainsolutions.com/sitemap.xml',
            'https://www.puredrivetrainsolutions.com/sitemap-products.xml',
        );
        $found = array(); $errors = array();
        foreach ( $candidates as $sitemap ) {
            $response = wp_safe_remote_get( $sitemap, array( 'timeout' => 28, 'redirection' => 3, 'user-agent' => 'EchoMotorworksCatalog/1.0 (+https://echomotorworks.com/)' ) );
            if ( is_wp_error( $response ) ) { $errors[] = $response->get_error_message(); continue; }
            if ( 200 !== wp_remote_retrieve_response_code( $response ) ) continue;
            $xml = wp_remote_retrieve_body( $response );
            if ( preg_match_all( '~<loc>\s*(https?://[^<]+)\s*</loc>~i', $xml, $m ) ) {
                foreach ( $m[1] as $url ) {
                    $url = html_entity_decode( trim( $url ), ENT_QUOTES, 'UTF-8' );
                    if ( $this->is_product_url( $url ) ) $found[] = esc_url_raw( $url );
                }
            }
            if ( $found ) break;
        }
        $found = array_values( array_unique( $found ) ); sort( $found );
        if ( ! $found ) return new WP_Error( 'pds_sitemap', 'No PDS product URLs were found in the supplier sitemap. ' . implode( ' ', array_unique( $errors ) ) );
        return $found;
    }

    private function is_product_url( string $url ): bool {
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        if ( false === strpos( $path, '/p/' ) ) return false;
        foreach ( array( '/blog/', '/resources/', '/terms', '/contact' ) as $bad ) if ( false !== strpos( $path, $bad ) ) return false;
        return true;
    }

    private function fetch_product( string $url ) {
        $response = wp_safe_remote_get( $url, array( 'timeout' => 30, 'redirection' => 3, 'user-agent' => 'EchoMotorworksCatalog/1.0 (+https://echomotorworks.com/)' ) );
        if ( is_wp_error( $response ) ) return $response;
        if ( 200 !== wp_remote_retrieve_response_code( $response ) ) return new WP_Error( 'pds_http', 'Supplier returned HTTP ' . wp_remote_retrieve_response_code( $response ) );
        $html = wp_remote_retrieve_body( $response );
        $json = $this->product_json_ld( $html );
        $name = sanitize_text_field( (string) ( $json['name'] ?? '' ) );
        if ( ! $name && preg_match( '~<h1[^>]*>(.*?)</h1>~is', $html, $m ) ) $name = sanitize_text_field( wp_strip_all_tags( $m[1] ) );
        if ( ! $name ) return new WP_Error( 'pds_parse', 'Could not read a product title.' );
        $description = (string) ( $json['description'] ?? '' );
        if ( ! $description && preg_match( '~<meta[^>]+(?:name|property)=["\'](?:description|og:description)["\'][^>]+content=["\']([^"\']+)["\']~i', $html, $m ) ) $description = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
        $image = $json['image'] ?? '';
        if ( is_array( $image ) ) $image = reset( $image );
        if ( is_array( $image ) ) $image = $image['url'] ?? '';
        if ( ! $image && preg_match( '~<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']~i', $html, $m ) ) $image = $m[1];
        $offer = $json['offers'] ?? array(); if ( isset( $offer[0] ) ) $offer = $offer[0];
        $price = is_array( $offer ) ? ( $offer['lowPrice'] ?? $offer['price'] ?? '' ) : '';
        $sku = sanitize_text_field( (string) ( $json['sku'] ?? '' ) );
        return array( 'url' => $url, 'name' => $name, 'description' => wp_kses_post( $description ), 'image' => esc_url_raw( (string) $image ), 'price' => wc_format_decimal( (string) $price ), 'sku' => $sku, 'category' => $this->infer_category( $url, $name ) );
    }

    private function product_json_ld( string $html ): array {
        if ( ! preg_match_all( '~<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', $html, $m ) ) return array();
        foreach ( $m[1] as $raw ) {
            $data = json_decode( html_entity_decode( trim( $raw ), ENT_QUOTES, 'UTF-8' ), true );
            if ( ! is_array( $data ) ) continue;
            $nodes = isset( $data['@graph'] ) ? (array) $data['@graph'] : array( $data );
            foreach ( $nodes as $node ) if ( is_array( $node ) && ( 'Product' === ( $node['@type'] ?? '' ) || in_array( 'Product', (array) ( $node['@type'] ?? array() ), true ) ) ) return $node;
        }
        return array();
    }

    private function sync_product( array $source ) {
        $existing = $this->find_by_source_url( $source['url'] ); $created = ! $existing;
        $product = new WC_Product_Simple( $existing ?: 0 );
        $product->set_name( $source['name'] ); $product->set_status( 'publish' ); $product->set_catalog_visibility( 'visible' );
        $product->set_description( $this->description_html( $source ) );
        $product->set_short_description( '<p>Built-to-order performance drivetrain component from Pure Drivetrain Solutions. Confirm transmission code and vehicle application before ordering.</p>' );
        $product->set_manage_stock( false ); $product->set_stock_status( 'instock' );
        if ( '' !== $source['price'] ) { $product->set_regular_price( $source['price'] ); $product->set_price( $source['price'] ); }
        else { $product->set_regular_price( '' ); $product->set_price( '' ); }
        if ( $created ) $product->set_sku( $this->unique_sku( $source['sku'] ?: 'PDS-' . strtoupper( substr( md5( $source['url'] ), 0, 10 ) ) ) );
        $product->set_category_ids( $this->category_ids( $source['category'] ) );
        $id = $product->save(); if ( ! $id ) return new WP_Error( 'pds_save', 'WooCommerce could not save ' . $source['name'] );
        update_post_meta( $id, '_echo_supplier', self::SUPPLIER ); update_post_meta( $id, '_echo_brand', self::SUPPLIER );
        update_post_meta( $id, '_echo_source_url', $source['url'] ); update_post_meta( $id, '_echo_source_key', self::SOURCE_KEY );
        update_post_meta( $id, '_echo_source_image_url', $source['image'] ); update_post_meta( $id, '_echo_supplier_sku', $source['sku'] );
        update_post_meta( $id, '_echo_order_mode', 'inquiry_confirmation' );
        $scope = $this->set_fitment_scope( $id, $source['name'] . ' ' . wp_strip_all_tags( $source['description'] ) );
        $this->assign_brand( $id );
        return array( 'created' => $created, 'name' => $source['name'], 'scope' => $scope );
    }

    private function description_html( array $source ): string {
        $body = $source['description'] ? wpautop( wp_kses_post( $source['description'] ) ) : '<p>Performance drivetrain product supplied by Pure Drivetrain Solutions.</p>';
        return $body . '<div class="echo-fitment-note"><p><strong>Fitment confirmation required:</strong> transmission family alone does not guarantee interchangeability. Confirm exact transmission code, drivetrain, input/output configuration, electronics, converter, transfer case and vehicle application before purchase.</p><p><a href="' . esc_url( $source['url'] ) . '" rel="nofollow noopener" target="_blank">View supplier reference</a></p></div>';
    }

    private function set_fitment_scope( int $id, string $text ): string {
        $codes = array();
        preg_match_all( '~\b(?:8HP(?:45|50|51|55|60|65A?|70|75|76|90|95A?)|6HP\d{2}|10R(?:60|80|90|140)|10L(?:80|90|1000)|8L(?:45|90)|6L(?:80|90)|TR6060|NAG1|845RE|850RE|870RE|875RE)\b~i', $text, $m );
        if ( ! empty( $m[0] ) ) $codes = array_values( array_unique( array_map( 'strtoupper', $m[0] ) ) );
        $vehicles = array();
        foreach ( array( 'Alfa Romeo','Aston Martin','Audi','BMW','Chevrolet','Chevy','Dodge','Ford','Jeep','Jaguar','Land Rover','Nissan','Infiniti','Toyota','Supra','Hellcat','Scat Pack','Trackhawk','TRX','Durango','Ram 1500','Wrangler 392','Mustang','Camaro','Corvette' ) as $vehicle ) if ( false !== stripos( $text, $vehicle ) ) $vehicles[] = $vehicle;
        $year = '';
        if ( preg_match( '~\b(19[89]\d|20[0-3]\d)\s*[-–]\s*(19[89]\d|20[0-3]\d|present)\b~i', $text, $ym ) ) $year = $ym[0];
        elseif ( preg_match( '~\b(19[89]\d|20[0-3]\d)\+\b~', $text, $ym ) ) $year = $ym[0];
        $type = $vehicles ? 'vehicle_specific' : 'transmission_specific';
        $raw_parts = array(); if ( $year ) $raw_parts[] = 'Years: ' . $year; if ( $vehicles ) $raw_parts[] = 'Applications named by supplier: ' . implode( ', ', array_unique( $vehicles ) ); if ( $codes ) $raw_parts[] = 'Transmission code(s): ' . implode( ', ', $codes );
        if ( ! $raw_parts ) $raw_parts[] = 'Drivetrain component; exact application must be confirmed from supplier specifications.';
        $raw = implode( ' | ', $raw_parts );
        $confidence = ( $codes && $vehicles ) ? 'medium' : 'low';
        $reason = 'Drivetrain interchange depends on exact transmission generation, bellhousing, drivetrain, electronics, converter and output configuration. Do not expose as an exact YMM match without verified application rows.';
        update_post_meta( $id, '_echo_fitment_type', $type ); update_post_meta( $id, '_echo_fitment_raw', $raw ); update_post_meta( $id, '_echo_fitment_confidence', $confidence ); update_post_meta( $id, '_echo_fitment_reason', $reason );
        update_post_meta( $id, '_echo_transmission_codes', implode( ',', $codes ) ); update_post_meta( $id, '_echo_fitment_review_required', 'yes' );
        return $type;
    }

    private function infer_category( string $url, string $name ): string {
        $s = strtolower( $url . ' ' . $name );
        if ( false !== strpos( $s, 'converter' ) ) return 'Torque Converters';
        if ( false !== strpos( $s, 'transfer-case' ) || false !== strpos( $s, 'transfer case' ) ) return 'Transfer Cases';
        if ( false !== strpos( $s, 'flex-plate' ) || false !== strpos( $s, 'flex plate' ) ) return 'Flex Plates';
        if ( false !== strpos( $s, 'valve-body' ) || false !== strpos( $s, 'valve body' ) ) return 'Performance Valve Bodies';
        if ( false !== strpos( $s, 'hard-part' ) || false !== strpos( $s, 'hard part' ) || false !== strpos( $s, 'rebuild kit' ) ) return 'Hard Parts Kits';
        return 'Transmissions';
    }

    private function category_ids( string $child ): array {
        if ( ! taxonomy_exists( 'product_cat' ) ) return array();
        $root = $this->term_id( self::SUPPLIER, 'product_cat', 0 ); $ids = $root ? array( $root ) : array();
        if ( $root && $child ) { $id = $this->term_id( $child, 'product_cat', $root ); if ( $id ) $ids[] = $id; }
        return $ids;
    }

    private function assign_brand( int $id ): void {
        foreach ( array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'berocket_brand' ) as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) continue; $term = $this->term_id( self::SUPPLIER, $taxonomy, 0 ); if ( $term ) wp_set_object_terms( $id, array( $term ), $taxonomy, false );
        }
        if ( function_exists( 'wc_delete_product_transients' ) ) wc_delete_product_transients( $id ); clean_post_cache( $id );
    }

    private function term_id( string $name, string $taxonomy, int $parent ): int {
        $existing = term_exists( $name, $taxonomy, $parent );
        if ( $existing ) return absint( is_array( $existing ) ? $existing['term_id'] : $existing );
        $created = wp_insert_term( $name, $taxonomy, array( 'parent' => $parent ) );
        return is_wp_error( $created ) ? 0 : absint( $created['term_id'] );
    }

    private function find_by_source_url( string $url ): int {
        $ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_echo_source_url', 'value' => $url ) ) ) );
        return $ids ? (int) $ids[0] : 0;
    }

    private function unique_sku( string $sku ): string {
        $base = sanitize_text_field( $sku ); if ( ! $base ) $base = 'PDS-' . wp_generate_password( 8, false, false ); $candidate = $base; $n = 2;
        while ( wc_get_product_id_by_sku( $candidate ) ) { $candidate = $base . '-' . $n; $n++; }
        return $candidate;
    }

    private function sideload_image( string $url, int $post_id, string $name ) {
        require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/image.php';
        $tmp = download_url( $url, 30 ); if ( is_wp_error( $tmp ) ) return $tmp;
        $path = (string) wp_parse_url( $url, PHP_URL_PATH ); $filename = sanitize_file_name( basename( $path ) ?: sanitize_title( $name ) . '.jpg' );
        $file = array( 'name' => $filename, 'tmp_name' => $tmp ); $id = media_handle_sideload( $file, $post_id, $name ); if ( is_wp_error( $id ) ) @unlink( $tmp ); return $id;
    }

    public function export_fitment(): void {
        $this->export_guard( 'echo_pds_export_fitment' );
        $out = fopen( 'php://output', 'w' ); $this->csv_headers( 'pds-fitment-' . gmdate( 'Y-m-d' ) . '.csv' );
        fputcsv( $out, array( 'product_id','product_sku','fitment_type','fitment_raw','confidence','reason','transmission_codes','review_required' ) );
        foreach ( $this->supplier_product_ids() as $id ) { $p = wc_get_product( $id ); fputcsv( $out, array( $id, $p ? $p->get_sku() : '', get_post_meta( $id, '_echo_fitment_type', true ), get_post_meta( $id, '_echo_fitment_raw', true ), get_post_meta( $id, '_echo_fitment_confidence', true ), get_post_meta( $id, '_echo_fitment_reason', true ), get_post_meta( $id, '_echo_transmission_codes', true ), get_post_meta( $id, '_echo_fitment_review_required', true ) ) ); }
        fclose( $out ); exit;
    }

    public function export_products(): void {
        $this->export_guard( 'echo_pds_export_products' );
        $out = fopen( 'php://output', 'w' ); $this->csv_headers( 'pds-products-' . gmdate( 'Y-m-d' ) . '.csv' );
        fputcsv( $out, array( 'product_id','sku','name','price','stock_status','source_url','source_image','supplier' ) );
        foreach ( $this->supplier_product_ids() as $id ) { $p = wc_get_product( $id ); if ( ! $p ) continue; fputcsv( $out, array( $id,$p->get_sku(),$p->get_name(),$p->get_price(),$p->get_stock_status(),get_post_meta( $id, '_echo_source_url', true ),get_post_meta( $id, '_echo_source_image_url', true ),self::SUPPLIER ) ); }
        fclose( $out ); exit;
    }

    private function csv_headers( string $filename ): void { nocache_headers(); header( 'Content-Type: text/csv; charset=utf-8' ); header( 'Content-Disposition: attachment; filename="' . $filename . '"' ); }
    private function export_guard( string $action ): void { if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Permission denied.' ); check_admin_referer( $action ); }
    private function guard( string $action ): void { check_ajax_referer( $action, 'nonce' ); if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( array( 'message' => 'Permission denied.' ) ); }
    private function urls(): array { $v = get_option( self::URLS_OPTION, array() ); return is_array( $v ) ? array_values( array_filter( $v, 'is_string' ) ) : array(); }
    private function empty_build_state( int $total ): array { return array( 'index'=>0,'processed'=>0,'total'=>$total,'created'=>0,'updated'=>0,'failed'=>0,'completed'=>false ); }
    private function build_state( int $total ): array { $s = get_option( self::BUILD_OPTION, array() ); return wp_parse_args( is_array( $s ) ? $s : array(), $this->empty_build_state( $total ) ); }
    private function empty_image_state(): array { return array( 'index'=>0,'processed'=>0,'total'=>0,'downloaded'=>0,'skipped'=>0,'failed'=>0,'completed'=>false ); }
    private function image_state(): array { $s = get_option( self::IMAGE_OPTION, array() ); return wp_parse_args( is_array( $s ) ? $s : array(), $this->empty_image_state() ); }
    private function response( array $state, string $message, array $warnings = array() ): array { $total=max(0,(int)($state['total']??0));$done=max(0,(int)($state['processed']??0));return array('done'=>!empty($state['completed']),'message'=>$message,'warnings'=>$warnings,'progress_pct'=>$total?round(100*$done/$total,1):0,'progress_text'=>$done.' / '.$total.' — created '.(int)($state['created']??0).', updated '.(int)($state['updated']??0).', failed '.(int)($state['failed']??0)); }
    private function supplier_product_ids(): array { global $wpdb; return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID WHERE p.post_type='product' AND pm.meta_key='_echo_supplier' AND pm.meta_value=%s ORDER BY p.ID ASC", self::SUPPLIER ) ) ); }
}
