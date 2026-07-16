<?php

defined( 'ABSPATH' ) || exit;

/**
 * Low-request EVIL ENERGY Shopify catalog snapshot and local WooCommerce builder.
 *
 * The supplier is contacted only during the explicit snapshot phase. Each
 * snapshot request asks Shopify for up to 250 complete products, including
 * variants and image URLs. Product creation then runs entirely from local JSON.
 */
final class Echo_Motorworks_EvilEnergy_Builder {
    private const SNAPSHOT_STATE_OPTION = 'echo_evilenergy_snapshot_state_v1';
    private const CATALOG_STATE_OPTION  = 'echo_evilenergy_catalog_state_v1';
    private const IMAGE_STATE_OPTION    = 'echo_evilenergy_image_state_v1';
    private const SOURCE_KEY            = 'evilenergy_shopify_snapshot_v1';
    private const PAGE_SIZE             = 250;
    private const MAX_PAGES             = 20;
    private const CATALOG_BATCH_SIZE    = 2;
    private const CATALOG_TIME_BUDGET   = 16.0;

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'admin_menu' ), 66 );
        add_action( 'wp_ajax_echo_capture_evilenergy_snapshot', array( $this, 'ajax_capture_snapshot' ) );
        add_action( 'wp_ajax_echo_sync_evilenergy_catalog', array( $this, 'ajax_sync_catalog' ) );
        add_action( 'wp_ajax_echo_sync_evilenergy_images', array( $this, 'ajax_sync_images' ) );
        add_action( 'admin_post_echo_export_evilenergy_products', array( $this, 'export_products' ) );
        add_action( 'admin_post_echo_export_evilenergy_snapshot', array( $this, 'export_snapshot' ) );
        add_action( 'admin_post_echo_export_evilenergy_snapshot_csv', array( $this, 'export_snapshot_csv' ) );
        add_action( 'admin_post_echo_import_evilenergy_snapshot', array( $this, 'import_snapshot' ) );
        add_action( 'admin_post_echo_finalize_evilenergy_snapshot', array( $this, 'finalize_snapshot' ) );
    }

    public function admin_menu(): void {
        $parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'tools.php';
        add_submenu_page(
            $parent,
            'EVIL ENERGY',
            'EVIL ENERGY',
            'manage_woocommerce',
            'echo-evilenergy',
            array( $this, 'page' )
        );
    }

    public function page(): void {
        $snapshot = $this->snapshot_state();
        $has_snapshot = ! empty( $snapshot['pages'] );
        $auto_snapshot_notice = '';

        /*
         * A saved local batch is already enough to run the offline builder.
         * Earlier versions required a separate form post to mark that batch as
         * complete. Some hosts/security layers blocked that post, leaving the
         * local catalog button disabled even though all product JSON was saved.
         * Validate and activate the saved files locally while rendering this
         * admin page. This makes zero supplier requests.
         */
        if ( $has_snapshot && empty( $snapshot['completed'] ) ) {
            $saved_products = $this->all_snapshot_products( $snapshot );
            if ( ! is_wp_error( $saved_products ) && ! empty( $saved_products ) ) {
                $snapshot['total_products']  = count( $saved_products );
                $snapshot['completed']       = true;
                $snapshot['manual_finalized'] = true;
                $snapshot['updated_at']      = gmdate( 'c' );
                $this->save_snapshot_state( $snapshot );
                $auto_snapshot_notice = sprintf(
                    'Activated the saved offline snapshot automatically: %d products are ready for the local catalog builder.',
                    count( $saved_products )
                );
            } elseif ( is_wp_error( $saved_products ) ) {
                $auto_snapshot_notice = 'The saved snapshot could not be activated: ' . $saved_products->get_error_message();
            }
        }

        $snapshot_ready = ! empty( $snapshot['completed'] ) && $has_snapshot;
        $notice = isset( $_GET['echo_evil_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['echo_evil_notice'] ) ) : '';
        $notice_type = isset( $_GET['echo_evil_notice_type'] ) ? sanitize_key( wp_unslash( $_GET['echo_evil_notice_type'] ) ) : 'success';
        ?>
        <div class="wrap">
            <h1>EVIL ENERGY — Offline Catalog Builder</h1>
            <?php if ( $notice ) : ?>
                <div class="notice notice-<?php echo esc_attr( in_array( $notice_type, array( 'success', 'warning', 'error', 'info' ), true ) ? $notice_type : 'success' ); ?> inline"><p><?php echo esc_html( $notice ); ?></p></div>
            <?php endif; ?>
            <?php if ( $auto_snapshot_notice ) : ?>
                <div class="notice notice-<?php echo esc_attr( $snapshot_ready ? 'success' : 'error' ); ?> inline"><p><?php echo esc_html( $auto_snapshot_notice ); ?></p></div>
            <?php endif; ?>
            <p>The supplier is contacted only while capturing the offline snapshot. Each click makes one supplier request for up to <?php echo esc_html( (string) self::PAGE_SIZE ); ?> complete products. Product creation, variations, brand assignment and fitment scopes run from local files.</p>
            <div class="notice notice-success inline"><p><strong>Low-request mode:</strong> there is no automatic request loop and no retry loop. Click once, wait for that batch to save, then decide whether to capture another batch.</p></div>
            <div class="notice notice-info inline"><p><strong>Fitment protection:</strong> generic dimensional products are marked universal with a specification warning. Vehicle, OEM and engine-family products are marked vehicle-specific, engine-specific or needs-review instead of being matched to every car.</p></div>

            <h2>1. Build or restore the offline snapshot</h2>
            <p><strong>Saved locally:</strong> <?php echo $has_snapshot ? esc_html( sprintf( '%d products across %d saved batch(es)%s', (int) $snapshot['total_products'], count( (array) $snapshot['pages'] ), $snapshot_ready ? ' — ready to build' : ' — capture not finalized' ) ) : 'No saved products yet'; ?></p>
            <p>
                <button type="button" class="button button-primary" id="echo-evil-snapshot">Capture Next EVIL ENERGY Batch</button>
                <button type="button" class="button" id="echo-evil-snapshot-stop" disabled>Stop</button>
                <button type="button" class="button" id="echo-evil-snapshot-restart">Restart Snapshot</button>
            </p>
            <p><em>Each click makes exactly one supplier request. Continuation uses the largest saved product ID to avoid repeating the first 250 products. Existing saved batches are preserved unless Restart Snapshot is used.</em></p>
            <div id="echo-evil-snapshot-progress" style="max-width:1000px;display:none">
                <div style="height:18px;background:#dcdcde;border-radius:4px;overflow:hidden"><div id="echo-evil-snapshot-bar" style="height:100%;width:0;background:#8c8f94;transition:width .2s"></div></div>
                <p id="echo-evil-snapshot-text" style="font-weight:600"></p>
                <textarea id="echo-evil-snapshot-log" readonly style="width:100%;min-height:150px;font-family:monospace"></textarea>
            </div>

            <?php if ( $has_snapshot ) : ?>
                <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:16px 0">
                    <?php if ( ! $snapshot_ready ) : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="echo_finalize_evilenergy_snapshot">
                            <?php wp_nonce_field( 'echo_finalize_evilenergy_snapshot' ); ?>
                            <?php submit_button( 'Use Saved Products Now', 'secondary', 'submit', false ); ?>
                        </form>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="echo_export_evilenergy_snapshot">
                        <?php wp_nonce_field( 'echo_export_evilenergy_snapshot' ); ?>
                        <?php submit_button( 'Download Offline Snapshot JSON', 'secondary', 'submit', false ); ?>
                    </form>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="echo_export_evilenergy_snapshot_csv">
                        <?php wp_nonce_field( 'echo_export_evilenergy_snapshot_csv' ); ?>
                        <?php submit_button( 'Download Snapshot Product List CSV', 'secondary', 'submit', false ); ?>
                    </form>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:1000px;padding:16px;border:1px solid #dcdcde;background:#fff;margin:14px 0 26px">
                <input type="hidden" name="action" value="echo_import_evilenergy_snapshot">
                <?php wp_nonce_field( 'echo_import_evilenergy_snapshot' ); ?>
                <label for="echo-evil-offline-json"><strong>Restore from an offline snapshot JSON</strong></label><br>
                <input id="echo-evil-offline-json" type="file" name="evilenergy_snapshot" accept="application/json,.json" required style="margin:10px 0">
                <p class="description">Uploading a previously downloaded EVIL ENERGY snapshot replaces only the saved snapshot files. Existing WooCommerce products are refreshed by source ID, not duplicated.</p>
                <?php submit_button( 'Upload Offline Snapshot', 'secondary', 'submit', false ); ?>
            </form>

            <h2>2. Build the local catalog</h2>
            <?php $catalog_status = $this->catalog_state( (int) $snapshot['total_products'] ); ?>
            <p><strong>WooCommerce EVIL ENERGY parents already created:</strong> <?php echo esc_html( (string) count( $this->supplier_product_ids() ) ); ?><br>
            <strong>Saved build progress:</strong> <?php echo esc_html( sprintf( '%d / %d products', (int) $catalog_status['processed'], (int) $catalog_status['total_products'] ) ); ?></p>
            <p>
                <button type="button" class="button button-primary" id="echo-evil-catalog" <?php disabled( ! $snapshot_ready ); ?>>Build / Resume Saved EVIL ENERGY Products</button>
                <button type="button" class="button" id="echo-evil-catalog-stop" disabled>Stop</button>
                <button type="button" class="button" id="echo-evil-catalog-restart" <?php disabled( ! $snapshot_ready ); ?>>Restart Local Catalog Build</button>
            </p>
            <p><em>This step makes zero supplier requests. It processes one parent product per saved step and includes all public variants in the snapshot.</em></p>
            <div id="echo-evil-catalog-progress" style="max-width:1000px;display:none">
                <div style="height:18px;background:#dcdcde;border-radius:4px;overflow:hidden"><div id="echo-evil-catalog-bar" style="height:100%;width:0;background:#2271b1;transition:width .2s"></div></div>
                <p id="echo-evil-catalog-text" style="font-weight:600"></p>
                <textarea id="echo-evil-catalog-log" readonly style="width:100%;min-height:220px;font-family:monospace"></textarea>
            </div>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:8px 0 22px">
                <input type="hidden" name="action" value="echo_export_evilenergy_products">
                <?php wp_nonce_field( 'echo_export_evilenergy_products' ); ?>
                <?php submit_button( 'Download Built EVIL ENERGY Catalog CSV', 'secondary', 'submit', false ); ?>
            </form>

            <h2>3. Download missing product images</h2>
            <p>
                <button type="button" class="button button-primary" id="echo-evil-images">Sync / Resume Missing Images</button>
                <button type="button" class="button" id="echo-evil-images-stop" disabled>Stop</button>
                <button type="button" class="button" id="echo-evil-images-restart">Retry All Missing Images</button>
            </p>
            <p><em>Optional. This requests one missing CDN image per step, waits between downloads and never replaces an existing featured image.</em></p>
            <div id="echo-evil-images-progress" style="max-width:1000px;display:none">
                <div style="height:18px;background:#dcdcde;border-radius:4px;overflow:hidden"><div id="echo-evil-images-bar" style="height:100%;width:0;background:#8c8f94;transition:width .2s"></div></div>
                <p id="echo-evil-images-text" style="font-weight:600"></p>
                <textarea id="echo-evil-images-log" readonly style="width:100%;min-height:150px;font-family:monospace"></textarea>
            </div>

            <script>
            jQuery(function($){
                $('#echo-evil-catalog').attr('data-echo-ready','1');
                function runner(config){
                    let running=false, stopped=false, retries=0;
                    const $start=$(config.start),$stop=$(config.stop),$restart=$(config.restart),$wrap=$(config.wrap),$bar=$(config.bar),$text=$(config.text),$log=$(config.log);
                    function append(m){if(!m)return;$log.val($log.val()+m+'\n');$log.scrollTop($log[0].scrollHeight);}
                    function finish(m){running=false;$start.prop('disabled',false);$restart.prop('disabled',false);$stop.prop('disabled',true);$text.text(m);append(m);}
                    function request(reset){
                        if(stopped){finish('Stopped. Saved progress is retained.');return;}
                        $.ajax({url:ajaxurl,method:'POST',timeout:config.timeout||50000,data:{action:config.action,nonce:config.nonce,reset:reset?1:0}})
                        .done(function(response){
                            if(!response||!response.success){
                                const m=response&&response.data&&response.data.message?response.data.message:'WordPress returned an error.';
                                if(config.noRetry){finish(m+' No automatic retry was made.');return;}
                                if(retries>=2){finish('Paused after repeated server errors. Click Build / Resume to continue from the last saved step.');return;}
                                retries++;append(m+' Retrying locally in '+(3*retries)+' seconds…');window.setTimeout(function(){request(false);},3000*retries);return;
                            }
                            retries=0;const d=response.data;$bar.css('width',Math.max(0,Math.min(100,d.progress_pct||0))+'%');$text.text(d.progress_text||'Progress saved');append(d.message);
                            if(d.warnings&&d.warnings.length)d.warnings.forEach(function(x){append('  Warning: '+x);});
                            if(d.done){if(config.action==='echo_capture_evilenergy_snapshot'){$('#echo-evil-catalog,#echo-evil-catalog-restart').prop('disabled',false);}finish(config.complete);}
                            else if(config.singleStep){finish('Batch saved. Click Capture Next EVIL ENERGY Batch for one more supplier request, or use the saved products now.');}
                            else window.setTimeout(function(){request(false);},config.delay||350);
                        }).fail(function(xhr){
                            const m='HTTP '+(xhr.status||'error')+'.';
                            if(config.noRetry){finish(m+' No automatic retry was made.');return;}
                            if(retries>=2){finish('Paused after repeated HTTP errors. Click Build / Resume to continue.');return;}
                            retries++;append(m+' Retrying locally…');window.setTimeout(function(){request(false);},3000*retries);
                        });
                    }
                    function begin(reset){if(running)return;running=true;stopped=false;retries=0;$start.prop('disabled',true);$restart.prop('disabled',true);$stop.prop('disabled',false);$wrap.show();if(reset){$bar.css('width','0');$log.val('');append('Restarting…');}else append('Starting or resuming…');request(reset);}
                    $start.on('click',function(){begin(false);});
                    $restart.on('click',function(){if(window.confirm(config.confirm||'Restart from the beginning?'))begin(true);});
                    $stop.on('click',function(){stopped=true;$stop.prop('disabled',true);});
                }
                runner({start:'#echo-evil-snapshot',stop:'#echo-evil-snapshot-stop',restart:'#echo-evil-snapshot-restart',wrap:'#echo-evil-snapshot-progress',bar:'#echo-evil-snapshot-bar',text:'#echo-evil-snapshot-text',log:'#echo-evil-snapshot-log',action:'echo_capture_evilenergy_snapshot',nonce:<?php echo wp_json_encode( wp_create_nonce( 'echo_capture_evilenergy_snapshot' ) ); ?>,timeout:45000,noRetry:true,singleStep:true,complete:'EVIL ENERGY offline snapshot complete.',confirm:'Restart the supplier snapshot? This deletes only the saved snapshot files, not WooCommerce products.'});
                runner({start:'#echo-evil-catalog',stop:'#echo-evil-catalog-stop',restart:'#echo-evil-catalog-restart',wrap:'#echo-evil-catalog-progress',bar:'#echo-evil-catalog-bar',text:'#echo-evil-catalog-text',log:'#echo-evil-catalog-log',action:'echo_sync_evilenergy_catalog',nonce:<?php echo wp_json_encode( wp_create_nonce( 'echo_sync_evilenergy_catalog' ) ); ?>,delay:350,timeout:55000,complete:'EVIL ENERGY local catalog build complete.',confirm:'Restart the local catalog build? Existing products will be refreshed by source ID, not duplicated.'});
                runner({start:'#echo-evil-images',stop:'#echo-evil-images-stop',restart:'#echo-evil-images-restart',wrap:'#echo-evil-images-progress',bar:'#echo-evil-images-bar',text:'#echo-evil-images-text',log:'#echo-evil-images-log',action:'echo_sync_evilenergy_images',nonce:<?php echo wp_json_encode( wp_create_nonce( 'echo_sync_evilenergy_images' ) ); ?>,delay:1800,timeout:55000,complete:'EVIL ENERGY missing-image pass complete.',confirm:'Retry the image pass from the first EVIL ENERGY product? Existing images will still be skipped.'});
            });
            </script>
        </div>
        <?php
    }

    public function ajax_capture_snapshot(): void {
        $this->ajax_guard( 'echo_capture_evilenergy_snapshot' );
        $reset = ! empty( $_POST['reset'] );
        if ( $reset ) $this->reset_snapshot();
        $state = $this->snapshot_state();
        if ( ! empty( $state['completed'] ) && ! empty( $state['manual_finalized'] ) ) {
            $state['completed'] = false;
            $state['manual_finalized'] = false;
            $this->save_snapshot_state( $state );
        } elseif ( ! empty( $state['completed'] ) ) {
            wp_send_json_success( $this->snapshot_response( $state, 'The saved EVIL ENERGY snapshot is already complete. Restart only when a fresh snapshot is intentionally required.' ) );
        }
        if ( count( (array) $state['pages'] ) >= self::MAX_PAGES ) {
            wp_send_json_error( array( 'message' => 'Stopped at the safety limit of ' . self::MAX_PAGES . ' supplier requests.' ) );
        }

        $page_number = count( (array) $state['pages'] ) + 1;
        $result = $this->fetch_supplier_page( $page_number, $state );
        if ( is_wp_error( $result ) ) {
            $state['last_error'] = $result->get_error_message();
            $state['failed_requests'] = (int) $state['failed_requests'] + 1;
            $this->save_snapshot_state( $state );
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        $raw_products = array_values( array_filter( (array) $result['products'], 'is_array' ) );
        $existing_ids = $this->existing_snapshot_ids( $state );
        $products = array();
        foreach ( $raw_products as $product ) {
            $id = absint( $product['id'] ?? 0 );
            if ( ! $id || isset( $existing_ids[ $id ] ) ) continue;
            $existing_ids[ $id ] = true;
            $products[] = $product;
        }
        $raw_count = count( $raw_products );
        $count = count( $products );

        if ( 0 === $raw_count || 0 === $count ) {
            $state['completed'] = true;
            $state['last_error'] = '';
            $state['updated_at'] = gmdate( 'c' );
            $this->save_snapshot_state( $state );
            $message = 0 === $raw_count ? 'The next supplier page was empty. Snapshot complete.' : 'The next supplier page contained no new product IDs. Snapshot complete without saving a duplicate batch.';
            wp_send_json_success( $this->snapshot_response( $state, $message ) );
        }

        $file = $this->snapshot_file( $page_number );
        if ( ! $file ) wp_send_json_error( array( 'message' => 'WordPress could not create the EVIL ENERGY snapshot folder.' ) );
        $payload = array(
            'source'     => (string) $result['endpoint'],
            'fetched_at' => gmdate( 'c' ),
            'products'   => $products,
        );
        $written = file_put_contents( $file, wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if ( false === $written ) wp_send_json_error( array( 'message' => 'WordPress could not save the EVIL ENERGY snapshot batch.' ) );

        $state['pages'][] = $file;
        $state['total_products'] = (int) $state['total_products'] + $count;
        $state['last_error'] = '';
        $state['completed'] = $raw_count < self::PAGE_SIZE;
        $state['manual_finalized'] = false;
        $state['updated_at'] = gmdate( 'c' );
        $this->save_snapshot_state( $state );

        $message = sprintf( 'Saved supplier batch %d: %d new products. Total saved locally: %d.', $page_number, $count, (int) $state['total_products'] );
        if ( $state['completed'] ) $message .= ' The supplier returned fewer than ' . self::PAGE_SIZE . ' products, so the offline snapshot is complete.';
        else $message .= ' No additional supplier request will run until the button is clicked again.';
        wp_send_json_success( $this->snapshot_response( $state, $message ) );
    }

    public function ajax_sync_catalog(): void {
        $this->ajax_guard( 'echo_sync_evilenergy_catalog' );
        if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Product' ) ) {
            wp_send_json_error( array( 'message' => 'WooCommerce must be active.' ) );
        }

        $snapshot = $this->snapshot_state();
        if ( empty( $snapshot['pages'] ) ) {
            wp_send_json_error( array( 'message' => 'Capture or restore the EVIL ENERGY offline snapshot first.' ) );
        }

        /*
         * A valid saved snapshot is enough to build. Do not require another
         * supplier request merely because an older plugin version did not mark
         * the snapshot complete.
         */
        if ( empty( $snapshot['completed'] ) ) {
            $saved_products = $this->all_snapshot_products( $snapshot );
            if ( is_wp_error( $saved_products ) || empty( $saved_products ) ) {
                wp_send_json_error( array( 'message' => is_wp_error( $saved_products ) ? $saved_products->get_error_message() : 'No valid EVIL ENERGY products were found in the saved snapshot.' ) );
            }
            $snapshot['total_products']   = count( $saved_products );
            $snapshot['completed']        = true;
            $snapshot['manual_finalized'] = true;
            $snapshot['updated_at']       = gmdate( 'c' );
            $this->save_snapshot_state( $snapshot );
        }

        $total = max( 0, (int) $snapshot['total_products'] );
        $reset = ! empty( $_POST['reset'] );
        $state = $reset ? $this->empty_catalog_state( $total ) : $this->catalog_state( $total );
        if ( $reset ) $this->save_catalog_state( $state );

        /* A later snapshot batch can add products after an earlier build ended. */
        if ( ! empty( $state['completed'] ) && (int) $state['processed'] < $total ) {
            $state['completed'] = false;
            $this->save_catalog_state( $state );
        }
        if ( ! empty( $state['completed'] ) ) {
            wp_send_json_success( $this->catalog_response( $state, 'The saved EVIL ENERGY catalog build is already complete.', array() ) );
        }

        $started  = microtime( true );
        $messages = array();
        $warnings = array();
        $worked   = 0;

        while ( $worked < self::CATALOG_BATCH_SIZE && ( microtime( true ) - $started ) < self::CATALOG_TIME_BUDGET ) {
            $next = $this->next_snapshot_product( $snapshot, $state );
            if ( is_wp_error( $next ) ) {
                wp_send_json_error( array( 'message' => $next->get_error_message() ) );
            }
            if ( empty( $next['product'] ) ) {
                $state['completed'] = true;
                $this->save_catalog_state( $state );
                $messages[] = 'Reached the end of the local EVIL ENERGY snapshot.';
                break;
            }

            $source = (array) $next['product'];
            $source_id = absint( $source['id'] ?? 0 );
            $result = $this->sync_product( $source );

            if ( is_wp_error( $result ) ) {
                $retry_source = absint( $state['retry_source_id'] ?? 0 );
                $retry_count  = (int) ( $state['retry_count'] ?? 0 );

                if ( $source_id && $retry_source !== $source_id ) {
                    $state['retry_source_id'] = $source_id;
                    $state['retry_count']     = 1;
                    $this->save_catalog_state( $state );
                    $warnings[] = $result->get_error_message();
                    $messages[] = 'One local WooCommerce save failed. The same product will be retried once before it is skipped.';
                    break;
                }

                if ( $retry_count < 2 ) {
                    $state['retry_count'] = $retry_count + 1;
                    $this->save_catalog_state( $state );
                    $warnings[] = $result->get_error_message();
                    $messages[] = 'Retrying the same local product on the next saved step.';
                    break;
                }

                $state['failed'] = (int) $state['failed'] + 1;
                $warnings[] = $result->get_error_message();
                $messages[] = 'Skipped one source product after two local WooCommerce save failures.';
            } else {
                $counter = ! empty( $result['created'] ) ? 'created' : 'updated';
                $state[ $counter ] = (int) $state[ $counter ] + 1;
                $state['variations_created'] = (int) $state['variations_created'] + (int) $result['variations_created'];
                $state['variations_updated'] = (int) $state['variations_updated'] + (int) $result['variations_updated'];
                $messages[] = sprintf(
                    '%s — %s; %d variation(s); scope: %s; brand: EVIL ENERGY.',
                    $result['name'],
                    ! empty( $result['created'] ) ? 'created' : 'refreshed',
                    (int) $result['variation_count'],
                    $result['scope']
                );
            }

            $state['retry_source_id'] = 0;
            $state['retry_count']     = 0;
            $state['processed']       = (int) $state['processed'] + 1;
            $state['page_index']      = (int) $next['page_index'];
            $state['product_index']   = (int) $next['product_index'] + 1;
            if ( (int) $state['processed'] >= $total ) $state['completed'] = true;

            /* Save after every product so a Hostinger timeout cannot erase work. */
            $this->save_catalog_state( $state );
            $worked++;

            if ( ! empty( $state['completed'] ) ) break;
        }

        if ( empty( $messages ) ) $messages[] = 'No unsaved EVIL ENERGY products were found in this local step.';
        wp_send_json_success( $this->catalog_response( $state, implode( "\n", $messages ), $warnings ) );
    }

    public function ajax_sync_images(): void {
        $this->ajax_guard( 'echo_sync_evilenergy_images' );
        if ( ! class_exists( 'WooCommerce' ) ) wp_send_json_error( array( 'message' => 'WooCommerce must be active.' ) );
        $reset = ! empty( $_POST['reset'] );
        $state = $reset ? $this->empty_image_state() : $this->image_state();
        if ( $reset ) $this->save_image_state( $state );
        if ( ! empty( $state['completed'] ) ) {
            wp_send_json_success( $this->image_response( $state, 'The saved EVIL ENERGY image pass is already complete.', array() ) );
        }

        $ids = $this->supplier_product_ids();
        $state['total'] = count( $ids );
        if ( (int) $state['index'] >= count( $ids ) ) {
            $state['completed'] = true;
            $this->save_image_state( $state );
            wp_send_json_success( $this->image_response( $state, 'Reached the end of the EVIL ENERGY product list.', array() ) );
        }

        $product_id = (int) $ids[ (int) $state['index'] ];
        $state['index'] = (int) $state['index'] + 1;
        $state['processed'] = (int) $state['processed'] + 1;
        $warnings = array();
        $name = get_the_title( $product_id );
        $image_url = esc_url_raw( (string) get_post_meta( $product_id, '_echo_source_image_url', true ) );
        if ( has_post_thumbnail( $product_id ) ) {
            $state['skipped'] = (int) $state['skipped'] + 1;
            $message = $name . ' — existing image preserved.';
        } elseif ( ! $image_url ) {
            $state['skipped'] = (int) $state['skipped'] + 1;
            $message = $name . ' — no source image URL.';
        } else {
            $result = $this->sideload_image( $image_url, $product_id, $name );
            if ( is_wp_error( $result ) ) {
                $state['failed'] = (int) $state['failed'] + 1;
                $warnings[] = $name . ': ' . $result->get_error_message();
                $message = $name . ' — image failed; progress saved.';
            } else {
                set_post_thumbnail( $product_id, (int) $result );
                update_post_meta( $product_id, '_echo_source_image_synced', gmdate( 'c' ) );
                $state['downloaded'] = (int) $state['downloaded'] + 1;
                $message = $name . ' — image downloaded.';
            }
        }
        if ( (int) $state['index'] >= count( $ids ) ) $state['completed'] = true;
        $this->save_image_state( $state );
        wp_send_json_success( $this->image_response( $state, $message, $warnings ) );
    }

    private function fetch_supplier_page( int $page_number, array $state ) {
        /*
         * Some Shopify storefronts ignore the old page parameter and return
         * page one repeatedly. Continue from the largest saved product ID
         * instead. The button still makes exactly one supplier request.
         */
        $known_ids = array_keys( $this->existing_snapshot_ids( $state ) );
        $since_id  = $known_ids ? max( array_map( 'absint', $known_ids ) ) : 0;
        $query     = array( 'limit' => self::PAGE_SIZE );
        if ( $since_id > 0 ) $query['since_id'] = $since_id;
        elseif ( $page_number > 1 ) $query['page'] = $page_number;

        $urls = array(
            add_query_arg( $query, 'https://www.ievilenergy.com/products.json' ),
            add_query_arg( $query, 'https://www.ievilenergy.com/collections/all/products.json' ),
        );
        $errors = array();
        foreach ( $urls as $url ) {
            $response = wp_safe_remote_get(
                $url,
                array(
                    'timeout'             => 28,
                    'redirection'         => 3,
                    'limit_response_size' => 12 * MB_IN_BYTES,
                    'user-agent'          => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/150 Safari/537.36 EchoMotorworksCatalog/0.4.4',
                    'headers'             => array( 'Accept' => 'application/json,text/plain,*/*' ),
                )
            );
            if ( is_wp_error( $response ) ) {
                $errors[] = $response->get_error_message();
                continue;
            }
            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( 200 !== $code ) {
                $retry_after = sanitize_text_field( (string) wp_remote_retrieve_header( $response, 'retry-after' ) );
                $errors[] = 'Supplier returned HTTP ' . $code . ( $retry_after ? ' (retry after ' . $retry_after . ')' : '' );
                continue;
            }
            $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! is_array( $decoded ) || ! isset( $decoded['products'] ) || ! is_array( $decoded['products'] ) ) {
                $errors[] = 'Supplier response was not a Shopify products document.';
                continue;
            }
            return array( 'endpoint' => $url, 'products' => $decoded['products'], 'since_id' => $since_id );
        }
        return new WP_Error( 'evilenergy_supplier', 'EVIL ENERGY snapshot paused: ' . implode( ' | ', array_unique( $errors ) ) );
    }

    private function sync_product( array $source ) {
        $source_id = absint( $source['id'] ?? 0 );
        $name = sanitize_text_field( wp_strip_all_tags( (string) ( $source['title'] ?? '' ) ) );
        if ( ! $source_id || ! $name ) return new WP_Error( 'evilenergy_product', 'Source product is missing an ID or title.' );
        $variants = array_values( array_filter( (array) ( $source['variants'] ?? array() ), 'is_array' ) );
        if ( ! $variants ) return new WP_Error( 'evilenergy_variants', $name . ': no public Shopify variants were returned.' );
        $is_variable = count( $variants ) > 1 || ! $this->is_default_variant( $variants[0], (array) ( $source['options'] ?? array() ) );
        $existing_id = $this->find_product_by_source_id( $source_id );
        $created = ! $existing_id;

        if ( $is_variable ) {
            if ( $existing_id ) wp_set_object_terms( $existing_id, 'variable', 'product_type' );
            $product = new WC_Product_Variable( $existing_id );
        } else {
            if ( $existing_id ) wp_set_object_terms( $existing_id, 'simple', 'product_type' );
            $product = new WC_Product_Simple( $existing_id );
        }

        $product->set_name( $name );
        $product->set_status( empty( $source['published_at'] ) ? 'draft' : 'publish' );
        $product->set_catalog_visibility( 'visible' );
        $product->set_reviews_allowed( true );
        $product->set_manage_stock( false );
        if ( $created ) {
            $base_sku = $is_variable ? 'EVIL-P-' . $source_id : $this->source_variant_sku( $variants[0], 'EVIL-' . $source_id );
            $product->set_sku( $this->unique_sku( $base_sku ) );
        }

        $handle = sanitize_title( (string) ( $source['handle'] ?? '' ) );
        $source_url = $handle ? 'https://www.ievilenergy.com/products/' . $handle : 'https://www.ievilenergy.com/';
        $body = wp_kses_post( (string) ( $source['body_html'] ?? '' ) );
        $notice = '<p><strong>Compatibility:</strong> Confirm dimensions, AN/thread standard, hose material, fuel compatibility, pressure, temperature, electrical requirements and installation clearance before ordering. “Universal” describes a dimensional component, not guaranteed fitment for every vehicle.</p>';
        $source_line = '<p><small>Supplier source: <a href="' . esc_url( $source_url ) . '" target="_blank" rel="noopener nofollow">EVIL ENERGY product page</a></small></p>';
        $product->set_description( $body . $notice . $source_line );
        $product->set_short_description( wp_trim_words( wp_strip_all_tags( $body ), 30, '…' ) );
        $source_tags = $this->normalize_source_tags( $source['tags'] ?? array() );
        $product->set_category_ids( $this->category_ids( (string) ( $source['product_type'] ?? '' ) ) );
        $product->set_tag_ids( $this->tag_ids( $source_tags ) );

        if ( $is_variable ) {
            $product->set_attributes( $this->variation_attributes( (array) ( $source['options'] ?? array() ), $variants ) );
        } else {
            $this->apply_variant_commerce( $product, $variants[0] );
        }

        $product_id = $product->save();
        if ( ! $product_id ) return new WP_Error( 'evilenergy_save', $name . ': WooCommerce could not save the product.' );

        update_post_meta( $product_id, '_echo_supplier', 'EVIL ENERGY' );
        update_post_meta( $product_id, '_echo_manufacturer', 'EVIL ENERGY' );
        update_post_meta( $product_id, '_echo_evilenergy_source_id', (string) $source_id );
        update_post_meta( $product_id, '_echo_evilenergy_source_key', self::SOURCE_KEY );
        update_post_meta( $product_id, '_echo_source_url', esc_url_raw( $source_url ) );
        update_post_meta( $product_id, '_echo_source_checked', gmdate( 'Y-m-d' ) );
        update_post_meta( $product_id, '_echo_source_updated_at', sanitize_text_field( (string) ( $source['updated_at'] ?? '' ) ) );
        update_post_meta( $product_id, '_echo_source_status', 'EVIL ENERGY Shopify snapshot; stock and price captured at snapshot time.' );
        $image_url = $this->primary_image_url( $source );
        if ( $image_url ) update_post_meta( $product_id, '_echo_source_image_url', $image_url );
        $this->assign_evilenergy_brand( $product_id );
        $scope = $this->set_scope( $product_id, $name . ' ' . wp_strip_all_tags( $body ) . ' ' . implode( ' ', $source_tags ) );

        $variations_created = 0;
        $variations_updated = 0;
        if ( $is_variable ) {
            $active_ids = array();
            foreach ( $variants as $variant_source ) {
                $result = $this->sync_variation( $product_id, $variant_source, (array) ( $source['options'] ?? array() ) );
                if ( is_wp_error( $result ) ) continue;
                $active_ids[] = (int) $result['variation_id'];
                if ( $result['created'] ) $variations_created++;
                else $variations_updated++;
            }
            $this->retire_missing_variations( $product_id, $active_ids );
            WC_Product_Variable::sync( $product_id );
            wc_delete_product_transients( $product_id );
        }

        return array(
            'created'            => $created,
            'name'               => $name,
            'scope'              => $scope,
            'variation_count'    => $is_variable ? count( $variants ) : 0,
            'variations_created' => $variations_created,
            'variations_updated' => $variations_updated,
        );
    }

    private function sync_variation( int $parent_id, array $source, array $options ) {
        $source_id = absint( $source['id'] ?? 0 );
        if ( ! $source_id ) return new WP_Error( 'evilenergy_variation', 'A source variation is missing its Shopify ID.' );
        $existing_id = $this->find_variation_by_source_id( $source_id );
        $created = ! $existing_id;
        $variation = new WC_Product_Variation( $existing_id );
        $variation->set_parent_id( $parent_id );
        $variation->set_status( 'publish' );
        $variation->set_manage_stock( false );
        if ( $created ) $variation->set_sku( $this->unique_sku( $this->source_variant_sku( $source, 'EVIL-V-' . $source_id ) ) );
        $attributes = array();
        foreach ( array_values( $options ) as $index => $option ) {
            $name = sanitize_title( (string) ( $option['name'] ?? 'Option ' . ( $index + 1 ) ) );
            $value = sanitize_text_field( (string) ( $source[ 'option' . ( $index + 1 ) ] ?? '' ) );
            if ( $name && $value ) $attributes[ $name ] = $value;
        }
        $variation->set_attributes( $attributes );
        $this->apply_variant_commerce( $variation, $source );
        $variation_id = $variation->save();
        if ( ! $variation_id ) return new WP_Error( 'evilenergy_variation_save', 'WooCommerce could not save EVIL ENERGY variation ' . $source_id . '.' );
        update_post_meta( $variation_id, '_echo_evilenergy_variant_id', (string) $source_id );
        update_post_meta( $variation_id, '_echo_supplier_sku', sanitize_text_field( (string) ( $source['sku'] ?? '' ) ) );
        update_post_meta( $variation_id, '_echo_supplier', 'EVIL ENERGY' );
        $image_url = esc_url_raw( (string) ( $source['featured_image']['src'] ?? '' ) );
        if ( $image_url ) update_post_meta( $variation_id, '_echo_source_image_url', $image_url );
        return array( 'variation_id' => $variation_id, 'created' => $created );
    }

    private function apply_variant_commerce( WC_Product $product, array $variant ): void {
        $price = wc_format_decimal( (string) ( $variant['price'] ?? '' ) );
        $compare = wc_format_decimal( (string) ( $variant['compare_at_price'] ?? '' ) );
        if ( '' !== $price ) {
            if ( '' !== $compare && (float) $compare > (float) $price ) {
                $product->set_regular_price( $compare );
                $product->set_sale_price( $price );
                $product->set_price( $price );
            } else {
                $product->set_regular_price( $price );
                $product->set_sale_price( '' );
                $product->set_price( $price );
            }
        }
        $product->set_stock_status( ! empty( $variant['available'] ) ? 'instock' : 'outofstock' );
    }

    private function variation_attributes( array $options, array $variants ): array {
        $attributes = array();
        foreach ( array_values( $options ) as $index => $option ) {
            $name = sanitize_text_field( (string) ( $option['name'] ?? '' ) );
            if ( ! $name || 'Title' === $name ) continue;
            $values = array_values( array_unique( array_filter( array_map(
                static fn( $variant ) => sanitize_text_field( (string) ( $variant[ 'option' . ( $index + 1 ) ] ?? '' ) ),
                $variants
            ) ) ) );
            if ( ! $values ) continue;
            $attribute = new WC_Product_Attribute();
            $attribute->set_id( 0 );
            $attribute->set_name( $name );
            $attribute->set_options( $values );
            $attribute->set_position( $index );
            $attribute->set_visible( true );
            $attribute->set_variation( true );
            $attributes[] = $attribute;
        }
        return $attributes;
    }

    private function set_scope( int $product_id, string $text ): string {
        $plain = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
        $vehicle_pattern = '~\b(?:Ford|Chevrolet|Chevy|GMC|Dodge|Ram|Jeep|Chrysler|Toyota|Lexus|Honda|Acura|Nissan|Infiniti|Subaru|Mazda|Mitsubishi|Hyundai|Kia|BMW|Mercedes|Audi|Volkswagen|VW|Volvo|Cadillac|Buick|Pontiac|Mustang|Camaro|Corvette|Silverado|Sierra|Tahoe|Suburban|F[- ]?150|F[- ]?250|F[- ]?350|F[- ]?450|F[- ]?550|Civic|Accord|Integra|WRX|BRZ|GT86|Supra|350Z|370Z|Miata|Tacoma|Tundra|4Runner|Charger|Challenger|Durango)\b~i';
        $engine_pattern = '~\b(?:LS swap|LS1|LS2|LS3|LS6|LS7|LSA|LT1|SBC|BBC|small block|big block|Coyote|Hemi|Vortec|Duramax|Powerstroke|Power Stroke|Cummins|K[- ]?series|B[- ]?series)\b~i';
        if ( preg_match( $vehicle_pattern, $plain ) ) {
            $type = 'vehicle_specific';
            $raw = 'Supplier copy names a vehicle or OEM application. Exact Year/Make/Model fitment has not been independently verified.';
            $confidence = 'medium';
            $reason = 'Vehicle-specific catalog item; keep out of broad compatible results until exact application data is verified.';
        } elseif ( preg_match( $engine_pattern, $plain ) ) {
            $type = 'engine_specific';
            $raw = 'Engine-family or swap component. Confirm engine generation, fuel pressure, fittings, electronics and chassis installation requirements.';
            $confidence = 'medium';
            $reason = 'Engine-family fitment cannot be represented accurately by a universal Year/Make/Model match.';
        } else {
            $type = 'universal';
            $raw = 'Universal/dimensional aftermarket component. Match size, thread, material, pressure, temperature, fluid and installation specifications.';
            $confidence = 'high';
            $reason = 'Supplier product is selected by technical dimensions and system requirements rather than a single vehicle application.';
        }
        update_post_meta( $product_id, '_echo_fitment_type', $type );
        update_post_meta( $product_id, '_echo_fitment_raw', $raw );
        update_post_meta( $product_id, '_echo_fitment_confidence', $confidence );
        update_post_meta( $product_id, '_echo_fitment_reason', $reason );
        return $type;
    }

    private function next_snapshot_product( array $snapshot, array $state ) {
        $page_index = (int) $state['page_index'];
        $product_index = (int) $state['product_index'];
        $pages = array_values( (array) $snapshot['pages'] );
        while ( isset( $pages[ $page_index ] ) ) {
            $payload = $this->read_snapshot_file( (string) $pages[ $page_index ] );
            if ( is_wp_error( $payload ) ) return $payload;
            $products = (array) ( $payload['products'] ?? array() );
            if ( isset( $products[ $product_index ] ) ) {
                return array( 'product' => $products[ $product_index ], 'page_index' => $page_index, 'product_index' => $product_index );
            }
            $page_index++;
            $product_index = 0;
        }
        return array( 'product' => null, 'page_index' => $page_index, 'product_index' => 0 );
    }

    private function read_snapshot_file( string $file ) {
        if ( ! is_readable( $file ) ) return new WP_Error( 'evilenergy_snapshot_file', 'A saved EVIL ENERGY snapshot file is missing.' );
        $decoded = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ( ! is_array( $decoded ) || ! isset( $decoded['products'] ) ) return new WP_Error( 'evilenergy_snapshot_json', 'A saved EVIL ENERGY snapshot file is invalid.' );
        return $decoded;
    }

    private function category_ids( string $product_type ): array {
        if ( ! taxonomy_exists( 'product_cat' ) ) return array();
        $root = $this->term_id( 'EVIL ENERGY', 'product_cat', 0 );
        $ids = $root ? array( $root ) : array();
        $product_type = trim( wp_strip_all_tags( $product_type ) );
        if ( $root && $product_type ) {
            $child = $this->term_id( $product_type, 'product_cat', $root );
            if ( $child ) $ids[] = $child;
        }
        return array_values( array_unique( $ids ) );
    }

    private function normalize_source_tags( $tags ): array {
        if ( is_string( $tags ) ) $tags = preg_split( '/\s*,\s*/', $tags, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $tags ) ) return array();
        $clean = array();
        foreach ( $tags as $tag ) {
            $tag = trim( wp_strip_all_tags( (string) $tag ) );
            if ( ! $tag || strlen( $tag ) > 70 ) continue;
            if ( in_array( strtolower( $tag ), array( 'evil energy', 'evilenergy' ), true ) ) continue;
            $clean[] = $tag;
        }
        return array_values( array_unique( $clean ) );
    }

    private function tag_ids( array $tags ): array {
        if ( ! taxonomy_exists( 'product_tag' ) ) return array();
        $ids = array();
        foreach ( array_slice( $tags, 0, 8 ) as $name ) {
            $id = $this->term_id( $name, 'product_tag', 0 );
            if ( $id ) $ids[] = $id;
        }
        return array_values( array_unique( $ids ) );
    }

    private function assign_evilenergy_brand( int $product_id ): void {
        $assigned = false;
        foreach ( array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'berocket_brand' ) as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) continue;
            $term_id = $this->term_id( 'EVIL ENERGY', $taxonomy, 0 );
            if ( ! $term_id ) continue;
            $result = wp_set_object_terms( $product_id, array( $term_id ), $taxonomy, false );
            if ( ! is_wp_error( $result ) ) $assigned = true;
        }
        if ( taxonomy_exists( 'product_tag' ) ) {
            foreach ( array( 'EVIL ENERGY', 'Evil Energy', 'EvilEnergy' ) as $legacy_tag ) {
                $term = term_exists( $legacy_tag, 'product_tag' );
                if ( $term ) wp_remove_object_terms( $product_id, absint( is_array( $term ) ? $term['term_id'] : $term ), 'product_tag' );
            }
        }
        if ( $assigned ) update_post_meta( $product_id, '_echo_brand', 'EVIL ENERGY' );
        if ( function_exists( 'wc_delete_product_transients' ) ) wc_delete_product_transients( $product_id );
        clean_post_cache( $product_id );
    }

    private function term_id( string $name, string $taxonomy, int $parent = 0 ): int {
        $term = term_exists( $name, $taxonomy, $parent ?: null );
        if ( ! $term ) $term = wp_insert_term( $name, $taxonomy, $parent ? array( 'parent' => $parent ) : array() );
        if ( is_wp_error( $term ) ) return 0;
        return (int) ( is_array( $term ) ? $term['term_id'] : $term );
    }

    private function primary_image_url( array $source ): string {
        $url = (string) ( $source['image']['src'] ?? '' );
        if ( ! $url && ! empty( $source['images'][0]['src'] ) ) $url = (string) $source['images'][0]['src'];
        return esc_url_raw( $url );
    }

    private function is_default_variant( array $variant, array $options ): bool {
        if ( count( $options ) > 1 ) return false;
        $option_name = (string) ( $options[0]['name'] ?? 'Title' );
        $title = (string) ( $variant['title'] ?? 'Default Title' );
        return 'Title' === $option_name && in_array( $title, array( 'Default Title', 'Default' ), true );
    }

    private function source_variant_sku( array $variant, string $fallback ): string {
        $supplier_sku = sanitize_text_field( (string) ( $variant['sku'] ?? '' ) );
        if ( $supplier_sku ) return 'EVIL-' . $supplier_sku;
        return $fallback;
    }

    private function unique_sku( string $raw, int $ignore_product_id = 0 ): string {
        $base = strtoupper( preg_replace( '/[^A-Za-z0-9._-]+/', '-', trim( $raw ) ) );
        $base = trim( $base, '-' );
        if ( ! $base ) $base = 'EVIL-' . wp_generate_password( 8, false, false );
        $candidate = substr( $base, 0, 90 );
        $suffix = 1;
        while ( true ) {
            $found = absint( wc_get_product_id_by_sku( $candidate ) );
            if ( ! $found || $found === $ignore_product_id ) return $candidate;
            $candidate = substr( $base, 0, 82 ) . '-' . $suffix++;
        }
    }

    private function find_product_by_source_id( int $source_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_echo_evilenergy_source_id' AND meta_value = %s ORDER BY post_id ASC LIMIT 1",
            (string) $source_id
        ) );
    }

    private function find_variation_by_source_id( int $source_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_echo_evilenergy_variant_id' AND meta_value = %s ORDER BY post_id ASC LIMIT 1",
            (string) $source_id
        ) );
    }

    private function retire_missing_variations( int $parent_id, array $active_ids ): void {
        foreach ( wc_get_products( array( 'type' => 'variation', 'parent' => $parent_id, 'limit' => -1, 'return' => 'ids' ) ) as $variation_id ) {
            $variation_id = (int) $variation_id;
            if ( in_array( $variation_id, $active_ids, true ) ) continue;
            if ( ! get_post_meta( $variation_id, '_echo_evilenergy_variant_id', true ) ) continue;
            $variation = wc_get_product( $variation_id );
            if ( $variation ) {
                $variation->set_stock_status( 'outofstock' );
                $variation->set_status( 'private' );
                $variation->save();
            }
        }
    }

    private function supplier_product_ids(): array {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID WHERE p.post_type='product' AND pm.meta_key='_echo_supplier' AND pm.meta_value=%s ORDER BY p.ID ASC",
            'EVIL ENERGY'
        ) );
        return array_map( 'absint', $ids );
    }

    private function sideload_image( string $url, int $product_id, string $name ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $tmp = download_url( $url, 25 );
        if ( is_wp_error( $tmp ) ) return $tmp;
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $filename = sanitize_file_name( basename( $path ) ?: sanitize_title( $name ) . '.jpg' );
        if ( ! pathinfo( $filename, PATHINFO_EXTENSION ) ) $filename .= '.jpg';
        $file = array( 'name' => $filename, 'tmp_name' => $tmp );
        $attachment_id = media_handle_sideload( $file, $product_id, $name );
        if ( is_wp_error( $attachment_id ) ) @unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        return $attachment_id;
    }


    public function finalize_snapshot(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'echo_finalize_evilenergy_snapshot' );
        $state = $this->snapshot_state();
        if ( empty( $state['pages'] ) ) $this->redirect_notice( 'No saved EVIL ENERGY products exist yet.', 'warning' );
        $products = $this->all_snapshot_products( $state );
        if ( is_wp_error( $products ) ) $this->redirect_notice( $products->get_error_message(), 'error' );
        $state['total_products'] = count( $products );
        $state['completed'] = true;
        $state['manual_finalized'] = true;
        $state['updated_at'] = gmdate( 'c' );
        $this->save_snapshot_state( $state );
        delete_option( self::CATALOG_STATE_OPTION );
        $this->redirect_notice( sprintf( 'Saved offline snapshot finalized with %d products. The local catalog builder is ready.', count( $products ) ), 'success' );
    }

    public function export_snapshot(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'echo_export_evilenergy_snapshot' );
        $state = $this->snapshot_state();
        $products = $this->all_snapshot_products( $state );
        if ( is_wp_error( $products ) ) wp_die( esc_html( $products->get_error_message() ) );
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="evil-energy-offline-snapshot.json"' );
        echo wp_json_encode(
            array(
                'snapshot_version' => 1,
                'supplier'         => 'EVIL ENERGY',
                'created_at'       => gmdate( 'c' ),
                'product_count'    => count( $products ),
                'products'         => $products,
            ),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    public function export_snapshot_csv(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'echo_export_evilenergy_snapshot_csv' );
        $state = $this->snapshot_state();
        $products = $this->all_snapshot_products( $state );
        if ( is_wp_error( $products ) ) wp_die( esc_html( $products->get_error_message() ) );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="evil-energy-snapshot-product-list.csv"' );
        $out = fopen( 'php://output', 'wb' );
        fputcsv( $out, array( 'source_product_id', 'title', 'handle', 'product_type', 'tags', 'published_at', 'updated_at', 'source_url', 'primary_image', 'variant_count', 'available_variants', 'minimum_price', 'maximum_price' ) );
        foreach ( $products as $product ) {
            $variants = array_values( array_filter( (array) ( $product['variants'] ?? array() ), 'is_array' ) );
            $prices = array();
            $available = 0;
            foreach ( $variants as $variant ) {
                $price = (float) ( $variant['price'] ?? 0 );
                if ( $price > 0 ) $prices[] = $price;
                if ( ! empty( $variant['available'] ) ) $available++;
            }
            $handle = sanitize_title( (string) ( $product['handle'] ?? '' ) );
            fputcsv( $out, array(
                absint( $product['id'] ?? 0 ),
                wp_strip_all_tags( (string) ( $product['title'] ?? '' ) ),
                $handle,
                wp_strip_all_tags( (string) ( $product['product_type'] ?? '' ) ),
                is_array( $product['tags'] ?? null ) ? implode( ', ', $product['tags'] ) : (string) ( $product['tags'] ?? '' ),
                sanitize_text_field( (string) ( $product['published_at'] ?? '' ) ),
                sanitize_text_field( (string) ( $product['updated_at'] ?? '' ) ),
                $handle ? 'https://www.ievilenergy.com/products/' . $handle : 'https://www.ievilenergy.com/',
                $this->primary_image_url( $product ),
                count( $variants ),
                $available,
                $prices ? min( $prices ) : '',
                $prices ? max( $prices ) : '',
            ) );
        }
        fclose( $out );
        exit;
    }

    public function import_snapshot(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'echo_import_evilenergy_snapshot' );
        if ( empty( $_FILES['evilenergy_snapshot']['tmp_name'] ) || ! is_uploaded_file( $_FILES['evilenergy_snapshot']['tmp_name'] ) ) {
            $this->redirect_notice( 'Choose an EVIL ENERGY offline snapshot JSON file first.', 'warning' );
        }
        $size = (int) ( $_FILES['evilenergy_snapshot']['size'] ?? 0 );
        if ( $size <= 0 || $size > 60 * MB_IN_BYTES ) $this->redirect_notice( 'The offline snapshot must be a valid JSON file smaller than 60 MB.', 'error' );
        $decoded = json_decode( (string) file_get_contents( $_FILES['evilenergy_snapshot']['tmp_name'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $products = is_array( $decoded ) && isset( $decoded['products'] ) && is_array( $decoded['products'] ) ? $decoded['products'] : array();
        if ( ! $products ) $this->redirect_notice( 'The uploaded JSON does not contain an EVIL ENERGY products array.', 'error' );
        $unique = array();
        foreach ( $products as $product ) {
            if ( ! is_array( $product ) ) continue;
            $id = absint( $product['id'] ?? 0 );
            $title = trim( wp_strip_all_tags( (string) ( $product['title'] ?? '' ) ) );
            if ( ! $id || ! $title ) continue;
            $unique[ $id ] = $product;
        }
        if ( ! $unique ) $this->redirect_notice( 'No valid product IDs and titles were found in the uploaded snapshot.', 'error' );
        $this->reset_snapshot();
        $files = array();
        $chunks = array_chunk( array_values( $unique ), self::PAGE_SIZE );
        foreach ( $chunks as $index => $chunk ) {
            $file = $this->snapshot_file( $index + 1 );
            if ( ! $file ) $this->redirect_notice( 'WordPress could not create the EVIL ENERGY snapshot folder.', 'error' );
            $payload = array( 'source' => 'offline-upload', 'fetched_at' => gmdate( 'c' ), 'products' => $chunk );
            if ( false === file_put_contents( $file, wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                $this->redirect_notice( 'WordPress could not save the uploaded offline snapshot.', 'error' );
            }
            $files[] = $file;
        }
        $state = $this->empty_snapshot_state();
        $state['pages'] = $files;
        $state['total_products'] = count( $unique );
        $state['completed'] = true;
        $state['manual_finalized'] = true;
        $state['updated_at'] = gmdate( 'c' );
        $this->save_snapshot_state( $state );
        delete_option( self::CATALOG_STATE_OPTION );
        $this->redirect_notice( sprintf( 'Offline EVIL ENERGY snapshot uploaded: %d products across %d local batch(es).', count( $unique ), count( $files ) ), 'success' );
    }

    public function export_products(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'echo_export_evilenergy_products' );
        if ( ! class_exists( 'WooCommerce' ) ) wp_die( 'WooCommerce must be active.' );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="evil-energy-products.csv"' );
        $out = fopen( 'php://output', 'wb' );
        fputcsv( $out, array( 'product_id', 'product_sku', 'parent_id', 'name', 'type', 'price', 'stock_status', 'fitment_type', 'fitment_raw', 'confidence', 'reason', 'source_url', 'source_image' ) );
        foreach ( $this->supplier_product_ids() as $id ) {
            $product = wc_get_product( $id );
            if ( ! $product ) continue;
            $this->export_product_row( $out, $product );
            if ( $product->is_type( 'variable' ) ) {
                foreach ( $product->get_children() as $child_id ) {
                    $child = wc_get_product( $child_id );
                    if ( $child ) $this->export_product_row( $out, $child );
                }
            }
        }
        fclose( $out );
        exit;
    }

    private function export_product_row( $out, WC_Product $product ): void {
        $id = $product->get_id();
        $parent_id = $product->get_parent_id();
        $scope_id = $parent_id ?: $id;
        fputcsv( $out, array(
            $id,
            $product->get_sku(),
            $parent_id,
            $product->get_name(),
            $product->get_type(),
            $product->get_price(),
            $product->get_stock_status(),
            get_post_meta( $scope_id, '_echo_fitment_type', true ),
            get_post_meta( $scope_id, '_echo_fitment_raw', true ),
            get_post_meta( $scope_id, '_echo_fitment_confidence', true ),
            get_post_meta( $scope_id, '_echo_fitment_reason', true ),
            get_post_meta( $scope_id, '_echo_source_url', true ),
            get_post_meta( $id, '_echo_source_image_url', true ) ?: get_post_meta( $scope_id, '_echo_source_image_url', true ),
        ) );
    }


    private function existing_snapshot_ids( array $state ): array {
        $ids = array();
        foreach ( (array) $state['pages'] as $file ) {
            $payload = $this->read_snapshot_file( (string) $file );
            if ( is_wp_error( $payload ) ) continue;
            foreach ( (array) ( $payload['products'] ?? array() ) as $product ) {
                $id = absint( is_array( $product ) ? ( $product['id'] ?? 0 ) : 0 );
                if ( $id ) $ids[ $id ] = true;
            }
        }
        return $ids;
    }

    private function all_snapshot_products( array $state ) {
        if ( empty( $state['pages'] ) ) return new WP_Error( 'evilenergy_snapshot_empty', 'No saved EVIL ENERGY snapshot products were found.' );
        $products = array();
        foreach ( (array) $state['pages'] as $file ) {
            $payload = $this->read_snapshot_file( (string) $file );
            if ( is_wp_error( $payload ) ) return $payload;
            foreach ( (array) ( $payload['products'] ?? array() ) as $product ) {
                if ( ! is_array( $product ) ) continue;
                $id = absint( $product['id'] ?? 0 );
                if ( $id ) $products[ $id ] = $product;
            }
        }
        return array_values( $products );
    }

    private function redirect_notice( string $message, string $type = 'success' ): void {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'                  => 'echo-evilenergy',
                    'echo_evil_notice'      => $message,
                    'echo_evil_notice_type' => $type,
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    private function ajax_guard( string $nonce_action ): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
        check_ajax_referer( $nonce_action, 'nonce' );
    }

    private function upload_dir(): string {
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) return '';
        $dir = trailingslashit( $uploads['basedir'] ) . 'echo-motorworks/evil-energy';
        return wp_mkdir_p( $dir ) ? $dir : '';
    }

    private function snapshot_file( int $page ): string {
        $dir = $this->upload_dir();
        return $dir ? trailingslashit( $dir ) . 'snapshot-page-' . $page . '.json' : '';
    }

    private function reset_snapshot(): void {
        $state = $this->snapshot_state();
        foreach ( (array) $state['pages'] as $file ) {
            if ( is_string( $file ) && is_file( $file ) ) @unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        delete_option( self::SNAPSHOT_STATE_OPTION );
        delete_option( self::CATALOG_STATE_OPTION );
    }

    private function empty_snapshot_state(): array {
        return array( 'since_id' => 0, 'pages' => array(), 'total_products' => 0, 'completed' => false, 'manual_finalized' => false, 'failed_requests' => 0, 'last_error' => '', 'updated_at' => '' );
    }

    private function snapshot_state(): array {
        return wp_parse_args( (array) get_option( self::SNAPSHOT_STATE_OPTION, array() ), $this->empty_snapshot_state() );
    }

    private function save_snapshot_state( array $state ): void {
        update_option( self::SNAPSHOT_STATE_OPTION, $state, false );
    }

    private function empty_catalog_state( int $total ): array {
        return array( 'page_index' => 0, 'product_index' => 0, 'total_products' => $total, 'processed' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0, 'variations_created' => 0, 'variations_updated' => 0, 'retry_source_id' => 0, 'retry_count' => 0, 'completed' => false );
    }

    private function catalog_state( int $total ): array {
        $state = wp_parse_args( (array) get_option( self::CATALOG_STATE_OPTION, array() ), $this->empty_catalog_state( $total ) );
        $state['total_products'] = $total;
        if ( (int) $state['processed'] < $total ) $state['completed'] = false;
        return $state;
    }

    private function save_catalog_state( array $state ): void {
        update_option( self::CATALOG_STATE_OPTION, $state, false );
    }

    private function empty_image_state(): array {
        return array( 'index' => 0, 'total' => 0, 'processed' => 0, 'downloaded' => 0, 'skipped' => 0, 'failed' => 0, 'completed' => false );
    }

    private function image_state(): array {
        return wp_parse_args( (array) get_option( self::IMAGE_STATE_OPTION, array() ), $this->empty_image_state() );
    }

    private function save_image_state( array $state ): void {
        update_option( self::IMAGE_STATE_OPTION, $state, false );
    }

    private function snapshot_response( array $state, string $message ): array {
        $pages = count( (array) $state['pages'] );
        $pct = ! empty( $state['completed'] ) ? 100 : min( 95, max( 3, $pages * 12 ) );
        return array(
            'done'          => ! empty( $state['completed'] ),
            'progress_pct'  => $pct,
            'progress_text' => sprintf( '%d products saved across %d supplier request(s)', (int) $state['total_products'], $pages ),
            'message'       => $message,
            'warnings'      => array(),
        );
    }

    private function catalog_response( array $state, string $message, array $warnings ): array {
        $total = max( 1, (int) $state['total_products'] );
        return array(
            'done'          => ! empty( $state['completed'] ),
            'progress_pct'  => min( 100, round( ( (int) $state['processed'] / $total ) * 100, 1 ) ),
            'progress_text' => sprintf( '%d/%d products — %d created, %d refreshed, %d failed; %d variations ready', (int) $state['processed'], (int) $state['total_products'], (int) $state['created'], (int) $state['updated'], (int) $state['failed'], (int) $state['variations_created'] + (int) $state['variations_updated'] ),
            'message'       => $message,
            'warnings'      => array_slice( $warnings, 0, 5 ),
        );
    }

    private function image_response( array $state, string $message, array $warnings ): array {
        $total = max( 1, (int) $state['total'] );
        return array(
            'done'          => ! empty( $state['completed'] ),
            'progress_pct'  => min( 100, round( ( (int) $state['processed'] / $total ) * 100, 1 ) ),
            'progress_text' => sprintf( '%d/%d products — %d downloaded, %d skipped, %d failed', (int) $state['processed'], (int) $state['total'], (int) $state['downloaded'], (int) $state['skipped'], (int) $state['failed'] ),
            'message'       => $message,
            'warnings'      => array_slice( $warnings, 0, 5 ),
        );
    }
}
