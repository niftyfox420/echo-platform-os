<?php

defined( 'ABSPATH' ) || exit;

/**
 * Supplier brand repair for Echo Motorworks.
 *
 * Version 0.5.0 intentionally does not change the storefront layout, WooCommerce
 * templates, product card CSS, prices, stock, images, descriptions, fitment or
 * categories. It only links products to the active WooCommerce brand taxonomy.
 */
final class Echo_Motorworks_Supplier_Brand_Repair {
    private const STATE_OPTION_PREFIX = 'echo_supplier_brand_single_state_';
    private const BATCH_SIZE = 50;

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'admin_menu' ), 68 );
        add_action( 'wp_ajax_echo_repair_one_supplier_brand', array( $this, 'ajax_repair_one' ) );
        add_action( 'wp_ajax_echo_clean_failed_storefront_fixes', array( $this, 'ajax_clean_storefront' ) );
        add_action( 'woocommerce_new_product', array( $this, 'maybe_assign_saved_product' ), 30, 1 );
        add_action( 'woocommerce_update_product', array( $this, 'maybe_assign_saved_product' ), 30, 1 );
    }

    public function admin_menu(): void {
        $parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'tools.php';
        add_submenu_page(
            $parent,
            'Supplier Brand Repair',
            'Supplier Brand Repair',
            'manage_woocommerce',
            'echo-supplier-brand-repair',
            array( $this, 'page' )
        );
    }

    public function page(): void {
        $taxonomies = $this->brand_taxonomies();
        $suppliers = $this->suppliers();
        ?>
        <div class="wrap">
            <h1>Supplier Brand Repair</h1>
            <p><strong>Clean mode:</strong> this page only repairs supplier-to-brand links. It does not load storefront CSS, replace templates, change product categories, rebuild products or contact supplier websites.</p>

            <?php if ( empty( $taxonomies ) ) : ?>
                <div class="notice notice-error inline"><p><strong>No WooCommerce brand taxonomy was detected.</strong> The repair cannot run until a Brands feature/plugin is active.</p></div>
            <?php else : ?>
                <div class="notice notice-success inline"><p><strong>Detected brand taxonomy:</strong> <code><?php echo esc_html( implode( ', ', $taxonomies ) ); ?></code></p></div>
            <?php endif; ?>

            <p>
                <button type="button" class="button" id="echo-clean-storefront-fixes">Clean removed storefront experiments</button>
                <span id="echo-clean-storefront-result" style="margin-left:10px"></span>
            </p>
            <p><em>This cleanup deletes the old failed repair progress options and purges caches. It does not touch products.</em></p>

            <table class="widefat striped" style="max-width:1120px;margin:18px 0">
                <thead><tr><th>Supplier</th><th>Brand term</th><th>Matched products found locally</th><th>Products currently linked to brand</th><th>Run separately</th></tr></thead>
                <tbody>
                <?php foreach ( $suppliers as $key => $supplier ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $supplier['label'] ); ?></strong></td>
                        <td><?php echo esc_html( $supplier['brand'] ); ?></td>
                        <td><?php echo esc_html( (string) $this->count_matching_products( $key ) ); ?></td>
                        <td><?php echo esc_html( (string) $this->current_brand_count( $supplier['brand'] ) ); ?></td>
                        <td><button type="button" class="button button-primary echo-repair-one-supplier" data-supplier="<?php echo esc_attr( $key ); ?>" <?php disabled( empty( $taxonomies ) ); ?>>Repair <?php echo esc_html( $supplier['brand'] ); ?></button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div id="echo-brand-progress" style="max-width:1120px;display:none">
                <div style="height:18px;background:#dcdcde;border-radius:4px;overflow:hidden"><div id="echo-brand-bar" style="height:100%;width:0;background:#2271b1;transition:width .2s"></div></div>
                <p id="echo-brand-text" style="font-weight:600"></p>
                <textarea id="echo-brand-log" readonly style="width:100%;min-height:260px;font-family:monospace"></textarea>
            </div>

            <script>
            jQuery(function($){
                const nonce = <?php echo wp_json_encode( wp_create_nonce( 'echo_repair_one_supplier_brand' ) ); ?>;
                const cleanNonce = <?php echo wp_json_encode( wp_create_nonce( 'echo_clean_failed_storefront_fixes' ) ); ?>;
                let running=false, supplier='';
                const $buttons=$('.echo-repair-one-supplier'), $wrap=$('#echo-brand-progress'), $bar=$('#echo-brand-bar'), $text=$('#echo-brand-text'), $log=$('#echo-brand-log');
                function append(m){ if(!m) return; $log.val($log.val()+m+'\n'); $log.scrollTop($log[0].scrollHeight); }
                function finish(m){ running=false; $buttons.prop('disabled', false); $text.text(m); append(m); }
                function step(reset){
                    $.ajax({url:ajaxurl,method:'POST',timeout:45000,data:{action:'echo_repair_one_supplier_brand',nonce:nonce,supplier:supplier,reset:reset?1:0}})
                    .done(function(response){
                        if(!response || !response.success){ finish((response&&response.data&&response.data.message)?response.data.message:'WordPress returned an error.'); return; }
                        const d=response.data||{};
                        $bar.css('width', Math.max(0, Math.min(100, d.progress_pct||0))+'%');
                        $text.text(d.progress_text||'Working…');
                        append(d.message||'');
                        if(d.done){ setTimeout(function(){ window.location.reload(); }, 900); }
                        else setTimeout(function(){ step(false); }, 150);
                    })
                    .fail(function(xhr){ finish('HTTP '+(xhr.status||'error')+'. Click the same supplier button to resume.'); });
                }
                $buttons.on('click', function(){
                    if(running) return;
                    running=true; supplier=$(this).data('supplier');
                    $buttons.prop('disabled', true); $wrap.show(); $bar.css('width','0'); $log.val('');
                    append('Starting separate brand repair for '+supplier+'…');
                    step(true);
                });
                $('#echo-clean-storefront-fixes').on('click', function(){
                    const $btn=$(this), $out=$('#echo-clean-storefront-result');
                    $btn.prop('disabled', true); $out.text('Cleaning…');
                    $.post(ajaxurl,{action:'echo_clean_failed_storefront_fixes',nonce:cleanNonce})
                    .done(function(response){ $out.text(response&&response.success ? response.data.message : 'Cleanup returned an error.'); })
                    .fail(function(){ $out.text('Cleanup failed.'); })
                    .always(function(){ $btn.prop('disabled', false); });
                });
            });
            </script>
        </div>
        <?php
    }

    public function maybe_assign_saved_product( int $product_id ): void {
        if ( 'product' !== get_post_type( $product_id ) ) return;
        $key = $this->detect_supplier_key_for_product( $product_id );
        $suppliers = $this->suppliers();
        if ( $key && isset( $suppliers[ $key ] ) ) {
            $this->assign_brand( $product_id, $suppliers[ $key ] );
        }
    }

    public function ajax_clean_storefront(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'You are not allowed to run cleanup.' ), 403 );
        }
        check_ajax_referer( 'echo_clean_failed_storefront_fixes', 'nonce' );
        foreach ( array(
            'echo_storefront_recovery_state_v1',
            'echo_storefront_recovery_state_v2',
            'echo_supplier_brand_repair_state_v1',
            'echo_supplier_brand_repair_state_v2',
            'echo_supplier_brand_repair_state_v3',
        ) as $option ) {
            delete_option( $option );
        }
        foreach ( array_keys( $this->suppliers() ) as $key ) {
            delete_option( self::STATE_OPTION_PREFIX . sanitize_key( $key ) );
        }
        $this->purge_caches();
        wp_send_json_success( array( 'message' => 'Removed old failed repair states and purged caches. No products were changed.' ) );
    }

    public function ajax_repair_one(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'You are not allowed to repair product brands.' ), 403 );
        }
        check_ajax_referer( 'echo_repair_one_supplier_brand', 'nonce' );
        if ( empty( $this->brand_taxonomies() ) ) {
            wp_send_json_error( array( 'message' => 'No supported WooCommerce product-brand taxonomy is active.' ), 400 );
        }
        wp_raise_memory_limit( 'admin' );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 40 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        $key = sanitize_key( (string) ( $_POST['supplier'] ?? '' ) );
        $suppliers = $this->suppliers();
        if ( ! isset( $suppliers[ $key ] ) ) {
            wp_send_json_error( array( 'message' => 'Unknown supplier.' ), 400 );
        }

        $state_option = self::STATE_OPTION_PREFIX . $key;
        if ( ! empty( $_POST['reset'] ) ) {
            delete_option( $state_option );
        }
        $total = $this->count_matching_products( $key );
        $state = get_option( $state_option, array() );
        if ( ! is_array( $state ) || (string) ( $state['version'] ?? '' ) !== '5' || ! empty( $state['completed'] ) ) {
            $state = array(
                'version'    => '5',
                'last_id'    => 0,
                'processed'  => 0,
                'repaired'   => 0,
                'total'      => $total,
                'completed'  => false,
                'updated_at' => time(),
            );
            update_option( $state_option, $state, false );
        }

        $ids = $this->next_matching_product_ids( $key, (int) $state['last_id'], self::BATCH_SIZE );
        if ( empty( $ids ) ) {
            $state['completed'] = true;
            $state['updated_at'] = time();
            update_option( $state_option, $state, false );
            $this->refresh_brand_counts();
            wp_send_json_success( array(
                'done' => true,
                'progress_pct' => 100,
                'progress_text' => sprintf( '%s brand repair complete', $suppliers[ $key ]['brand'] ),
                'message' => sprintf( '%s now has %d linked products in the primary brand index.', $suppliers[ $key ]['brand'], $this->current_brand_count( $suppliers[ $key ]['brand'] ) ),
            ) );
        }

        $repaired = 0;
        foreach ( $ids as $product_id ) {
            if ( $this->assign_brand( (int) $product_id, $suppliers[ $key ] ) ) {
                $repaired++;
                $state['repaired'] = (int) $state['repaired'] + 1;
            }
            $state['processed'] = (int) $state['processed'] + 1;
            $state['last_id'] = max( (int) $state['last_id'], (int) $product_id );
        }
        $state['total'] = $total;
        $state['updated_at'] = time();
        update_option( $state_option, $state, false );

        $denominator = max( 1, $total );
        wp_send_json_success( array(
            'done' => false,
            'progress_pct' => min( 99.9, round( 100 * (int) $state['processed'] / $denominator, 1 ) ),
            'progress_text' => sprintf( '%s: %d / %d matched products checked', $suppliers[ $key ]['brand'], (int) $state['processed'], $total ),
            'message' => sprintf( 'Linked %d %s products in this batch.', $repaired, $suppliers[ $key ]['brand'] ),
        ) );
    }

    private function suppliers(): array {
        return array(
            'mabotech' => array(
                'label' => 'Mabotech',
                'brand' => 'Mabotech',
                'slug' => 'mabotech',
                'sku_prefixes' => array( 'MAB-' ),
                'strong_terms' => array( 'Mabotech', 'mabotech.net' ),
                'remove_tags' => array( 'Mabotech', 'Mabotech Labs' ),
            ),
            'flf' => array(
                'label' => 'FLF / Finish Line Factory',
                'brand' => 'FLF Racing Supply',
                'slug' => 'flf-racing-supply',
                'sku_prefixes' => array( 'FLF-' ),
                'strong_terms' => array( 'FLF Racing Supply', 'Finish Line Factory', 'finishlinefactory.com' ),
                'remove_tags' => array( 'FLF Racing Supply', 'Finish Line Factory' ),
            ),
            'leistune' => array(
                'label' => 'Leistune',
                'brand' => 'Leistune',
                'slug' => 'leistune',
                'sku_prefixes' => array( 'LEI-' ),
                'strong_terms' => array( 'Leistune', 'leistune.com' ),
                'remove_tags' => array( 'Leistune' ),
            ),
            'ats' => array(
                'label' => 'Applied Torque Solutions',
                'brand' => 'Applied Torque Solutions',
                'slug' => 'applied-torque-solutions',
                'sku_prefixes' => array( 'ATS-' ),
                'strong_terms' => array( 'Applied Torque Solutions', 'appliedtorquesolutions.com', 'applied-torque-solutions.com' ),
                'remove_tags' => array( 'Applied Torque Solutions', 'ATS' ),
            ),
            'eldoc' => array(
                'label' => 'El Doc Solutions',
                'brand' => 'El Doc Solutions',
                'slug' => 'el-doc-solutions',
                'sku_prefixes' => array( 'ELDOC-', 'EL-DOC-' ),
                'strong_terms' => array( 'El Doc Solutions', 'El Doc', 'jackalmotorsports.com', 'eldocsolutions.com', 'el-doc-solutions.com' ),
                'remove_tags' => array( 'El Doc Solutions', 'El Doc' ),
            ),
            'evilenergy' => array(
                'label' => 'EVIL ENERGY',
                'brand' => 'EVIL ENERGY',
                'slug' => 'evil-energy',
                'sku_prefixes' => array( 'EVIL-', 'EVIL-P-' ),
                'strong_terms' => array( 'EVIL ENERGY', 'Evil Energy', 'ievilenergy.com', 'evilenergy.com', 'evil-energy.com' ),
                'remove_tags' => array( 'EVIL ENERGY', 'Evil Energy' ),
            ),
        );
    }

    private function detect_supplier_key_for_product( int $product_id ): string {
        foreach ( array_keys( $this->suppliers() ) as $key ) {
            if ( in_array( $product_id, $this->next_matching_product_ids( $key, $product_id - 1, 1 ), true ) ) {
                return $key;
            }
        }
        return '';
    }

    private function count_matching_products( string $key ): int {
        global $wpdb;
        $where = $this->supplier_where_sql( $key );
        if ( '' === $where ) return 0;
        return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p WHERE p.post_type='product' AND p.post_status NOT IN ('trash','auto-draft') AND ({$where})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private function next_matching_product_ids( string $key, int $last_id, int $limit ): array {
        global $wpdb;
        $where = $this->supplier_where_sql( $key );
        if ( '' === $where ) return array();
        $sql = $wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p WHERE p.post_type='product' AND p.post_status NOT IN ('trash','auto-draft') AND p.ID > %d AND ({$where}) ORDER BY p.ID ASC LIMIT %d",
            $last_id,
            $limit
        );
        return array_map( 'absint', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private function supplier_where_sql( string $key ): string {
        global $wpdb;
        $suppliers = $this->suppliers();
        if ( ! isset( $suppliers[ $key ] ) ) return '';
        $supplier = $suppliers[ $key ];
        $parts = array();

        foreach ( (array) $supplier['sku_prefixes'] as $prefix ) {
            $parts[] = $wpdb->prepare( "EXISTS (SELECT 1 FROM {$wpdb->postmeta} sku WHERE sku.post_id=p.ID AND sku.meta_key='_sku' AND sku.meta_value LIKE %s)", $wpdb->esc_like( $prefix ) . '%' );
        }
        foreach ( (array) $supplier['strong_terms'] as $term ) {
            $like = '%' . $wpdb->esc_like( strtolower( $term ) ) . '%';
            $parts[] = $wpdb->prepare( 'LOWER(p.post_title) LIKE %s', $like );
            $parts[] = $wpdb->prepare( 'LOWER(p.post_content) LIKE %s', $like );
            $parts[] = $wpdb->prepare( "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id=p.ID AND LOWER(CAST(pm.meta_value AS CHAR)) LIKE %s)", $like );
            if ( taxonomy_exists( 'product_tag' ) ) {
                $parts[] = $wpdb->prepare(
                    "EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id=tt.term_id WHERE tr.object_id=p.ID AND tt.taxonomy='product_tag' AND LOWER(t.name) LIKE %s)",
                    $like
                );
            }
        }
        return $parts ? implode( ' OR ', $parts ) : '';
    }

    private function assign_brand( int $product_id, array $supplier ): bool {
        if ( 'product' !== get_post_type( $product_id ) ) return false;
        $success = false;
        foreach ( $this->brand_taxonomies() as $taxonomy ) {
            $term_id = $this->brand_term_id( (string) $supplier['brand'], (string) $supplier['slug'], $taxonomy );
            if ( ! $term_id ) continue;
            $result = wp_set_object_terms( $product_id, array( $term_id ), $taxonomy, false );
            if ( ! is_wp_error( $result ) ) $success = true;
        }
        if ( ! $success ) return false;

        if ( taxonomy_exists( 'product_tag' ) ) {
            foreach ( (array) $supplier['remove_tags'] as $old_tag ) {
                $term = term_exists( $old_tag, 'product_tag' );
                if ( $term ) wp_remove_object_terms( $product_id, absint( is_array( $term ) ? $term['term_id'] : $term ), 'product_tag' );
            }
        }
        update_post_meta( $product_id, '_echo_brand', sanitize_text_field( (string) $supplier['brand'] ) );
        update_post_meta( $product_id, '_echo_supplier', sanitize_text_field( (string) $supplier['brand'] ) );
        if ( function_exists( 'wc_delete_product_transients' ) ) wc_delete_product_transients( $product_id );
        clean_post_cache( $product_id );
        return true;
    }

    private function brand_taxonomies(): array {
        $found = array();
        foreach ( array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'berocket_brand' ) as $taxonomy ) {
            if ( taxonomy_exists( $taxonomy ) ) $found[] = $taxonomy;
        }
        return array_values( array_unique( $found ) );
    }

    private function brand_term_id( string $name, string $slug, string $taxonomy ): int {
        $term = term_exists( $slug, $taxonomy );
        if ( ! $term ) $term = term_exists( $name, $taxonomy );
        if ( ! $term ) $term = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
        if ( is_wp_error( $term ) ) return 0;
        return absint( is_array( $term ) ? $term['term_id'] : $term );
    }

    private function preferred_brand_taxonomy(): string {
        $taxonomies = $this->brand_taxonomies();
        return $taxonomies ? (string) $taxonomies[0] : '';
    }

    private function current_brand_count( string $brand ): int {
        global $wpdb;
        $taxonomy = $this->preferred_brand_taxonomy();
        if ( ! $taxonomy ) return 0;
        $term = get_term_by( 'name', $brand, $taxonomy );
        if ( ! $term || is_wp_error( $term ) ) return 0;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id=p.ID INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE p.post_type='product' AND p.post_status NOT IN ('trash','auto-draft') AND tt.taxonomy=%s AND tt.term_id=%d",
            $taxonomy,
            (int) $term->term_id
        ) );
    }

    private function refresh_brand_counts(): void {
        global $wpdb;
        foreach ( $this->brand_taxonomies() as $taxonomy ) {
            $tt_ids = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy=%s", $taxonomy ) ) );
            if ( $tt_ids ) wp_update_term_count_now( $tt_ids, $taxonomy );
            clean_taxonomy_cache( $taxonomy );
        }
        $this->purge_caches();
    }

    private function purge_caches(): void {
        delete_transient( 'wc_term_counts' );
        delete_transient( 'wc_attribute_taxonomies' );
        if ( function_exists( 'wc_delete_product_transients' ) ) wc_delete_product_transients();
        wp_cache_flush();
        do_action( 'litespeed_purge_all' );
    }
}
