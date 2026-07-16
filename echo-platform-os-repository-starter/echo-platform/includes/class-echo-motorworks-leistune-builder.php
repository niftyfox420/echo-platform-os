<?php

defined( 'ABSPATH' ) || exit;

/**
 * Builds conservative, exact FuelEconomy.gov vehicle-ID fitment for the
 * seven Leistune products carried by Echo Motorworks.
 */
final class Echo_Motorworks_Leistune_Builder {
    private const STATE_OPTION = 'echo_leistune_builder_state_v1';
    private const STATE_VERSION = '1';
    private const OPTIONS_PER_REQUEST = 2;
    private const SOURCE = 'leistune_exact_builder_v1';

    private Echo_Motorworks_API $api;
    private Echo_Motorworks_Garage $garage;

    public function __construct( Echo_Motorworks_API $api, Echo_Motorworks_Garage $garage ) {
        $this->api = $api;
        $this->garage = $garage;
        add_action( 'admin_menu', array( $this, 'admin_menu' ), 61 );
        add_action( 'wp_ajax_echo_build_leistune_fitment', array( $this, 'ajax_build' ) );
        add_action( 'admin_post_echo_export_leistune_fitment', array( $this, 'export_fitment' ) );
    }

    public function admin_menu(): void {
        $parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'tools.php';
        add_submenu_page(
            $parent,
            'Leistune Fitment',
            'Leistune Fitment',
            'manage_woocommerce',
            'echo-leistune-fitment',
            array( $this, 'page' )
        );
    }

    public function page(): void {
        ?>
        <div class="wrap">
            <h1>Leistune Exact Fitment</h1>
            <p>This builder checks conservative Audi application profiles against live FuelEconomy.gov options, saves the exact EPA vehicle IDs, and links the matching Leistune parent SKUs.</p>
            <div class="notice notice-info inline"><p><strong>Coverage:</strong> the ECU/TCU tunes, DL501 tune, ZF8 tune and intake manifolds receive confirmed rows where Leistune publishes a direct application. The SQ7 tune and 5-bar MAP/IAT sensor receive conditional rows because an ECU/software check is still required. The immobilizer service remains module-specific and is not falsely shown as fitting every VW/Audi.</p></div>
            <p>
                <button type="button" class="button button-primary" id="echo-lei-build">Build / Resume Leistune Exact Fitment</button>
                <button type="button" class="button" id="echo-lei-stop" disabled>Stop</button>
                <button type="button" class="button" id="echo-lei-restart">Restart from Beginning</button>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:8px 0 18px">
                <input type="hidden" name="action" value="echo_export_leistune_fitment">
                <?php wp_nonce_field( 'echo_export_leistune_fitment' ); ?>
                <?php submit_button( 'Download Built Leistune Exact CSV', 'secondary', 'submit', false ); ?>
            </form>
            <p><em>Progress is saved after every small chunk. It is safe to close the tab and resume later.</em></p>
            <div id="echo-lei-progress" style="max-width:900px;display:none">
                <div style="height:18px;background:#dcdcde;border-radius:4px;overflow:hidden"><div id="echo-lei-progress-bar" style="height:100%;width:0;background:#2271b1;transition:width .2s"></div></div>
                <p id="echo-lei-progress-text" style="font-weight:600"></p>
                <textarea id="echo-lei-log" readonly style="width:100%;min-height:180px;font-family:monospace"></textarea>
            </div>
            <script>
            jQuery(function($){
                const nonce = <?php echo wp_json_encode( wp_create_nonce( 'echo_build_leistune_fitment' ) ); ?>;
                let stopped = false;
                let running = false;
                let retries = 0;
                const $start = $('#echo-lei-build');
                const $stop = $('#echo-lei-stop');
                const $restart = $('#echo-lei-restart');
                const $wrap = $('#echo-lei-progress');
                const $bar = $('#echo-lei-progress-bar');
                const $text = $('#echo-lei-progress-text');
                const $log = $('#echo-lei-log');

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
                        data: {
                            action: 'echo_build_leistune_fitment',
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
                            finish('Leistune fitment build complete. Exact EPA vehicle records and product links are ready.');
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
                        append('Restarting Leistune exact-fitment build from the beginning…');
                    } else {
                        append('Starting or resuming Leistune exact-fitment build…');
                    }
                    run(reset);
                }
                $start.on('click', function(){ begin(false); });
                $restart.on('click', function(){
                    if (window.confirm('Restart the Leistune builder from the beginning? Existing rows will be refreshed safely.')) {
                        begin(true);
                    }
                });
                $stop.on('click', function(){ stopped = true; $stop.prop('disabled', true); });
            });
            </script>
        </div>
        <?php
    }

    public function ajax_build(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'You are not allowed to build fitment.' ), 403 );
        }
        check_ajax_referer( 'echo_build_leistune_fitment', 'nonce' );
        wp_raise_memory_limit( 'admin' );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 45 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        $this->sync_product_scopes();
        $tasks = $this->tasks();
        $total = count( $tasks );
        $reset = ! empty( $_POST['reset'] );
        $state = $this->builder_state( $reset );

        if ( ! $total || ! empty( $state['completed'] ) || (int) $state['task_index'] >= $total ) {
            $state['completed'] = true;
            $state['task_index'] = $total;
            $this->save_state( $state );
            wp_send_json_success( array(
                'done' => true,
                'completed_tasks' => $total,
                'total_tasks' => $total,
                'progress_pct' => 100,
                'progress_text' => 'Complete',
                'message' => 'Leistune exact-fitment build is complete.',
                'errors' => array(),
            ) );
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
            $candidate_profiles = array_values( array_filter(
                $profiles,
                fn( array $profile ): bool => $this->pattern_matches( $profile['model_pattern'] ?? '', $model )
                    && ! $this->pattern_matches( $profile['exclude_model_pattern'] ?? '', $model, false )
            ) );
            if ( $candidate_profiles ) {
                $work_models[] = array( 'model' => $model, 'profiles' => $candidate_profiles );
            }
        }

        if ( (int) $state['model_index'] >= count( $work_models ) ) {
            $message = $this->complete_task( $state, $task, $total );
            $this->save_state( $state );
            wp_send_json_success( $this->response( $state, $total, $message, array() ) );
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
            $message = (int) $state['model_index'] >= count( $work_models )
                ? $this->complete_task( $state, $task, $total )
                : $task['label'] . ' ' . $model . ': model complete; moving to the next model.';
            $this->save_state( $state );
            wp_send_json_success( $this->response( $state, $total, $message, array() ) );
        }

        $slice = array_slice( $options, $option_index, self::OPTIONS_PER_REQUEST );
        $matched_vehicle_ids = array();
        $fitment_rows = 0;
        $errors = array();

        foreach ( $slice as $option ) {
            $epa_id = sanitize_text_field( $option['value'] ?? '' );
            if ( '' === $epa_id || ! ctype_digit( $epa_id ) ) {
                continue;
            }
            $vehicle = $this->api->get_vehicle( $epa_id );
            if ( is_wp_error( $vehicle ) ) {
                $state['chunk_failures'] = (int) ( $state['chunk_failures'] ?? 0 ) + 1;
                $this->save_state( $state );
                if ( (int) $state['chunk_failures'] < 3 ) {
                    wp_send_json_error( array( 'message' => $task['label'] . ' ' . $model . ' #' . $epa_id . ': ' . $vehicle->get_error_message() ), 503 );
                }
                $errors[] = $model . ' #' . $epa_id . ': skipped after three failed detail requests.';
                $state['chunk_failures'] = 0;
                continue;
            }

            $vehicle_groups = array();
            $vehicle_notes = array();
            foreach ( $candidate_profiles as $profile ) {
                if ( ! $this->vehicle_matches_profile( $vehicle, $profile ) ) {
                    continue;
                }
                foreach ( (array) ( $profile['groups'] ?? array() ) as $group ) {
                    $vehicle_groups[ sanitize_key( $group ) ] = sanitize_key( $profile['status'] ?? 'confirmed' );
                }
                $vehicle_notes[] = sanitize_text_field( $profile['label'] ?? 'Leistune application profile' );
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
                foreach ( $this->group_products( $group ) as $sku ) {
                    $product_id = function_exists( 'wc_get_product_id_by_sku' ) ? absint( wc_get_product_id_by_sku( $sku ) ) : 0;
                    if ( ! $product_id ) {
                        $state['task_missing_products'][ $sku ] = true;
                        continue;
                    }
                    if ( $this->upsert_fitment( $product_id, $vehicle, $status, implode( '; ', array_unique( $vehicle_notes ) ) ) ) {
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
                $message .= ' ' . $this->complete_task( $state, $task, $total );
            }
        }
        $this->save_state( $state );
        wp_send_json_success( $this->response( $state, $total, $message, $errors ) );
    }

    public function export_fitment(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'You are not allowed to export fitment.' );
        }
        check_admin_referer( 'echo_export_leistune_fitment' );
        global $wpdb;
        $fitment = Echo_Motorworks_DB::fitment_table();
        $vehicles = Echo_Motorworks_DB::vehicles_table();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT f.*, v.source AS vehicle_source, v.source_vehicle_id
                 FROM {$fitment} f
                 LEFT JOIN {$vehicles} v ON v.id = f.vehicle_id
                 WHERE f.source = %s
                 ORDER BY f.product_id, f.year_start, f.make, f.model, v.source_vehicle_id",
                self::SOURCE
            ),
            ARRAY_A
        );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=leistune-fitment.csv' );
        $out = fopen( 'php://output', 'wb' );
        fwrite( $out, "\xEF\xBB\xBF" );
        $headers = array( 'product_id','product_sku','vehicle_source','source_vehicle_id','year_start','year_end','make','model','submodel','generation','chassis','engine','engine_code','transmission','drivetrain','body_style','status','notes','supplier','source' );
        fputcsv( $out, $headers );
        foreach ( $rows as $row ) {
            fputcsv( $out, array(
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
                'https://leistune.com/',
            ) );
        }
        fclose( $out );
        exit;
    }

    private function builder_state( bool $reset ): array {
        if ( $reset ) {
            delete_option( self::STATE_OPTION );
        }
        $state = get_option( self::STATE_OPTION, array() );
        if ( ! is_array( $state ) || (string) ( $state['version'] ?? '' ) !== self::STATE_VERSION ) {
            $state = array(
                'version' => self::STATE_VERSION,
                'task_index' => 0,
                'model_index' => 0,
                'option_index' => 0,
                'task_matched_vehicle_ids' => array(),
                'task_fitment_rows' => 0,
                'task_missing_products' => array(),
                'task_errors' => array(),
                'chunk_failures' => 0,
                'completed' => false,
                'updated_at' => time(),
            );
            $this->save_state( $state );
        }
        return $state;
    }

    private function save_state( array $state ): void {
        $state['updated_at'] = time();
        update_option( self::STATE_OPTION, $state, false );
    }

    private function complete_task( array &$state, array $task, int $total ): string {
        $matched = count( (array) ( $state['task_matched_vehicle_ids'] ?? array() ) );
        $rows = (int) ( $state['task_fitment_rows'] ?? 0 );
        $missing = array_keys( (array) ( $state['task_missing_products'] ?? array() ) );
        $errors = array_slice( (array) ( $state['task_errors'] ?? array() ), 0, 10 );
        Echo_Motorworks_DB::log( 'info', 'leistune_fitment_builder', 'Leistune exact-fitment task completed.', array(
            'task' => $task['label'],
            'matched_vehicles' => $matched,
            'fitment_rows' => $rows,
            'missing_products' => $missing,
            'errors' => $errors,
        ) );
        $state['task_index'] = (int) $state['task_index'] + 1;
        $state['model_index'] = 0;
        $state['option_index'] = 0;
        $state['task_matched_vehicle_ids'] = array();
        $state['task_fitment_rows'] = 0;
        $state['task_missing_products'] = array();
        $state['task_errors'] = array();
        $state['chunk_failures'] = 0;
        $state['completed'] = (int) $state['task_index'] >= $total;
        return sprintf( 'Completed %s: %d exact vehicle%s and %d product link%s.', $task['label'], $matched, 1 === $matched ? '' : 's', $rows, 1 === $rows ? '' : 's' );
    }

    private function response( array $state, int $total, string $message, array $errors ): array {
        $completed = min( $total, (int) $state['task_index'] );
        $progress = $total ? round( ( $completed / $total ) * 100, 2 ) : 100;
        return array(
            'done' => ! empty( $state['completed'] ),
            'completed_tasks' => $completed,
            'total_tasks' => $total,
            'progress_pct' => $progress,
            'progress_text' => ! empty( $state['completed'] ) ? 'Complete' : sprintf( 'Completed %d of %d year/make tasks — progress saved', $completed, $total ),
            'message' => $message,
            'errors' => array_slice( $errors, 0, 5 ),
        );
    }

    private function tasks(): array {
        $grouped = array();
        foreach ( $this->profiles() as $profile ) {
            $start = absint( $profile['year_start'] ?? 0 );
            $end = absint( $profile['year_end'] ?? $start );
            for ( $year = $start; $year <= $end; ++$year ) {
                $key = $year . '|' . $profile['make'];
                if ( ! isset( $grouped[ $key ] ) ) {
                    $grouped[ $key ] = array( 'year' => $year, 'make' => $profile['make'], 'profiles' => array(), 'label' => $year . ' ' . $profile['make'] );
                }
                $grouped[ $key ]['profiles'][] = $profile;
            }
        }
        ksort( $grouped, SORT_NATURAL );
        return array_values( $grouped );
    }

    private function profiles(): array {
        $auto7 = '~(?:Auto.*(?:AM-S7|S7|7)|Automatic.*7|7[- ]speed|7-spd)~i';
        $auto8 = '~(?:Auto.*(?:AM-S8|S8|8)|Automatic.*8|8[- ]speed|8-spd)~i';
        $v8_4t = '~8 cyl 4\\.0L~i';
        $profiles = array();
        $add = static function( array $profile ) use ( &$profiles ): void {
            $profile += array(
                'engine_pattern' => '',
                'transmission_pattern' => '',
                'fuel_pattern' => '',
                'drive_pattern' => '',
                'exclude_pattern' => '',
                'exclude_model_pattern' => '',
                'status' => 'confirmed',
            );
            $profiles[] = $profile;
        };

        // S6 / S7: C7/C7.5 4.0T with DL501 seven-speed S tronic.
        foreach ( array( array( 'S6', '~^S6(?:\\b|$)~i' ), array( 'S7', '~^S7(?:\\b|$)~i' ) ) as $model ) {
            $base = array( 'year_start'=>2013, 'year_end'=>2018, 'make'=>'Audi', 'model_pattern'=>$model[1], 'engine_pattern'=>$v8_4t, 'transmission_pattern'=>$auto7 );
            $add( $base + array( 'groups'=>array('combo4t','dl501','manifold4t'), 'label'=>'Audi '.$model[0].' C7/C7.5 4.0T DL501' ) );
            $add( $base + array( 'groups'=>array('mapsensor4t'), 'label'=>'Audi '.$model[0].' MED17.1.1 4.0T sensor application', 'status'=>'conditional' ) );
        }

        // A8 / S8: D4 4.0T, eight-speed Tiptronic. Manifold starts at supplier-listed 2012.
        $a8_models = array( array( 'A8 / A8 L', '~^A8(?: L)?(?:\\b|$)~i' ), array( 'S8', '~^S8(?:\\b|$)~i' ) );
        foreach ( $a8_models as $model ) {
            $base_1218 = array( 'year_start'=>2012, 'year_end'=>2018, 'make'=>'Audi', 'model_pattern'=>$model[1], 'engine_pattern'=>$v8_4t, 'transmission_pattern'=>$auto8 );
            $base_1318 = array( 'year_start'=>2013, 'year_end'=>2018, 'make'=>'Audi', 'model_pattern'=>$model[1], 'engine_pattern'=>$v8_4t, 'transmission_pattern'=>$auto8 );
            $add( $base_1218 + array( 'groups'=>array('manifold4t'), 'label'=>'Audi '.$model[0].' D4 4.0T intake manifold' ) );
            $add( $base_1318 + array( 'groups'=>array('combo4t','zf8'), 'label'=>'Audi '.$model[0].' D4 4.0T ECU/TCU and ZF8' ) );
            $add( $base_1218 + array( 'groups'=>array('mapsensor4t'), 'label'=>'Audi '.$model[0].' MED17.1.1 4.0T sensor application', 'status'=>'conditional' ) );
        }

        // RS7: first-generation C7/C7.5 4.0T, eight-speed Tiptronic.
        $rs7 = array( 'year_start'=>2014, 'year_end'=>2018, 'make'=>'Audi', 'model_pattern'=>'~^RS ?7(?:\\b|$)~i', 'engine_pattern'=>$v8_4t, 'transmission_pattern'=>$auto8 );
        $add( $rs7 + array( 'groups'=>array('combo4t','zf8','manifold4t'), 'label'=>'Audi RS7 C7/C7.5 4.0T ZF8' ) );
        $add( $rs7 + array( 'groups'=>array('mapsensor4t'), 'label'=>'Audi RS7 MED17.1.1 4.0T sensor application', 'status'=>'conditional' ) );

        // U.S.-market SQ7 4.0T. Supplier names SQ7 but does not publish years, so rows remain conditional.
        $add( array(
            'year_start'=>2020,
            'year_end'=>2025,
            'make'=>'Audi',
            'model_pattern'=>'~^SQ7(?:\\b|$)~i',
            'engine_pattern'=>$v8_4t,
            'transmission_pattern'=>$auto8,
            'groups'=>array('sq7'),
            'label'=>'Audi SQ7 4.0T ECU tune — verify ECU software box code',
            'status'=>'conditional',
        ) );

        return $profiles;
    }

    private function group_products( string $group ): array {
        $groups = array(
            'combo4t' => array( 'LEI-SW-001' ),
            'zf8' => array( 'LEI-TCU-003' ),
            'dl501' => array( 'LEI-TCU-004' ),
            'sq7' => array( 'LEI-ECU-005' ),
            'manifold4t' => array( 'LEI-MAN-006' ),
            'mapsensor4t' => array( 'LEI-SEN-007' ),
        );
        return $groups[ $group ] ?? array();
    }

    private function sync_product_scopes(): void {
        $scopes = array(
            'LEI-SW-001' => array( 'vehicle_specific', '2013-2018 Audi C7/C7.5/D4 EA824 4.0T applications.', 'high', 'Leistune publishes the chassis, engine and year range.' ),
            'LEI-TCU-003' => array( 'vehicle_specific', 'Audi EA824 4.0T applications using the ZF eight-speed automatic.', 'high', 'Transmission-specific calibration; exact vehicle and TCU box code must match.' ),
            'LEI-TCU-004' => array( 'vehicle_specific', '2013-2018 Audi S6/S7 4.0T with DL501 seven-speed S tronic.', 'high', 'Leistune explicitly names S6/S7 and DL501.' ),
            'LEI-ECU-005' => array( 'engine_specific', 'Audi SQ7 4.0T; ECU software box-code verification required.', 'medium', 'Supplier names SQ7 but does not publish a year range.' ),
            'LEI-MAN-006' => array( 'engine_specific', 'Supplier-listed Audi EA824 4.0T applications only; off-road/competition use.', 'high', 'Leistune publishes explicit models and years.' ),
            'LEI-SEN-007' => array( 'engine_specific', 'Audi Bosch MED17.1.1 4.0T ECU with compatible four-pin MAP/IAT connection.', 'medium', 'Custom ECU scaling is mandatory and EPA data cannot identify the ECU box code.' ),
            'LEI-SVC-008' => array( 'needs_review', 'Module-specific VW/Audi ECU/TCU immobilizer service.', 'high', 'Compatibility is determined by ECU/TCU module family, not Year/Make/Model alone.' ),
        );
        foreach ( $scopes as $sku => $scope ) {
            $product_id = function_exists( 'wc_get_product_id_by_sku' ) ? absint( wc_get_product_id_by_sku( $sku ) ) : 0;
            if ( ! $product_id ) {
                continue;
            }
            update_post_meta( $product_id, '_echo_fitment_type', $scope[0] );
            update_post_meta( $product_id, '_echo_fitment_raw', $scope[1] );
            update_post_meta( $product_id, '_echo_fitment_confidence', $scope[2] );
            update_post_meta( $product_id, '_echo_fitment_reason', $scope[3] );
        }
    }

    private function pattern_matches( string $pattern, string $value, bool $blank_matches = true ): bool {
        if ( '' === $pattern ) {
            return $blank_matches;
        }
        return 1 === @preg_match( $pattern, $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }

    private function vehicle_matches_profile( array $vehicle, array $profile ): bool {
        if ( ! $this->pattern_matches( $profile['transmission_pattern'] ?? '', (string) ( $vehicle['transmission'] ?? '' ) ) ) {
            return false;
        }
        if ( ! $this->pattern_matches( $profile['engine_pattern'] ?? '', (string) ( $vehicle['engine'] ?? '' ) ) ) {
            return false;
        }
        if ( ! $this->pattern_matches( $profile['fuel_pattern'] ?? '', (string) ( $vehicle['fuel_type'] ?? '' ) ) ) {
            return false;
        }
        if ( ! $this->pattern_matches( $profile['drive_pattern'] ?? '', (string) ( $vehicle['drivetrain'] ?? '' ) ) ) {
            return false;
        }
        $combined = implode( ' | ', array( $vehicle['model'] ?? '', $vehicle['engine'] ?? '', $vehicle['transmission'] ?? '', $vehicle['drivetrain'] ?? '', $vehicle['fuel_type'] ?? '', $vehicle['option_label'] ?? '' ) );
        if ( $this->pattern_matches( $profile['exclude_pattern'] ?? '', $combined, false ) ) {
            return false;
        }
        return true;
    }

    private function upsert_fitment( int $product_id, array $vehicle, string $status, string $profile_notes ): bool {
        global $wpdb;
        $table = Echo_Motorworks_DB::fitment_table();
        $vehicle_id = absint( $vehicle['id'] ?? 0 );
        $source_vehicle_id = sanitize_text_field( $vehicle['source_vehicle_id'] ?? '' );
        if ( ! $vehicle_id || '' === $source_vehicle_id ) {
            return false;
        }
        $status = in_array( $status, array( 'confirmed', 'conditional' ), true ) ? $status : 'conditional';
        $source_key = hash( 'sha256', implode( '|', array( $product_id, $vehicle_id, 'epa', $source_vehicle_id, self::SOURCE ) ) );
        $now = current_time( 'mysql', true );
        $data = array(
            'product_id' => $product_id,
            'vehicle_id' => $vehicle_id,
            'year_start' => absint( $vehicle['year'] ?? 0 ),
            'year_end' => absint( $vehicle['year'] ?? 0 ),
            'make' => sanitize_text_field( $vehicle['make'] ?? '' ),
            'model' => sanitize_text_field( $vehicle['model'] ?? '' ),
            'submodel' => sanitize_text_field( $vehicle['submodel'] ?? '' ),
            'generation' => sanitize_text_field( $vehicle['generation'] ?? '' ),
            'chassis' => sanitize_text_field( $vehicle['chassis'] ?? '' ),
            'engine' => sanitize_text_field( $vehicle['engine'] ?? '' ),
            'engine_code' => sanitize_text_field( $vehicle['engine_code'] ?? '' ),
            'transmission' => sanitize_text_field( $vehicle['transmission'] ?? '' ),
            'drivetrain' => sanitize_text_field( $vehicle['drivetrain'] ?? '' ),
            'body_style' => sanitize_text_field( $vehicle['body_style'] ?? '' ),
            'normalized_make' => Echo_Motorworks_DB::normalize( (string) ( $vehicle['make'] ?? '' ) ),
            'normalized_model' => Echo_Motorworks_DB::normalize( (string) ( $vehicle['model'] ?? '' ) ),
            'normalized_engine' => Echo_Motorworks_DB::normalize( (string) ( $vehicle['engine'] ?? '' ) ),
            'normalized_submodel' => Echo_Motorworks_DB::normalize( (string) ( $vehicle['submodel'] ?? '' ) ),
            'normalized_transmission' => Echo_Motorworks_DB::normalize( (string) ( $vehicle['transmission'] ?? '' ) ),
            'normalized_drivetrain' => Echo_Motorworks_DB::normalize( (string) ( $vehicle['drivetrain'] ?? '' ) ),
            'fitment_status' => $status,
            'fitment_notes' => sanitize_textarea_field( 'Leistune exact EPA fitment. ' . $profile_notes . '. Confirm ECU/TCU software box codes and hardware on modified or swapped vehicles.' ),
            'supplier' => 'Leistune',
            'source' => self::SOURCE,
            'source_key' => $source_key,
            'updated_at' => $now,
        );
        $existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_key = %s", $source_key ) );
        if ( $existing ) {
            return false !== $wpdb->update( $table, $data, array( 'id' => $existing ) );
        }
        $data['created_at'] = $now;
        return (bool) $wpdb->insert( $table, $data );
    }
}
