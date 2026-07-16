<?php

defined( 'ABSPATH' ) || exit;

/**
 * Unified supplier health dashboard and live missing-image repair.
 * Existing supplier builders remain intact; this class provides one safe control center.
 */
final class Echo_Motorworks_Supplier_Engine {
    private const STATE_PREFIX = 'echo_supplier_engine_image_v3_';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'admin_menu' ), 64 );
        add_action( 'wp_ajax_echo_supplier_engine_health', array( $this, 'ajax_health' ) );
        add_action( 'wp_ajax_echo_supplier_engine_images', array( $this, 'ajax_images' ) );
    }

    public function admin_menu(): void {
        add_submenu_page(
            class_exists( 'WooCommerce' ) ? 'woocommerce' : 'tools.php',
            'Echo Supplier Engine',
            'Echo Supplier Engine',
            'manage_woocommerce',
            'echo-supplier-engine',
            array( $this, 'page' )
        );
    }

    public function page(): void {
        $nonce = wp_create_nonce( 'echo_supplier_engine' );
        ?>
        <div class="wrap echo-engine-wrap">
            <h1>Echo Supplier Engine</h1>
            <p>One dashboard for supplier health, missing images, existing catalog builders, and fitment tools.</p>
            <div class="notice notice-info inline"><p><strong>Safe to test:</strong> image repair only touches products that have no featured image. It processes one product at a time and saves progress after every step.</p></div>

            <p><button type="button" class="button button-primary" id="echo-refresh-health">Refresh supplier health</button></p>
            <div id="echo-engine-grid" class="echo-engine-grid">
                <?php foreach ( $this->suppliers() as $key => $supplier ) : ?>
                    <section class="echo-engine-card" data-supplier="<?php echo esc_attr( $key ); ?>">
                        <h2><?php echo esc_html( $supplier['label'] ); ?></h2>
                        <div class="echo-health">Loading health…</div>
                        <div class="echo-actions">
                            <button class="button button-primary echo-live-images" data-supplier="<?php echo esc_attr( $key ); ?>">Repair Missing Images</button>
                            <button class="button echo-restart-images" data-supplier="<?php echo esc_attr( $key ); ?>">Restart Image Scan</button>
                            <?php if ( ! empty( $supplier['admin_url'] ) ) : ?>
                                <a class="button" href="<?php echo esc_url( admin_url( $supplier['admin_url'] ) ); ?>">Open Existing Tool</a>
                            <?php endif; ?>
                            <button class="button echo-stop-images" disabled>Stop</button>
                        </div>
                        <div class="echo-progress" hidden>
                            <div class="echo-track"><div class="echo-bar"></div></div>
                            <p class="echo-progress-text"></p>
                            <textarea class="echo-log" readonly></textarea>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>
        <style>
            .echo-engine-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:18px;max-width:1400px}
            .echo-engine-card{background:#fff;border:1px solid #dcdcde;border-top:4px solid #d63638;padding:18px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
            .echo-engine-card h2{margin-top:0}.echo-health{min-height:86px;margin:12px 0;padding:12px;background:#f6f7f7}
            .echo-health-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;text-align:center}.echo-health-grid strong{display:block;font-size:22px}
            .echo-actions{display:flex;gap:8px;flex-wrap:wrap}.echo-track{height:18px;background:#dcdcde;border-radius:4px;overflow:hidden;margin-top:14px}.echo-bar{height:100%;width:0;background:#2271b1}
            .echo-log{width:100%;min-height:145px;font-family:monospace;margin-top:8px}.echo-progress-text{font-weight:600}
        </style>
        <script>
        jQuery(function($){
            const nonce=<?php echo wp_json_encode( $nonce ); ?>; const runs={};
            function health(){
                $('#echo-refresh-health').prop('disabled',true).text('Refreshing…');
                $.post(ajaxurl,{action:'echo_supplier_engine_health',nonce}).done(function(r){
                    if(!r||!r.success)return;
                    Object.keys(r.data).forEach(function(key){const d=r.data[key],$c=$('.echo-engine-card[data-supplier="'+key+'"]');
                        $c.find('.echo-health').html('<div class="echo-health-grid"><span><strong>'+d.total+'</strong>products</span><span><strong>'+d.with_images+'</strong>with images</span><span><strong>'+d.missing_images+'</strong>missing</span></div><p><strong>Image coverage:</strong> '+d.coverage+'%</p>');
                    });
                }).always(()=>$('#echo-refresh-health').prop('disabled',false).text('Refresh supplier health'));
            }
            function append($c,m){const $l=$c.find('.echo-log');$l.val($l.val()+m+'\n');$l.scrollTop($l[0].scrollHeight);}
            function finish($c,key,m){runs[key]=false;$c.find('.echo-live-images,.echo-restart-images').prop('disabled',false);$c.find('.echo-stop-images').prop('disabled',true);$c.find('.echo-progress-text').text(m);append($c,m);health();}
            function step($c,key,reset,retries){
                if(runs[key]==='stop'){finish($c,key,'Stopped. Progress was saved.');return;}
                $.ajax({url:ajaxurl,method:'POST',timeout:70000,data:{action:'echo_supplier_engine_images',nonce,supplier:key,reset:reset?1:0}})
                .done(function(r){
                    if(!r||!r.success){let m=r&&r.data&&r.data.message?r.data.message:'WordPress returned an error.';if(retries<3){append($c,m+' Retrying…');setTimeout(()=>step($c,key,false,retries+1),2500*(retries+1));}else finish($c,key,'Paused after repeated errors. Click Repair Missing Images to resume.');return;}
                    const d=r.data;$c.find('.echo-bar').css('width',(d.progress_pct||0)+'%');$c.find('.echo-progress-text').text(d.progress_text||'Working…');append($c,d.message||'');
                    if(d.done)finish($c,key,'Missing-image scan complete.');else setTimeout(()=>step($c,key,false,0),350);
                }).fail(function(x){if(retries<3){append($c,'HTTP '+(x.status||'error')+'. Retrying…');setTimeout(()=>step($c,key,false,retries+1),2500*(retries+1));}else finish($c,key,'Paused after repeated HTTP errors. Click Repair Missing Images to resume.');});
            }
            $('.echo-live-images,.echo-restart-images').on('click',function(){const key=$(this).data('supplier'),$c=$(this).closest('.echo-engine-card'),reset=$(this).hasClass('echo-restart-images');if(runs[key])return;runs[key]=true;$c.find('.echo-progress').prop('hidden',false);$c.find('.echo-live-images,.echo-restart-images').prop('disabled',true);$c.find('.echo-stop-images').prop('disabled',false);if(reset)$c.find('.echo-log').val('');append($c,reset?'Restarting live image scan…':'Starting or resuming live image scan…');step($c,key,reset,0);});
            $('.echo-stop-images').on('click',function(){const key=$(this).closest('.echo-engine-card').data('supplier');runs[key]='stop';$(this).prop('disabled',true);});
            $('#echo-refresh-health').on('click',health);health();
        });
        </script>
        <?php
    }

    public function ajax_health(): void {
        $this->guard();
        $result = array();
        foreach ( $this->suppliers() as $key => $supplier ) {
            $ids = $this->matching_product_ids( $supplier );
            $with = 0;
            foreach ( $ids as $id ) if ( get_post_thumbnail_id( $id ) ) $with++;
            $total = count( $ids );
            $result[ $key ] = array(
                'total' => $total,
                'with_images' => $with,
                'missing_images' => max( 0, $total - $with ),
                'coverage' => $total ? round( 100 * $with / $total, 1 ) : 100,
            );
        }
        wp_send_json_success( $result );
    }

    public function ajax_images(): void {
        $this->guard();
        if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 60 );
        $key = sanitize_key( (string) ( $_POST['supplier'] ?? '' ) );
        $suppliers = $this->suppliers();
        if ( ! isset( $suppliers[ $key ] ) ) wp_send_json_error( array( 'message' => 'Unknown supplier.' ), 400 );
        $option = self::STATE_PREFIX . $key;
        $state = ! empty( $_POST['reset'] ) ? array() : get_option( $option, array() );
        if ( empty( $state['ids'] ) || ! is_array( $state['ids'] ) ) {
            $state = array( 'ids' => $this->matching_product_ids( $suppliers[ $key ], true ), 'index' => 0, 'downloaded' => 0, 'skipped' => 0, 'failed' => 0 );
        }
        $total = count( $state['ids'] );
        if ( $state['index'] >= $total ) {
            delete_option( $option );
            wp_send_json_success( $this->response( $state, $total, 'All missing-image products have been checked.', true ) );
        }
        $id = (int) $state['ids'][ $state['index']++ ];
        $product = wc_get_product( $id );
        $message = '#' . $id . ': ';
        if ( ! $product ) { $state['skipped']++; $message .= 'product no longer exists.'; }
        elseif ( get_post_thumbnail_id( $id ) ) { $state['skipped']++; $message .= $product->get_name() . ' already has an image; skipped.'; }
        else {
            $found = $this->find_live_image( $id, $product, $suppliers[ $key ] );
            if ( empty( $found['image'] ) ) { $state['failed']++; $message .= $product->get_name() . ' — no reliable live supplier image found.'; }
            else {
                $attachment = $this->sideload( $found['image'], $id, $product->get_name() );
                if ( is_wp_error( $attachment ) ) { $state['failed']++; $message .= $product->get_name() . ' — download failed: ' . $attachment->get_error_message(); }
                else {
                    set_post_thumbnail( $id, (int) $attachment );
                    update_post_meta( $id, '_echo_image_repair_source', esc_url_raw( $found['image'] ) );
                    if ( ! empty( $found['page'] ) ) update_post_meta( $id, '_echo_source_url', esc_url_raw( $found['page'] ) );
                    $state['downloaded']++; $message .= $product->get_name() . ' — image added from live supplier page.';
                }
            }
        }
        update_option( $option, $state, false );
        $done = $state['index'] >= $total;
        if ( $done ) delete_option( $option );
        wp_send_json_success( $this->response( $state, $total, $message, $done ) );
    }

    private function guard(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
        check_ajax_referer( 'echo_supplier_engine', 'nonce' );
        if ( ! function_exists( 'wc_get_products' ) ) wp_send_json_error( array( 'message' => 'WooCommerce is not active.' ), 400 );
    }

    private function suppliers(): array {
        return array(
            'mabotech' => array( 'adapter'=>'woocommerce','label'=>'Mabotech','prefixes'=>array('MAB-'),'terms'=>array('Mabotech','mabotech.net'),'domains'=>array('mabotech.net'),'search'=>array('https://mabotech.net/?s=%s&post_type=product'),'admin_url'=>'admin.php?page=echo-mabotech-builder' ),
            'leistune' => array( 'adapter'=>'woocommerce','label'=>'Leistune','prefixes'=>array('LEI-'),'terms'=>array('Leistune','leistune.com'),'domains'=>array('leistune.com'),'search'=>array('https://leistune.com/?s=%s&post_type=product'),'admin_url'=>'admin.php?page=echo-leistune-builder' ),
            'eldoc' => array( 'adapter'=>'woocommerce','label'=>'El Doc Solutions','prefixes'=>array('ELD-'),'terms'=>array('El Doc Solutions','eldocsolutions.com'),'domains'=>array('eldocsolutions.com'),'search'=>array('https://eldocsolutions.com/?s=%s&post_type=product'),'admin_url'=>'admin.php?page=echo-eldoc-builder' ),
            'flf' => array( 'adapter'=>'flf','label'=>'FLF Racing Supply','prefixes'=>array('FLF-'),'terms'=>array('FLF Racing Supply','Finish Line Factory','finishlinefactory.com'),'domains'=>array('finishlinefactory.com'),'search'=>array('https://www.finishlinefactory.com/?s=%s&post_type=product','https://www.finishlinefactory.com/search?q=%s'),'admin_url'=>'admin.php?page=echo-flf-builder' ),
            'ats' => array( 'adapter'=>'ats','label'=>'Applied Torque Solutions','prefixes'=>array('ATS-'),'terms'=>array('Applied Torque Solutions','appliedtorquesolutions.com'),'domains'=>array('appliedtorquesolutions.com'),'search'=>array('https://appliedtorquesolutions.com/?s=%s&post_type=product','https://appliedtorquesolutions.com/search?q=%s'),'admin_url'=>'' ),
            'evilenergy' => array( 'adapter'=>'evilenergy','label'=>'EVIL ENERGY','prefixes'=>array('EVE-','EVIL-'),'terms'=>array('EVIL ENERGY','ievilenergy.com'),'domains'=>array('ievilenergy.com'),'search'=>array('https://www.ievilenergy.com/search?q=%s'),'admin_url'=>'admin.php?page=echo-evilenergy-builder' ),
            'pds' => array( 'adapter'=>'pds','label'=>'Pure Drivetrain Solutions','prefixes'=>array('PDS-'),'terms'=>array('Pure Drivetrain Solutions','puredrivetrainsolutions.com'),'domains'=>array('puredrivetrainsolutions.com'),'search'=>array('https://www.puredrivetrainsolutions.com/search?q=%s'),'admin_url'=>'admin.php?page=echo-pds-builder' ),
        );
    }

    private function matching_product_ids( array $supplier, bool $missing_only = false ): array {
        $ids = wc_get_products( array( 'status'=>array('publish','draft','private'),'limit'=>-1,'return'=>'ids' ) );
        return array_values( array_filter( array_map( 'intval', $ids ), function( int $id ) use ( $supplier, $missing_only ): bool {
            if ( $missing_only && get_post_thumbnail_id( $id ) ) return false;
            $sku = (string) get_post_meta( $id, '_sku', true );
            foreach ( $supplier['prefixes'] as $prefix ) if ( 0 === stripos( $sku, $prefix ) ) return true;
            $haystack = strtolower( implode( ' ', array_filter( array(
                get_post_meta( $id, '_echo_supplier', true ), get_post_meta( $id, '_echo_brand', true ), get_post_meta( $id, '_echo_source_url', true ), get_post_meta( $id, '_product_url', true ), get_post_field( 'post_title', $id )
            ) ) ) );
            foreach ( $supplier['terms'] as $term ) if ( false !== strpos( $haystack, strtolower( $term ) ) ) return true;
            return false;
        } ) );
    }

    private function find_live_image( int $id, $product, array $supplier ): array {
        foreach ( array('_echo_source_image_url','_echo_image_url','_source_image_url','image_url','Images','_thumbnail_external_url') as $meta ) {
            $image = $this->first_url( (string) get_post_meta( $id, $meta, true ) );
            if ( $image && $this->remote_is_image( $image ) ) return array('image'=>$image,'page'=>'');
        }
        $source = $this->source_url( $id, $supplier );
        if ( $source ) {
            $image = $this->extract_image_from_page( $source, (string) ($supplier['adapter'] ?? 'generic') );
            if ( $image ) return array('image'=>$image,'page'=>$source);
        }
        $query = $this->search_query( $product );
        foreach ( $supplier['search'] as $pattern ) {
            $search_url = sprintf( $pattern, rawurlencode( $query ) );
            $page = $this->find_product_page_from_search( $search_url, $supplier['domains'], $product );
            if ( $page ) {
                $image = $this->extract_image_from_page( $page, (string) ($supplier['adapter'] ?? 'generic') );
                if ( $image ) return array('image'=>$image,'page'=>$page);
            }
        }
        return array('image'=>'','page'=>'');
    }

    private function source_url( int $id, array $supplier ): string {
        foreach ( array('_echo_source_url','_source_url','source_url','_product_url','product_url','_echo_supplier_url','external_url') as $key ) {
            $url = esc_url_raw( (string) get_post_meta( $id, $key, true ) );
            if ( $url && $this->allowed_domain( $url, $supplier['domains'] ) ) return $url;
        }
        return '';
    }

    private function search_query( $product ): string {
        $sku = trim( (string) $product->get_sku() );
        $sku = preg_replace( '/^(?:FLF|ATS|MAB|LEI|ELD|EVE|EVIL|PDS)-/i', '', $sku );
        return trim( $sku ? $sku . ' ' . $product->get_name() : $product->get_name() );
    }

    private function find_product_page_from_search( string $url, array $domains, $product ): string {
        $html = $this->get_html( $url ); if ( ! $html ) return '';
        $candidates = array();
        if ( preg_match_all( '~href=["\']([^"\']+)["\']~i', $html, $m ) ) {
            foreach ( $m[1] as $href ) {
                $href = html_entity_decode( $href );
                if ( 0 === strpos( $href, '/' ) ) { $parts = wp_parse_url( $url ); $href = ($parts['scheme']??'https').'://'.($parts['host']??'').$href; }
                if ( ! wp_http_validate_url( $href ) || ! $this->allowed_domain( $href, $domains ) ) continue;
                if ( preg_match( '~(?:/product/|/products/|/shop/)~i', $href ) ) $candidates[] = $href;
            }
        }
        $name_tokens = array_values( array_filter( preg_split( '/[^a-z0-9]+/i', strtolower( $product->get_name() ) ), fn($v)=>strlen($v)>3 ) );
        $best = '';$best_score=0;
        foreach ( array_unique( $candidates ) as $candidate ) {
            $slug = strtolower( wp_parse_url( $candidate, PHP_URL_PATH ) ?: '' );$score=0;
            foreach ( array_slice( $name_tokens, 0, 8 ) as $token ) if ( false !== strpos( $slug, $token ) ) $score++;
            if ( $score > $best_score ) { $best=$candidate;$best_score=$score; }
        }
        return $best_score >= 1 ? esc_url_raw( $best ) : '';
    }

    private function extract_image_from_page( string $url, string $adapter = 'generic' ): string {
        $html = $this->get_html( $url );
        if ( ! $html ) return '';

        $patterns = array();
        if ( 'ats' === $adapter ) {
            $patterns = array(
                '~<img[^>]+(?:class=["\'][^"\']*(?:product-gallery|woocommerce-product-gallery|wp-post-image)[^"\']*["\'])[^>]+(?:data-large_image|data-src|src)=["\']([^"\']+)~i',
                '~<a[^>]+(?:class=["\'][^"\']*(?:woocommerce-product-gallery__image|product-gallery)[^"\']*["\'])[^>]+href=["\']([^"\']+)~i',
            );
        } elseif ( 'flf' === $adapter ) {
            $patterns = array(
                '~<img[^>]+(?:class=["\'][^"\']*(?:product__media|product-gallery|wp-post-image)[^"\']*["\'])[^>]+(?:data-zoom|data-src|src)=["\']([^"\']+)~i',
                '~<source[^>]+srcset=["\']([^"\']+)~i',
            );
        } elseif ( in_array( $adapter, array( 'evilenergy', 'pds' ), true ) ) {
            $patterns = array(
                '~<img[^>]+(?:class=["\'][^"\']*(?:product|gallery|main-image)[^"\']*["\'])[^>]+(?:data-src|data-original|src)=["\']([^"\']+)~i',
                '~"featured_image"\s*:\s*"(https?:\\?/\\?/[^"\\]+)"~i',
            );
        }
        $patterns = array_merge( $patterns, array(
            '~<meta[^>]+property=["\']og:image(?::secure_url)?["\'][^>]+content=["\']([^"\']+)~i',
            '~<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image(?::secure_url)?["\']~i',
            '~<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)~i',
            '~<img[^>]+(?:class=["\'][^"\']*(?:wp-post-image|product|gallery)[^"\']*["\'])[^>]+(?:data-large_image|data-src|src)=["\']([^"\']+)~i',
            '~"image"\s*:\s*(?:\[\s*)?"(https?:\\?/\\?/[^"\\]+)"~i'
        ) );

        foreach ( $patterns as $pattern ) {
            if ( ! preg_match( $pattern, $html, $m ) ) continue;
            $candidate = html_entity_decode( str_replace( '\\/', '/', $m[1] ) );
            if ( false !== strpos( $candidate, ',' ) ) $candidate = trim( preg_split( '/\s*,\s*/', $candidate )[0] );
            $candidate = preg_replace( '/\s+\d+(?:w|x)$/', '', $candidate );
            if ( 0 === strpos( $candidate, '//' ) ) $candidate = 'https:' . $candidate;
            if ( 0 === strpos( $candidate, '/' ) ) {
                $parts = wp_parse_url( $url );
                $candidate = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . $candidate;
            }
            if ( $this->remote_is_image( $candidate ) ) return esc_url_raw( $candidate );
        }
        return '';
    }

    private function get_html( string $url ): string {
        $response = wp_safe_remote_get( $url, array('timeout'=>22,'redirection'=>5,'headers'=>array('Accept'=>'text/html,application/xhtml+xml'),'user-agent'=>'Mozilla/5.0 (compatible; EchoMotorworksCatalog/1.0; +https://echomotorworks.com/)') );
        if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) < 200 || (int) wp_remote_retrieve_response_code( $response ) >= 400 ) return '';
        return (string) wp_remote_retrieve_body( $response );
    }

    private function remote_is_image( string $url ): bool {
        if ( ! wp_http_validate_url( $url ) || preg_match( '~(?:logo|favicon|icon|avatar|placeholder|spacer|sprite)~i', $url ) ) return false;
        $response = wp_safe_remote_head( $url, array('timeout'=>12,'redirection'=>5,'user-agent'=>'Mozilla/5.0 EchoMotorworks/1.0') );
        if ( is_wp_error( $response ) ) return (bool) preg_match( '~\.(?:jpe?g|png|webp|gif)(?:\?|$)~i', $url );
        $code=(int)wp_remote_retrieve_response_code($response);$type=strtolower((string)wp_remote_retrieve_header($response,'content-type'));
        return $code>=200 && $code<400 && (0===strpos($type,'image/') || preg_match('~\.(?:jpe?g|png|webp|gif)(?:\?|$)~i',$url));
    }

    private function first_url( string $value ): string {
        if ( preg_match_all( '~https?://[^\s,|"\'<>]+~i', html_entity_decode( $value ), $m ) ) foreach ( $m[0] as $url ) if ( wp_http_validate_url( $url ) ) return esc_url_raw( $url );
        return '';
    }

    private function allowed_domain( string $url, array $domains ): bool {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        foreach ( $domains as $domain ) if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) return true;
        return false;
    }

    private function sideload( string $url, int $id, string $description ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment = media_sideload_image( esc_url_raw( $url ), $id, sanitize_text_field( $description ), 'id' );
        if ( ! is_wp_error( $attachment ) ) return $attachment;

        // Some supplier CDNs omit a filename extension or send unusual headers.
        $tmp = download_url( esc_url_raw( $url ), 30 );
        if ( is_wp_error( $tmp ) ) return $attachment;
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $name = sanitize_file_name( basename( $path ) );
        if ( ! preg_match( '/\.(?:jpe?g|png|webp|gif)$/i', $name ) ) $name = 'echo-product-' . $id . '.jpg';
        $file = array( 'name' => $name, 'tmp_name' => $tmp );
        $second = media_handle_sideload( $file, $id, sanitize_text_field( $description ) );
        if ( is_wp_error( $second ) ) @unlink( $tmp );
        return $second;
    }

    private function response( array $state, int $total, string $message, bool $done ): array {
        $processed=min((int)$state['index'],$total);
        return array('done'=>$done,'progress_pct'=>$total?round(100*$processed/$total,1):100,'progress_text'=>sprintf('%d / %d checked — %d added, %d skipped, %d unresolved',$processed,$total,(int)$state['downloaded'],(int)$state['skipped'],(int)$state['failed']),'message'=>$message);
    }
}
