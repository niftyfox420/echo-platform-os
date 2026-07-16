<?php

defined( 'ABSPATH' ) || exit;

/**
 * Automated, guarded supplier catalog intake wizard.
 *
 * Workflow:
 *  1. Analyze CSV/XLSX and auto-detect columns.
 *  2. Confirm supplier identity, import mode, and destructive behavior.
 *  3. Import/update in small AJAX batches.
 *  4. In replace mode, archive or trash only supplier products absent from the new file.
 */
final class Echo_Motorworks_Supplier_Intake {
    private const TRANSIENT_PREFIX = 'echo_supplier_intake_';
    private const JOB_OPTION_PREFIX = 'echo_supplier_job_';

    public function __construct() {
        add_action( 'admin_post_echo_supplier_intake_analyze', array( $this, 'analyze' ) );
        add_action( 'admin_post_echo_supplier_intake_start', array( $this, 'start' ) );
        add_action( 'wp_ajax_echo_supplier_intake_batch', array( $this, 'batch' ) );
        add_action( 'wp_ajax_echo_supplier_intake_status', array( $this, 'status' ) );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;
        $token = sanitize_key( $_GET['intake'] ?? '' );
        $job   = sanitize_key( $_GET['job'] ?? '' );
        echo '<h2>Supplier Intake Wizard</h2>';
        echo '<p>Upload the official file from a supplier. Echo detects the columns, asks you to verify the supplier and import rules, then handles products, categories, images, pricing, stock and fitment metadata together.</p>';

        if ( $job ) {
            $this->render_job( $job );
            return;
        }
        if ( $token ) {
            $data = get_transient( self::TRANSIENT_PREFIX . $token );
            if ( is_array( $data ) ) {
                $this->render_confirm( $token, $data );
                return;
            }
        }
        $this->render_upload();
    }

    private function render_upload(): void {
        ?>
        <div class="echo-manager-card" style="max-width:900px">
            <h3>1. Upload the supplier file</h3>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'echo_supplier_intake_analyze' ); ?>
                <input type="hidden" name="action" value="echo_supplier_intake_analyze">
                <div class="echo-form-grid">
                    <label>Supplier name to verify
                        <input type="text" name="supplier_name" required placeholder="Example: Turn 14 Distribution">
                    </label>
                    <label>Supplier website
                        <input type="url" name="supplier_website" placeholder="https://supplier.com">
                    </label>
                    <label>SKU prefix (optional fallback)
                        <input type="text" name="sku_prefix" placeholder="Example: T14-">
                    </label>
                    <label>Catalog file
                        <input type="file" name="catalog_file" required accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                    </label>
                    <div class="echo-wide">
                        <p><strong>Accepted:</strong> CSV or XLSX. The wizard will attempt to recognize SKU, product name, descriptions, price, sale price, category, image URL, stock, source URL and fitment fields automatically.</p>
                    </div>
                </div>
                <p><button class="button button-primary button-hero">Analyze File</button></p>
            </form>
        </div>
        <?php
    }

    private function render_confirm( string $token, array $data ): void {
        $mapping = $data['mapping'];
        $count = count( $data['rows'] );
        $supplier = $data['supplier_name'];
        $existing = $this->supplier_product_ids( $supplier );
        ?>
        <div class="echo-manager-grid">
            <div class="echo-manager-card"><div class="echo-metric"><?php echo number_format_i18n( $count ); ?></div><p>Rows detected</p></div>
            <div class="echo-manager-card"><div class="echo-metric"><?php echo number_format_i18n( count( $existing ) ); ?></div><p>Existing <?php echo esc_html( $supplier ); ?> products</p></div>
            <div class="echo-manager-card"><div class="echo-metric"><?php echo esc_html( strtoupper( $data['extension'] ) ); ?></div><p>File type</p></div>
        </div>
        <div class="echo-manager-card" style="margin-top:16px">
            <h3>2. Verify the data before import</h3>
            <p><strong>Supplier:</strong> <?php echo esc_html( $supplier ); ?> &nbsp; <strong>Website:</strong> <?php echo esc_html( $data['supplier_website'] ?: 'Not supplied' ); ?></p>
            <table class="echo-table"><thead><tr><th>Product field</th><th>Detected source column</th></tr></thead><tbody>
            <?php foreach ( $mapping as $field => $column ) : ?>
                <tr><td><?php echo esc_html( ucwords( str_replace( '_', ' ', $field ) ) ); ?></td><td><?php echo esc_html( $column ?: 'Not detected' ); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php if ( empty( $mapping['name'] ) || empty( $mapping['sku'] ) ) : ?>
                <div class="notice notice-error inline"><p><strong>Stop:</strong> Echo could not confidently detect both Product Name and SKU. Ask the supplier for a cleaner file or rename those columns before importing.</p></div>
            <?php endif; ?>
        </div>
        <div class="echo-manager-card" style="margin-top:16px;border-top-color:#b32d2e">
            <h3>3. Choose how this supplier catalog should be handled</h3>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'echo_supplier_intake_start' ); ?>
                <input type="hidden" name="action" value="echo_supplier_intake_start">
                <input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
                <div class="echo-form-grid">
                    <label>Import mode
                        <select name="mode" id="echo-intake-mode">
                            <option value="merge">Update / merge — keep supplier products not in this file</option>
                            <option value="replace_archive">Replace catalog — archive supplier products not in this file</option>
                            <option value="replace_trash">Replace catalog — move supplier products not in this file to Trash</option>
                        </select>
                    </label>
                    <label>Products without a price
                        <select name="missing_price">
                            <option value="request">Publish with Request Pricing</option>
                            <option value="draft">Save as draft</option>
                        </select>
                    </label>
                    <label><input type="checkbox" name="download_images" value="1" checked> Download product images into Media Library</label>
                    <label><input type="checkbox" name="publish" value="1" checked> Publish imported products</label>
                    <label class="echo-wide">Type the supplier name to confirm
                        <input type="text" name="supplier_confirmation" required autocomplete="off" placeholder="<?php echo esc_attr( $supplier ); ?>">
                        <span class="description">Required for every import. Replace modes will only affect products assigned to this exact supplier.</span>
                    </label>
                </div>
                <div class="notice notice-warning inline"><p><strong>Safe replacement behavior:</strong> Echo imports and updates the new file first. Only after successful processing will it archive or trash existing <?php echo esc_html( $supplier ); ?> products whose SKUs are absent from the new file.</p></div>
                <p><button class="button button-primary button-hero" <?php disabled( empty( $mapping['name'] ) || empty( $mapping['sku'] ) ); ?>>Start Verified Import</button> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=echo-catalog-manager&tab=intake' ) ); ?>">Cancel</a></p>
            </form>
        </div>
        <?php
    }

    private function render_job( string $job_id ): void {
        $job = get_option( self::JOB_OPTION_PREFIX . $job_id );
        if ( ! is_array( $job ) ) {
            echo '<div class="notice notice-error"><p>Import job not found.</p></div>';
            return;
        }
        ?>
        <div class="echo-manager-card" style="max-width:1000px">
            <h3>Supplier catalog import in progress</h3>
            <p><strong><?php echo esc_html( $job['supplier_name'] ); ?></strong> — <span id="echo-intake-phase"><?php echo esc_html( $job['phase'] ); ?></span></p>
            <div style="height:18px;background:#e5e7eb;border-radius:999px;overflow:hidden"><div id="echo-intake-bar" style="height:100%;width:0;background:#d63638;transition:width .25s"></div></div>
            <p id="echo-intake-count">Preparing…</p>
            <pre id="echo-intake-log" style="max-height:320px;overflow:auto;background:#111827;color:#e5e7eb;padding:14px;border-radius:6px"></pre>
            <p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=echo-catalog-manager&tab=intake' ) ); ?>">Start another intake</a></p>
        </div>
        <script>
        (function(){
            const job = <?php echo wp_json_encode( $job_id ); ?>;
            const nonce = <?php echo wp_json_encode( wp_create_nonce( 'echo_supplier_intake_batch' ) ); ?>;
            const ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            let running = true;
            async function step(){
                if(!running) return;
                const body = new URLSearchParams({action:'echo_supplier_intake_batch',job:job,_ajax_nonce:nonce});
                try {
                    const res = await fetch(ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});
                    const json = await res.json();
                    if(!json.success) throw new Error((json.data&&json.data.message)||'Import failed');
                    const d=json.data;
                    document.getElementById('echo-intake-bar').style.width=d.percent+'%';
                    document.getElementById('echo-intake-phase').textContent=d.phase;
                    document.getElementById('echo-intake-count').textContent=d.processed+' / '+d.total+' rows — '+d.created+' created, '+d.updated+' updated, '+d.failed+' failed, '+d.removed+' removed';
                    document.getElementById('echo-intake-log').textContent=(d.log||[]).join('\n');
                    if(d.done){running=false;document.getElementById('echo-intake-count').insertAdjacentHTML('beforeend',' <strong>Complete.</strong>');return;}
                    setTimeout(step,300);
                } catch(e){running=false;document.getElementById('echo-intake-log').textContent+='\nERROR: '+e.message;}
            }
            step();
        })();
        </script>
        <?php
    }

    public function analyze(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'echo_supplier_intake_analyze' );
        $supplier = sanitize_text_field( wp_unslash( $_POST['supplier_name'] ?? '' ) );
        if ( ! $supplier || empty( $_FILES['catalog_file']['tmp_name'] ) ) wp_die( 'Supplier and file are required.' );
        $name = sanitize_file_name( $_FILES['catalog_file']['name'] );
        $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, array( 'csv', 'xlsx' ), true ) ) wp_die( 'Only CSV and XLSX files are accepted.' );
        $upload = wp_upload_bits( 'echo-intake-' . time() . '-' . $name, null, file_get_contents( $_FILES['catalog_file']['tmp_name'] ) );
        if ( ! empty( $upload['error'] ) ) wp_die( esc_html( $upload['error'] ) );
        $rows = 'xlsx' === $ext ? $this->read_xlsx( $upload['file'] ) : $this->read_csv( $upload['file'] );
        if ( empty( $rows ) ) wp_die( 'No product rows were found.' );
        $headers = array_keys( $rows[0] );
        $mapping = $this->detect_mapping( $headers );
        $token = wp_generate_password( 20, false, false );
        set_transient( self::TRANSIENT_PREFIX . $token, array(
            'supplier_name' => $supplier,
            'supplier_website' => esc_url_raw( wp_unslash( $_POST['supplier_website'] ?? '' ) ),
            'sku_prefix' => sanitize_text_field( wp_unslash( $_POST['sku_prefix'] ?? '' ) ),
            'file' => $upload['file'],
            'extension' => $ext,
            'rows' => $rows,
            'mapping' => $mapping,
        ), DAY_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=echo-catalog-manager&tab=intake&intake=' . $token ) );
        exit;
    }

    public function start(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'echo_supplier_intake_start' );
        $token = sanitize_key( $_POST['token'] ?? '' );
        $data = get_transient( self::TRANSIENT_PREFIX . $token );
        if ( ! is_array( $data ) ) wp_die( 'The analyzed file expired. Upload it again.' );
        $confirmation = sanitize_text_field( wp_unslash( $_POST['supplier_confirmation'] ?? '' ) );
        if ( 0 !== strcasecmp( trim( $confirmation ), trim( $data['supplier_name'] ) ) ) wp_die( 'Supplier confirmation did not match.' );
        $job_id = wp_generate_password( 16, false, false );
        update_option( self::JOB_OPTION_PREFIX . $job_id, array(
            'supplier_name' => $data['supplier_name'],
            'supplier_website' => $data['supplier_website'],
            'sku_prefix' => $data['sku_prefix'],
            'rows' => $data['rows'],
            'mapping' => $data['mapping'],
            'mode' => sanitize_key( $_POST['mode'] ?? 'merge' ),
            'download_images' => ! empty( $_POST['download_images'] ),
            'publish' => ! empty( $_POST['publish'] ),
            'missing_price' => sanitize_key( $_POST['missing_price'] ?? 'request' ),
            'offset' => 0,
            'phase' => 'import',
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'removed' => 0,
            'skus' => array(),
            'log' => array( 'Verified supplier: ' . $data['supplier_name'] ),
            'started' => time(),
        ), false );
        delete_transient( self::TRANSIENT_PREFIX . $token );
        wp_safe_redirect( admin_url( 'admin.php?page=echo-catalog-manager&tab=intake&job=' . $job_id ) );
        exit;
    }

    public function batch(): void {
        check_ajax_referer( 'echo_supplier_intake_batch' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        $job_id = sanitize_key( $_POST['job'] ?? '' );
        $key = self::JOB_OPTION_PREFIX . $job_id;
        $job = get_option( $key );
        if ( ! is_array( $job ) ) wp_send_json_error( array( 'message' => 'Job not found' ) );
        $total = count( $job['rows'] );
        if ( 'import' === $job['phase'] ) {
            $limit = $job['download_images'] ? 2 : 20;
            $end = min( $total, $job['offset'] + $limit );
            for ( $i = $job['offset']; $i < $end; $i++ ) {
                try {
                    $result = $this->upsert_product( $job['rows'][$i], $job );
                    if ( ! empty( $result['sku'] ) ) $job['skus'][] = $result['sku'];
                    $job[ $result['created'] ? 'created' : 'updated' ]++;
                    $job['log'][] = sprintf( '%s %s', $result['created'] ? 'Created' : 'Updated', $result['sku'] ?: $result['name'] );
                } catch ( Throwable $e ) {
                    $job['failed']++;
                    $job['log'][] = 'FAILED row ' . ( $i + 2 ) . ': ' . $e->getMessage();
                }
            }
            $job['offset'] = $end;
            if ( $job['offset'] >= $total ) $job['phase'] = str_starts_with( $job['mode'], 'replace_' ) ? 'cleanup' : 'complete';
        } elseif ( 'cleanup' === $job['phase'] ) {
            $keep = array_unique( array_filter( $job['skus'] ) );
            $ids = $this->supplier_product_ids( $job['supplier_name'] );
            foreach ( $ids as $id ) {
                $sku = (string) get_post_meta( $id, '_sku', true );
                if ( $sku && in_array( $sku, $keep, true ) ) continue;
                if ( 'replace_trash' === $job['mode'] ) wp_trash_post( $id );
                else wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
                $job['removed']++;
            }
            $job['log'][] = sprintf( '%d old supplier products %s.', $job['removed'], 'replace_trash' === $job['mode'] ? 'moved to Trash' : 'archived as drafts' );
            $job['phase'] = 'complete';
        }
        $job['skus'] = array_values( array_unique( $job['skus'] ) );
        $job['log'] = array_slice( $job['log'], -80 );
        update_option( $key, $job, false );
        $processed = min( $job['offset'], $total );
        wp_send_json_success( array(
            'phase' => $job['phase'], 'processed' => $processed, 'total' => $total,
            'created' => $job['created'], 'updated' => $job['updated'], 'failed' => $job['failed'], 'removed' => $job['removed'],
            'percent' => 'complete' === $job['phase'] ? 100 : ( $total ? round( 95 * $processed / $total ) : 95 ),
            'done' => 'complete' === $job['phase'], 'log' => $job['log'],
        ) );
    }

    public function status(): void { wp_send_json_success(); }

    private function upsert_product( array $row, array $job ): array {
        if ( ! class_exists( 'WC_Product_Simple' ) ) throw new RuntimeException( 'WooCommerce is unavailable.' );
        $m = $job['mapping'];
        $name = $this->cell( $row, $m['name'] );
        $sku  = $this->cell( $row, $m['sku'] );
        if ( ! $name ) throw new RuntimeException( 'Product name is blank.' );
        if ( ! $sku ) $sku = sanitize_title( $job['sku_prefix'] . $name );
        $id = wc_get_product_id_by_sku( $sku );
        $created = ! $id;
        $product = $id ? wc_get_product( $id ) : new WC_Product_Simple();
        if ( ! $product ) throw new RuntimeException( 'Could not load product.' );
        $product->set_name( wp_strip_all_tags( $name ) );
        $product->set_sku( $sku );
        $product->set_description( wp_kses_post( $this->cell( $row, $m['description'] ) ) );
        $product->set_short_description( wp_kses_post( $this->cell( $row, $m['short_description'] ) ) );
        $regular = $this->money( $this->cell( $row, $m['regular_price'] ) );
        $sale = $this->money( $this->cell( $row, $m['sale_price'] ) );
        if ( '' !== $regular ) $product->set_regular_price( $regular );
        if ( '' !== $sale ) $product->set_sale_price( $sale );
        $stock = strtolower( $this->cell( $row, $m['stock_status'] ) );
        $product->set_stock_status( in_array( $stock, array( 'outofstock', 'out of stock', 'no', '0' ), true ) ? 'outofstock' : 'instock' );
        $has_price = '' !== $regular || '' !== $sale;
        $status = $job['publish'] && ( $has_price || 'request' === $job['missing_price'] ) ? 'publish' : 'draft';
        $product->set_status( $status );
        $id = $product->save();

        update_post_meta( $id, '_echo_supplier', $job['supplier_name'] );
        update_post_meta( $id, 'supplier', $job['supplier_name'] );
        update_post_meta( $id, '_echo_supplier_website', $job['supplier_website'] );
        $source = $this->cell( $row, $m['source_url'] );
        if ( $source ) update_post_meta( $id, '_echo_source_url', esc_url_raw( $source ) );
        foreach ( array( 'fitment_type' => '_echo_fitment_type', 'fitment_raw' => '_echo_fitment_raw' ) as $field => $meta ) {
            $value = $this->cell( $row, $m[$field] );
            if ( $value ) update_post_meta( $id, $meta, sanitize_text_field( $value ) );
        }
        if ( ! $has_price ) update_post_meta( $id, '_echo_request_pricing', '1' ); else delete_post_meta( $id, '_echo_request_pricing' );

        $cats = preg_split( '/\s*[|>,;]\s*/', $this->cell( $row, $m['categories'] ), -1, PREG_SPLIT_NO_EMPTY );
        if ( $cats ) {
            $term_ids = array();
            foreach ( $cats as $cat ) {
                $term = term_exists( $cat, 'product_cat' );
                if ( ! $term ) $term = wp_insert_term( $cat, 'product_cat' );
                if ( ! is_wp_error( $term ) ) $term_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
            }
            if ( $term_ids ) wp_set_object_terms( $id, $term_ids, 'product_cat', false );
        }
        foreach ( array( 'product_brand', 'pwb-brand', 'berocket_brand' ) as $taxonomy ) {
            if ( taxonomy_exists( $taxonomy ) ) wp_set_object_terms( $id, $job['supplier_name'], $taxonomy, false );
        }
        $image = trim( preg_split( '/[,|\s]+/', $this->cell( $row, $m['image_url'] ) )[0] ?? '' );
        if ( $job['download_images'] && $image && ! has_post_thumbnail( $id ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $attachment = media_sideload_image( esc_url_raw( $image ), $id, $name, 'id' );
            if ( ! is_wp_error( $attachment ) ) set_post_thumbnail( $id, (int) $attachment );
        }
        return array( 'created' => $created, 'sku' => $sku, 'name' => $name );
    }

    private function supplier_product_ids( string $supplier ): array {
        $q = new WP_Query( array(
            'post_type' => 'product', 'post_status' => array( 'publish','draft','pending','private' ), 'fields' => 'ids', 'posts_per_page' => -1,
            'meta_query' => array( 'relation' => 'OR',
                array( 'key' => '_echo_supplier', 'value' => $supplier, 'compare' => '=' ),
                array( 'key' => 'supplier', 'value' => $supplier, 'compare' => '=' ),
            ),
        ) );
        return array_map( 'intval', $q->posts );
    }

    private function detect_mapping( array $headers ): array {
        $rules = array(
            'sku' => array( 'sku','product sku','part number','part #','part no','mpn','manufacturer part number','item number' ),
            'name' => array( 'name','product name','title','product title','item name' ),
            'description' => array( 'description','long description','product description','body','details' ),
            'short_description' => array( 'short description','summary','excerpt','features' ),
            'regular_price' => array( 'regular price','price','msrp','retail price','map price','sale price usd' ),
            'sale_price' => array( 'sale price','dealer price','special price','discount price' ),
            'categories' => array( 'categories','category','product category','department','type' ),
            'image_url' => array( 'images','image','image url','image urls','primary image','photo url','main image' ),
            'stock_status' => array( 'stock status','availability','in stock','inventory status','stock' ),
            'source_url' => array( 'source url','product url','url','link','website url' ),
            'fitment_type' => array( 'fitment type','fitment_type','application type' ),
            'fitment_raw' => array( 'fitment','fitment raw','application','applications','vehicle fitment','year make model' ),
        );
        $norm = array();
        foreach ( $headers as $h ) $norm[$h] = $this->normalize( $h );
        $map = array();
        foreach ( $rules as $field => $aliases ) {
            $map[$field] = '';
            foreach ( $aliases as $alias ) {
                foreach ( $norm as $original => $normalized ) {
                    if ( $normalized === $this->normalize( $alias ) ) { $map[$field] = $original; break 2; }
                }
            }
            if ( ! $map[$field] ) {
                foreach ( $aliases as $alias ) foreach ( $norm as $original => $normalized ) {
                    if ( str_contains( $normalized, $this->normalize( $alias ) ) ) { $map[$field] = $original; break 2; }
                }
            }
        }
        return $map;
    }

    private function read_csv( string $file ): array {
        $handle = fopen( $file, 'r' );
        if ( ! $handle ) return array();
        $first = fgets( $handle ); rewind( $handle );
        $delimiter = substr_count( (string) $first, "\t" ) > substr_count( (string) $first, ',' ) ? "\t" : ',';
        $headers = fgetcsv( $handle, 0, $delimiter );
        if ( ! $headers ) return array();
        $headers = array_map( fn($h) => trim( preg_replace('/^\xEF\xBB\xBF/', '', (string)$h) ), $headers );
        $rows = array();
        while ( ( $values = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
            if ( ! array_filter( $values, fn($v) => '' !== trim((string)$v) ) ) continue;
            $values = array_pad( $values, count( $headers ), '' );
            $rows[] = array_combine( $headers, array_slice( $values, 0, count( $headers ) ) );
        }
        fclose( $handle );
        return $rows;
    }

    private function read_xlsx( string $file ): array {
        if ( ! class_exists( 'ZipArchive' ) ) return array();
        $zip = new ZipArchive(); if ( true !== $zip->open( $file ) ) return array();
        $shared = array();
        $shared_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
        if ( $shared_xml ) {
            $xml = simplexml_load_string( $shared_xml );
            if ( $xml ) foreach ( $xml->si as $si ) { $parts=array(); foreach($si->xpath('.//t') as $t)$parts[]=(string)$t; $shared[]=implode('',$parts); }
        }
        $sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' ); $zip->close();
        if ( ! $sheet_xml ) return array();
        $xml = simplexml_load_string( $sheet_xml ); if ( ! $xml ) return array();
        $matrix = array();
        foreach ( $xml->sheetData->row as $row ) {
            $line = array();
            foreach ( $row->c as $cell ) {
                $ref=(string)$cell['r']; preg_match('/([A-Z]+)/',$ref,$m); $letters=$m[1]??'A';
                $idx=0; for($i=0;$i<strlen($letters);$i++)$idx=$idx*26+(ord($letters[$i])-64); $idx--;
                $value=(string)$cell->v; if((string)$cell['t']==='s')$value=$shared[(int)$value]??'';
                $line[$idx]=$value;
            }
            if($line){ksort($line);$max=max(array_keys($line));$matrix[]=array_replace(array_fill(0,$max+1,''),$line);}
        }
        if ( count($matrix)<2 ) return array();
        $headers=array_map('trim',$matrix[0]); $rows=array();
        foreach(array_slice($matrix,1) as $values){$values=array_pad($values,count($headers),'');if(!array_filter($values,fn($v)=>''!==trim((string)$v)))continue;$rows[]=array_combine($headers,array_slice($values,0,count($headers)));}
        return $rows;
    }

    private function normalize( string $value ): string { return trim( preg_replace( '/[^a-z0-9]+/', ' ', strtolower( $value ) ) ); }
    private function cell( array $row, string $column ): string { return $column && isset( $row[$column] ) ? trim( (string) $row[$column] ) : ''; }
    private function money( string $value ): string { $value=preg_replace('/[^0-9.\-]/','',$value); return is_numeric($value) ? wc_format_decimal($value) : ''; }
}
