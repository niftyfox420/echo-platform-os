<?php

defined( 'ABSPATH' ) || exit;

/**
 * Guarded catalog duplicate cleanup for Echo Motorworks.
 *
 * Workflow: scan -> preview -> explicitly select duplicate records -> backup ->
 * merge/archive or merge/trash. No cleanup is performed during activation or
 * page load.
 */
final class Echo_Motorworks_Catalog_Cleanup {
    private const HISTORY_OPTION = 'echo_catalog_cleanup_history_v1';
    private const SOURCE_META_KEYS = array(
        '_echo_source_url', '_echo_product_url', '_echo_supplier_url',
        'source_url', '_source_url', '_product_url',
    );
    private const COPY_META_KEYS = array(
        '_thumbnail_id', '_regular_price', '_sale_price', '_price', '_stock_status',
        '_manage_stock', '_stock', '_backorders', '_echo_supplier', '_echo_fitment_type',
        '_echo_fitment_raw', '_echo_fitment_confidence', '_echo_fitment_reason',
        '_echo_fitment_review_required', '_echo_source_url', '_echo_product_url',
        '_echo_supplier_url', 'source_url', '_source_url', '_product_url',
    );

    public function __construct() {
        add_action( 'admin_post_echo_catalog_cleanup_execute', array( $this, 'execute' ) );
        add_action( 'admin_post_echo_catalog_cleanup_undo', array( $this, 'undo' ) );
        add_action( 'admin_post_echo_catalog_cleanup_download_backup', array( $this, 'download_backup' ) );
    }

    public function suppliers(): array {
        global $wpdb;
        $values = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_echo_supplier' AND meta_value <> '' ORDER BY meta_value"
        );
        $out = array();
        foreach ( $values ?: array() as $value ) {
            $value = trim( (string) $value );
            if ( '' !== $value ) {
                $out[ sanitize_title( $value ) ] = $value;
            }
        }
        return $out;
    }

    public function scan( string $supplier ): array {
        $supplier = trim( $supplier );
        if ( '' === $supplier ) {
            return array();
        }

        $ids = get_posts(
            array(
                'post_type'      => 'product',
                'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'meta_query'     => array(
                    array(
                        'key'     => '_echo_supplier',
                        'value'   => $supplier,
                        'compare' => '=',
                    ),
                ),
            )
        );

        $products = array();
        foreach ( $ids as $id ) {
            $products[ $id ] = $this->product_snapshot( (int) $id, false );
        }

        $indexes = array(
            'sku'    => array(),
            'source' => array(),
            'name'   => array(),
        );

        foreach ( $products as $id => $product ) {
            if ( '' !== $product['sku'] ) {
                $indexes['sku'][ strtolower( $product['sku'] ) ][] = $id;
            }
            if ( '' !== $product['source_url'] ) {
                $indexes['source'][ $this->normalize_url( $product['source_url'] ) ][] = $id;
            }
            $normalized_name = $this->normalize_name( $product['name'] );
            if ( '' !== $normalized_name ) {
                $indexes['name'][ $normalized_name ][] = $id;
            }
        }

        $raw_groups = array();
        foreach ( $indexes as $type => $groups ) {
            foreach ( $groups as $key => $group_ids ) {
                $group_ids = array_values( array_unique( array_map( 'absint', $group_ids ) ) );
                if ( count( $group_ids ) < 2 ) {
                    continue;
                }
                sort( $group_ids );
                $signature = implode( '-', $group_ids );
                if ( ! isset( $raw_groups[ $signature ] ) ) {
                    $raw_groups[ $signature ] = array(
                        'ids'     => $group_ids,
                        'reasons' => array(),
                    );
                }
                $labels = array(
                    'sku'    => 'Duplicate SKU',
                    'source' => 'Same supplier/source URL',
                    'name'   => 'Same normalized product name',
                );
                $raw_groups[ $signature ]['reasons'][] = $labels[ $type ];
            }
        }

        // Merge overlapping duplicate groups so one product does not appear in
        // multiple independent destructive actions.
        $merged = array();
        foreach ( $raw_groups as $group ) {
            $matches = array();
            foreach ( $merged as $index => $existing ) {
                if ( array_intersect( $existing['ids'], $group['ids'] ) ) {
                    $matches[] = $index;
                }
            }
            if ( ! $matches ) {
                $merged[] = $group;
                continue;
            }
            $target = array_shift( $matches );
            $merged[ $target ]['ids'] = array_values( array_unique( array_merge( $merged[ $target ]['ids'], $group['ids'] ) ) );
            $merged[ $target ]['reasons'] = array_values( array_unique( array_merge( $merged[ $target ]['reasons'], $group['reasons'] ) ) );
            foreach ( array_reverse( $matches ) as $index ) {
                $merged[ $target ]['ids'] = array_values( array_unique( array_merge( $merged[ $target ]['ids'], $merged[ $index ]['ids'] ) ) );
                $merged[ $target ]['reasons'] = array_values( array_unique( array_merge( $merged[ $target ]['reasons'], $merged[ $index ]['reasons'] ) ) );
                array_splice( $merged, $index, 1 );
            }
        }

        $result = array();
        foreach ( $merged as $group ) {
            $rows = array();
            foreach ( $group['ids'] as $id ) {
                if ( isset( $products[ $id ] ) ) {
                    $rows[] = $products[ $id ];
                }
            }
            if ( count( $rows ) < 2 ) {
                continue;
            }
            usort( $rows, static function ( array $a, array $b ): int {
                if ( $a['quality_score'] === $b['quality_score'] ) {
                    return $a['id'] <=> $b['id'];
                }
                return $b['quality_score'] <=> $a['quality_score'];
            } );
            $keeper = $rows[0];
            $group_id = substr( md5( $supplier . '|' . implode( ',', array_column( $rows, 'id' ) ) ), 0, 12 );
            $result[] = array(
                'group_id'           => $group_id,
                'reasons'            => $group['reasons'],
                'products'           => $rows,
                'recommended_keeper' => $keeper['id'],
            );
        }

        usort( $result, static function ( array $a, array $b ): int {
            return min( array_column( $a['products'], 'id' ) ) <=> min( array_column( $b['products'], 'id' ) );
        } );
        return $result;
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage the catalog.', 'echo-motorworks-core' ) );
        }

        $suppliers = $this->suppliers();
        $supplier = sanitize_text_field( wp_unslash( $_GET['cleanup_supplier'] ?? '' ) );
        if ( '' === $supplier && isset( $suppliers['flf-racing-supply'] ) ) {
            $supplier = $suppliers['flf-racing-supply'];
        }
        $groups = $supplier ? $this->scan( $supplier ) : array();
        $history = array_reverse( get_option( self::HISTORY_OPTION, array() ) );
        ?>
        <style>
        .echo-cleanup-hero{background:#111827;color:#fff;border-radius:10px;padding:20px 22px;margin-bottom:18px}.echo-cleanup-hero h2{color:#fff;margin:0 0 6px}.echo-cleanup-hero p{margin:0;color:#d1d5db}.echo-cleanup-summary{display:flex;gap:12px;flex-wrap:wrap;margin:14px 0}.echo-cleanup-stat{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 15px;min-width:150px}.echo-cleanup-stat strong{display:block;font-size:24px}.echo-dupe-group{background:#fff;border:1px solid #dcdcde;border-left:5px solid #d63638;border-radius:8px;margin:16px 0;overflow:hidden}.echo-dupe-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:14px 16px;background:#f6f7f7}.echo-dupe-reasons{color:#50575e}.echo-dupe-table{width:100%;border-collapse:collapse}.echo-dupe-table th,.echo-dupe-table td{padding:10px;border-top:1px solid #e2e4e7;text-align:left;vertical-align:top}.echo-quality{display:inline-block;border-radius:999px;padding:3px 8px;background:#e7f5ea;color:#0a5c2d;font-weight:700}.echo-recommended{color:#0a5c2d;font-weight:700}.echo-cleanup-controls{display:grid;grid-template-columns:minmax(220px,1fr) minmax(250px,1fr);gap:12px;padding:14px 16px;background:#fafafa;border-top:1px solid #e2e4e7}.echo-cleanup-controls select{width:100%}.echo-danger-note{background:#fff8e5;border-left:4px solid #dba617;padding:12px 14px;margin:14px 0}.echo-history-row code{font-size:11px}@media(max-width:782px){.echo-cleanup-controls{grid-template-columns:1fr}.echo-dupe-table{display:block;overflow:auto}}
        </style>
        <div class="echo-cleanup-hero">
            <h2>Catalog Cleanup</h2>
            <p>Find duplicate supplier products, choose the record to keep, preview the action, create a backup automatically, and merge/archive or merge/trash only the records you approve.</p>
        </div>

        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="echo-toolbar">
            <input type="hidden" name="page" value="echo-catalog-manager"><input type="hidden" name="tab" value="cleanup">
            <label>Supplier<select name="cleanup_supplier">
                <option value="">Choose supplier</option>
                <?php foreach ( $suppliers as $name ) : ?><option value="<?php echo esc_attr( $name ); ?>" <?php selected( $supplier, $name ); ?>><?php echo esc_html( $name ); ?></option><?php endforeach; ?>
            </select></label>
            <button class="button button-primary">Scan catalog</button>
        </form>

        <?php if ( $supplier ) : ?>
            <div class="echo-cleanup-summary">
                <div class="echo-cleanup-stat"><strong><?php echo esc_html( count( $groups ) ); ?></strong><span>duplicate groups</span></div>
                <div class="echo-cleanup-stat"><strong><?php echo esc_html( array_sum( array_map( static fn( $group ) => max( 0, count( $group['products'] ) - 1 ), $groups ) ) ); ?></strong><span>possible duplicate records</span></div>
                <div class="echo-cleanup-stat"><strong><?php echo esc_html( $supplier ); ?></strong><span>selected supplier</span></div>
            </div>
            <div class="echo-danger-note"><strong>Safe default:</strong> use <em>Merge + Archive</em>. It preserves the duplicate as a draft and can be undone from Cleanup History. Use Trash only after reviewing the archived result.</div>

            <?php if ( ! $groups ) : ?>
                <div class="echo-empty">No duplicate groups were found using SKU, supplier URL, or normalized product name.</div>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="echo_catalog_cleanup_execute">
                    <input type="hidden" name="supplier" value="<?php echo esc_attr( $supplier ); ?>">
                    <?php wp_nonce_field( 'echo_catalog_cleanup_execute' ); ?>
                    <?php foreach ( $groups as $group ) : $gid = $group['group_id']; ?>
                        <section class="echo-dupe-group">
                            <div class="echo-dupe-head"><div><strong>Duplicate group <?php echo esc_html( $gid ); ?></strong><div class="echo-dupe-reasons"><?php echo esc_html( implode( ' · ', $group['reasons'] ) ); ?></div></div><label><input type="checkbox" name="selected_groups[]" value="<?php echo esc_attr( $gid ); ?>"> Clean this group</label></div>
                            <table class="echo-dupe-table"><thead><tr><th>Keep</th><th>Remove</th><th>Product</th><th>Catalog data</th><th>Quality</th></tr></thead><tbody>
                            <?php foreach ( $group['products'] as $product ) : ?>
                                <tr>
                                    <td><input type="radio" name="keeper[<?php echo esc_attr( $gid ); ?>]" value="<?php echo esc_attr( $product['id'] ); ?>" <?php checked( $group['recommended_keeper'], $product['id'] ); ?>> <?php if ( $group['recommended_keeper'] === $product['id'] ) : ?><span class="echo-recommended">Recommended</span><?php endif; ?></td>
                                    <td><input type="checkbox" name="duplicates[<?php echo esc_attr( $gid ); ?>][]" value="<?php echo esc_attr( $product['id'] ); ?>" <?php checked( $group['recommended_keeper'] !== $product['id'] ); ?>></td>
                                    <td><strong><a href="<?php echo esc_url( get_edit_post_link( $product['id'] ) ); ?>"><?php echo esc_html( $product['name'] ); ?></a></strong><br>ID <?php echo esc_html( $product['id'] ); ?> · <?php echo esc_html( $product['status'] ); ?><br>SKU: <code><?php echo esc_html( $product['sku'] ?: 'missing' ); ?></code></td>
                                    <td><?php echo $product['has_image'] ? 'Image ✓' : 'Image missing'; ?> · <?php echo $product['has_description'] ? 'Description ✓' : 'Description missing'; ?><br>Price: <?php echo esc_html( $product['price'] ?: 'missing' ); ?> · Fitment rows: <?php echo esc_html( $product['fitment_rows'] ); ?><br><small><?php echo esc_html( $product['source_url'] ); ?></small></td>
                                    <td><span class="echo-quality"><?php echo esc_html( $product['quality_score'] ); ?>/10</span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody></table>
                            <div class="echo-cleanup-controls">
                                <label>Action<select name="operation[<?php echo esc_attr( $gid ); ?>]"><option value="merge_archive">Merge best data + Archive duplicates</option><option value="merge_trash">Merge best data + Move duplicates to Trash</option><option value="archive_only">Archive duplicates without merging</option><option value="trash_only">Move duplicates to Trash without merging</option></select></label>
                                <label>Confirmation<input type="text" name="confirmation[<?php echo esc_attr( $gid ); ?>]" placeholder="Type CLEAN <?php echo esc_attr( $gid ); ?>" style="width:100%"></label>
                            </div>
                            <?php foreach ( $group['products'] as $product ) : ?><input type="hidden" name="group_products[<?php echo esc_attr( $gid ); ?>][]" value="<?php echo esc_attr( $product['id'] ); ?>"><?php endforeach; ?>
                        </section>
                    <?php endforeach; ?>
                    <p><button class="button button-primary button-large">Run approved cleanup</button></p>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <div class="echo-inspector">
            <h2>Cleanup History</h2>
            <?php if ( ! $history ) : ?><div class="echo-empty">No cleanup actions have been run.</div><?php else : ?>
                <table class="echo-table"><thead><tr><th>Time</th><th>Supplier</th><th>Action</th><th>Products</th><th>Backup</th><th>Undo</th></tr></thead><tbody>
                <?php foreach ( array_slice( $history, 0, 20 ) as $entry ) : ?>
                    <tr class="echo-history-row"><td><?php echo esc_html( $entry['created_at'] ); ?></td><td><?php echo esc_html( $entry['supplier'] ); ?></td><td><?php echo esc_html( $entry['operation'] ); ?></td><td>Kept #<?php echo esc_html( $entry['keeper_id'] ); ?><br>Changed: <?php echo esc_html( implode( ', ', $entry['duplicate_ids'] ) ); ?></td><td><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=echo_catalog_cleanup_download_backup&cleanup_id=' . rawurlencode( $entry['id'] ) ), 'echo_catalog_cleanup_download_backup_' . $entry['id'] ) ); ?>">Download JSON</a></td><td><?php if ( empty( $entry['undone_at'] ) ) : ?><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=echo_catalog_cleanup_undo&cleanup_id=' . rawurlencode( $entry['id'] ) ), 'echo_catalog_cleanup_undo_' . $entry['id'] ) ); ?>" onclick="return confirm('Undo this cleanup and restore the archived products and moved fitment rows?');">Undo</a><?php else : ?>Undone <?php echo esc_html( $entry['undone_at'] ); ?><?php endif; ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function execute(): void {
        $this->guard( 'echo_catalog_cleanup_execute' );
        $supplier = sanitize_text_field( wp_unslash( $_POST['supplier'] ?? '' ) );
        $selected = array_map( 'sanitize_key', (array) ( $_POST['selected_groups'] ?? array() ) );
        $keepers = (array) ( $_POST['keeper'] ?? array() );
        $duplicates = (array) ( $_POST['duplicates'] ?? array() );
        $operations = (array) ( $_POST['operation'] ?? array() );
        $confirmations = (array) ( $_POST['confirmation'] ?? array() );
        $group_products = (array) ( $_POST['group_products'] ?? array() );

        if ( '' === $supplier || ! $selected ) {
            $this->redirect( $supplier, 'Choose at least one duplicate group.', 'error' );
        }

        $current_scan = $this->scan( $supplier );
        $valid_groups = array();
        foreach ( $current_scan as $group ) {
            $valid_groups[ $group['group_id'] ] = array_map( 'absint', array_column( $group['products'], 'id' ) );
        }

        $completed = 0;
        foreach ( $selected as $group_id ) {
            if ( ! isset( $valid_groups[ $group_id ] ) ) {
                continue;
            }
            $submitted_ids = array_values( array_unique( array_map( 'absint', (array) ( $group_products[ $group_id ] ?? array() ) ) ) );
            sort( $submitted_ids );
            $valid_ids = $valid_groups[ $group_id ];
            sort( $valid_ids );
            if ( $submitted_ids !== $valid_ids ) {
                continue; // Catalog changed since preview.
            }
            $keeper_id = absint( $keepers[ $group_id ] ?? 0 );
            $duplicate_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $duplicates[ $group_id ] ?? array() ) ) ) ) );
            $duplicate_ids = array_values( array_diff( $duplicate_ids, array( $keeper_id ) ) );
            $operation = sanitize_key( $operations[ $group_id ] ?? 'merge_archive' );
            $confirmation = trim( sanitize_text_field( wp_unslash( $confirmations[ $group_id ] ?? '' ) ) );
            if ( ! in_array( $keeper_id, $valid_ids, true ) || ! $duplicate_ids || $confirmation !== 'CLEAN ' . $group_id ) {
                continue;
            }
            if ( array_diff( $duplicate_ids, $valid_ids ) ) {
                continue;
            }
            if ( ! in_array( $operation, array( 'merge_archive', 'merge_trash', 'archive_only', 'trash_only' ), true ) ) {
                continue;
            }

            $entry = $this->perform_cleanup( $supplier, $group_id, $keeper_id, $duplicate_ids, $operation );
            if ( $entry ) {
                $history = get_option( self::HISTORY_OPTION, array() );
                $history[] = $entry;
                if ( count( $history ) > 50 ) {
                    $history = array_slice( $history, -50 );
                }
                update_option( self::HISTORY_OPTION, $history, false );
                $completed++;
            }
        }

        $message = $completed ? sprintf( 'Completed %d cleanup group(s). Backups were saved automatically.', $completed ) : 'No cleanup groups were executed. Check the selections and confirmation text.';
        $this->redirect( $supplier, $message, $completed ? 'success' : 'error' );
    }

    private function perform_cleanup( string $supplier, string $group_id, int $keeper_id, array $duplicate_ids, string $operation ): array {
        global $wpdb;

        $backup = array(
            'schema'        => 1,
            'supplier'      => $supplier,
            'group_id'      => $group_id,
            'operation'     => $operation,
            'keeper_before' => $this->product_snapshot( $keeper_id, true ),
            'duplicates'    => array(),
            'fitment_moves' => array(),
            'note_moves'    => array(),
        );
        foreach ( $duplicate_ids as $id ) {
            $backup['duplicates'][ $id ] = $this->product_snapshot( $id, true );
        }

        if ( str_starts_with( $operation, 'merge_' ) ) {
            foreach ( $duplicate_ids as $duplicate_id ) {
                $this->merge_product_data( $keeper_id, $duplicate_id );
            }
        }

        $fitment_table = Echo_Motorworks_DB::fitment_table();
        $notes_table = Echo_Motorworks_DB::notes_table();
        if ( $this->table_exists( $fitment_table ) ) {
            foreach ( $duplicate_ids as $duplicate_id ) {
                $row_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$fitment_table} WHERE product_id = %d", $duplicate_id ) ) ?: array() );
                if ( $row_ids ) {
                    $backup['fitment_moves'][ $duplicate_id ] = $row_ids;
                    $wpdb->update( $fitment_table, array( 'product_id' => $keeper_id, 'updated_at' => current_time( 'mysql', true ) ), array( 'product_id' => $duplicate_id ), array( '%d', '%s' ), array( '%d' ) );
                }
            }
        }
        if ( $this->table_exists( $notes_table ) ) {
            foreach ( $duplicate_ids as $duplicate_id ) {
                $row_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$notes_table} WHERE product_id = %d", $duplicate_id ) ) ?: array() );
                if ( $row_ids ) {
                    $backup['note_moves'][ $duplicate_id ] = $row_ids;
                    $wpdb->update( $notes_table, array( 'product_id' => $keeper_id ), array( 'product_id' => $duplicate_id ), array( '%d' ), array( '%d' ) );
                }
            }
        }

        foreach ( $duplicate_ids as $duplicate_id ) {
            update_post_meta( $duplicate_id, '_echo_cleanup_merged_into', $keeper_id );
            update_post_meta( $duplicate_id, '_echo_cleanup_group', $group_id );
            if ( str_contains( $operation, 'trash' ) ) {
                wp_trash_post( $duplicate_id );
            } else {
                wp_update_post( array( 'ID' => $duplicate_id, 'post_status' => 'draft' ) );
            }
        }

        $id = 'cleanup_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 6, false, false );
        $backup_path = $this->save_backup( $id, $backup );
        Echo_Motorworks_DB::log( 'info', 'catalog_cleanup', 'Catalog duplicate cleanup completed.', array( 'cleanup_id' => $id, 'supplier' => $supplier, 'keeper_id' => $keeper_id, 'duplicate_ids' => $duplicate_ids, 'operation' => $operation ) );

        return array(
            'id'            => $id,
            'supplier'      => $supplier,
            'group_id'      => $group_id,
            'operation'     => $operation,
            'keeper_id'     => $keeper_id,
            'duplicate_ids' => $duplicate_ids,
            'backup_path'   => $backup_path,
            'created_at'    => current_time( 'mysql' ),
            'undone_at'     => '',
        );
    }

    private function merge_product_data( int $keeper_id, int $duplicate_id ): void {
        $keeper = get_post( $keeper_id );
        $duplicate = get_post( $duplicate_id );
        if ( ! $keeper || ! $duplicate ) {
            return;
        }

        $update = array( 'ID' => $keeper_id );
        if ( '' === trim( (string) $keeper->post_content ) && '' !== trim( (string) $duplicate->post_content ) ) {
            $update['post_content'] = $duplicate->post_content;
        }
        if ( '' === trim( (string) $keeper->post_excerpt ) && '' !== trim( (string) $duplicate->post_excerpt ) ) {
            $update['post_excerpt'] = $duplicate->post_excerpt;
        }
        if ( count( $update ) > 1 ) {
            wp_update_post( wp_slash( $update ) );
        }

        foreach ( self::COPY_META_KEYS as $key ) {
            $keeper_value = get_post_meta( $keeper_id, $key, true );
            $duplicate_value = get_post_meta( $duplicate_id, $key, true );
            if ( ( '' === $keeper_value || null === $keeper_value ) && '' !== $duplicate_value && null !== $duplicate_value ) {
                update_post_meta( $keeper_id, $key, $duplicate_value );
            }
        }

        foreach ( get_object_taxonomies( 'product' ) as $taxonomy ) {
            $keeper_terms = wp_get_object_terms( $keeper_id, $taxonomy, array( 'fields' => 'ids' ) );
            $duplicate_terms = wp_get_object_terms( $duplicate_id, $taxonomy, array( 'fields' => 'ids' ) );
            if ( is_wp_error( $keeper_terms ) || is_wp_error( $duplicate_terms ) ) {
                continue;
            }
            $merged = array_values( array_unique( array_merge( array_map( 'absint', $keeper_terms ), array_map( 'absint', $duplicate_terms ) ) ) );
            if ( $merged ) {
                wp_set_object_terms( $keeper_id, $merged, $taxonomy, false );
            }
        }
    }

    public function undo(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Permission denied.' );
        }
        $cleanup_id = sanitize_text_field( wp_unslash( $_GET['cleanup_id'] ?? '' ) );
        check_admin_referer( 'echo_catalog_cleanup_undo_' . $cleanup_id );
        $history = get_option( self::HISTORY_OPTION, array() );
        $index = null;
        foreach ( $history as $i => $entry ) {
            if ( $entry['id'] === $cleanup_id ) { $index = $i; break; }
        }
        if ( null === $index || ! empty( $history[ $index ]['undone_at'] ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=echo-catalog-manager&tab=cleanup&cleanup_notice=' . rawurlencode( 'Cleanup entry was not found or was already undone.' ) . '&cleanup_notice_type=error' ) );
            exit;
        }
        $backup = $this->read_backup( $history[ $index ]['backup_path'] );
        if ( ! $backup ) {
            wp_safe_redirect( admin_url( 'admin.php?page=echo-catalog-manager&tab=cleanup&cleanup_notice=' . rawurlencode( 'Backup file could not be read.' ) . '&cleanup_notice_type=error' ) );
            exit;
        }

        $this->restore_product_snapshot( $backup['keeper_before'] );
        foreach ( $backup['duplicates'] as $snapshot ) {
            $this->restore_product_snapshot( $snapshot );
        }

        global $wpdb;
        $fitment_table = Echo_Motorworks_DB::fitment_table();
        foreach ( $backup['fitment_moves'] as $duplicate_id => $row_ids ) {
            if ( $row_ids && $this->table_exists( $fitment_table ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $row_ids ), '%d' ) );
                $sql = $wpdb->prepare( "UPDATE {$fitment_table} SET product_id = %d, updated_at = %s WHERE id IN ({$placeholders})", array_merge( array( absint( $duplicate_id ), current_time( 'mysql', true ) ), array_map( 'absint', $row_ids ) ) );
                $wpdb->query( $sql );
            }
        }
        $notes_table = Echo_Motorworks_DB::notes_table();
        foreach ( $backup['note_moves'] as $duplicate_id => $row_ids ) {
            if ( $row_ids && $this->table_exists( $notes_table ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $row_ids ), '%d' ) );
                $sql = $wpdb->prepare( "UPDATE {$notes_table} SET product_id = %d WHERE id IN ({$placeholders})", array_merge( array( absint( $duplicate_id ) ), array_map( 'absint', $row_ids ) ) );
                $wpdb->query( $sql );
            }
        }

        $history[ $index ]['undone_at'] = current_time( 'mysql' );
        update_option( self::HISTORY_OPTION, $history, false );
        Echo_Motorworks_DB::log( 'info', 'catalog_cleanup', 'Catalog cleanup was undone.', array( 'cleanup_id' => $cleanup_id ) );
        wp_safe_redirect( admin_url( 'admin.php?page=echo-catalog-manager&tab=cleanup&cleanup_supplier=' . rawurlencode( $history[ $index ]['supplier'] ) . '&cleanup_notice=' . rawurlencode( 'Cleanup was undone.' ) . '&cleanup_notice_type=success' ) );
        exit;
    }

    public function download_backup(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Permission denied.' );
        }
        $cleanup_id = sanitize_text_field( wp_unslash( $_GET['cleanup_id'] ?? '' ) );
        check_admin_referer( 'echo_catalog_cleanup_download_backup_' . $cleanup_id );
        foreach ( get_option( self::HISTORY_OPTION, array() ) as $entry ) {
            if ( $entry['id'] === $cleanup_id && is_readable( $entry['backup_path'] ) ) {
                nocache_headers();
                header( 'Content-Type: application/json; charset=utf-8' );
                header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $cleanup_id . '.json' ) . '"' );
                readfile( $entry['backup_path'] );
                exit;
            }
        }
        wp_die( 'Backup not found.' );
    }

    private function product_snapshot( int $product_id, bool $full ): array {
        global $wpdb;
        $post = get_post( $product_id );
        if ( ! $post ) {
            return array();
        }
        $sku = (string) get_post_meta( $product_id, '_sku', true );
        $source_url = '';
        foreach ( self::SOURCE_META_KEYS as $key ) {
            $candidate = trim( (string) get_post_meta( $product_id, $key, true ) );
            if ( '' !== $candidate ) { $source_url = $candidate; break; }
        }
        $fitment_rows = 0;
        $fitment_table = Echo_Motorworks_DB::fitment_table();
        if ( $this->table_exists( $fitment_table ) ) {
            $fitment_rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$fitment_table} WHERE product_id = %d", $product_id ) );
        }
        $has_image = has_post_thumbnail( $product_id );
        $has_description = '' !== trim( (string) $post->post_content );
        $price = (string) get_post_meta( $product_id, '_price', true );
        $quality = 0;
        $quality += 'publish' === $post->post_status ? 2 : 0;
        $quality += $has_image ? 2 : 0;
        $quality += $has_description ? 2 : 0;
        $quality += '' !== $price ? 1 : 0;
        $quality += '' !== $sku ? 1 : 0;
        $quality += $fitment_rows > 0 ? 2 : 0;

        $snapshot = array(
            'id'              => $product_id,
            'name'            => $post->post_title,
            'status'          => $post->post_status,
            'sku'             => $sku,
            'source_url'      => $source_url,
            'price'           => $price,
            'has_image'       => $has_image,
            'has_description' => $has_description,
            'fitment_rows'    => $fitment_rows,
            'quality_score'   => $quality,
        );
        if ( ! $full ) {
            return $snapshot;
        }

        $meta = array();
        foreach ( self::COPY_META_KEYS as $key ) {
            $exists = metadata_exists( 'post', $product_id, $key );
            $meta[ $key ] = array( 'exists' => $exists, 'value' => $exists ? get_post_meta( $product_id, $key, true ) : null );
        }
        $taxonomies = array();
        foreach ( get_object_taxonomies( 'product' ) as $taxonomy ) {
            $terms = wp_get_object_terms( $product_id, $taxonomy, array( 'fields' => 'ids' ) );
            $taxonomies[ $taxonomy ] = is_wp_error( $terms ) ? array() : array_map( 'absint', $terms );
        }
        $snapshot['post'] = array(
            'post_title'   => $post->post_title,
            'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            'post_status'  => $post->post_status,
            'post_name'    => $post->post_name,
            'post_parent'  => $post->post_parent,
            'menu_order'   => $post->menu_order,
        );
        $snapshot['meta'] = $meta;
        $snapshot['taxonomies'] = $taxonomies;
        return $snapshot;
    }

    private function restore_product_snapshot( array $snapshot ): void {
        if ( empty( $snapshot['id'] ) || empty( $snapshot['post'] ) ) {
            return;
        }
        $id = absint( $snapshot['id'] );
        if ( 'trash' === get_post_status( $id ) ) {
            wp_untrash_post( $id );
        }
        wp_update_post( wp_slash( array_merge( array( 'ID' => $id ), $snapshot['post'] ) ) );
        foreach ( $snapshot['meta'] as $key => $state ) {
            if ( $state['exists'] ) {
                update_post_meta( $id, $key, $state['value'] );
            } else {
                delete_post_meta( $id, $key );
            }
        }
        foreach ( $snapshot['taxonomies'] as $taxonomy => $term_ids ) {
            if ( taxonomy_exists( $taxonomy ) ) {
                wp_set_object_terms( $id, array_map( 'absint', $term_ids ), $taxonomy, false );
            }
        }
        delete_post_meta( $id, '_echo_cleanup_merged_into' );
        delete_post_meta( $id, '_echo_cleanup_group' );
    }

    private function save_backup( string $id, array $backup ): string {
        $upload = wp_upload_dir();
        $dir = trailingslashit( $upload['basedir'] ) . 'echo-catalog-cleanup/backups';
        wp_mkdir_p( $dir );
        $path = trailingslashit( $dir ) . sanitize_file_name( $id . '.json' );
        file_put_contents( $path, wp_json_encode( $backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
        return $path;
    }

    private function read_backup( string $path ): array {
        if ( ! is_readable( $path ) ) { return array(); }
        $data = json_decode( (string) file_get_contents( $path ), true );
        return is_array( $data ) ? $data : array();
    }

    private function normalize_name( string $name ): string {
        $name = strtolower( remove_accents( wp_strip_all_tags( $name ) ) );
        $name = preg_replace( '/\b(?:new|sale|updated)\b/', ' ', $name );
        $name = preg_replace( '/[^a-z0-9]+/', ' ', (string) $name );
        return trim( preg_replace( '/\s+/', ' ', (string) $name ) );
    }

    private function normalize_url( string $url ): string {
        $url = strtolower( trim( $url ) );
        $url = preg_replace( '#^https?://#', '', $url );
        $url = preg_replace( '#^www\.#', '', (string) $url );
        $url = strtok( (string) $url, '?' );
        return untrailingslashit( (string) $url );
    }

    private function table_exists( string $table ): bool {
        global $wpdb;
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }

    private function guard( string $nonce_action ): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Permission denied.' );
        }
        check_admin_referer( $nonce_action );
    }

    private function redirect( string $supplier, string $message, string $type ): void {
        wp_safe_redirect( add_query_arg( array(
            'page'                => 'echo-catalog-manager',
            'tab'                 => 'cleanup',
            'cleanup_supplier'    => $supplier,
            'cleanup_notice'      => $message,
            'cleanup_notice_type' => $type,
        ), admin_url( 'admin.php' ) ) );
        exit;
    }
}
