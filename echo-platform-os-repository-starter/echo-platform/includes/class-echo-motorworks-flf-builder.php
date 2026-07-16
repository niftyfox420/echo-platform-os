<?php

defined( 'ABSPATH' ) || exit;

/**
 * Offline FLF Racing Supply catalog importer.
 *
 * This replaces the rate-limited live FLF API scan. The user uploads the
 * archived FLF WooCommerce CSV once, then WordPress processes local rows in
 * small resumable chunks. Catalog building makes zero requests to FLF.
 */
final class Echo_Motorworks_FLF_Builder {
    private const STATE_OPTION = 'echo_flf_offline_catalog_state_v1';
    private const SOURCE_KEY   = 'flf_offline_catalog_v1';
    private const BATCH_SIZE   = 8;
    private const BRAND_REPAIR_OPTION = 'echo_flf_brand_repair_state_v1';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'admin_menu' ), 64 );
        add_action( 'admin_post_echo_upload_flf_offline_catalog', array( $this, 'upload_catalog' ) );
        add_action( 'wp_ajax_echo_sync_flf_offline_catalog', array( $this, 'ajax_sync_catalog' ) );
        add_action( 'wp_ajax_echo_repair_flf_branding', array( $this, 'ajax_repair_branding' ) );
        add_action( 'admin_post_echo_export_flf_catalog', array( $this, 'export_catalog' ) );
    }

    public function admin_menu(): void {
        $parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'tools.php';
        add_submenu_page(
            $parent,
            'FLF Racing Supply',
            'FLF Racing Supply',
            'manage_woocommerce',
            'echo-flf-catalog',
            array( $this, 'page' )
        );
    }

    public function page(): void {
        $state = $this->state();
        $file_ready = ! empty( $state['file'] ) && is_readable( (string) $state['file'] );
        $filename = $file_ready ? basename( (string) $state['file'] ) : '';
        ?>
        <div class="wrap">
            <h1>FLF Racing Supply — Offline Catalog Builder</h1>
            <p>This version does not crawl or repeatedly ping Finish Line Factory. Upload the archived FLF WooCommerce CSV once, then the catalog is created locally in small saved batches.</p>
            <div class="notice notice-success inline"><p><strong>Zero supplier catalog requests:</strong> product creation, prices, descriptions, categories and scopes are read from the uploaded CSV on this server. Existing FLF products are refreshed by SKU instead of duplicated.</p></div>
            <div class="notice notice-info inline"><p><strong>Images:</strong> existing FLF featured images are preserved. Source image URLs found in the CSV are stored for a later controlled image pass, but this catalog builder does not download them and cannot trigger another FLF rate limit.</p></div>

            <h2>1. Upload the FLF catalog file</h2>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="background:#fff;border:1px solid #dcdcde;padding:18px;max-width:920px">
                <input type="hidden" name="action" value="echo_upload_flf_offline_catalog">
                <?php wp_nonce_field( 'echo_upload_flf_offline_catalog' ); ?>
                <input type="file" name="flf_catalog" accept=".csv,text/csv" required>
                <?php submit_button( 'Upload FLF Catalog', 'secondary', 'submit', false ); ?>
                <p class="description">Use <code>FLF_full_woocommerce_import_1536_products.csv</code>. The filename can stay exactly as downloaded.</p>
            </form>

            <?php if ( isset( $_GET['echo_flf_notice'] ) ) : ?>
                <div class="notice notice-<?php echo 'error' === (string) $_GET['echo_flf_notice'] ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['echo_flf_message'] ?? '' ) ) ); ?></p></div>
            <?php endif; ?>

            <h2>2. Build the catalog</h2>
            <p><strong>Uploaded file:</strong> <?php echo $file_ready ? esc_html( $filename ) : 'No file uploaded yet'; ?></p>
            <p>
                <button type="button" class="button button-primary" id="echo-flf-build" <?php disabled( ! $file_ready ); ?>>Build / Resume Offline FLF Catalog</button>
                <button type="button" class="button" id="echo-flf-stop" disabled>Stop</button>
                <button type="button" class="button" id="echo-flf-restart" <?php disabled( ! $file_ready ); ?>>Restart File from Row 1</button>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:8px 0 18px">
                <input type="hidden" name="action" value="echo_export_flf_catalog">
                <?php wp_nonce_field( 'echo_export_flf_catalog' ); ?>
                <?php submit_button( 'Download Built FLF Catalog CSV', 'secondary', 'submit', false ); ?>
            </form>
            <p><em>Progress is saved after every product. Closing the tab or replacing the plugin does not erase the uploaded CSV or completed products.</em></p>

            <h2>3. Repair the FLF brand field</h2>
            <div class="notice notice-warning inline"><p>The original offline import placed <strong>FLF Racing Supply</strong> and <strong>Finish Line Factory</strong> in product tags while leaving the WooCommerce Brands field empty. This repair assigns one proper brand, removes the two supplier-name tags, and keeps the useful <strong>Fluid Delivery</strong> tag.</p></div>
            <p>
                <button type="button" class="button button-primary" id="echo-flf-repair-branding">Repair FLF Brand Assignment</button>
                <button type="button" class="button" id="echo-flf-repair-stop" disabled>Stop</button>
            </p>
            <div id="echo-flf-brand-progress" style="max-width:1000px;display:none">
                <div style="height:18px;background:#dcdcde;border-radius:4px;overflow:hidden"><div id="echo-flf-brand-progress-bar" style="height:100%;width:0;background:#2271b1;transition:width .2s"></div></div>
                <p id="echo-flf-brand-progress-text" style="font-weight:600"></p>
                <textarea id="echo-flf-brand-log" readonly style="width:100%;min-height:150px;font-family:monospace"></textarea>
            </div>
            <div id="echo-flf-progress" style="max-width:1000px;display:none">
                <div style="height:18px;background:#dcdcde;border-radius:4px;overflow:hidden"><div id="echo-flf-progress-bar" style="height:100%;width:0;background:#2271b1;transition:width .2s"></div></div>
                <p id="echo-flf-progress-text" style="font-weight:600"></p>
                <textarea id="echo-flf-log" readonly style="width:100%;min-height:240px;font-family:monospace"></textarea>
            </div>
            <script>
            jQuery(function($){
                const nonce = <?php echo wp_json_encode( wp_create_nonce( 'echo_sync_flf_offline_catalog' ) ); ?>;
                let stopped=false, running=false, retries=0;
                const $start=$('#echo-flf-build'), $stop=$('#echo-flf-stop'), $restart=$('#echo-flf-restart');
                const $wrap=$('#echo-flf-progress'), $bar=$('#echo-flf-progress-bar'), $text=$('#echo-flf-progress-text'), $log=$('#echo-flf-log');
                function append(m){if(!m)return;$log.val($log.val()+m+'\n');$log.scrollTop($log[0].scrollHeight);}
                function finish(m){running=false;$start.prop('disabled',false);$restart.prop('disabled',false);$stop.prop('disabled',true);$text.text(m);append(m);}
                function run(reset){
                    if(stopped){finish('Stopped. Saved progress is retained.');return;}
                    $.ajax({url:ajaxurl,method:'POST',timeout:45000,data:{action:'echo_sync_flf_offline_catalog',nonce:nonce,reset:reset?1:0}})
                    .done(function(response){
                        if(!response||!response.success){
                            const message=response&&response.data&&response.data.message?response.data.message:'WordPress returned an error.';
                            if(retries>=3){finish('Paused after repeated local server errors. Click Build / Resume to continue.');return;}
                            retries++;append(message+' Retrying…');window.setTimeout(function(){run(false);},3000*retries);return;
                        }
                        retries=0;const d=response.data;
                        $bar.css('width',Math.max(0,Math.min(100,d.progress_pct||0))+'%');
                        $text.text(d.progress_text||'Working…');append(d.message);
                        if(d.warnings&&d.warnings.length)d.warnings.forEach(function(x){append('  Warning: '+x);});
                        if(d.done)finish('Offline FLF catalog build complete.');
                        else window.setTimeout(function(){run(false);},350);
                    }).fail(function(xhr){
                        if(retries>=3){finish('Paused after repeated HTTP errors. Click Build / Resume to continue.');return;}
                        retries++;append('HTTP '+(xhr.status||'error')+'. Retrying…');window.setTimeout(function(){run(false);},3000*retries);
                    });
                }
                function begin(reset){
                    if(running)return;running=true;stopped=false;retries=0;
                    $start.prop('disabled',true);$restart.prop('disabled',true);$stop.prop('disabled',false);$wrap.show();
                    if(reset){$bar.css('width','0');$log.val('');append('Restarting the local FLF file from row 1…');}
                    else append('Starting or resuming the local FLF catalog…');
                    run(reset);
                }
                $start.on('click',function(){begin(false);});
                $restart.on('click',function(){if(window.confirm('Restart the uploaded FLF file from row 1? Existing products will be refreshed by SKU, not duplicated.'))begin(true);});
                $stop.on('click',function(){stopped=true;$stop.prop('disabled',true);});
            });
            </script>
            <script>
            jQuery(function($){
                const nonce = <?php echo wp_json_encode( wp_create_nonce( 'echo_repair_flf_branding' ) ); ?>;
                let stopped=false, running=false, retries=0;
                const $start=$('#echo-flf-repair-branding'), $stop=$('#echo-flf-repair-stop');
                const $wrap=$('#echo-flf-brand-progress'), $bar=$('#echo-flf-brand-progress-bar'), $text=$('#echo-flf-brand-progress-text'), $log=$('#echo-flf-brand-log');
                function append(m){if(!m)return;$log.val($log.val()+m+'\n');$log.scrollTop($log[0].scrollHeight);}
                function finish(m){running=false;$start.prop('disabled',false);$stop.prop('disabled',true);$text.text(m);append(m);}
                function step(reset){
                    if(stopped){finish('Stopped. Brand-repair progress is saved.');return;}
                    $.ajax({url:ajaxurl,method:'POST',timeout:45000,data:{action:'echo_repair_flf_branding',nonce:nonce,reset:reset?1:0}})
                    .done(function(response){
                        if(!response||!response.success){
                            const message=response&&response.data&&response.data.message?response.data.message:'WordPress returned an error.';
                            if(retries>=3){finish('Paused after repeated local errors. Click Repair FLF Brand Assignment to resume.');return;}
                            retries++;append(message+' Retrying…');window.setTimeout(function(){step(false);},2000*retries);return;
                        }
                        retries=0;const d=response.data;
                        $bar.css('width',Math.max(0,Math.min(100,d.progress_pct||0))+'%');
                        $text.text(d.progress_text||'Working…');append(d.message);
                        if(d.done)finish('FLF brand repair complete.');
                        else window.setTimeout(function(){step(false);},250);
                    }).fail(function(xhr){
                        if(retries>=3){finish('Paused after repeated HTTP errors. Click Repair FLF Brand Assignment to resume.');return;}
                        retries++;append('HTTP '+(xhr.status||'error')+'. Retrying…');window.setTimeout(function(){step(false);},2000*retries);
                    });
                }
                $start.on('click',function(){
                    if(running)return;running=true;stopped=false;retries=0;
                    $start.prop('disabled',true);$stop.prop('disabled',false);$wrap.show();$bar.css('width','0');$log.val('');
                    append('Starting or resuming the FLF brand repair…');step(false);
                });
                $stop.on('click',function(){stopped=true;$stop.prop('disabled',true);});
            });
            </script>
        </div>
        <?php
    }

    public function upload_catalog(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'echo_upload_flf_offline_catalog' );
        if ( empty( $_FILES['flf_catalog']['tmp_name'] ) || ! is_uploaded_file( $_FILES['flf_catalog']['tmp_name'] ) ) {
            $this->redirect_notice( 'error', 'No CSV file was uploaded.' );
        }
        $name = sanitize_file_name( (string) ( $_FILES['flf_catalog']['name'] ?? 'flf-products.csv' ) );
        if ( 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
            $this->redirect_notice( 'error', 'The FLF catalog must be a CSV file.' );
        }
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) $this->redirect_notice( 'error', (string) $uploads['error'] );
        $dir = trailingslashit( $uploads['basedir'] ) . 'echo-motorworks';
        if ( ! wp_mkdir_p( $dir ) ) $this->redirect_notice( 'error', 'WordPress could not create the Echo Motorworks upload folder.' );
        $destination = trailingslashit( $dir ) . 'flf-catalog.csv';
        if ( ! @move_uploaded_file( $_FILES['flf_catalog']['tmp_name'], $destination ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $this->redirect_notice( 'error', 'WordPress could not save the uploaded CSV.' );
        }
        $inspection = $this->inspect_file( $destination );
        if ( is_wp_error( $inspection ) ) {
            @unlink( $destination ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $this->redirect_notice( 'error', $inspection->get_error_message() );
        }
        $state = $this->empty_state();
        $state['file'] = $destination;
        $state['filename'] = $name;
        $state['total_rows'] = (int) $inspection['total_rows'];
        $state['header'] = $inspection['header'];
        update_option( self::STATE_OPTION, $state, false );
        $this->redirect_notice( 'success', sprintf( 'Uploaded %s with %d product rows. Ready to build locally.', $name, (int) $state['total_rows'] ) );
    }

    private function redirect_notice( string $type, string $message ): void {
        $url = add_query_arg(
            array(
                'page' => 'echo-flf-catalog',
                'echo_flf_notice' => $type,
                'echo_flf_message' => rawurlencode( $message ),
            ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }

    public function ajax_sync_catalog(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
        check_ajax_referer( 'echo_sync_flf_offline_catalog', 'nonce' );
        if ( ! class_exists( 'WC_Product_Simple' ) ) wp_send_json_error( array( 'message' => 'WooCommerce is not active.' ) );
        if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 50 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        $state = $this->state();
        if ( ! empty( $_POST['reset'] ) ) {
            $file = (string) ( $state['file'] ?? '' );
            $filename = (string) ( $state['filename'] ?? '' );
            $total = (int) ( $state['total_rows'] ?? 0 );
            $header = (array) ( $state['header'] ?? array() );
            $state = $this->empty_state();
            $state['file'] = $file;
            $state['filename'] = $filename;
            $state['total_rows'] = $total;
            $state['header'] = $header;
            $this->save_state( $state );
        }
        if ( empty( $state['file'] ) || ! is_readable( (string) $state['file'] ) ) {
            wp_send_json_error( array( 'message' => 'Upload the FLF CSV first.' ) );
        }
        if ( ! empty( $state['completed'] ) ) {
            wp_send_json_success( $this->response( $state, 'The saved offline FLF import is already complete.', array() ) );
        }

        $rows = $this->rows_from_offset( (string) $state['file'], (int) $state['row_index'], self::BATCH_SIZE );
        if ( is_wp_error( $rows ) ) wp_send_json_error( array( 'message' => $rows->get_error_message() ) );
        if ( ! $rows ) {
            $state['completed'] = true;
            $this->save_state( $state );
            wp_send_json_success( $this->response( $state, 'Reached the end of the uploaded FLF file.', array() ) );
        }

        $warnings = array();
        $messages = array();
        foreach ( $rows as $row ) {
            $result = $this->sync_row( $row );
            if ( is_wp_error( $result ) ) {
                $state['failed'] = (int) $state['failed'] + 1;
                $warnings[] = $result->get_error_message();
            } else {
                $state['processed'] = (int) $state['processed'] + 1;
                if ( ! empty( $result['created'] ) ) $state['created'] = (int) $state['created'] + 1;
                else $state['updated'] = (int) $state['updated'] + 1;
                $messages[] = sprintf( '%s — %s; scope: %s', $result['name'], ! empty( $result['created'] ) ? 'created' : 'refreshed', $result['scope'] );
            }
            $state['row_index'] = (int) $state['row_index'] + 1;
            $this->save_state( $state );
        }
        if ( (int) $state['row_index'] >= (int) $state['total_rows'] ) $state['completed'] = true;
        $this->save_state( $state );
        $message = implode( ' | ', $messages );
        if ( ! $message ) $message = sprintf( 'Processed local rows through %d.', (int) $state['row_index'] );
        wp_send_json_success( $this->response( $state, $message, $warnings ) );
    }

    private function inspect_file( string $path ) {
        $handle = fopen( $path, 'rb' );
        if ( ! $handle ) return new WP_Error( 'flf_csv_open', 'Could not open the uploaded CSV.' );
        $header = fgetcsv( $handle );
        if ( ! is_array( $header ) ) { fclose( $handle ); return new WP_Error( 'flf_csv_header', 'The CSV has no header row.' ); }
        $header = array_map( array( $this, 'clean_header' ), $header );
        foreach ( array( 'SKU', 'Name', 'Regular price' ) as $required ) {
            if ( ! in_array( $required, $header, true ) ) { fclose( $handle ); return new WP_Error( 'flf_csv_columns', 'Missing required CSV column: ' . $required ); }
        }
        $total = 0;
        while ( false !== ( $row = fgetcsv( $handle ) ) ) {
            if ( is_array( $row ) && array_filter( $row, static fn( $v ) => '' !== trim( (string) $v ) ) ) $total++;
        }
        fclose( $handle );
        if ( ! $total ) return new WP_Error( 'flf_csv_empty', 'The CSV contains no product rows.' );
        return array( 'header' => $header, 'total_rows' => $total );
    }

    public function clean_header( $value ): string {
        return trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $value ) );
    }

    private function rows_from_offset( string $path, int $offset, int $limit ) {
        $handle = fopen( $path, 'rb' );
        if ( ! $handle ) return new WP_Error( 'flf_csv_open', 'Could not reopen the FLF CSV.' );
        $header = fgetcsv( $handle );
        if ( ! is_array( $header ) ) { fclose( $handle ); return new WP_Error( 'flf_csv_header', 'The FLF CSV header is missing.' ); }
        $header = array_map( array( $this, 'clean_header' ), $header );
        $seen = 0;
        $rows = array();
        while ( false !== ( $values = fgetcsv( $handle ) ) ) {
            if ( ! is_array( $values ) || ! array_filter( $values, static fn( $v ) => '' !== trim( (string) $v ) ) ) continue;
            if ( $seen++ < $offset ) continue;
            $values = array_pad( $values, count( $header ), '' );
            $rows[] = array_combine( $header, array_slice( $values, 0, count( $header ) ) );
            if ( count( $rows ) >= $limit ) break;
        }
        fclose( $handle );
        return $rows;
    }

    private function sync_row( array $row ) {
        $sku = sanitize_text_field( (string) ( $row['SKU'] ?? '' ) );
        $name = sanitize_text_field( (string) ( $row['Name'] ?? '' ) );
        if ( ! $sku || ! $name ) return new WP_Error( 'flf_row_required', 'Skipped a row missing SKU or product name.' );

        $existing_id = absint( wc_get_product_id_by_sku( $sku ) );
        $created = ! $existing_id;
        if ( $existing_id ) {
            $existing = wc_get_product( $existing_id );
            if ( ! $existing || $existing->is_type( 'variation' ) ) return new WP_Error( 'flf_sku_conflict', $sku . ': SKU is already used by an incompatible product.' );
            if ( ! $existing->is_type( 'simple' ) ) wp_set_object_terms( $existing_id, 'simple', 'product_type' );
        }
        $product = new WC_Product_Simple( $existing_id );
        $product->set_name( $name );
        if ( $created ) $product->set_sku( $sku );
        $product->set_status( 'publish' );
        $product->set_catalog_visibility( 'visible' );
        $product->set_reviews_allowed( true );
        $product->set_manage_stock( false );
        if ( $created ) $product->set_stock_status( 'outofstock' );

        $price = wc_format_decimal( (string) ( $row['Regular price'] ?? '' ) );
        if ( '' !== $price && ! get_post_meta( $existing_id, '_echo_manual_price_override', true ) ) {
            $product->set_regular_price( $price );
            $product->set_price( $price );
        }
        $weight = wc_format_decimal( (string) ( $row['Weight (lbs)'] ?? '' ) );
        if ( '' !== $weight ) $product->set_weight( $weight );

        $description = wp_kses_post( (string) ( $row['Description'] ?? '' ) );
        $notice = '<p><strong>Compatibility:</strong> Match AN size, thread standard, hose family, material, pressure/temperature requirements and installation dimensions before ordering. A universal catalog classification does not mean every size fits every installation.</p>';
        $product->set_description( $description . $notice );
        $product->set_short_description( wp_trim_words( wp_strip_all_tags( $description ), 28, '…' ) );

        $categories = $this->category_ids( (string) ( $row['Categories'] ?? '' ) );
        if ( $categories ) $product->set_category_ids( $categories );
        $tags = $this->tag_ids();
        if ( $tags ) $product->set_tag_ids( $tags );

        $attribute_name = sanitize_text_field( (string) ( $row['Attribute 1 name'] ?? '' ) );
        $attribute_values = sanitize_text_field( (string) ( $row['Attribute 1 value(s)'] ?? '' ) );
        if ( $attribute_name && $attribute_values ) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_id( 0 );
            $attribute->set_name( $attribute_name );
            $attribute->set_options( array_map( 'trim', str_getcsv( $attribute_values ) ) );
            $attribute->set_position( 0 );
            $attribute->set_visible( true );
            $attribute->set_variation( false );
            $product->set_attributes( array( $attribute ) );
        }

        $product_id = $product->save();
        if ( ! $product_id ) return new WP_Error( 'flf_save', $sku . ': WooCommerce could not save the product.' );

        $image_url = esc_url_raw( trim( (string) ( $row['Images'] ?? '' ) ) );
        if ( $image_url ) update_post_meta( $product_id, '_echo_source_image_url', $image_url );
        update_post_meta( $product_id, '_echo_supplier', 'FLF Racing Supply' );
        update_post_meta( $product_id, '_echo_manufacturer', 'FLF Racing Supply' );
        update_post_meta( $product_id, '_echo_brand', 'FLF Racing Supply' );
        $this->assign_brand( $product_id );
        update_post_meta( $product_id, '_echo_supplier_sku', $sku );
        update_post_meta( $product_id, '_echo_flf_source_key', self::SOURCE_KEY );
        update_post_meta( $product_id, '_echo_source_checked', gmdate( 'Y-m-d' ) );
        update_post_meta( $product_id, '_echo_source_status', 'Archived FLF catalog; confirm current supplier stock before fulfillment.' );

        $scope = $this->set_scope( $product_id, $name . ' ' . $description );
        wc_delete_product_transients( $product_id );
        return array( 'created' => $created, 'name' => $name, 'scope' => $scope );
    }

    private function category_ids( string $raw ): array {
        if ( ! taxonomy_exists( 'product_cat' ) || ! $raw ) return array();
        $ids = array();
        foreach ( preg_split( '/\s*,\s*/', $raw ) as $path ) {
            $parent = 0;
            foreach ( array_filter( array_map( 'trim', explode( '>', $path ) ) ) as $name ) {
                $parent = $this->term_id( $name, 'product_cat', $parent );
                if ( ! $parent ) break;
            }
            if ( $parent ) $ids[] = $parent;
        }
        return array_values( array_unique( $ids ) );
    }

    private function tag_ids(): array {
        $ids = array();
        foreach ( array( 'Fluid Delivery' ) as $name ) {
            $id = $this->term_id( $name, 'product_tag', 0 );
            if ( $id ) $ids[] = $id;
        }
        return $ids;
    }

    private function term_id( string $name, string $taxonomy, int $parent = 0 ): int {
        if ( ! taxonomy_exists( $taxonomy ) ) return 0;
        $term = term_exists( $name, $taxonomy, $parent ?: null );
        if ( ! $term ) {
            $args = $parent && 'product_cat' === $taxonomy ? array( 'parent' => $parent ) : array();
            $term = wp_insert_term( $name, $taxonomy, $args );
        }
        if ( is_wp_error( $term ) ) return 0;
        return absint( is_array( $term ) ? $term['term_id'] : $term );
    }

    private function brand_taxonomy(): string {
        foreach ( array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'berocket_brand' ) as $taxonomy ) {
            if ( taxonomy_exists( $taxonomy ) ) return $taxonomy;
        }
        return '';
    }

    private function assign_brand( int $product_id ): bool {
        $taxonomy = $this->brand_taxonomy();
        if ( ! $taxonomy ) return false;
        $brand_id = $this->term_id( 'FLF Racing Supply', $taxonomy, 0 );
        if ( ! $brand_id ) return false;
        $result = wp_set_object_terms( $product_id, array( $brand_id ), $taxonomy, false );
        if ( is_wp_error( $result ) ) return false;

        if ( taxonomy_exists( 'product_tag' ) ) {
            foreach ( array( 'FLF Racing Supply', 'Finish Line Factory' ) as $old_tag ) {
                $term = term_exists( $old_tag, 'product_tag' );
                if ( $term ) wp_remove_object_terms( $product_id, absint( is_array( $term ) ? $term['term_id'] : $term ), 'product_tag' );
            }
        }
        return true;
    }

    public function ajax_repair_branding(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
        check_ajax_referer( 'echo_repair_flf_branding', 'nonce' );

        global $wpdb;
        $taxonomy = $this->brand_taxonomy();
        if ( ! $taxonomy ) {
            wp_send_json_error( array( 'message' => 'No WooCommerce product-brand taxonomy is active.' ), 400 );
        }

        $reset = ! empty( $_POST['reset'] );
        $state = get_option( self::BRAND_REPAIR_OPTION, array() );
        if ( $reset || ! is_array( $state ) || empty( $state['total'] ) ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE p.post_type = 'product'
                   AND pm.meta_key = %s
                   AND pm.meta_value = %s",
                '_echo_supplier',
                'FLF Racing Supply'
            ) );
            $state = array( 'last_id' => 0, 'processed' => 0, 'total' => $total, 'completed' => false );
        }

        if ( ! empty( $state['completed'] ) ) {
            wp_send_json_success( array(
                'done' => true,
                'progress_pct' => 100,
                'progress_text' => sprintf( '%d / %d products repaired', (int) $state['processed'], (int) $state['total'] ),
                'message' => 'All saved FLF products already have the proper brand assignment.',
            ) );
        }

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = 'product'
               AND pm.meta_key = %s
               AND pm.meta_value = %s
               AND p.ID > %d
             ORDER BY p.ID ASC
             LIMIT 50",
            '_echo_supplier',
            'FLF Racing Supply',
            (int) $state['last_id']
        ) );

        if ( empty( $ids ) ) {
            $state['completed'] = true;
            update_option( self::BRAND_REPAIR_OPTION, $state, false );
            wp_send_json_success( array(
                'done' => true,
                'progress_pct' => 100,
                'progress_text' => sprintf( '%d / %d products repaired', (int) $state['processed'], (int) $state['total'] ),
                'message' => 'FLF brand repair finished.',
            ) );
        }

        $repaired = 0;
        foreach ( array_map( 'absint', $ids ) as $product_id ) {
            if ( $this->assign_brand( $product_id ) ) {
                update_post_meta( $product_id, '_echo_manufacturer', 'FLF Racing Supply' );
                update_post_meta( $product_id, '_echo_brand', 'FLF Racing Supply' );
                wc_delete_product_transients( $product_id );
                clean_post_cache( $product_id );
                $repaired++;
            }
            $state['last_id'] = max( (int) $state['last_id'], $product_id );
            $state['processed']++;
        }
        update_option( self::BRAND_REPAIR_OPTION, $state, false );

        $total = max( 1, (int) $state['total'] );
        $pct = min( 100, round( 100 * (int) $state['processed'] / $total, 1 ) );
        wp_send_json_success( array(
            'done' => false,
            'progress_pct' => $pct,
            'progress_text' => sprintf( '%d / %d products repaired', (int) $state['processed'], (int) $state['total'] ),
            'message' => sprintf( 'Assigned FLF Racing Supply as the brand on %d products in this batch.', $repaired ),
        ) );
    }

    private function set_scope( int $product_id, string $combined ): string {
        $application_pattern = '/\b(?:Audi|BMW|Chevrolet|Chevy|Corvette|Camaro|Chrysler|Coyote|Dodge|Duramax|Ferrari|Ford|GM|Honda|Hyundai|Jeep|Lamborghini|Lexus|LS[1237A-Z0-9]*|LT[1-9A-Z0-9]*|Mazda|Mercedes|Mitsubishi|Mustang|Nissan|Porsche|Ram|Subaru|Supra|Tesla|Toyota|Volkswagen|VW|WRX)\b/i';
        if ( preg_match( $application_pattern, wp_strip_all_tags( $combined ) ) ) {
            $scope = 'needs_review';
            $raw = 'The archived FLF catalog copy contains a vehicle, engine-family or OEM-specific application reference.';
            $confidence = 'medium';
            $reason = 'Exact Year/Make/Model fitment was not inferred. Confirm thread, port, vehicle/application and installation dimensions.';
        } else {
            $scope = 'universal';
            $raw = 'Universal motorsport fluid-delivery component selected by AN size, thread standard, hose family, material and installation dimensions.';
            $confidence = 'high';
            $reason = 'The archived FLF catalog lists this as dimensional plumbing, routing, service, heat-protection or general motorsport hardware.';
        }
        update_post_meta( $product_id, '_echo_fitment_type', $scope );
        update_post_meta( $product_id, '_echo_fitment_raw', $raw );
        update_post_meta( $product_id, '_echo_fitment_confidence', $confidence );
        update_post_meta( $product_id, '_echo_fitment_reason', $reason );
        return $scope;
    }

    private function empty_state(): array {
        return array(
            'file' => '', 'filename' => '', 'header' => array(), 'total_rows' => 0,
            'row_index' => 0, 'processed' => 0, 'created' => 0, 'updated' => 0,
            'failed' => 0, 'completed' => false,
        );
    }

    private function state(): array {
        $state = get_option( self::STATE_OPTION, array() );
        return is_array( $state ) ? array_merge( $this->empty_state(), $state ) : $this->empty_state();
    }

    private function save_state( array $state ): void {
        update_option( self::STATE_OPTION, $state, false );
    }

    private function response( array $state, string $message, array $warnings ): array {
        $total = max( 1, (int) $state['total_rows'] );
        $done_count = min( $total, (int) $state['row_index'] );
        return array(
            'done' => ! empty( $state['completed'] ),
            'progress_pct' => round( 100 * $done_count / $total, 1 ),
            'progress_text' => sprintf(
                '%d / %d rows — %d created, %d refreshed, %d failed',
                $done_count, $total, (int) $state['created'], (int) $state['updated'], (int) $state['failed']
            ),
            'message' => $message,
            'warnings' => $warnings,
        );
    }

    public function export_catalog(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'echo_export_flf_catalog' );
        $ids = get_posts( array(
            'post_type' => 'product', 'post_status' => array( 'publish','draft','private','pending' ),
            'fields' => 'ids', 'numberposts' => -1, 'meta_key' => '_echo_supplier', 'meta_value' => 'FLF Racing Supply',
            'orderby' => 'ID', 'order' => 'ASC',
        ) );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=flf-products.csv' );
        $out = fopen( 'php://output', 'wb' );
        fputcsv( $out, array( 'product_id','sku','name','status','stock_status','regular_price','category','fitment_type','source_image_url' ) );
        foreach ( $ids as $id ) {
            $p = wc_get_product( $id );
            if ( ! $p ) continue;
            $cats = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'names' ) );
            fputcsv( $out, array(
                $id, $p->get_sku(), $p->get_name(), $p->get_status(), $p->get_stock_status(),
                $p->get_regular_price(), implode( ' | ', is_array( $cats ) ? $cats : array() ),
                get_post_meta( $id, '_echo_fitment_type', true ),
                get_post_meta( $id, '_echo_source_image_url', true ),
            ) );
        }
        fclose( $out );
        exit;
    }
}
