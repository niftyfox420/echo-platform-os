<?php

defined( 'ABSPATH' ) || exit;

/**
 * Synchronizes the two current El Doc Solutions LPFP products and builds
 * conservative, exact FuelEconomy.gov vehicle-ID fitment for their confirmed
 * Audi 3.0T applications.
 */
final class Echo_Motorworks_ElDoc_Builder {
    private const STATE_OPTION = 'echo_eldoc_builder_state_v1';
    private const STATE_VERSION = '1';
    private const OPTIONS_PER_REQUEST = 2;
    private const SOURCE = 'eldoc_exact_builder_v1';

    private Echo_Motorworks_API $api;
    private Echo_Motorworks_Garage $garage;

    public function __construct( Echo_Motorworks_API $api, Echo_Motorworks_Garage $garage ) {
        $this->api = $api;
        $this->garage = $garage;
        add_action( 'admin_menu', array( $this, 'admin_menu' ), 62 );
        add_action( 'wp_ajax_echo_sync_eldoc_products', array( $this, 'ajax_sync_products' ) );
        add_action( 'wp_ajax_echo_build_eldoc_fitment', array( $this, 'ajax_build' ) );
        add_action( 'admin_post_echo_export_eldoc_fitment', array( $this, 'export_fitment' ) );
    }

    public function admin_menu(): void {
        $parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'tools.php';
        add_submenu_page(
            $parent,
            'El Doc Solutions',
            'El Doc Solutions',
            'manage_woocommerce',
            'echo-eldoc-fitment',
            array( $this, 'page' )
        );
    }

    public function page(): void {
        ?>
        <div class="wrap">
            <h1>El Doc Solutions Catalog & Exact Fitment</h1>
            <p>This page creates or refreshes the two current El Doc Solutions LPFP products, their six variations, supplier images, prices, descriptions and exact FuelEconomy.gov vehicle-ID fitment.</p>
            <div class="notice notice-info inline"><p><strong>Conservative coverage:</strong> only the supplier-confirmed B8/B8.5 Audi S4/S5 and C7/C7.5 Audi A6/A7 3.0T applications are matched. The supplier marks A4, S6, Q5 and SQ5 fitment as unconfirmed, so those vehicles are intentionally excluded.</p></div>

            <h2>1. Sync the El Doc products</h2>
            <p><button type="button" class="button button-primary" id="echo-eldoc-sync">Create / Refresh El Doc Products</button></p>
            <div id="echo-eldoc-sync-result" style="max-width:900px"></div>

            <h2>2. Build exact vehicle fitment</h2>
            <p>
                <button type="button" class="button button-primary" id="echo-eldoc-build">Build / Resume El Doc Exact Fitment</button>
                <button type="button" class="button" id="echo-eldoc-stop" disabled>Stop</button>
                <button type="button" class="button" id="echo-eldoc-restart">Restart from Beginning</button>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:8px 0 18px">
                <input type="hidden" name="action" value="echo_export_eldoc_fitment">
                <?php wp_nonce_field( 'echo_export_eldoc_fitment' ); ?>
                <?php submit_button( 'Download Built El Doc Exact CSV', 'secondary', 'submit', false ); ?>
            </form>
            <p><em>Progress is saved after every small chunk. It is safe to close the tab and resume later.</em></p>
            <div id="echo-eldoc-progress" style="max-width:900px;display:none">
                <div style="height:18px;background:#dcdcde;border-radius:4px;overflow:hidden"><div id="echo-eldoc-progress-bar" style="height:100%;width:0;background:#2271b1;transition:width .2s"></div></div>
                <p id="echo-eldoc-progress-text" style="font-weight:600"></p>
                <textarea id="echo-eldoc-log" readonly style="width:100%;min-height:180px;font-family:monospace"></textarea>
            </div>
            <script>
            jQuery(function($){
                const syncNonce = <?php echo wp_json_encode( wp_create_nonce( 'echo_sync_eldoc_products' ) ); ?>;
                const buildNonce = <?php echo wp_json_encode( wp_create_nonce( 'echo_build_eldoc_fitment' ) ); ?>;
                let stopped = false;
                let running = false;
                let retries = 0;
                const $start = $('#echo-eldoc-build');
                const $stop = $('#echo-eldoc-stop');
                const $restart = $('#echo-eldoc-restart');
                const $wrap = $('#echo-eldoc-progress');
                const $bar = $('#echo-eldoc-progress-bar');
                const $text = $('#echo-eldoc-progress-text');
                const $log = $('#echo-eldoc-log');

                $('#echo-eldoc-sync').on('click', function(){
                    const $button = $(this);
                    const $result = $('#echo-eldoc-sync-result');
                    $button.prop('disabled', true);
                    $result.html('<p>Syncing two parent products, six variations and supplier images…</p>');
                    $.post(ajaxurl, {action:'echo_sync_eldoc_products', nonce:syncNonce}).done(function(response){
                        if (!response || !response.success) {
                            const message = response && response.data && response.data.message ? response.data.message : 'Product sync failed.';
                            $result.html('<div class="notice notice-error inline"><p>' + $('<div>').text(message).html() + '</p></div>');
                            return;
                        }
                        const d = response.data;
                        let html = '<div class="notice notice-success inline"><p><strong>' + d.message + '</strong></p>';
                        if (d.warnings && d.warnings.length) {
                            html += '<p>Warnings:</p><ul>' + d.warnings.map(function(x){ return '<li>' + $('<div>').text(x).html() + '</li>'; }).join('') + '</ul>';
                        }
                        html += '</div>';
                        $result.html(html);
                    }).fail(function(xhr){
                        $result.html('<div class="notice notice-error inline"><p>Product sync request failed' + (xhr.status ? ' (HTTP ' + xhr.status + ')' : '') + '.</p></div>');
                    }).always(function(){ $button.prop('disabled', false); });
                });

                function append(message){
                    if (!message) return;
                    $log.val($log.val() + message + "\n");
                    $log.scrollTop($log[0].scrollHeight);
                }
                function finish(message){
                    running = false;
                    $start.prop('disabled', false);
                    $restart.prop('disabled', false);
                    $stop.prop('disabled', true);
                    $text.text(message);
                    append(message);
                }
                function retryRequest(reset, message){
                    if (stopped) { finish('Stopped. Click Build / Resume later; saved progress is retained.'); return; }
                    if (retries >= 4) {
                        finish('Builder paused after repeated server timeouts. Click Build / Resume to continue from the last saved chunk.');
                        return;
                    }
                    retries += 1;
                    const delay = Math.min(8000, 1000 * Math.pow(2, retries - 1));
                    append((message || 'Temporary request failure.') + ' Retrying the saved chunk in ' + Math.round(delay / 1000) + 's…');
                    window.setTimeout(function(){ run(reset); }, delay);
                }
                function run(reset){
                    if (stopped) { finish('Stopped. Click Build / Resume later; saved progress is retained.'); return; }
                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        timeout: 50000,
                        data: {action:'echo_build_eldoc_fitment', nonce:buildNonce, reset:reset ? 1 : 0}
                    }).done(function(response){
                        if (!response || !response.success) {
                            const message = response && response.data && response.data.message ? response.data.message : 'WordPress returned an error.';
                            retryRequest(false, message);
                            return;
                        }
                        retries = 0;
                        const d = response.data;
                        const pct = typeof d.progress_pct === 'number' ? d.progress_pct : 0;
                        $bar.css('width', Math.max(0, Math.min(100, pct)) + '%');
                        $text.text(d.progress_text || ('Task ' + d.completed_tasks + ' of ' + d.total_tasks));
                        append(d.message);
                        if (d.errors && d.errors.length) d.errors.forEach(function(error){ append('  Warning: ' + error); });
                        if (d.done) finish('El Doc fitment build complete. Exact EPA vehicle records and product links are ready.');
                        else window.setTimeout(function(){ run(false); }, 150);
                    }).fail(function(xhr){ retryRequest(false, xhr && xhr.status ? 'HTTP ' + xhr.status : 'Request failed'); });
                }
                function begin(reset){
                    if (running) return;
                    running = true; stopped = false; retries = 0;
                    $start.prop('disabled', true); $restart.prop('disabled', true); $stop.prop('disabled', false); $wrap.show();
                    if (reset) { $bar.css('width','0'); $log.val(''); append('Restarting El Doc exact-fitment build from the beginning…'); }
                    else append('Starting or resuming El Doc exact-fitment build…');
                    run(reset);
                }
                $start.on('click', function(){ begin(false); });
                $restart.on('click', function(){ if (window.confirm('Restart the El Doc builder from the beginning? Existing rows will be refreshed safely.')) begin(true); });
                $stop.on('click', function(){ stopped = true; $stop.prop('disabled', true); });
            });
            </script>
        </div>
        <?php
    }

    public function ajax_sync_products(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'You are not allowed to sync products.' ), 403 );
        }
        check_ajax_referer( 'echo_sync_eldoc_products', 'nonce' );
        if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Product_Variable' ) ) {
            wp_send_json_error( array( 'message' => 'WooCommerce must be active.' ), 400 );
        }
        wp_raise_memory_limit( 'admin' );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 90 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        $created = 0;
        $updated = 0;
        $variations = 0;
        $warnings = array();
        foreach ( $this->product_definitions() as $definition ) {
            $result = $this->sync_product( $definition );
            if ( is_wp_error( $result ) ) {
                $warnings[] = $definition['name'] . ': ' . $result->get_error_message();
                continue;
            }
            if ( ! empty( $result['created'] ) ) ++$created; else ++$updated;
            $variations += (int) ( $result['variations'] ?? 0 );
            if ( ! empty( $result['warning'] ) ) $warnings[] = $result['warning'];
        }
        $this->sync_product_scopes();
        wp_send_json_success( array(
            'message' => sprintf( 'El Doc catalog synchronized: %d parent product%s created, %d refreshed and %d variation%s ready.', $created, 1 === $created ? '' : 's', $updated, $variations, 1 === $variations ? '' : 's' ),
            'warnings' => $warnings,
        ) );
    }

    private function product_definitions(): array {
        $common_long = '<p><strong>Confirmed platform choices:</strong> Audi B8/B8.5 S4/S5 3.0T and Audi C7/C7.5 A6/A7 3.0T.</p><p><strong>Do not use the vehicle match alone to select the variation.</strong> Early B8 cars require the B8-to-B8.5 conversion harness and a compatible fuel-pump calibration. The supplier lists A4, S6, Q5 and SQ5 fitment as unconfirmed, so those vehicles are intentionally excluded from verified matching.</p>';
        return array(
            array(
                'sku' => 'ELDOC-LPFP-SINGLE',
                'name' => 'El Doc 3.0T Single LPFP Upgrade',
                'short' => 'Complete 340 LPH brushless low-pressure fuel-pump assembly for confirmed Audi B8/B8.5 S4/S5 and C7/C7.5 A6/A7 3.0T applications.',
                'description' => '<p>The El Doc Solutions Single LPFP Upgrade is a complete brushless fuel-pump assembly rather than a loose drop-in pump. Supplier bench testing reports approximately 340 LPH at 40 PSI through an unmodified OEM fuel-filter lid.</p>' . $common_long,
                'source' => 'https://jackalmotorsports.com/products/el-doc-3-0t-single-lpfp-upgrade',
                'image' => 'https://jackalmotorsports.com/cdn/shop/files/singles4_5.jpg?v=1724357371&width=1445',
                'variations' => array(
                    array( 'sku'=>'ELDOC-SINGLE-B8-YES', 'platform'=>'B8/B8.5 S4/S5', 'harness'=>'Yes', 'price'=>'760.00' ),
                    array( 'sku'=>'ELDOC-SINGLE-B8-NO', 'platform'=>'B8/B8.5 S4/S5', 'harness'=>'No', 'price'=>'520.00' ),
                    array( 'sku'=>'ELDOC-SINGLE-C7-NO', 'platform'=>'C7/C7.5 A6/A7', 'harness'=>'No', 'price'=>'575.00' ),
                ),
            ),
            array(
                'sku' => 'ELDOC-LPFP-DUAL',
                'name' => 'El Doc 3.0T Dual LPFP Upgrade',
                'short' => 'Complete dual-brushless LPFP assembly with controller and wiring for confirmed Audi B8/B8.5 S4/S5 and C7/C7.5 A6/A7 3.0T applications.',
                'description' => '<p>The El Doc Solutions Dual LPFP Upgrade is a complete assembly with wiring harness and controller. Supplier bench testing reports approximately 511 LPH at 40 PSI and 455 LPH at 60 PSI.</p>' . $common_long,
                'source' => 'https://jackalmotorsports.com/products/el-doc-3-0t-dual-lpfp-upgrade',
                'image' => 'https://jackalmotorsports.com/cdn/shop/files/A64.jpg?v=1724357813&width=1445',
                'variations' => array(
                    array( 'sku'=>'ELDOC-DUAL-B8-YES', 'platform'=>'B8/B8.5 S4/S5', 'harness'=>'Yes', 'price'=>'1490.00' ),
                    array( 'sku'=>'ELDOC-DUAL-B8-NO', 'platform'=>'B8/B8.5 S4/S5', 'harness'=>'No', 'price'=>'1250.00' ),
                    array( 'sku'=>'ELDOC-DUAL-C7-NO', 'platform'=>'C7/C7.5 A6/A7', 'harness'=>'No', 'price'=>'1325.00' ),
                ),
            ),
        );
    }

    private function sync_product( array $definition ) {
        $existing_id = absint( wc_get_product_id_by_sku( $definition['sku'] ) );
        $created = ! $existing_id;
        $product = $existing_id ? wc_get_product( $existing_id ) : new WC_Product_Variable();
        if ( ! $product || ! is_a( $product, 'WC_Product_Variable' ) ) {
            if ( $existing_id ) wp_set_object_terms( $existing_id, 'variable', 'product_type' );
            $product = new WC_Product_Variable( $existing_id );
        }

        $product->set_name( $definition['name'] );
        $product->set_sku( $definition['sku'] );
        $product->set_status( 'publish' );
        $product->set_catalog_visibility( 'visible' );
        $product->set_short_description( $definition['short'] );
        $product->set_description( $definition['description'] );
        $product->set_manage_stock( false );
        $product->set_stock_status( 'outofstock' );
        $product->set_reviews_allowed( true );

        $platform = new WC_Product_Attribute();
        $platform->set_id( 0 );
        $platform->set_name( 'Platform' );
        $platform->set_options( array( 'B8/B8.5 S4/S5', 'C7/C7.5 A6/A7' ) );
        $platform->set_position( 0 );
        $platform->set_visible( true );
        $platform->set_variation( true );

        $harness = new WC_Product_Attribute();
        $harness->set_id( 0 );
        $harness->set_name( 'B8 to B8.5 Conversion Harness' );
        $harness->set_options( array( 'Yes', 'No' ) );
        $harness->set_position( 1 );
        $harness->set_visible( true );
        $harness->set_variation( true );
        $product->set_attributes( array( $platform, $harness ) );

        $category_id = $this->term_id( 'Fuel System', 'product_cat' );
        if ( $category_id ) $product->set_category_ids( array( $category_id ) );
        $tag_ids = array_filter( array(
            $this->term_id( 'El Doc Solutions', 'product_tag' ),
            $this->term_id( 'LPFP', 'product_tag' ),
            $this->term_id( 'Audi 3.0T', 'product_tag' ),
            $this->term_id( 'Fuel Pump', 'product_tag' ),
        ) );
        if ( $tag_ids ) $product->set_tag_ids( array_values( $tag_ids ) );

        $product_id = $product->save();
        if ( ! $product_id ) return new WP_Error( 'eldoc_product_save', 'WooCommerce could not save the parent product.' );
        update_post_meta( $product_id, '_echo_supplier', 'El Doc Solutions' );
        update_post_meta( $product_id, '_echo_manufacturer', 'El Doc Solutions' );
        update_post_meta( $product_id, '_echo_source_url', esc_url_raw( $definition['source'] ) );
        update_post_meta( $product_id, '_echo_source_checked', gmdate( 'Y-m-d' ) );

        $warning = '';
        if ( ! get_post_thumbnail_id( $product_id ) && ! empty( $definition['image'] ) ) {
            $image = $this->sideload_image( $definition['image'], $product_id, $definition['name'] );
            if ( is_wp_error( $image ) ) $warning = $definition['name'] . ': product saved, but the supplier image could not be downloaded (' . $image->get_error_message() . '). Click sync again later to retry the image.';
        }

        $variation_count = 0;
        foreach ( $definition['variations'] as $position => $variation_definition ) {
            $variation_id = absint( wc_get_product_id_by_sku( $variation_definition['sku'] ) );
            $variation = $variation_id ? wc_get_product( $variation_id ) : new WC_Product_Variation();
            if ( ! $variation || ! is_a( $variation, 'WC_Product_Variation' ) ) $variation = new WC_Product_Variation( $variation_id );
            $variation->set_parent_id( $product_id );
            $variation->set_sku( $variation_definition['sku'] );
            $variation->set_status( 'publish' );
            $variation->set_regular_price( $variation_definition['price'] );
            $variation->set_price( $variation_definition['price'] );
            $variation->set_manage_stock( false );
            $variation->set_stock_status( 'outofstock' );
            $variation->set_menu_order( (int) $position );
            $variation->set_attributes( array(
                'platform' => $variation_definition['platform'],
                'b8-to-b8-5-conversion-harness' => $variation_definition['harness'],
            ) );
            if ( $variation->save() ) ++$variation_count;
        }
        WC_Product_Variable::sync( $product_id );
        wc_delete_product_transients( $product_id );

        return array( 'created'=>$created, 'variations'=>$variation_count, 'warning'=>$warning );
    }

    private function term_id( string $name, string $taxonomy ): int {
        if ( ! taxonomy_exists( $taxonomy ) ) return 0;
        $term = term_exists( $name, $taxonomy );
        if ( ! $term ) $term = wp_insert_term( $name, $taxonomy );
        if ( is_wp_error( $term ) ) return 0;
        return absint( is_array( $term ) ? $term['term_id'] : $term );
    }

    private function sideload_image( string $url, int $product_id, string $description ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_id = media_sideload_image( esc_url_raw( $url ), $product_id, sanitize_text_field( $description ), 'id' );
        if ( is_wp_error( $attachment_id ) ) return $attachment_id;
        set_post_thumbnail( $product_id, absint( $attachment_id ) );
        return absint( $attachment_id );
    }

    public function ajax_build(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( array( 'message'=>'You are not allowed to build fitment.' ), 403 );
        check_ajax_referer( 'echo_build_eldoc_fitment', 'nonce' );
        wp_raise_memory_limit( 'admin' );
        if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 45 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        $this->sync_product_scopes();
        $tasks = $this->tasks();
        $total = count( $tasks );
        $reset = ! empty( $_POST['reset'] );
        $state = $this->builder_state( $reset );

        if ( ! $total || ! empty( $state['completed'] ) || (int) $state['task_index'] >= $total ) {
            $state['completed'] = true; $state['task_index'] = $total; $this->save_state( $state );
            wp_send_json_success( array( 'done'=>true, 'completed_tasks'=>$total, 'total_tasks'=>$total, 'progress_pct'=>100, 'progress_text'=>'Complete', 'message'=>'El Doc exact-fitment build is complete.', 'errors'=>array() ) );
        }

        $task_index = (int) $state['task_index'];
        $task = $tasks[ $task_index ];
        $year = (int) $task['year'];
        $make = (string) $task['make'];
        $profiles = $task['profiles'];
        $models = $this->api->get_menu( 'model', $year, $make );
        if ( is_wp_error( $models ) ) wp_send_json_error( array( 'message'=>$task['label'] . ': ' . $models->get_error_message() ), 503 );

        $work_models = array();
        foreach ( $models as $model_item ) {
            $model = sanitize_text_field( $model_item['text'] ?? $model_item['value'] ?? '' );
            if ( '' === $model ) continue;
            $candidate_profiles = array_values( array_filter( $profiles, fn( array $profile ): bool => $this->pattern_matches( $profile['model_pattern'] ?? '', $model ) && ! $this->pattern_matches( $profile['exclude_model_pattern'] ?? '', $model, false ) ) );
            if ( $candidate_profiles ) $work_models[] = array( 'model'=>$model, 'profiles'=>$candidate_profiles );
        }

        if ( (int) $state['model_index'] >= count( $work_models ) ) {
            $message = $this->complete_task( $state, $task, $total ); $this->save_state( $state );
            wp_send_json_success( $this->response( $state, $total, $message, array() ) );
        }

        $model_index = (int) $state['model_index'];
        $model_work = $work_models[ $model_index ];
        $model = $model_work['model'];
        $candidate_profiles = $model_work['profiles'];
        $options = $this->api->get_menu( 'options', $year, $make, $model );
        if ( is_wp_error( $options ) ) wp_send_json_error( array( 'message'=>$task['label'] . ' ' . $model . ': ' . $options->get_error_message() ), 503 );

        $option_index = (int) $state['option_index'];
        if ( $option_index >= count( $options ) ) {
            $state['model_index'] = $model_index + 1; $state['option_index'] = 0; $state['chunk_failures'] = 0;
            $message = (int) $state['model_index'] >= count( $work_models ) ? $this->complete_task( $state, $task, $total ) : $task['label'] . ' ' . $model . ': model complete; moving to the next model.';
            $this->save_state( $state ); wp_send_json_success( $this->response( $state, $total, $message, array() ) );
        }

        $slice = array_slice( $options, $option_index, self::OPTIONS_PER_REQUEST );
        $matched_vehicle_ids = array(); $fitment_rows = 0; $errors = array();
        foreach ( $slice as $option ) {
            $epa_id = sanitize_text_field( $option['value'] ?? '' );
            if ( '' === $epa_id || ! ctype_digit( $epa_id ) ) continue;
            $vehicle = $this->api->get_vehicle( $epa_id );
            if ( is_wp_error( $vehicle ) ) {
                $state['chunk_failures'] = (int) ( $state['chunk_failures'] ?? 0 ) + 1; $this->save_state( $state );
                if ( (int) $state['chunk_failures'] < 3 ) wp_send_json_error( array( 'message'=>$task['label'] . ' ' . $model . ' #' . $epa_id . ': ' . $vehicle->get_error_message() ), 503 );
                $errors[] = $model . ' #' . $epa_id . ': skipped after three failed detail requests.'; $state['chunk_failures'] = 0; continue;
            }

            $vehicle_groups = array(); $vehicle_notes = array();
            foreach ( $candidate_profiles as $profile ) {
                if ( ! $this->vehicle_matches_profile( $vehicle, $profile ) ) continue;
                foreach ( (array) ( $profile['groups'] ?? array() ) as $group ) $vehicle_groups[ sanitize_key( $group ) ] = sanitize_key( $profile['status'] ?? 'confirmed' );
                $vehicle_notes[] = sanitize_text_field( $profile['label'] ?? 'El Doc application profile' );
            }
            if ( empty( $vehicle_groups ) ) continue;

            $internal_vehicle_id = $this->garage->upsert_vehicle( $vehicle );
            if ( ! $internal_vehicle_id ) continue;
            $vehicle['id'] = $internal_vehicle_id; $matched_vehicle_ids[ $epa_id ] = true; $state['task_matched_vehicle_ids'][ $epa_id ] = true;

            foreach ( $vehicle_groups as $group => $status ) {
                foreach ( $this->group_products( $group ) as $sku ) {
                    $product_id = absint( wc_get_product_id_by_sku( $sku ) );
                    if ( ! $product_id ) { $state['task_missing_products'][ $sku ] = true; continue; }
                    if ( $this->upsert_fitment( $product_id, $vehicle, $status, implode( '; ', array_unique( $vehicle_notes ) ) ) ) { ++$fitment_rows; $state['task_fitment_rows'] = (int) ( $state['task_fitment_rows'] ?? 0 ) + 1; }
                }
            }
        }

        $processed = count( $slice ); $state['option_index'] = $option_index + $processed; $state['chunk_failures'] = 0;
        if ( $errors ) $state['task_errors'] = array_values( array_unique( array_merge( (array) ( $state['task_errors'] ?? array() ), $errors ) ) );
        $message = sprintf( '%s / %s: processed option%s %d–%d of %d; %d matching vehicle%s, %d product link%s.', $task['label'], $model, 1 === $processed ? '' : 's', $option_index + 1, min( count( $options ), $option_index + $processed ), count( $options ), count( $matched_vehicle_ids ), 1 === count( $matched_vehicle_ids ) ? '' : 's', $fitment_rows, 1 === $fitment_rows ? '' : 's' );
        if ( (int) $state['option_index'] >= count( $options ) ) {
            $state['model_index'] = $model_index + 1; $state['option_index'] = 0;
            if ( (int) $state['model_index'] >= count( $work_models ) ) $message .= ' ' . $this->complete_task( $state, $task, $total );
        }
        $this->save_state( $state ); wp_send_json_success( $this->response( $state, $total, $message, $errors ) );
    }

    public function export_fitment(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'You are not allowed to export fitment.' );
        check_admin_referer( 'echo_export_eldoc_fitment' );
        global $wpdb;
        $fitment = Echo_Motorworks_DB::fitment_table(); $vehicles = Echo_Motorworks_DB::vehicles_table();
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT f.*, v.source AS vehicle_source, v.source_vehicle_id FROM {$fitment} f LEFT JOIN {$vehicles} v ON v.id=f.vehicle_id WHERE f.source=%s ORDER BY f.product_id,f.year_start,f.make,f.model,v.source_vehicle_id", self::SOURCE ), ARRAY_A );
        nocache_headers(); header( 'Content-Type: text/csv; charset=utf-8' ); header( 'Content-Disposition: attachment; filename=eldoc-fitment.csv' );
        $out = fopen( 'php://output', 'wb' ); fwrite( $out, "\xEF\xBB\xBF" );
        $headers = array( 'product_id','product_sku','vehicle_source','source_vehicle_id','year_start','year_end','make','model','submodel','generation','chassis','engine','engine_code','transmission','drivetrain','body_style','status','notes','supplier','source' ); fputcsv( $out, $headers );
        foreach ( $rows as $row ) fputcsv( $out, array( $row['product_id'], get_post_meta( (int) $row['product_id'], '_sku', true ), $row['vehicle_source'] ?: 'epa', $row['source_vehicle_id'], $row['year_start'], $row['year_end'], $row['make'], $row['model'], $row['submodel'], $row['generation'], $row['chassis'], $row['engine'], $row['engine_code'], $row['transmission'], $row['drivetrain'], $row['body_style'], $row['fitment_status'], $row['fitment_notes'], $row['supplier'], 'https://jackalmotorsports.com/collections/el-doc-solutions' ) );
        fclose( $out ); exit;
    }

    private function builder_state( bool $reset ): array {
        if ( $reset ) delete_option( self::STATE_OPTION );
        $state = get_option( self::STATE_OPTION, array() );
        if ( ! is_array( $state ) || (string) ( $state['version'] ?? '' ) !== self::STATE_VERSION ) {
            $state = array( 'version'=>self::STATE_VERSION, 'task_index'=>0, 'model_index'=>0, 'option_index'=>0, 'task_matched_vehicle_ids'=>array(), 'task_fitment_rows'=>0, 'task_missing_products'=>array(), 'task_errors'=>array(), 'chunk_failures'=>0, 'completed'=>false, 'updated_at'=>time() );
            $this->save_state( $state );
        }
        return $state;
    }
    private function save_state( array $state ): void { $state['updated_at'] = time(); update_option( self::STATE_OPTION, $state, false ); }
    private function complete_task( array &$state, array $task, int $total ): string {
        $matched = count( (array) ( $state['task_matched_vehicle_ids'] ?? array() ) ); $rows = (int) ( $state['task_fitment_rows'] ?? 0 );
        Echo_Motorworks_DB::log( 'info', 'eldoc_fitment_builder', 'El Doc exact-fitment task completed.', array( 'task'=>$task['label'], 'matched_vehicles'=>$matched, 'fitment_rows'=>$rows, 'missing_products'=>array_keys( (array) ( $state['task_missing_products'] ?? array() ) ), 'errors'=>array_slice( (array) ( $state['task_errors'] ?? array() ), 0, 10 ) ) );
        $state['task_index'] = (int) $state['task_index'] + 1; $state['model_index'] = 0; $state['option_index'] = 0; $state['task_matched_vehicle_ids'] = array(); $state['task_fitment_rows'] = 0; $state['task_missing_products'] = array(); $state['task_errors'] = array(); $state['chunk_failures'] = 0; $state['completed'] = (int) $state['task_index'] >= $total;
        return sprintf( 'Completed %s: %d exact vehicle%s and %d product link%s.', $task['label'], $matched, 1 === $matched ? '' : 's', $rows, 1 === $rows ? '' : 's' );
    }
    private function response( array $state, int $total, string $message, array $errors ): array {
        $completed = min( $total, (int) $state['task_index'] ); $progress = $total ? round( ( $completed / $total ) * 100, 2 ) : 100;
        return array( 'done'=>! empty( $state['completed'] ), 'completed_tasks'=>$completed, 'total_tasks'=>$total, 'progress_pct'=>$progress, 'progress_text'=>! empty( $state['completed'] ) ? 'Complete' : sprintf( 'Completed %d of %d year/make tasks — progress saved', $completed, $total ), 'message'=>$message, 'errors'=>array_slice( $errors, 0, 5 ) );
    }
    private function tasks(): array {
        $grouped = array();
        foreach ( $this->profiles() as $profile ) for ( $year=absint( $profile['year_start'] ); $year<=absint( $profile['year_end'] ); ++$year ) { $key=$year . '|' . $profile['make']; if ( ! isset( $grouped[$key] ) ) $grouped[$key]=array( 'year'=>$year, 'make'=>$profile['make'], 'profiles'=>array(), 'label'=>$year . ' ' . $profile['make'] ); $grouped[$key]['profiles'][]=$profile; }
        ksort( $grouped, SORT_NATURAL ); return array_values( $grouped );
    }
    private function profiles(): array {
        $engine = '~6 cyl 3\\.0L~i';
        $fuel = '~Gasoline~i';
        $drive = '~All-Wheel Drive~i';
        $profiles = array();
        $add = static function( array $profile ) use ( &$profiles ): void { $profile += array( 'engine_pattern'=>'', 'transmission_pattern'=>'', 'fuel_pattern'=>'', 'drive_pattern'=>'', 'exclude_pattern'=>'', 'exclude_model_pattern'=>'', 'status'=>'confirmed' ); $profiles[]=$profile; };
        foreach ( array( array('S4','~^S4(?:\\b|$)~i',2010,2016), array('S5','~^S5(?:\\b|$)~i',2010,2017) ) as $model ) {
            $add( array( 'year_start'=>$model[2], 'year_end'=>2012, 'make'=>'Audi', 'model_pattern'=>$model[1], 'engine_pattern'=>$engine, 'fuel_pattern'=>$fuel, 'drive_pattern'=>$drive, 'groups'=>array('lpfp'), 'label'=>'Early B8 Audi ' . $model[0] . ' 3.0T — conversion harness and compatible pump calibration required', 'status'=>'conditional' ) );
            $add( array( 'year_start'=>2013, 'year_end'=>$model[3], 'make'=>'Audi', 'model_pattern'=>$model[1], 'engine_pattern'=>$engine, 'fuel_pattern'=>$fuel, 'drive_pattern'=>$drive, 'groups'=>array('lpfp'), 'label'=>'B8.5 Audi ' . $model[0] . ' 3.0T — select the correct no-harness variation' ) );
        }
        foreach ( array( array('A6','~^A6(?:\\b| |$)~i'), array('A7','~^A7(?:\\b| |$)~i') ) as $model ) {
            $add( array( 'year_start'=>2012, 'year_end'=>2018, 'make'=>'Audi', 'model_pattern'=>$model[1], 'engine_pattern'=>$engine, 'fuel_pattern'=>$fuel, 'drive_pattern'=>$drive, 'groups'=>array('lpfp'), 'label'=>'C7/C7.5 Audi ' . $model[0] . ' 3.0T — select the C7/C7.5 no-harness variation' ) );
        }
        return $profiles;
    }
    private function group_products( string $group ): array { return 'lpfp' === $group ? array( 'ELDOC-LPFP-SINGLE', 'ELDOC-LPFP-DUAL' ) : array(); }
    private function sync_product_scopes(): void {
        $scope = array( 'vehicle_specific', 'Confirmed Audi B8/B8.5 S4/S5 and C7/C7.5 A6/A7 3.0T applications only.', 'high', 'El Doc Solutions publishes these two platform groups; A4, S6, Q5 and SQ5 are explicitly unconfirmed and excluded.' );
        foreach ( array( 'ELDOC-LPFP-SINGLE','ELDOC-LPFP-DUAL' ) as $sku ) { $product_id=absint( wc_get_product_id_by_sku( $sku ) ); if ( ! $product_id ) continue; update_post_meta( $product_id, '_echo_fitment_type', $scope[0] ); update_post_meta( $product_id, '_echo_fitment_raw', $scope[1] ); update_post_meta( $product_id, '_echo_fitment_confidence', $scope[2] ); update_post_meta( $product_id, '_echo_fitment_reason', $scope[3] ); }
    }
    private function pattern_matches( string $pattern, string $value, bool $blank_matches=true ): bool { if ( '' === $pattern ) return $blank_matches; return 1 === @preg_match( $pattern, $value ); }
    private function vehicle_matches_profile( array $vehicle, array $profile ): bool {
        if ( ! $this->pattern_matches( $profile['transmission_pattern'] ?? '', (string) ( $vehicle['transmission'] ?? '' ) ) ) return false;
        if ( ! $this->pattern_matches( $profile['engine_pattern'] ?? '', (string) ( $vehicle['engine'] ?? '' ) ) ) return false;
        if ( ! $this->pattern_matches( $profile['fuel_pattern'] ?? '', (string) ( $vehicle['fuel_type'] ?? '' ) ) ) return false;
        if ( ! $this->pattern_matches( $profile['drive_pattern'] ?? '', (string) ( $vehicle['drivetrain'] ?? '' ) ) ) return false;
        $combined=implode( ' | ', array( $vehicle['model']??'', $vehicle['engine']??'', $vehicle['transmission']??'', $vehicle['drivetrain']??'', $vehicle['fuel_type']??'', $vehicle['option_label']??'' ) );
        if ( $this->pattern_matches( $profile['exclude_pattern'] ?? '', $combined, false ) ) return false;
        return true;
    }
    private function upsert_fitment( int $product_id, array $vehicle, string $status, string $profile_notes ): bool {
        global $wpdb; $table=Echo_Motorworks_DB::fitment_table(); $vehicle_id=absint( $vehicle['id']??0 ); $source_vehicle_id=sanitize_text_field( $vehicle['source_vehicle_id']??'' ); if ( ! $vehicle_id || '' === $source_vehicle_id ) return false;
        $status=in_array( $status, array('confirmed','conditional'), true ) ? $status : 'conditional'; $source_key=hash( 'sha256', implode( '|', array( $product_id,$vehicle_id,'epa',$source_vehicle_id,self::SOURCE ) ) ); $now=current_time( 'mysql', true );
        $data=array( 'product_id'=>$product_id, 'vehicle_id'=>$vehicle_id, 'year_start'=>absint($vehicle['year']??0), 'year_end'=>absint($vehicle['year']??0), 'make'=>sanitize_text_field($vehicle['make']??''), 'model'=>sanitize_text_field($vehicle['model']??''), 'submodel'=>sanitize_text_field($vehicle['submodel']??''), 'generation'=>sanitize_text_field($vehicle['generation']??''), 'chassis'=>sanitize_text_field($vehicle['chassis']??''), 'engine'=>sanitize_text_field($vehicle['engine']??''), 'engine_code'=>sanitize_text_field($vehicle['engine_code']??''), 'transmission'=>sanitize_text_field($vehicle['transmission']??''), 'drivetrain'=>sanitize_text_field($vehicle['drivetrain']??''), 'body_style'=>sanitize_text_field($vehicle['body_style']??''), 'normalized_make'=>Echo_Motorworks_DB::normalize((string)($vehicle['make']??'')), 'normalized_model'=>Echo_Motorworks_DB::normalize((string)($vehicle['model']??'')), 'normalized_engine'=>Echo_Motorworks_DB::normalize((string)($vehicle['engine']??'')), 'normalized_submodel'=>Echo_Motorworks_DB::normalize((string)($vehicle['submodel']??'')), 'normalized_transmission'=>Echo_Motorworks_DB::normalize((string)($vehicle['transmission']??'')), 'normalized_drivetrain'=>Echo_Motorworks_DB::normalize((string)($vehicle['drivetrain']??'')), 'fitment_status'=>$status, 'fitment_notes'=>sanitize_textarea_field( 'El Doc exact EPA fitment. ' . $profile_notes . '. Confirm the platform variation, conversion-harness requirement and pump calibration before fulfillment.' ), 'supplier'=>'El Doc Solutions', 'source'=>self::SOURCE, 'source_key'=>$source_key, 'updated_at'=>$now );
        $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE source_key=%s",$source_key)); if($existing)return false!==$wpdb->update($table,$data,array('id'=>$existing)); $data['created_at']=$now; return(bool)$wpdb->insert($table,$data);
    }
}
