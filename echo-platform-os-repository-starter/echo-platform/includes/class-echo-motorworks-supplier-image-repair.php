<?php

defined( 'ABSPATH' ) || exit;

/** Resumable missing-image repair for FLF and Applied Torque Solutions. */
final class Echo_Motorworks_Supplier_Image_Repair {
    private const STATE_PREFIX = 'echo_supplier_image_repair_v1_';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'admin_menu' ), 66 );
        add_action( 'wp_ajax_echo_supplier_image_repair', array( $this, 'ajax_repair' ) );
    }

    public function admin_menu(): void {
        add_submenu_page(
            class_exists( 'WooCommerce' ) ? 'woocommerce' : 'tools.php',
            'Supplier Image Repair',
            'Supplier Images',
            'manage_woocommerce',
            'echo-supplier-images',
            array( $this, 'page' )
        );
    }

    public function page(): void {
        $nonce = wp_create_nonce( 'echo_supplier_image_repair' );
        ?>
        <div class="wrap">
            <h1>Supplier Missing-Image Repair</h1>
            <p>Downloads pictures only for products that currently have no featured image. Existing images are never replaced.</p>
            <div class="notice notice-info inline"><p><strong>Safe/resumable:</strong> one product is checked per step. You may stop, close the page, or resume later. FLF uses its saved CSV image URLs first; Applied Torque uses saved source URLs and supplier-page image metadata.</p></div>
            <?php foreach ( $this->suppliers() as $key => $supplier ) : ?>
                <div style="background:#fff;border:1px solid #dcdcde;padding:18px;margin:18px 0;max-width:980px">
                    <h2><?php echo esc_html( $supplier['label'] ); ?></h2>
                    <p><button class="button button-primary echo-image-start" data-supplier="<?php echo esc_attr( $key ); ?>">Download / Resume Missing Images</button>
                    <button class="button echo-image-restart" data-supplier="<?php echo esc_attr( $key ); ?>">Restart Scan</button>
                    <button class="button echo-image-stop" disabled>Stop</button></p>
                    <div class="echo-image-progress" style="display:none">
                        <div style="height:18px;background:#dcdcde;border-radius:4px;overflow:hidden"><div class="echo-image-bar" style="height:100%;width:0;background:#2271b1"></div></div>
                        <p class="echo-image-text" style="font-weight:600"></p>
                        <textarea class="echo-image-log" readonly style="width:100%;min-height:150px;font-family:monospace"></textarea>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <script>
        jQuery(function($){
            const nonce=<?php echo wp_json_encode( $nonce ); ?>; let stopped=false,running=false,retries=0,$box;
            function append(m){const $l=$box.find('.echo-image-log');$l.val($l.val()+m+'\n');$l.scrollTop($l[0].scrollHeight);}
            function finish(m){running=false;$box.find('.echo-image-start,.echo-image-restart').prop('disabled',false);$box.find('.echo-image-stop').prop('disabled',true);$box.find('.echo-image-text').text(m);append(m);}
            function step(supplier,reset){
                if(stopped){finish('Stopped. Progress was saved.');return;}
                $.ajax({url:ajaxurl,method:'POST',timeout:60000,data:{action:'echo_supplier_image_repair',nonce:nonce,supplier:supplier,reset:reset?1:0}})
                .done(function(r){
                    if(!r||!r.success){const m=r&&r.data&&r.data.message?r.data.message:'WordPress returned an error.';if(retries++<3){append(m+' Retrying…');setTimeout(()=>step(supplier,false),2500*retries);}else finish('Paused after repeated errors. Click Resume.');return;}
                    retries=0;const d=r.data;$box.find('.echo-image-bar').css('width',(d.progress_pct||0)+'%');$box.find('.echo-image-text').text(d.progress_text||'Working…');append(d.message||'');
                    if(d.done)finish('Missing-image scan complete.');else setTimeout(()=>step(supplier,false),350);
                }).fail(function(x){if(retries++<3){append('HTTP '+(x.status||'error')+'. Retrying…');setTimeout(()=>step(supplier,false),2500*retries);}else finish('Paused after repeated HTTP errors. Click Resume.');});
            }
            $('.echo-image-start,.echo-image-restart').on('click',function(){if(running)return;running=true;stopped=false;retries=0;$box=$(this).closest('div[style*="background"]');const supplier=$(this).data('supplier'),reset=$(this).hasClass('echo-image-restart');$box.find('.echo-image-progress').show();$box.find('.echo-image-start,.echo-image-restart').prop('disabled',true);$box.find('.echo-image-stop').prop('disabled',false);if(reset){$box.find('.echo-image-log').val('');append('Restarting missing-image scan…');}else append('Starting or resuming missing-image scan…');step(supplier,reset);});
            $('.echo-image-stop').on('click',function(){stopped=true;$(this).prop('disabled',true);});
        });
        </script>
        <?php
    }

    public function ajax_repair(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
        check_ajax_referer( 'echo_supplier_image_repair', 'nonce' );
        $supplier_key = sanitize_key( (string) ( $_POST['supplier'] ?? '' ) );
        $suppliers = $this->suppliers();
        if ( ! isset( $suppliers[ $supplier_key ] ) ) wp_send_json_error( array( 'message' => 'Unknown supplier.' ), 400 );
        if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 55 );

        $option = self::STATE_PREFIX . $supplier_key;
        $reset = ! empty( $_POST['reset'] );
        $state = $reset ? array() : get_option( $option, array() );
        if ( empty( $state['ids'] ) || ! is_array( $state['ids'] ) ) {
            $state = array( 'ids' => $this->matching_product_ids( $suppliers[ $supplier_key ] ), 'index' => 0, 'downloaded' => 0, 'skipped' => 0, 'failed' => 0 );
        }
        $total = count( $state['ids'] );
        if ( $state['index'] >= $total ) {
            delete_option( $option );
            wp_send_json_success( $this->response( $state, $total, 'All matching products have been checked.', true ) );
        }

        $product_id = (int) $state['ids'][ $state['index'] ];
        $state['index']++;
        $product = wc_get_product( $product_id );
        $message = '#' . $product_id . ': ';
        if ( ! $product ) {
            $state['skipped']++; $message .= 'product no longer exists.';
        } elseif ( get_post_thumbnail_id( $product_id ) ) {
            $state['skipped']++; $message .= $product->get_name() . ' already has an image; skipped.';
        } else {
            $image_url = $this->find_image_url( $product_id, $supplier_key );
            if ( ! $image_url ) {
                $state['failed']++; $message .= $product->get_name() . ' — no usable supplier image URL was found.';
            } else {
                $attachment = $this->sideload( $image_url, $product_id, $product->get_name() );
                if ( is_wp_error( $attachment ) ) {
                    $state['failed']++; $message .= $product->get_name() . ' — image download failed: ' . $attachment->get_error_message();
                } else {
                    set_post_thumbnail( $product_id, (int) $attachment );
                    update_post_meta( $product_id, '_echo_image_repair_source', esc_url_raw( $image_url ) );
                    $state['downloaded']++; $message .= $product->get_name() . ' — image added.';
                }
            }
        }
        update_option( $option, $state, false );
        $done = $state['index'] >= $total;
        if ( $done ) delete_option( $option );
        wp_send_json_success( $this->response( $state, $total, $message, $done ) );
    }

    private function suppliers(): array {
        return array(
            'flf' => array( 'label' => 'FLF Racing Supply', 'prefixes' => array( 'FLF-' ), 'terms' => array( 'FLF Racing Supply', 'Finish Line Factory', 'finishlinefactory.com' ) ),
            'ats' => array( 'label' => 'Applied Torque Solutions', 'prefixes' => array( 'ATS-' ), 'terms' => array( 'Applied Torque Solutions', 'appliedtorquesolutions.com', 'applied-torque-solutions.com' ) ),
        );
    }

    private function matching_product_ids( array $supplier ): array {
        $ids = wc_get_products( array( 'status' => array( 'publish','draft','private' ), 'limit' => -1, 'return' => 'ids' ) );
        return array_values( array_filter( array_map( 'intval', $ids ), function( int $id ) use ( $supplier ): bool {
            $sku = (string) get_post_meta( $id, '_sku', true );
            foreach ( $supplier['prefixes'] as $prefix ) if ( 0 === stripos( $sku, $prefix ) ) return true;
            $haystack = strtolower( implode( ' ', array_filter( array(
                get_post_meta( $id, '_echo_supplier', true ), get_post_meta( $id, '_echo_brand', true ),
                get_post_meta( $id, '_echo_source_url', true ), get_post_meta( $id, '_product_url', true ),
                get_post_field( 'post_title', $id ), get_post_field( 'post_content', $id )
            ) ) ) );
            foreach ( $supplier['terms'] as $term ) if ( false !== strpos( $haystack, strtolower( $term ) ) ) return true;
            return false;
        } ) );
    }

    private function find_image_url( int $product_id, string $supplier ): string {
        $keys = array( '_echo_source_image_url','_echo_image_url','_source_image_url','image_url','Images','_thumbnail_external_url' );
        foreach ( $keys as $key ) {
            $url = $this->first_image_url( (string) get_post_meta( $product_id, $key, true ) );
            if ( $url ) return $url;
        }
        $all = get_post_meta( $product_id );
        foreach ( $all as $key => $values ) {
            if ( false === stripos( $key, 'image' ) && false === stripos( $key, 'photo' ) ) continue;
            foreach ( (array) $values as $value ) { $url = $this->first_image_url( (string) maybe_unserialize( $value ) ); if ( $url ) return $url; }
        }
        $source = $this->source_url( $product_id );
        return $source ? $this->image_from_page( $source ) : '';
    }

    private function source_url( int $product_id ): string {
        foreach ( array( '_echo_source_url','_source_url','source_url','_product_url','product_url','_echo_supplier_url','external_url' ) as $key ) {
            $url = esc_url_raw( (string) get_post_meta( $product_id, $key, true ) ); if ( $url ) return $url;
        }
        $content = (string) get_post_field( 'post_content', $product_id );
        if ( preg_match( '~https?://[^\s"\'<>]+~i', $content, $m ) ) return esc_url_raw( html_entity_decode( $m[0] ) );
        return '';
    }

    private function image_from_page( string $url ): string {
        $response = wp_safe_remote_get( $url, array( 'timeout' => 20, 'redirection' => 5, 'user-agent' => 'Mozilla/5.0 EchoMotorworks/1.0' ) );
        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) return '';
        $html = (string) wp_remote_retrieve_body( $response );
        $patterns = array(
            '~<meta[^>]+property=["\']og:image(?::secure_url)?["\'][^>]+content=["\']([^"\']+)~i',
            '~<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image(?::secure_url)?["\']~i',
            '~<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)~i',
            '~"image"\s*:\s*"(https?:\\?/\\?/[^"\\]+)"~i'
        );
        foreach ( $patterns as $pattern ) if ( preg_match( $pattern, $html, $m ) ) {
            $candidate = html_entity_decode( str_replace( '\\/', '/', $m[1] ) );
            if ( $this->is_image_url( $candidate ) ) return esc_url_raw( $candidate );
        }
        return '';
    }

    private function first_image_url( string $value ): string {
        if ( ! $value ) return '';
        $value = html_entity_decode( $value );
        if ( preg_match_all( '~https?://[^\s,|"\'<>]+~i', $value, $m ) ) foreach ( $m[0] as $url ) if ( $this->is_image_url( $url ) ) return esc_url_raw( $url );
        return '';
    }

    private function is_image_url( string $url ): bool {
        if ( ! wp_http_validate_url( $url ) ) return false;
        return ! preg_match( '~(?:logo|favicon|icon|avatar|placeholder|spacer)~i', $url );
    }

    private function sideload( string $url, int $product_id, string $description ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        return media_sideload_image( esc_url_raw( $url ), $product_id, sanitize_text_field( $description ), 'id' );
    }

    private function response( array $state, int $total, string $message, bool $done ): array {
        $processed = min( (int) $state['index'], $total );
        return array(
            'done' => $done,
            'progress_pct' => $total ? round( 100 * $processed / $total, 1 ) : 100,
            'progress_text' => sprintf( '%d / %d checked — %d added, %d skipped, %d unresolved', $processed, $total, (int)$state['downloaded'], (int)$state['skipped'], (int)$state['failed'] ),
            'message' => $message,
        );
    }
}
