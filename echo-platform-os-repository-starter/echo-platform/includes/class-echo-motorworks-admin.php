<?php

defined( 'ABSPATH' ) || exit;

final class Echo_Motorworks_Admin {
    private const ATS_BUILDER_STATE_OPTION = 'echo_ats_builder_state_v2';
    private const ATS_BUILDER_STATE_VERSION = '2';
    private const ATS_OPTIONS_PER_REQUEST = 2;

    private Echo_Motorworks_API $api;
    private Echo_Motorworks_Garage $garage;

    public function __construct( Echo_Motorworks_API $api, Echo_Motorworks_Garage $garage ) {
        $this->api = $api;
        $this->garage = $garage;
        add_action( 'admin_menu', array( $this, 'admin_menu' ), 60 );
        add_action( 'admin_post_echo_import_fitment_csv', array( $this, 'import_fitment_csv' ) );
        add_action( 'admin_post_echo_import_product_fitment_types', array( $this, 'import_product_fitment_types' ) );
        add_action( 'admin_post_echo_clear_vehicle_cache', array( $this, 'clear_vehicle_cache' ) );
        add_action( 'wp_ajax_echo_build_ats_fitment', array( $this, 'ajax_build_ats_fitment' ) );
        add_action( 'admin_post_echo_export_ats_fitment', array( $this, 'export_ats_fitment' ) );
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
    }

    public function admin_menu(): void {
        $parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'tools.php';
        add_submenu_page(
            $parent,
            'Echo Motorworks Catalog',
            'Echo Motorworks Catalog',
            'manage_woocommerce',
            'echo-motorworks-catalog',
            array( $this, 'dashboard_page' )
        );
        add_submenu_page(
            $parent,
            'Vehicle Fitment',
            'Vehicle Fitment',
            'manage_woocommerce',
            'echo-motorworks-fitment',
            array( $this, 'fitment_page' )
        );
        add_submenu_page(
            $parent,
            'Echo Settings',
            'Echo Settings',
            'manage_woocommerce',
            'echo-motorworks-settings',
            array( $this, 'settings_page' )
        );
    }

    public function dashboard_page(): void {
        global $wpdb;
        $vehicle_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Echo_Motorworks_DB::vehicles_table() );
        $fitment_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Echo_Motorworks_DB::fitment_table() );
        $matched_products = (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT product_id) FROM ' . Echo_Motorworks_DB::fitment_table() . " WHERE fitment_status IN ('confirmed','conditional')" );
        ?>
        <div class="wrap">
            <h1>Echo Motorworks Catalog</h1>
            <p>Phase 1 uses real U.S. government vehicle data and only verified product-fitment rows.</p>
            <div class="echo-admin-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;max-width:1000px">
                <?php $this->metric_card( 'Saved vehicle records', $vehicle_count ); ?>
                <?php $this->metric_card( 'Verified fitment rows', $fitment_count ); ?>
                <?php $this->metric_card( 'Products with fitment', $matched_products ); ?>
                <?php $this->metric_card( 'Vehicle source', 'EPA + NHTSA' ); ?>
            </div>
            <hr>
            <h2>Truth rules</h2>
            <ul style="list-style:disc;padding-left:22px">
                <li>A vehicle appearing in the selector does not imply any product fits it.</li>
                <li>Products appear under “compatible” only after a confirmed or conditional fitment row is imported.</li>
                <li>Universal products are never converted into vehicle-specific matches.</li>
                <li>“Does Not Fit” is shown only when an explicit exclusion exists.</li>
            </ul>
        </div>
        <?php
    }

    public function fitment_page(): void {
        ?>
        <div class="wrap">
            <h1>Vehicle Fitment</h1>
            <p>Import product fitment scopes first, then supplier-confirmed year/make/model rows. Product ID or exact WooCommerce SKU is required.</p>
            <div class="notice notice-warning inline"><p><strong>Do not upload guessed fitment.</strong> Blank engine fields mean the supplier confirmed the part for every engine under that exact year/make/model row.</p></div>
            <h2>1. Product fitment types</h2>
            <p>Sets each product to universal, vehicle-specific, engine-specific, or needs-review without changing product pricing, inventory, or descriptions.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="echo_import_product_fitment_types">
                <?php wp_nonce_field( 'echo_import_product_fitment_types' ); ?>
                <table class="form-table"><tbody>
                    <tr><th scope="row"><label for="echo_product_fitment_types_csv">Product type CSV</label></th><td><input type="file" id="echo_product_fitment_types_csv" name="echo_product_fitment_types_csv" accept=".csv,text/csv" required></td></tr>
                </tbody></table>
                <?php submit_button( 'Import Product Fitment Types', 'secondary' ); ?>
            </form>
            <p><code>product_id, product_sku, fitment_type, fitment_raw, confidence, reason</code></p>
            <p><a class="button" href="<?php echo esc_url( ECHO_MOTORWORKS_CORE_URL . 'samples/product-fitment-type-template.csv' ); ?>">Download product type template</a></p>
            <hr>
            <h2>2. Exact vehicle fitment</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="echo_import_fitment_csv">
                <?php wp_nonce_field( 'echo_import_fitment_csv' ); ?>
                <table class="form-table"><tbody>
                    <tr><th scope="row"><label for="echo_fitment_csv">Fitment CSV</label></th><td><input type="file" id="echo_fitment_csv" name="echo_fitment_csv" accept=".csv,text/csv" required></td></tr>
                </tbody></table>
                <?php submit_button( 'Import Verified Fitment' ); ?>
            </form>
            <h2>Accepted columns</h2>
            <code>product_id, product_sku, year_start, year_end, make, model, submodel, engine, engine_code, transmission, drivetrain, status, notes, supplier, source</code>
            <p><a class="button" href="<?php echo esc_url( ECHO_MOTORWORKS_CORE_URL . 'samples/verified-fitment-template.csv' ); ?>">Download CSV template</a></p>
            <hr>
            <h2>3. Applied Torque Solutions exact-fitment builder</h2>
            <p>This one-time builder checks curated ATS transmission application profiles against live FuelEconomy.gov options, saves the exact EPA vehicle IDs, and connects matching ATS parent SKUs. It is safe to rerun.</p>
            <div class="notice notice-info inline"><p><strong>Conservative coverage:</strong> ambiguous tools, commercial ZF Powerline parts, transfer-case services without an exact application list, generic components that require housing verification, apparel, and core charges are intentionally not converted into verified vehicle matches.</p></div>
            <p>
                <button type="button" class="button button-primary" id="echo-ats-build">Build / Resume ATS Exact Fitment</button>
                <button type="button" class="button" id="echo-ats-stop" disabled>Stop</button>
                <button type="button" class="button" id="echo-ats-restart">Restart from Beginning</button>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:8px 0 18px">
                <input type="hidden" name="action" value="echo_export_ats_fitment">
                <?php wp_nonce_field( 'echo_export_ats_fitment' ); ?>
                <?php submit_button( 'Download Built ATS Exact CSV', 'secondary', 'submit', false ); ?>
            </form>
            <p><em>Progress is saved on the server after every small chunk. Temporary 504 errors are retried automatically, and closing this tab will not erase completed work.</em></p>
            <div id="echo-ats-progress" style="max-width:900px;display:none">
                <div style="height:18px;background:#dcdcde;border-radius:4px;overflow:hidden"><div id="echo-ats-progress-bar" style="height:100%;width:0;background:#2271b1;transition:width .2s"></div></div>
                <p id="echo-ats-progress-text" style="font-weight:600"></p>
                <textarea id="echo-ats-log" readonly style="width:100%;min-height:180px;font-family:monospace"></textarea>
            </div>
            <script>
            jQuery(function($){
                const nonce = <?php echo wp_json_encode( wp_create_nonce( 'echo_build_ats_fitment' ) ); ?>;
                let stopped = false;
                let running = false;
                let retries = 0;
                const $start = $('#echo-ats-build');
                const $stop = $('#echo-ats-stop');
                const $restart = $('#echo-ats-restart');
                const $wrap = $('#echo-ats-progress');
                const $bar = $('#echo-ats-progress-bar');
                const $text = $('#echo-ats-progress-text');
                const $log = $('#echo-ats-log');

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
                    append((message || 'Temporary request failure.') + ' Retrying the same saved chunk in ' + Math.round(delay / 1000) + 's…');
                    window.setTimeout(function(){ run(reset); }, delay);
                }

                function run(reset){
                    if (stopped) { finish('Stopped. Click Build / Resume later; saved progress is retained.'); return; }
                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        timeout: 50000,
                        data: {
                            action: 'echo_build_ats_fitment',
                            nonce: nonce,
                            reset: reset ? 1 : 0
                        }
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
                        if (d.errors && d.errors.length) {
                            d.errors.forEach(function(error){ append('  Warning: ' + error); });
                        }
                        if (d.done) {
                            finish('ATS fitment build complete. Exact EPA vehicle records and product links are ready.');
                        } else {
                            window.setTimeout(function(){ run(false); }, 150);
                        }
                    }).fail(function(xhr){
                        const detail = xhr && xhr.status ? 'HTTP ' + xhr.status : 'Request failed';
                        retryRequest(false, detail);
                    });
                }

                function begin(reset){
                    if (running) return;
                    running = true;
                    stopped = false;
                    retries = 0;
                    $start.prop('disabled', true);
                    $restart.prop('disabled', true);
                    $stop.prop('disabled', false);
                    $wrap.show();
                    if (reset) {
                        $bar.css('width','0');
                        $log.val('');
                        append('Restarting ATS exact-fitment build from the beginning…');
                    } else {
                        append('Starting or resuming ATS exact-fitment build…');
                    }
                    run(reset);
                }

                $start.on('click', function(){ begin(false); });
                $restart.on('click', function(){
                    if (window.confirm('Restart the ATS builder from the beginning? Existing fitment rows will remain and be refreshed safely.')) {
                        begin(true);
                    }
                });
                $stop.on('click', function(){ stopped = true; $stop.prop('disabled', true); });
            });
            </script>
        </div>
        <?php
    }

    public function export_ats_fitment(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'You are not allowed to export fitment.' );
        }
        check_admin_referer( 'echo_export_ats_fitment' );
        global $wpdb;
        $fitment = Echo_Motorworks_DB::fitment_table();
        $vehicles = Echo_Motorworks_DB::vehicles_table();
        $rows = $wpdb->get_results(
            "SELECT f.*, v.source AS vehicle_source, v.source_vehicle_id
             FROM {$fitment} f
             LEFT JOIN {$vehicles} v ON v.id = f.vehicle_id
             WHERE f.source = 'ats_exact_builder_v1'
             ORDER BY f.product_id, f.year_start, f.make, f.model, v.source_vehicle_id",
            ARRAY_A
        );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=ats-fitment.csv' );
        $out = fopen( 'php://output', 'wb' );
        fwrite( $out, "\xEF\xBB\xBF" );
        $headers = array( 'product_id','product_sku','vehicle_source','source_vehicle_id','year_start','year_end','make','model','submodel','generation','chassis','engine','engine_code','transmission','drivetrain','body_style','status','notes','supplier','source' );
        fputcsv( $out, $headers );
        foreach ( $rows as $row ) {
            fputcsv(
                $out,
                array(
                    $row['product_id'],
                    get_post_meta( (int) $row['product_id'], '_sku', true ),
                    $row['vehicle_source'] ?: 'epa',
                    $row['source_vehicle_id'],
                    $row['year_start'],
                    $row['year_end'],
                    $row['make'],
                    $row['model'],
                    $row['submodel'],
                    $row['generation'],
                    $row['chassis'],
                    $row['engine'],
                    $row['engine_code'],
                    $row['transmission'],
                    $row['drivetrain'],
                    $row['body_style'],
                    $row['fitment_status'],
                    $row['fitment_notes'],
                    $row['supplier'],
                    'https://applied-torque-solutions.com/',
                )
            );
        }
        fclose( $out );
        exit;
    }

    public function ajax_build_ats_fitment(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'You are not allowed to build fitment.' ), 403 );
        }
        check_ajax_referer( 'echo_build_ats_fitment', 'nonce' );
        wp_raise_memory_limit( 'admin' );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 45 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        $tasks = $this->ats_tasks();
        $total = count( $tasks );
        $reset = ! empty( $_POST['reset'] );
        $state = $this->ats_builder_state( $tasks, $reset );

        if ( ! $total || ! empty( $state['completed'] ) || (int) $state['task_index'] >= $total ) {
            $state['completed'] = true;
            $state['task_index'] = $total;
            $this->save_ats_builder_state( $state );
            wp_send_json_success(
                array(
                    'done'            => true,
                    'completed_tasks' => $total,
                    'total_tasks'     => $total,
                    'progress_pct'    => 100,
                    'progress_text'   => 'Complete',
                    'message'         => 'ATS exact-fitment build is complete.',
                    'errors'          => array(),
                )
            );
        }

        $task_index = (int) $state['task_index'];
        $task = $tasks[ $task_index ];
        $year = (int) $task['year'];
        $make = (string) $task['make'];
        $profiles = $task['profiles'];

        $models = $this->api->get_menu( 'model', $year, $make );
        if ( is_wp_error( $models ) ) {
            wp_send_json_error( array( 'message' => $task['label'] . ': ' . $models->get_error_message() ), 503 );
        }

        $work_models = array();
        foreach ( $models as $model_item ) {
            $model = sanitize_text_field( $model_item['text'] ?? $model_item['value'] ?? '' );
            if ( '' === $model ) {
                continue;
            }
            $candidate_profiles = array_values(
                array_filter(
                    $profiles,
                    fn( array $profile ): bool => $this->ats_pattern_matches( $profile['model_pattern'] ?? '', $model )
                        && ! $this->ats_pattern_matches( $profile['exclude_model_pattern'] ?? '', $model, false )
                )
            );
            if ( $candidate_profiles ) {
                $work_models[] = array(
                    'model'    => $model,
                    'profiles' => $candidate_profiles,
                );
            }
        }

        if ( (int) $state['model_index'] >= count( $work_models ) ) {
            $message = $this->complete_ats_builder_task( $state, $task, $total );
            $this->save_ats_builder_state( $state );
            wp_send_json_success( $this->ats_builder_response( $state, $total, $message, array() ) );
        }

        $model_index = (int) $state['model_index'];
        $model_work = $work_models[ $model_index ];
        $model = $model_work['model'];
        $candidate_profiles = $model_work['profiles'];

        $options = $this->api->get_menu( 'options', $year, $make, $model );
        if ( is_wp_error( $options ) ) {
            wp_send_json_error( array( 'message' => $task['label'] . ' ' . $model . ': ' . $options->get_error_message() ), 503 );
        }

        $option_index = (int) $state['option_index'];
        if ( $option_index >= count( $options ) ) {
            $state['model_index'] = $model_index + 1;
            $state['option_index'] = 0;
            $state['chunk_failures'] = 0;
            if ( (int) $state['model_index'] >= count( $work_models ) ) {
                $message = $this->complete_ats_builder_task( $state, $task, $total );
            } else {
                $message = $task['label'] . ' ' . $model . ': model complete; moving to the next model.';
            }
            $this->save_ats_builder_state( $state );
            wp_send_json_success( $this->ats_builder_response( $state, $total, $message, array() ) );
        }

        $slice = array_slice( $options, $option_index, self::ATS_OPTIONS_PER_REQUEST );
        $matched_vehicle_ids = array();
        $fitment_rows = 0;
        $missing_products = array();
        $errors = array();

        foreach ( $slice as $option ) {
            $epa_id = sanitize_text_field( $option['value'] ?? '' );
            if ( '' === $epa_id || ! ctype_digit( $epa_id ) ) {
                continue;
            }

            $vehicle = $this->api->get_vehicle( $epa_id );
            if ( is_wp_error( $vehicle ) ) {
                $state['chunk_failures'] = (int) ( $state['chunk_failures'] ?? 0 ) + 1;
                $this->save_ats_builder_state( $state );
                if ( (int) $state['chunk_failures'] < 3 ) {
                    wp_send_json_error(
                        array(
                            'message' => $task['label'] . ' ' . $model . ' #' . $epa_id . ': ' . $vehicle->get_error_message(),
                        ),
                        503
                    );
                }
                $errors[] = $model . ' #' . $epa_id . ': skipped after three failed detail requests.';
                $state['chunk_failures'] = 0;
                continue;
            }

            $vehicle_groups = array();
            $vehicle_notes  = array();
            foreach ( $candidate_profiles as $profile ) {
                if ( ! $this->ats_vehicle_matches_profile( $vehicle, $profile ) ) {
                    continue;
                }
                foreach ( (array) ( $profile['groups'] ?? array() ) as $group ) {
                    $vehicle_groups[ sanitize_key( $group ) ] = sanitize_key( $profile['status'] ?? 'confirmed' );
                }
                $vehicle_notes[] = sanitize_text_field( $profile['label'] ?? 'ATS application profile' );
            }

            if ( empty( $vehicle_groups ) ) {
                continue;
            }

            $internal_vehicle_id = $this->garage->upsert_vehicle( $vehicle );
            if ( ! $internal_vehicle_id ) {
                continue;
            }
            $vehicle['id'] = $internal_vehicle_id;
            $matched_vehicle_ids[ $epa_id ] = true;
            $state['task_matched_vehicle_ids'][ $epa_id ] = true;

            foreach ( $vehicle_groups as $group => $status ) {
                foreach ( $this->ats_group_products( $group ) as $sku ) {
                    $product_id = function_exists( 'wc_get_product_id_by_sku' ) ? absint( wc_get_product_id_by_sku( $sku ) ) : 0;
                    if ( ! $product_id ) {
                        $missing_products[ $sku ] = true;
                        $state['task_missing_products'][ $sku ] = true;
                        continue;
                    }
                    if ( $this->upsert_ats_fitment( $product_id, $sku, $vehicle, $status, implode( '; ', array_unique( $vehicle_notes ) ) ) ) {
                        ++$fitment_rows;
                        $state['task_fitment_rows'] = (int) ( $state['task_fitment_rows'] ?? 0 ) + 1;
                    }
                }
            }
        }

        $processed = count( $slice );
        $state['option_index'] = $option_index + $processed;
        $state['chunk_failures'] = 0;
        if ( $errors ) {
            $state['task_errors'] = array_values( array_unique( array_merge( (array) ( $state['task_errors'] ?? array() ), $errors ) ) );
        }

        $range_start = $option_index + 1;
        $range_end = min( count( $options ), $option_index + $processed );
        $message = sprintf(
            '%s / %s: processed option%s %d–%d of %d; %d matching vehicle%s, %d product link%s.',
            $task['label'],
            $model,
            1 === $processed ? '' : 's',
            $range_start,
            $range_end,
            count( $options ),
            count( $matched_vehicle_ids ),
            1 === count( $matched_vehicle_ids ) ? '' : 's',
            $fitment_rows,
            1 === $fitment_rows ? '' : 's'
        );

        if ( (int) $state['option_index'] >= count( $options ) ) {
            $state['model_index'] = $model_index + 1;
            $state['option_index'] = 0;
            if ( (int) $state['model_index'] >= count( $work_models ) ) {
                $message .= ' ' . $this->complete_ats_builder_task( $state, $task, $total );
            }
        }

        $this->save_ats_builder_state( $state );
        wp_send_json_success( $this->ats_builder_response( $state, $total, $message, $errors ) );
    }

    private function ats_builder_state( array $tasks, bool $reset = false ): array {
        if ( $reset ) {
            delete_option( self::ATS_BUILDER_STATE_OPTION );
        }

        $state = get_option( self::ATS_BUILDER_STATE_OPTION, array() );
        if ( ! is_array( $state ) || (string) ( $state['version'] ?? '' ) !== self::ATS_BUILDER_STATE_VERSION ) {
            $state = array(
                'version'                  => self::ATS_BUILDER_STATE_VERSION,
                'task_index'               => $reset ? 0 : $this->ats_legacy_resume_index( $tasks ),
                'model_index'              => 0,
                'option_index'             => 0,
                'task_matched_vehicle_ids' => array(),
                'task_fitment_rows'        => 0,
                'task_missing_products'    => array(),
                'task_errors'              => array(),
                'chunk_failures'           => 0,
                'completed'                => false,
                'updated_at'               => time(),
            );
            $this->save_ats_builder_state( $state );
        }
        return $state;
    }

    private function save_ats_builder_state( array $state ): void {
        $state['updated_at'] = time();
        update_option( self::ATS_BUILDER_STATE_OPTION, $state, false );
    }

    private function ats_legacy_resume_index( array $tasks ): int {
        global $wpdb;
        if ( empty( $tasks ) ) {
            return 0;
        }
        $label_indexes = array();
        foreach ( $tasks as $index => $task ) {
            $label_indexes[ (string) $task['label'] ] = (int) $index;
        }
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT data FROM ' . Echo_Motorworks_DB::logs_table() . ' WHERE context = %s ORDER BY id DESC LIMIT 1000',
                'ats_fitment_builder'
            )
        );
        $max_index = -1;
        foreach ( $rows as $json ) {
            $data = json_decode( (string) $json, true );
            $label = is_array( $data ) ? (string) ( $data['task'] ?? '' ) : '';
            if ( '' !== $label && isset( $label_indexes[ $label ] ) ) {
                $max_index = max( $max_index, $label_indexes[ $label ] );
            }
        }
        return min( count( $tasks ), $max_index + 1 );
    }

    private function complete_ats_builder_task( array &$state, array $task, int $total ): string {
        $matched = count( (array) ( $state['task_matched_vehicle_ids'] ?? array() ) );
        $rows = (int) ( $state['task_fitment_rows'] ?? 0 );
        $missing = array_keys( (array) ( $state['task_missing_products'] ?? array() ) );
        $errors = array_slice( (array) ( $state['task_errors'] ?? array() ), 0, 10 );

        Echo_Motorworks_DB::log(
            'info',
            'ats_fitment_builder',
            'ATS exact-fitment task completed.',
            array(
                'task'             => $task['label'],
                'matched_vehicles' => $matched,
                'fitment_rows'     => $rows,
                'missing_products' => $missing,
                'errors'           => $errors,
            )
        );

        $state['task_index'] = (int) $state['task_index'] + 1;
        $state['model_index'] = 0;
        $state['option_index'] = 0;
        $state['task_matched_vehicle_ids'] = array();
        $state['task_fitment_rows'] = 0;
        $state['task_missing_products'] = array();
        $state['task_errors'] = array();
        $state['chunk_failures'] = 0;
        $state['completed'] = (int) $state['task_index'] >= $total;

        return sprintf(
            'Completed %s: %d exact vehicle%s and %d product link%s.',
            $task['label'],
            $matched,
            1 === $matched ? '' : 's',
            $rows,
            1 === $rows ? '' : 's'
        );
    }

    private function ats_builder_response( array $state, int $total, string $message, array $errors ): array {
        $completed = min( $total, (int) $state['task_index'] );
        $progress = $total ? round( ( $completed / $total ) * 100, 2 ) : 100;
        $text = ! empty( $state['completed'] )
            ? 'Complete'
            : sprintf( 'Completed %d of %d year/make tasks — progress saved', $completed, $total );
        return array(
            'done'            => ! empty( $state['completed'] ),
            'completed_tasks' => $completed,
            'total_tasks'     => $total,
            'progress_pct'    => $progress,
            'progress_text'   => $text,
            'message'         => $message,
            'errors'          => array_slice( $errors, 0, 5 ),
        );
    }

    private function ats_tasks(): array {
        $grouped = array();
        foreach ( $this->ats_profiles() as $profile ) {
            $start = absint( $profile['year_start'] ?? 0 );
            $end   = absint( $profile['year_end'] ?? $start );
            for ( $year = $start; $year <= $end; ++$year ) {
                $key = $year . '|' . $profile['make'];
                if ( ! isset( $grouped[ $key ] ) ) {
                    $grouped[ $key ] = array(
                        'year'     => $year,
                        'make'     => $profile['make'],
                        'profiles' => array(),
                        'label'    => $year . ' ' . $profile['make'],
                    );
                }
                $grouped[ $key ]['profiles'][] = $profile;
            }
        }
        ksort( $grouped, SORT_NATURAL );
        return array_values( $grouped );
    }

    private function ats_profiles(): array {
        $auto8  = '~Automatic.*(?:8-spd|S8|8-speed)~i';
        $auto10 = '~Automatic.*(?:10-spd|S10|10-speed)~i';
        $bmw_models = '~^(?:(?:1|2|3|4|5|6|7|8)[0-9]{2}|M(?:2|3|4|5|6|8)|X(?:3|4|5|6|7)|Z4|ActiveHybrid|Alpina)~i';
        $bmw_exclude = '~^(?:X1|X2|i3|i4|i5|i7|i8|XM)|(?:228i|M235i).*Gran Coupe~i';
        $profiles = array();
        $add = static function( array $profile ) use ( &$profiles ): void {
            $profile += array(
                'engine_pattern'        => '',
                'fuel_pattern'          => '',
                'drive_pattern'         => '',
                'exclude_pattern'       => '',
                'exclude_model_pattern' => '',
                'status'                => 'confirmed',
            );
            $profiles[] = $profile;
        };

        // Ford 10R80 — exact automatic 10-speed options only.
        foreach ( array(
            array( 2018, 2025, 'Ford', '~Mustang~i', 'Ford Mustang 10R80' ),
            array( 2017, 2025, 'Ford', '~F[- ]?150|F150~i', 'Ford F-150 10R80' ),
            array( 2018, 2025, 'Ford', '~Expedition~i', 'Ford Expedition 10R80' ),
            array( 2018, 2025, 'Lincoln', '~Navigator~i', 'Lincoln Navigator 10R80' ),
            array( 2019, 2023, 'Ford', '~Ranger~i', 'Ford Ranger 10R80' ),
        ) as $r ) {
            $add( array( 'year_start'=>$r[0], 'year_end'=>$r[1], 'make'=>$r[2], 'model_pattern'=>$r[3], 'transmission_pattern'=>$auto10, 'exclude_pattern'=>'~Hybrid|Electric~i', 'groups'=>array('ten_speed'), 'label'=>$r[4] ) );
        }

        // GM 10L80 / 10L90 — 10-speed V8 or 3.0 diesel applications only.
        $gm_engine = '~(?:8 cyl (?:5\\.3L|6\\.2L)|6 cyl 3\\.0L)~i';
        $add( array( 'year_start'=>2017, 'year_end'=>2024, 'make'=>'Chevrolet', 'model_pattern'=>'~Camaro~i', 'transmission_pattern'=>$auto10, 'engine_pattern'=>'~8 cyl 6\\.2L~i', 'groups'=>array('ten_speed'), 'label'=>'Chevrolet Camaro V8 10L80/10L90' ) );
        foreach ( array(
            array('Chevrolet','~Silverado.*1500|Silverado 1500~i',2019,2025,'Chevrolet Silverado 1500 10L80'),
            array('GMC','~Sierra.*1500|Sierra 1500~i',2019,2025,'GMC Sierra 1500 10L80'),
            array('Chevrolet','~Tahoe~i',2018,2025,'Chevrolet Tahoe 10L80'),
            array('Chevrolet','~Suburban~i',2018,2025,'Chevrolet Suburban 10L80'),
            array('GMC','~Yukon~i',2018,2025,'GMC Yukon 10L80'),
            array('Cadillac','~Escalade~i',2018,2025,'Cadillac Escalade 10L80'),
        ) as $r ) {
            $add( array( 'year_start'=>$r[2], 'year_end'=>$r[3], 'make'=>$r[0], 'model_pattern'=>$r[1], 'transmission_pattern'=>$auto10, 'engine_pattern'=>$gm_engine, 'groups'=>array('ten_speed'), 'label'=>$r[4] ) );
        }

        // FCA / Stellantis ZF 8HP7X and 8HP9X performance applications.
        $fca7 = '~(?:8 cyl (?:5\\.7L|6\\.4L)|6 cyl 3\\.0L)~i';
        $fca9 = '~8 cyl 6\\.2L~i';
        foreach ( array(
            array('Dodge','~Challenger~i',2015,2023,'Dodge Challenger'),
            array('Dodge','~Charger~i',2015,2023,'Dodge Charger'),
            array('Dodge','~Durango~i',2014,2025,'Dodge Durango'),
            array('Jeep','~Grand Cherokee~i',2014,2025,'Jeep Grand Cherokee'),
            array('Ram','~1500~i',2013,2025,'Ram 1500'),
            array('Jeep','~Wagoneer|Grand Wagoneer~i',2022,2025,'Jeep Wagoneer'),
        ) as $r ) {
            $add( array( 'year_start'=>$r[2], 'year_end'=>$r[3], 'make'=>$r[0], 'model_pattern'=>$r[1], 'transmission_pattern'=>$auto8, 'engine_pattern'=>$fca7, 'groups'=>array('zf7x'), 'label'=>$r[4].' 8HP7X' ) );
            $add( array( 'year_start'=>$r[2], 'year_end'=>$r[3], 'make'=>$r[0], 'model_pattern'=>$r[1], 'transmission_pattern'=>$auto8, 'engine_pattern'=>$fca9, 'groups'=>array('zf9x'), 'label'=>$r[4].' 8HP9X' ) );
        }
        $add( array( 'year_start'=>2015, 'year_end'=>2023, 'make'=>'Chrysler', 'model_pattern'=>'~^300~i', 'transmission_pattern'=>$auto8, 'engine_pattern'=>'~8 cyl 5\\.7L~i', 'groups'=>array('zf7x'), 'label'=>'Chrysler 300 8HP7X' ) );
        $add( array( 'year_start'=>2018, 'year_end'=>2025, 'make'=>'Jeep', 'model_pattern'=>'~Wrangler~i', 'transmission_pattern'=>$auto8, 'engine_pattern'=>'~(?:8 cyl 6\\.4L|6 cyl 3\\.0L)~i', 'groups'=>array('zf7x'), 'label'=>'Jeep Wrangler high-torque 8HP7X' ) );
        $add( array( 'year_start'=>2020, 'year_end'=>2025, 'make'=>'Jeep', 'model_pattern'=>'~Gladiator~i', 'transmission_pattern'=>$auto8, 'engine_pattern'=>'~6 cyl 3\\.0L~i', 'groups'=>array('zf7x'), 'label'=>'Jeep Gladiator diesel 8HP7X' ) );

        // Dodge-specific converter selection (not generalized to Jeep/Ram).
        foreach ( array(
            array('~Challenger~i',2015,2023,'Dodge Challenger converter'),
            array('~Charger~i',2015,2023,'Dodge Charger converter'),
            array('~Durango~i',2014,2025,'Dodge Durango converter'),
        ) as $r ) {
            $add( array( 'year_start'=>$r[1], 'year_end'=>$r[2], 'make'=>'Dodge', 'model_pattern'=>$r[0], 'transmission_pattern'=>$auto8, 'engine_pattern'=>'~8 cyl (?:5\\.7L|6\\.4L|6\\.2L)~i', 'groups'=>array('dodge_converter'), 'label'=>$r[3] ) );
        }

        // BMW longitudinal ZF 8HP applications. Excludes known transverse/electric model families.
        $add( array( 'year_start'=>2011, 'year_end'=>2025, 'make'=>'BMW', 'model_pattern'=>$bmw_models, 'exclude_model_pattern'=>$bmw_exclude, 'transmission_pattern'=>$auto8, 'engine_pattern'=>'~(?:4 cyl|6 cyl)~i', 'exclude_pattern'=>'~Diesel|Electric~i', 'groups'=>array('zf5x','bmw_8hp'), 'label'=>'BMW gasoline 4/6-cylinder 8HP5X', 'status'=>'conditional' ) );
        $add( array( 'year_start'=>2011, 'year_end'=>2025, 'make'=>'BMW', 'model_pattern'=>$bmw_models, 'exclude_model_pattern'=>$bmw_exclude, 'transmission_pattern'=>$auto8, 'engine_pattern'=>'~8 cyl~i', 'exclude_pattern'=>'~Diesel|Electric~i', 'groups'=>array('zf7x','bmw_8hp'), 'label'=>'BMW V8 8HP7X', 'status'=>'conditional' ) );
        $add( array( 'year_start'=>2011, 'year_end'=>2020, 'make'=>'BMW', 'model_pattern'=>$bmw_models, 'exclude_model_pattern'=>$bmw_exclude, 'transmission_pattern'=>$auto8, 'engine_pattern'=>'~6 cyl 3\\.0L~i', 'fuel_pattern'=>'~Diesel~i', 'groups'=>array('zf7x','bmw_8hp','bmw_converter'), 'label'=>'BMW N57 diesel 8HP70 family', 'status'=>'conditional' ) );
        $add( array( 'year_start'=>2011, 'year_end'=>2022, 'make'=>'BMW', 'model_pattern'=>$bmw_models, 'exclude_model_pattern'=>$bmw_exclude, 'transmission_pattern'=>$auto8, 'engine_pattern'=>'~12 cyl~i', 'groups'=>array('zf9x'), 'label'=>'BMW V12 8HP9X', 'status'=>'conditional' ) );
        $add( array( 'year_start'=>2016, 'year_end'=>2025, 'make'=>'BMW', 'model_pattern'=>$bmw_models, 'exclude_model_pattern'=>$bmw_exclude, 'transmission_pattern'=>$auto8, 'engine_pattern'=>'~6 cyl 3\\.0L~i', 'exclude_pattern'=>'~Diesel|Electric~i', 'groups'=>array('bmw_converter'), 'label'=>'BMW X58 gasoline converter application', 'status'=>'conditional' ) );

        // Toyota GR Supra uses the BMW/ZF 8HP5X family.
        $add( array( 'year_start'=>2020, 'year_end'=>2025, 'make'=>'Toyota', 'model_pattern'=>'~Supra~i', 'transmission_pattern'=>$auto8, 'engine_pattern'=>'~(?:4 cyl 2\\.0L|6 cyl 3\\.0L)~i', 'groups'=>array('zf5x'), 'label'=>'Toyota GR Supra 8HP5X', 'status'=>'conditional' ) );

        // Audi longitudinal 3.0L automatic-8 applications used for ATS 8HP55/65 kits.
        $add( array( 'year_start'=>2011, 'year_end'=>2025, 'make'=>'Audi', 'model_pattern'=>'~^(?:A4|A5|A6|A7|A8|S4|S5|Q5|Q7|SQ5)~i', 'transmission_pattern'=>$auto8, 'engine_pattern'=>'~6 cyl 3\\.0L~i', 'groups'=>array('zf55_65'), 'label'=>'Audi longitudinal 3.0L 8HP55/65', 'status'=>'conditional' ) );

        return $profiles;
    }

    private function ats_group_products( string $group ): array {
        $groups = array(
            'ten_speed'       => array( 'ATS-019','ATS-020','ATS-021','ATS-022','ATS-037','ATS-038' ),
            'zf5x'            => array( 'ATS-002','ATS-003','ATS-004','ATS-007','ATS-008','ATS-009','ATS-028','ATS-029','ATS-030','ATS-039','ATS-042','ATS-045','ATS-048' ),
            'zf55_65'         => array( 'ATS-005','ATS-006' ),
            'zf7x'            => array( 'ATS-002','ATS-004','ATS-010','ATS-011','ATS-012','ATS-031','ATS-032','ATS-033','ATS-034','ATS-039','ATS-043','ATS-046','ATS-049' ),
            'zf9x'            => array( 'ATS-013','ATS-014','ATS-015','ATS-035','ATS-036','ATS-044','ATS-047','ATS-050','ATS-056' ),
            'bmw_8hp'         => array( 'ATS-040','ATS-054' ),
            'bmw_converter'   => array( 'ATS-052' ),
            'dodge_converter' => array( 'ATS-055' ),
        );
        return $groups[ $group ] ?? array();
    }

    private function ats_pattern_matches( string $pattern, string $value, bool $blank_matches = true ): bool {
        if ( '' === $pattern ) {
            return $blank_matches;
        }
        $result = @preg_match( $pattern, $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        return 1 === $result;
    }

    private function ats_vehicle_matches_profile( array $vehicle, array $profile ): bool {
        if ( ! $this->ats_pattern_matches( $profile['transmission_pattern'] ?? '', (string) ( $vehicle['transmission'] ?? '' ) ) ) {
            return false;
        }
        if ( ! $this->ats_pattern_matches( $profile['engine_pattern'] ?? '', (string) ( $vehicle['engine'] ?? '' ) ) ) {
            return false;
        }
        if ( ! $this->ats_pattern_matches( $profile['fuel_pattern'] ?? '', (string) ( $vehicle['fuel_type'] ?? '' ) ) ) {
            return false;
        }
        if ( ! $this->ats_pattern_matches( $profile['drive_pattern'] ?? '', (string) ( $vehicle['drivetrain'] ?? '' ) ) ) {
            return false;
        }
        $combined = implode( ' | ', array(
            $vehicle['model'] ?? '', $vehicle['engine'] ?? '', $vehicle['transmission'] ?? '',
            $vehicle['drivetrain'] ?? '', $vehicle['fuel_type'] ?? '', $vehicle['option_label'] ?? '',
        ) );
        if ( $this->ats_pattern_matches( $profile['exclude_pattern'] ?? '', $combined, false ) ) {
            return false;
        }
        return true;
    }

    private function upsert_ats_fitment( int $product_id, string $sku, array $vehicle, string $status, string $profile_notes ): bool {
        global $wpdb;
        $table = Echo_Motorworks_DB::fitment_table();
        $vehicle_id = absint( $vehicle['id'] ?? 0 );
        $source_vehicle_id = sanitize_text_field( $vehicle['source_vehicle_id'] ?? '' );
        if ( ! $vehicle_id || '' === $source_vehicle_id ) {
            return false;
        }
        $status = in_array( $status, array( 'confirmed', 'conditional' ), true ) ? $status : 'conditional';
        $source = 'ats_exact_builder_v1';
        $source_key = hash( 'sha256', implode( '|', array( $product_id, $vehicle_id, 'epa', $source_vehicle_id, $source ) ) );
        $now = current_time( 'mysql', true );
        $data = array(
            'product_id'             => $product_id,
            'vehicle_id'             => $vehicle_id,
            'year_start'             => absint( $vehicle['year'] ?? 0 ),
            'year_end'               => absint( $vehicle['year'] ?? 0 ),
            'make'                   => sanitize_text_field( $vehicle['make'] ?? '' ),
            'model'                  => sanitize_text_field( $vehicle['model'] ?? '' ),
            'submodel'               => sanitize_text_field( $vehicle['submodel'] ?? '' ),
            'generation'             => sanitize_text_field( $vehicle['generation'] ?? '' ),
            'chassis'                => sanitize_text_field( $vehicle['chassis'] ?? '' ),
            'engine'                 => sanitize_text_field( $vehicle['engine'] ?? '' ),
            'engine_code'            => sanitize_text_field( $vehicle['engine_code'] ?? '' ),
            'transmission'           => sanitize_text_field( $vehicle['transmission'] ?? '' ),
            'drivetrain'             => sanitize_text_field( $vehicle['drivetrain'] ?? '' ),
            'body_style'             => sanitize_text_field( $vehicle['body_style'] ?? '' ),
            'normalized_make'        => Echo_Motorworks_DB::normalize( (string) ( $vehicle['make'] ?? '' ) ),
            'normalized_model'       => Echo_Motorworks_DB::normalize( (string) ( $vehicle['model'] ?? '' ) ),
            'normalized_engine'      => Echo_Motorworks_DB::normalize( (string) ( $vehicle['engine'] ?? '' ) ),
            'normalized_submodel'    => Echo_Motorworks_DB::normalize( (string) ( $vehicle['submodel'] ?? '' ) ),
            'normalized_transmission'=> Echo_Motorworks_DB::normalize( (string) ( $vehicle['transmission'] ?? '' ) ),
            'normalized_drivetrain'  => Echo_Motorworks_DB::normalize( (string) ( $vehicle['drivetrain'] ?? '' ) ),
            'fitment_status'         => $status,
            'fitment_notes'          => sanitize_textarea_field( 'ATS exact EPA fitment. ' . $profile_notes . '. Confirm the transmission identification tag for modified or swapped vehicles.' ),
            'supplier'               => 'Applied Torque Solutions',
            'source'                 => $source,
            'source_key'             => $source_key,
            'updated_at'             => $now,
        );
        $existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_key = %s", $source_key ) );
        if ( $existing ) {
            return false !== $wpdb->update( $table, $data, array( 'id' => $existing ) );
        }
        $data['created_at'] = $now;
        return (bool) $wpdb->insert( $table, $data );
    }

    public function settings_page(): void {
        ?>
        <div class="wrap">
            <h1>Echo Motorworks Settings</h1>
            <table class="widefat striped" style="max-width:900px"><tbody>
                <tr><th>Year / Make / Model / Option</th><td>FuelEconomy.gov Web Services</td></tr>
                <tr><th>VIN decoding</th><td>NHTSA vPIC</td></tr>
                <tr><th>Product fitment</th><td>Echo verified fitment table</td></tr>
                <tr><th>Cache</th><td>WordPress transients; menus cached up to 30 days, vehicle records up to 90 days</td></tr>
            </tbody></table>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:20px">
                <input type="hidden" name="action" value="echo_clear_vehicle_cache">
                <?php wp_nonce_field( 'echo_clear_vehicle_cache' ); ?>
                <?php submit_button( 'Clear Vehicle Data Cache', 'secondary' ); ?>
            </form>
        </div>
        <?php
    }

    public function import_product_fitment_types(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'You are not allowed to import product fitment types.' );
        }
        check_admin_referer( 'echo_import_product_fitment_types' );
        wp_raise_memory_limit( 'admin' );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        if ( empty( $_FILES['echo_product_fitment_types_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['echo_product_fitment_types_csv']['tmp_name'] ) ) {
            $this->redirect_notice( 'error', 'No product type CSV file was uploaded.' );
        }

        $handle = fopen( $_FILES['echo_product_fitment_types_csv']['tmp_name'], 'rb' );
        if ( ! $handle ) {
            $this->redirect_notice( 'error', 'The product type CSV could not be opened.' );
        }

        $headers = fgetcsv( $handle );
        if ( ! is_array( $headers ) ) {
            fclose( $handle );
            $this->redirect_notice( 'error', 'The product type CSV has no header row.' );
        }
        $headers = array_map( static function ( $value ): string {
            $value = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $value );
            return sanitize_key( trim( (string) $value ) );
        }, $headers );

        if ( ! array_intersect( $headers, array( 'product_id', 'product_sku' ) ) || ! in_array( 'fitment_type', $headers, true ) ) {
            fclose( $handle );
            $this->redirect_notice( 'error', 'The CSV needs product_id or product_sku plus fitment_type.' );
        }

        $allowed_types = array( 'universal', 'vehicle_specific', 'engine_specific', 'needs_review', 'unknown' );
        $updated = 0;
        $skipped = 0;
        while ( ( $values = fgetcsv( $handle ) ) !== false ) {
            if ( count( $values ) < count( $headers ) ) {
                $values = array_pad( $values, count( $headers ), '' );
            }
            $row = array_combine( $headers, array_slice( $values, 0, count( $headers ) ) );
            if ( ! is_array( $row ) ) {
                ++$skipped;
                continue;
            }

            $product_id = absint( $row['product_id'] ?? 0 );
            if ( ! $product_id && ! empty( $row['product_sku'] ) && function_exists( 'wc_get_product_id_by_sku' ) ) {
                $product_id = absint( wc_get_product_id_by_sku( sanitize_text_field( $row['product_sku'] ) ) );
            }
            $type = sanitize_key( (string) ( $row['fitment_type'] ?? '' ) );
            if ( ! $product_id || 'product' !== get_post_type( $product_id ) || ! in_array( $type, $allowed_types, true ) ) {
                ++$skipped;
                continue;
            }

            update_post_meta( $product_id, '_echo_fitment_type', $type );
            if ( array_key_exists( 'fitment_raw', $row ) ) {
                update_post_meta( $product_id, '_echo_fitment_raw', sanitize_textarea_field( (string) $row['fitment_raw'] ) );
            }
            if ( array_key_exists( 'confidence', $row ) ) {
                update_post_meta( $product_id, '_echo_fitment_confidence', sanitize_key( (string) $row['confidence'] ) );
            }
            if ( array_key_exists( 'reason', $row ) ) {
                update_post_meta( $product_id, '_echo_fitment_reason', sanitize_text_field( (string) $row['reason'] ) );
            }
            ++$updated;
        }
        fclose( $handle );

        Echo_Motorworks_DB::log( 'info', 'product_fitment_type_import', 'Product fitment type CSV import completed.', compact( 'updated', 'skipped' ) );
        $this->redirect_notice( 'success', sprintf( 'Updated %d product fitment types; skipped %d rows.', $updated, $skipped ) );
    }

    public function import_fitment_csv(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'You are not allowed to import fitment.' );
        }
        check_admin_referer( 'echo_import_fitment_csv' );
        wp_raise_memory_limit( 'admin' );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        if ( empty( $_FILES['echo_fitment_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['echo_fitment_csv']['tmp_name'] ) ) {
            $this->redirect_notice( 'error', 'No CSV file was uploaded.' );
        }

        $handle = fopen( $_FILES['echo_fitment_csv']['tmp_name'], 'rb' );
        if ( ! $handle ) {
            $this->redirect_notice( 'error', 'The CSV could not be opened.' );
        }

        $headers = fgetcsv( $handle );
        if ( ! is_array( $headers ) ) {
            fclose( $handle );
            $this->redirect_notice( 'error', 'The CSV has no header row.' );
        }
        $headers = array_map( static function ( $value ): string {
            $value = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $value );
            return sanitize_key( trim( (string) $value ) );
        }, $headers );

        $required_any = array_intersect( $headers, array( 'product_id', 'product_sku' ) );
        foreach ( array( 'make', 'model' ) as $required ) {
            if ( ! in_array( $required, $headers, true ) ) {
                fclose( $handle );
                $this->redirect_notice( 'error', 'Missing required column: ' . $required );
            }
        }
        if ( ! $required_any ) {
            fclose( $handle );
            $this->redirect_notice( 'error', 'The CSV needs product_id or product_sku.' );
        }

        $imported = 0;
        $skipped  = 0;
        $row_num  = 1;
        while ( ( $values = fgetcsv( $handle ) ) !== false ) {
            ++$row_num;
            if ( count( $values ) < count( $headers ) ) {
                $values = array_pad( $values, count( $headers ), '' );
            }
            $row = array_combine( $headers, array_slice( $values, 0, count( $headers ) ) );
            if ( ! is_array( $row ) ) {
                ++$skipped;
                continue;
            }
            $result = $this->import_fitment_row( $row, $row_num );
            if ( $result ) {
                ++$imported;
            } else {
                ++$skipped;
            }
        }
        fclose( $handle );

        Echo_Motorworks_DB::log( 'info', 'fitment_import', 'Fitment CSV import completed.', compact( 'imported', 'skipped' ) );
        $this->redirect_notice( 'success', sprintf( 'Imported %d verified fitment rows; skipped %d rows.', $imported, $skipped ) );
    }

    private function import_fitment_row( array $row, int $row_num ): bool {
        global $wpdb;

        $product_id = absint( $row['product_id'] ?? 0 );
        if ( ! $product_id && ! empty( $row['product_sku'] ) && function_exists( 'wc_get_product_id_by_sku' ) ) {
            $product_id = absint( wc_get_product_id_by_sku( sanitize_text_field( $row['product_sku'] ) ) );
        }
        if ( ! $product_id || 'product' !== get_post_type( $product_id ) ) {
            Echo_Motorworks_DB::log( 'warning', 'fitment_import', 'Product was not found.', array( 'row' => $row_num, 'sku' => $row['product_sku'] ?? '' ) );
            return false;
        }

        $vehicle_id       = 0;
        $vehicle_source   = sanitize_key( $row['vehicle_source'] ?? 'epa' );
        $source_vehicle_id = sanitize_text_field( $row['source_vehicle_id'] ?? '' );
        $resolved_vehicle = null;

        if ( '' !== $source_vehicle_id ) {
            $resolved_vehicle = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT * FROM ' . Echo_Motorworks_DB::vehicles_table() . ' WHERE source = %s AND source_vehicle_id = %s LIMIT 1',
                    $vehicle_source,
                    $source_vehicle_id
                ),
                ARRAY_A
            );

            if ( ! is_array( $resolved_vehicle ) ) {
                Echo_Motorworks_DB::log(
                    'warning',
                    'fitment_import',
                    'Exact source vehicle was not found in the local vehicle table.',
                    array( 'row' => $row_num, 'vehicle_source' => $vehicle_source, 'source_vehicle_id' => $source_vehicle_id )
                );
                return false;
            }

            $vehicle_id = absint( $resolved_vehicle['id'] ?? 0 );
        }

        $make  = sanitize_text_field( $row['make'] ?? ( $resolved_vehicle['make'] ?? '' ) );
        $model = sanitize_text_field( $row['model'] ?? ( $resolved_vehicle['model'] ?? '' ) );
        if ( '' === $make || '' === $model ) {
            return false;
        }

        $status = sanitize_key( $row['status'] ?? 'confirmed' );
        if ( ! in_array( $status, array( 'confirmed', 'conditional', 'excluded', 'unknown' ), true ) ) {
            $status = 'unknown';
        }

        $year_start = absint( $row['year_start'] ?? 0 );
        $year_end   = absint( $row['year_end'] ?? 0 );
        if ( $year_start && ! $year_end ) {
            $year_end = $year_start;
        }
        if ( $year_end && ! $year_start ) {
            $year_start = $year_end;
        }
        if ( $year_start && $year_end && $year_start > $year_end ) {
            [ $year_start, $year_end ] = array( $year_end, $year_start );
        }

        if ( $resolved_vehicle ) {
            if ( ! $year_start ) {
                $year_start = absint( $resolved_vehicle['year'] ?? 0 );
            }
            if ( ! $year_end ) {
                $year_end = $year_start;
            }
        }

        $source = sanitize_text_field( $row['source'] ?? 'manual_csv' );
        $source_key_payload = array(
            $product_id, $vehicle_id, $vehicle_source, $source_vehicle_id, $year_start, $year_end, $make, $model,
            $row['submodel'] ?? '', $row['engine'] ?? '', $row['engine_code'] ?? '',
            $row['transmission'] ?? '', $row['drivetrain'] ?? '', $status, $source,
        );
        $source_key = hash( 'sha256', implode( '|', array_map( 'strval', $source_key_payload ) ) );
        $now = current_time( 'mysql', true );

        $data = array(
            'product_id'        => $product_id,
            'vehicle_id'        => $vehicle_id ?: null,
            'year_start'        => $year_start ?: null,
            'year_end'          => $year_end ?: null,
            'make'              => $make,
            'model'             => $model,
            'submodel'          => sanitize_text_field( $row['submodel'] ?? ( $resolved_vehicle['submodel'] ?? '' ) ),
            'generation'        => sanitize_text_field( $row['generation'] ?? ( $resolved_vehicle['generation'] ?? '' ) ),
            'chassis'           => sanitize_text_field( $row['chassis'] ?? ( $resolved_vehicle['chassis'] ?? '' ) ),
            'engine'            => sanitize_text_field( $row['engine'] ?? ( $resolved_vehicle['engine'] ?? '' ) ),
            'engine_code'       => sanitize_text_field( $row['engine_code'] ?? ( $resolved_vehicle['engine_code'] ?? '' ) ),
            'transmission'      => sanitize_text_field( $row['transmission'] ?? ( $resolved_vehicle['transmission'] ?? '' ) ),
            'drivetrain'        => sanitize_text_field( $row['drivetrain'] ?? ( $resolved_vehicle['drivetrain'] ?? '' ) ),
            'body_style'        => sanitize_text_field( $row['body_style'] ?? ( $resolved_vehicle['body_style'] ?? '' ) ),
            'normalized_make'   => Echo_Motorworks_DB::normalize( $make ),
            'normalized_model'  => Echo_Motorworks_DB::normalize( $model ),
            'normalized_engine'      => Echo_Motorworks_DB::normalize( (string) ( $row['engine'] ?? ( $resolved_vehicle['engine'] ?? '' ) ) ),
            'normalized_submodel'    => Echo_Motorworks_DB::normalize( (string) ( $row['submodel'] ?? ( $resolved_vehicle['submodel'] ?? '' ) ) ),
            'normalized_transmission'=> Echo_Motorworks_DB::normalize( (string) ( $row['transmission'] ?? ( $resolved_vehicle['transmission'] ?? '' ) ) ),
            'normalized_drivetrain'  => Echo_Motorworks_DB::normalize( (string) ( $row['drivetrain'] ?? ( $resolved_vehicle['drivetrain'] ?? '' ) ) ),
            'fitment_status'    => $status,
            'fitment_notes'     => sanitize_textarea_field( $row['notes'] ?? '' ),
            'supplier'          => sanitize_text_field( $row['supplier'] ?? '' ),
            'source'            => $source,
            'source_key'        => $source_key,
            'updated_at'        => $now,
        );

        $existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Echo_Motorworks_DB::fitment_table() . ' WHERE source_key = %s', $source_key ) );
        if ( $existing ) {
            return false !== $wpdb->update( Echo_Motorworks_DB::fitment_table(), $data, array( 'id' => $existing ) );
        }
        $data['created_at'] = $now;
        return (bool) $wpdb->insert( Echo_Motorworks_DB::fitment_table(), $data );
    }

    public function clear_vehicle_cache(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'You are not allowed to clear the cache.' );
        }
        check_admin_referer( 'echo_clear_vehicle_cache' );
        $count = $this->api->clear_cache();
        $this->redirect_notice( 'success', sprintf( 'Cleared %d cached vehicle-data entries.', $count ) );
    }

    public function admin_notices(): void {
        if ( empty( $_GET['echo_notice'] ) || empty( $_GET['echo_message'] ) ) {
            return;
        }
        $type = sanitize_key( wp_unslash( $_GET['echo_notice'] ) );
        $message = sanitize_text_field( wp_unslash( $_GET['echo_message'] ) );
        printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info' ), esc_html( $message ) );
    }

    private function redirect_notice( string $type, string $message ): void {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'         => 'echo-motorworks-fitment',
                    'echo_notice'  => $type,
                    'echo_message' => $message,
                ),
                admin_url( class_exists( 'WooCommerce' ) ? 'admin.php' : 'tools.php' )
            )
        );
        exit;
    }

    private function metric_card( string $label, $value ): void {
        printf( '<div style="background:#fff;border:1px solid #dcdcde;padding:18px"><strong style="display:block;font-size:28px">%s</strong><span>%s</span></div>', esc_html( (string) $value ), esc_html( $label ) );
    }
}
