<?php

defined( 'ABSPATH' ) || exit;

/**
 * One-stop WooCommerce catalog control center.
 * Keeps the proven supplier builders and fitment engine, while adding a
 * generic supplier registry and safe CSV product pipeline for future brands.
 */
final class Echo_Motorworks_Catalog_Manager {
    private const OPTION = 'echo_catalog_manager_suppliers_v1';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'admin_menu' ), 55 );
        add_action( 'admin_menu', array( $this, 'hide_legacy_menus' ), 999 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_post_echo_catalog_add_supplier', array( $this, 'add_supplier' ) );
        add_action( 'admin_post_echo_catalog_import_products', array( $this, 'import_products' ) );
        add_action( 'admin_post_echo_catalog_export_template', array( $this, 'export_template' ) );
        add_action( 'admin_post_echo_platform_save_settings', array( $this, 'save_settings' ) );
        add_action( 'admin_post_echo_platform_request_status', array( $this, 'update_request_status' ) );
        add_action( 'init', array( $this, 'ensure_request_post_type' ), 30 );
        add_action( 'admin_notices', array( $this, 'notices' ) );
    }


    public function enqueue_admin_assets( string $hook ): void {
        if ( 'toplevel_page_echo-catalog-manager' !== $hook ) {
            return;
        }
        wp_enqueue_style(
            'echo-platform-admin',
            ECHO_MOTORWORKS_CORE_URL . 'assets/css/admin-platform.css',
            array(),
            ECHO_MOTORWORKS_CORE_VERSION
        );
    }

    public function admin_menu(): void {
        add_menu_page(
            'Echo Platform',
            'Echo Platform',
            'manage_woocommerce',
            'echo-catalog-manager',
            array( $this, 'page' ),
            'dashicons-admin-tools',
            56
        );
        add_submenu_page(
            'echo-catalog-manager',
            'Dashboard',
            'Dashboard',
            'manage_woocommerce',
            'echo-catalog-manager',
            array( $this, 'page' )
        );
    }

    /**
     * Keep every proven legacy screen available by direct URL, but remove the
     * duplicate submenu clutter. The unified manager is the only Echo entry.
     */
    public function hide_legacy_menus(): void {
        $parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'tools.php';
        $legacy = array(
            'echo-motorworks-catalog',
            'echo-motorworks-fitment',
            'echo-motorworks-settings',
            'echo-leistune-fitment',
            'echo-eldoc-fitment',
            'echo-flf-catalog',
            'echo-supplier-engine',
            'echo-mabotech',
            'echo-supplier-images',
            'echo-evilenergy',
            'echo-pds',
            'echo-supplier-brand-repair',
        );
        foreach ( $legacy as $slug ) {
            remove_submenu_page( $parent, $slug );
        }
        remove_menu_page( 'edit.php?post_type=em_part_request' );
    }

    public function page(): void {
        $tab = sanitize_key( $_GET['tab'] ?? 'dashboard' );
        $tabs = array(
            'dashboard' => array( 'label' => 'Mission Control', 'icon' => 'dashicons-dashboard', 'group' => 'home' ),
            'review' => array( 'label' => 'Review Center', 'icon' => 'dashicons-yes-alt', 'group' => 'home' ),
            'automation' => array( 'label' => 'Automation Rules', 'icon' => 'dashicons-controls-repeat', 'group' => 'home' ),
            'notifications' => array( 'label' => 'Notifications', 'icon' => 'dashicons-bell', 'group' => 'home' ),
            'activity' => array( 'label' => 'Activity', 'icon' => 'dashicons-list-view', 'group' => 'home' ),
            'smart' => array( 'label' => 'Smart Action Queue', 'icon' => 'dashicons-lightbulb', 'group' => 'home' ),
            'products' => array( 'label' => 'Products & Imports', 'icon' => 'dashicons-products', 'group' => 'catalog' ),
            'health' => array( 'label' => 'Health Scan', 'icon' => 'dashicons-heart', 'group' => 'catalog' ),
            'cleanup' => array( 'label' => 'Duplicate Finder', 'icon' => 'dashicons-trash', 'group' => 'catalog' ),
            'images' => array( 'label' => 'Image Intelligence', 'icon' => 'dashicons-format-image', 'group' => 'catalog' ),
            'suppliers' => array( 'label' => 'Supplier List', 'icon' => 'dashicons-groups', 'group' => 'suppliers' ),
            'intake' => array( 'label' => 'Supplier Intake', 'icon' => 'dashicons-upload', 'group' => 'suppliers' ),
            'sync' => array( 'label' => 'Sync Center', 'icon' => 'dashicons-update', 'group' => 'suppliers' ),
            'discovery' => array( 'label' => 'Discovery Center', 'icon' => 'dashicons-search', 'group' => 'suppliers' ),
            'api' => array( 'label' => 'API Connections', 'icon' => 'dashicons-rest-api', 'group' => 'suppliers' ),
            'mapping' => array( 'label' => 'Field Mapping', 'icon' => 'dashicons-randomize', 'group' => 'suppliers' ),
            'previews' => array( 'label' => 'Sync Preview', 'icon' => 'dashicons-visibility', 'group' => 'suppliers' ),
            'synchistory' => array( 'label' => 'Sync History', 'icon' => 'dashicons-backup', 'group' => 'suppliers' ),
            'jobs' => array( 'label' => 'Background Jobs', 'icon' => 'dashicons-backup', 'group' => 'tools' ),
            'fitment' => array( 'label' => 'Fitment', 'icon' => 'dashicons-admin-tools', 'group' => 'vehicles' ),
            'garage' => array( 'label' => 'Garage', 'icon' => 'dashicons-car', 'group' => 'vehicles' ),
            'diagnostics' => array( 'label' => 'Fitment Auditor', 'icon' => 'dashicons-search', 'group' => 'vehicles' ),
            'reports' => array( 'label' => 'Reports', 'icon' => 'dashicons-chart-bar', 'group' => 'business' ),
            'support' => array( 'label' => 'Support', 'icon' => 'dashicons-sos', 'group' => 'business' ),
            'modules' => array( 'label' => 'Module Manager', 'icon' => 'dashicons-screenoptions', 'group' => 'tools' ),
            'developer' => array( 'label' => 'Developer Mode', 'icon' => 'dashicons-editor-code', 'group' => 'tools' ),
            'settings' => array( 'label' => 'Settings', 'icon' => 'dashicons-admin-settings', 'group' => 'tools' ),
        );
        if ( ! isset( $tabs[ $tab ] ) ) {
            $tab = 'dashboard';
        }
        ?>
        <div class="wrap echo-platform-app">
            <div class="echo-app-shell">
                <aside class="echo-app-sidebar">
                    <div class="echo-brand"><span class="echo-mark">E</span><div><strong>ECHO</strong><small>MOTORWORKS</small></div></div>
                    <nav>
                        <?php
                        $groups = array(
                            'home' => '', 'catalog' => 'Catalog', 'suppliers' => 'Suppliers',
                            'vehicles' => 'Vehicles', 'business' => 'Business', 'tools' => 'Tools',
                        );
                        foreach ( $groups as $group_key => $group_label ) :
                            if ( $group_label ) echo '<div class="echo-nav-heading">' . esc_html( $group_label ) . '</div>';
                            foreach ( $tabs as $slug => $item ) :
                                if ( $item['group'] !== $group_key ) continue;
                                ?>
                                <a class="<?php echo $tab === $slug ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=echo-catalog-manager&tab=' . $slug ) ); ?>">
                                    <span class="dashicons <?php echo esc_attr( $item['icon'] ); ?>"></span><span><?php echo esc_html( $item['label'] ); ?></span>
                                </a>
                                <?php
                            endforeach;
                        endforeach;
                        ?>
                    </nav>
                    <div class="echo-sidebar-foot"><span>Echo Platform</span><strong>v<?php echo esc_html( ECHO_MOTORWORKS_CORE_VERSION ); ?></strong></div>
                </aside>
                <main class="echo-app-main">
                    <?php if ( ! empty( $_GET['echo_notice'] ) ) : $notice_type = sanitize_key( $_GET['echo_notice_type'] ?? 'success' ); ?>
                        <div class="notice <?php echo 'error' === $notice_type ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( rawurldecode( wp_unslash( $_GET['echo_notice'] ) ) ); ?></p></div>
                    <?php endif; ?>
                    <header class="echo-app-header">
                        <div><h1><?php echo esc_html( $tabs[ $tab ]['label'] ); ?></h1><p>One platform for the entire Echo Motorworks operation.</p></div>
                        <div class="echo-header-actions"><a class="button" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener">View Store</a><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=echo-catalog-manager&tab=health' ) ); ?>">Run Health Check</a></div>
                    </header>
                    <?php if ( 'dashboard' === $tab ) : $this->supplier_workspace(); endif; ?>
                    <section class="echo-manager-panel">
                        <?php $method = 'tab_' . $tab; $this->{$method}(); ?>
                    </section>
                </main>
            </div>
        </div>
        <?php
    }

    private function supplier_workspace(): void {
        $suppliers = $this->suppliers();
        ?>
        <div class="echo-workspace">
            <label>Supplier workspace
                <select id="echo-workspace-supplier">
                    <option value="">Choose a supplier</option>
                    <?php foreach ( $suppliers as $key => $supplier ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $supplier['name'] ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Go to
                <select id="echo-workspace-action">
                    <option value="">Choose an action</option>
                    <option value="<?php echo esc_url( admin_url('admin.php?page=echo-catalog-manager&tab=suppliers') ); ?>">Supplier details</option>
                    <option value="<?php echo esc_url( admin_url('admin.php?page=echo-catalog-manager&tab=products') ); ?>">Import / update products</option>
                    <option value="<?php echo esc_url( admin_url('admin.php?page=echo-catalog-manager&tab=images') ); ?>">Repair images</option>
                    <option value="<?php echo esc_url( admin_url('admin.php?page=echo-catalog-manager&tab=fitment') ); ?>">Manage fitment</option>
                    <option value="<?php echo esc_url( admin_url('admin.php?page=echo-catalog-manager&tab=health') ); ?>">Catalog health</option>
                </select>
            </label>
            <button type="button" id="echo-workspace-go" class="button button-primary">Open</button>
        </div>
        <?php
    }
    private function tab_dashboard(): void { Echo_Platform_OS_UI::mission_control(); }

    private function platform_status(): void {
        if ( ! class_exists( 'Echo_Motorworks_Platform' ) ) {
            return;
        }
        $diag = Echo_Motorworks_Platform::diagnostics();
        ?>
        <div class="echo-manager-card" style="margin-top:18px">
            <h2>Platform status</h2>
            <p><strong>Database:</strong> <?php echo $diag['tables_ready'] ? '<span style="color:#008a20">Ready</span>' : '<span style="color:#b32d2e">Check required</span>'; ?> &nbsp; <strong>Uploads:</strong> <?php echo $diag['uploads_writable'] ? '<span style="color:#008a20">Writable</span>' : '<span style="color:#b32d2e">Not writable</span>'; ?> &nbsp; <strong>WooCommerce:</strong> <?php echo esc_html( $diag['woocommerce'] ?: 'Not detected' ); ?></p>
            <div class="echo-manager-grid">
                <?php foreach ( $diag['modules'] as $module ) : ?>
                    <div style="border:1px solid #dcdcde;padding:12px;background:#f6f7f7">
                        <strong><?php echo esc_html( $module['label'] ); ?></strong><br>
                        <span style="color:<?php echo $module['ready'] ? '#008a20' : '#b32d2e'; ?>"><?php echo $module['ready'] ? 'Ready' : 'Unavailable'; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <p style="margin-bottom:0;color:#646970">Installed <?php echo esc_html( $diag['installed_version'] ); ?> · Schema <?php echo esc_html( $diag['db_version'] ); ?> · PHP <?php echo esc_html( $diag['php'] ); ?> · WordPress <?php echo esc_html( $diag['wordpress'] ); ?></p>
        </div>
        <?php
    }

    private function tab_suppliers(): void {
        $suppliers = $this->suppliers();
        ?>
        <div class="echo-manager-card">
            <h2>Add supplier</h2>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <input type="hidden" name="action" value="echo_catalog_add_supplier">
                <?php wp_nonce_field( 'echo_catalog_add_supplier' ); ?>
                <div class="echo-form-grid">
                    <p><label>Supplier name<input required name="name" type="text" placeholder="Example Performance"></label></p>
                    <p><label>Website<input required name="website" type="url" placeholder="https://example.com"></label></p>
                    <p><label>SKU prefix<input required name="prefix" type="text" maxlength="12" placeholder="EXP-"></label></p>
                    <p><label>Brand slug<input name="slug" type="text" placeholder="example-performance"></label></p>
                    <p class="echo-wide"><label>Logo URL (optional)<input name="logo" type="url" placeholder="https://..."></label></p>
                </div>
                <button class="button button-primary">Add supplier</button>
            </form>
        </div>
        <h2>Configured suppliers</h2>
        <div class="echo-supplier-filter">
            <label>Choose supplier<select id="echo-supplier-filter-select"><option value="">All suppliers</option><?php foreach ( $suppliers as $key => $supplier ) : ?><option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $supplier['name'] ); ?></option><?php endforeach; ?></select></label>
            <label>Search suppliers<input id="echo-supplier-filter-text" type="search" placeholder="Name, website, SKU prefix, product count..."></label>
        </div>
        <table class="echo-table"><thead><tr><th>Supplier</th><th>Website</th><th>SKU prefix</th><th>Products</th><th>Existing tool</th></tr></thead><tbody>
        <?php foreach ( $suppliers as $key => $supplier ) : $count = $this->supplier_product_count( $supplier ); ?>
            <tr data-echo-supplier-row data-echo-supplier-key="<?php echo esc_attr( $key ); ?>"><td><strong><?php echo esc_html( $supplier['name'] ); ?></strong><br><code><?php echo esc_html( $key ); ?></code></td><td><a target="_blank" rel="noopener" href="<?php echo esc_url( $supplier['website'] ); ?>"><?php echo esc_html( $supplier['website'] ); ?></a></td><td><code><?php echo esc_html( $supplier['prefix'] ); ?></code></td><td><?php echo esc_html( $count ); ?></td><td><?php if ( ! empty($supplier['tool']) ) : ?><a class="button" href="<?php echo esc_url( admin_url($supplier['tool']) ); ?>">Open</a><?php else: ?>Generic CSV<?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php
    }

    private function tab_products(): void {
        ?>
        <div class="echo-manager-card">
            <h2>Import or update supplier products</h2>
            <p>Upload the standard Echo CSV. Existing products update by SKU; new SKUs are created. The import never deletes products.</p>
            <p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url('admin-post.php?action=echo_catalog_export_template'), 'echo_catalog_export_template' ) ); ?>">Download CSV template</a></p>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <input type="hidden" name="action" value="echo_catalog_import_products">
                <?php wp_nonce_field( 'echo_catalog_import_products' ); ?>
                <div class="echo-form-grid">
                    <p><label>Supplier<select required name="supplier"><option value="">Select supplier</option><?php foreach($this->suppliers() as $key=>$s): ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($s['name']); ?></option><?php endforeach; ?></select></label></p>
                    <p><label>CSV file<input required type="file" name="catalog_csv" accept=".csv,text/csv"></label></p>
                    <p><label><input type="checkbox" name="download_images" value="1" checked> Download image_url as featured image when missing</label></p>
                    <p><label><input type="checkbox" name="publish_new" value="1"> Publish newly created products immediately</label></p>
                </div>
                <button class="button button-primary">Import / Update Products</button>
            </form>
            <hr><p><strong>Accepted columns:</strong> name, sku, price, regular_price, sale_price, description, short_description, category, image_url, source_url, fitment_type, fitment_raw, stock_status.</p>
        </div>
        <?php
    }

    private function tab_images(): void {
        $state = Echo_Motorworks_Smart_Engine::image_state();
        $suppliers = $this->suppliers();
        $total = max( 1, (int) $state['total'] );
        $progress = min( 100, (int) round( ( (int) $state['processed'] / $total ) * 100 ) );
        $candidates = is_array( $state['candidates'] ?? null ) ? $state['candidates'] : array();
        ?>
        <div class="echo-discovery-hero">
            <div><span class="echo-eyebrow">UNIVERSAL IMAGE ENGINE</span><h2>Find the best image without supplier-specific hacks</h2><p>Echo checks trusted import data, the Media Library, product descriptions and public supplier product pages. Every match is scored and waits for your approval.</p></div>
            <span class="dashicons dashicons-images-alt2"></span>
        </div>
        <div class="echo-stat-grid echo-stat-grid-5">
            <div class="echo-stat"><span>Scan status</span><strong><?php echo esc_html( ucfirst( (string) $state['status'] ) ); ?></strong><small><?php echo esc_html( (int) $state['processed'] . ' / ' . (int) $state['total'] ); ?></small></div>
            <div class="echo-stat"><span>Missing</span><strong><?php echo esc_html( (int) $state['missing'] ); ?></strong><small>Products checked</small></div>
            <div class="echo-stat"><span>Matches found</span><strong><?php echo esc_html( (int) $state['found'] ); ?></strong><small>Awaiting review</small></div>
            <div class="echo-stat"><span>High confidence</span><strong><?php echo esc_html( (int) $state['high_confidence'] ); ?></strong><small>90% or better</small></div>
            <div class="echo-stat"><span>Low quality</span><strong><?php echo esc_html( (int) $state['poor_quality'] ); ?></strong><small>Under 500px</small></div>
        </div>
        <div class="echo-manager-card">
            <div class="echo-card-row"><div><h2>Run Image Intelligence</h2><p>Run the whole catalog or focus on one supplier. The scan runs in safe background batches.</p></div>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="echo-inline-form">
                <input type="hidden" name="action" value="echo_platform_start_image_scan"><?php wp_nonce_field( 'echo_platform_start_image_scan' ); ?>
                <select name="supplier"><option value="">All suppliers</option><?php foreach ( $suppliers as $key=>$supplier ) : ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($supplier['name']); ?></option><?php endforeach; ?></select>
                <button class="button button-primary">Start Smart Scan</button>
            </form></div>
            <?php if ( in_array( $state['status'], array('queued','running'), true ) ) : ?><div class="echo-progress"><span style="width:<?php echo esc_attr($progress); ?>%"></span></div><p><?php echo esc_html($progress); ?>% complete. Refresh this page in a moment; the job continues even if you leave.</p><?php endif; ?>
        </div>
        <div class="echo-manager-card"><div class="echo-card-row"><div><h2>Review Queue</h2><p>Nothing changes until you approve it.</p></div><span class="echo-chip"><?php echo esc_html(count($candidates)); ?> candidates</span></div>
        <?php if ( ! $candidates ) : ?><div class="echo-empty">No image candidates are waiting. Run a scan or check back when the background job finishes.</div><?php else : ?><div class="echo-image-review-grid">
        <?php foreach ( array_slice($candidates,0,80,true) as $product_id=>$candidate ) : ?>
            <article class="echo-image-candidate"><div class="echo-image-preview"><?php if(!empty($candidate['url'])):?><img src="<?php echo esc_url($candidate['url']); ?>" alt=""><?php else:?><span class="dashicons dashicons-format-image"></span><?php endif;?></div><div class="echo-image-info"><span class="echo-score <?php echo (int)$candidate['confidence']>=90?'is-high':'is-review'; ?>"><?php echo esc_html((int)$candidate['confidence']); ?>% match</span><h3><?php echo esc_html($candidate['title']??get_the_title((int)$product_id)); ?></h3><p><?php echo esc_html($candidate['reason']??'Suggested image'); ?></p><small>SKU: <?php echo esc_html($candidate['sku']?:'—'); ?> · Part: <?php echo esc_html($candidate['part_number']?:'—'); ?></small><div class="echo-candidate-actions"><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="echo_platform_apply_image_candidate"><input type="hidden" name="product_id" value="<?php echo esc_attr($product_id); ?>"><?php wp_nonce_field('echo_platform_apply_image_candidate'); ?><button class="button button-primary">Approve Image</button></form><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="echo_platform_ignore_image_candidate"><input type="hidden" name="product_id" value="<?php echo esc_attr($product_id); ?>"><?php wp_nonce_field('echo_platform_ignore_image_candidate'); ?><button class="button">Ignore</button></form><a class="button" href="<?php echo esc_url(get_edit_post_link((int)$product_id)); ?>">Edit Product</a></div></div></article>
        <?php endforeach; ?></div><?php endif; ?></div>
        <div class="echo-manager-card echo-safety-note"><span class="dashicons dashicons-shield"></span><div><h3>Smart, but controlled</h3><p>Exact SKU and part-number matches score highest. Existing images are never silently replaced, remote images are downloaded only after approval, and ignored suggestions do not alter the product.</p></div></div>
        <?php
    }

    private function tab_fitment(): void {
        ?>
        <div class="echo-manager-card" style="margin-bottom:16px">
            <h2>Fitment Manager</h2>
            <p>The complete fitment importer is embedded below. Use it to assign universal/review states, upload exact year-make-model fitment, and run the Applied Torque Solutions fitment builder.</p>
        </div>
        <div class="echo-embedded-tool echo-embedded-fitment">
            <?php echo_motorworks_core()->admin->fitment_page(); ?>
        </div>
        <style>
            .echo-embedded-tool>.wrap{margin:0}.echo-embedded-fitment>.wrap>h1{display:none}
        </style>
        <?php
    }

    private function tab_garage(): void {
        global $wpdb;
        $vehicle_table = class_exists('Echo_Motorworks_DB') ? Echo_Motorworks_DB::vehicles_table() : '';
        $garage_rows = $wpdb->get_results("SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key='_echo_garage_vehicles'");
        $saved = 0; $owners = 0;
        foreach ((array)$garage_rows as $row) { $garage = maybe_unserialize($row->meta_value); if (is_array($garage) && $garage) { $owners++; $saved += count($garage); } }
        $vehicles = $vehicle_table ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$vehicle_table}") : 0;
        ?>
        <div class="echo-manager-grid">
            <?php $this->metric('Saved garage vehicles',$saved,'All saved customer vehicles'); ?>
            <?php $this->metric('Garage customers',$owners,'Registered users with a saved vehicle'); ?>
            <?php $this->metric('Vehicle database',$vehicles,'Year / make / model records available to lookup'); ?>
        </div>
        <div class="echo-manager-card" style="margin-top:18px">
            <h2>Garage behavior</h2>
            <p>Garage shopping shows verified vehicle-specific products plus products explicitly marked Universal. Unknown products are not automatically treated as compatible.</p>
            <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=echo-catalog-manager&tab=fitment')); ?>">Manage product fitment</a> <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=echo-catalog-manager&tab=health')); ?>">Check fitment coverage</a></p>
        </div>
        <?php
    }

    private function tab_support(): void {
        $status = sanitize_key($_GET['request_status'] ?? 'all');
        $type = sanitize_key($_GET['request_type'] ?? 'all');
        $search = sanitize_text_field(wp_unslash($_GET['request_search'] ?? ''));
        $meta_query = array();
        if ($status !== 'all') $meta_query[] = array('key'=>'_echo_request_status','value'=>$status);
        if ($type !== 'all') $meta_query[] = array('key'=>'_em_request_type','value'=>$type);
        $args = array('post_type'=>'em_part_request','post_status'=>array('publish','private','draft'),'posts_per_page'=>50,'orderby'=>'date','order'=>'DESC','s'=>$search);
        if ($meta_query) $args['meta_query'] = $meta_query;
        $requests = get_posts($args);
        ?>
        <div class="echo-manager-grid">
            <?php $this->metric('Open',$this->request_count('open'),'New requests'); ?>
            <?php $this->metric('In progress',$this->request_count('in_progress'),'Currently being researched'); ?>
            <?php $this->metric('Waiting',$this->request_count('waiting'),'Waiting on customer or supplier'); ?>
            <?php $this->metric('Completed',$this->request_count('complete'),'Closed requests'); ?>
        </div>
        <div class="echo-manager-card" style="margin-top:18px">
            <h2>Support Center</h2>
            <form class="echo-toolbar" method="get">
                <input type="hidden" name="page" value="echo-catalog-manager"><input type="hidden" name="tab" value="support">
                <label>Status<select name="request_status"><option value="all">All</option><?php foreach(array('open'=>'Open','in_progress'=>'In progress','waiting'=>'Waiting','complete'=>'Completed') as $k=>$v): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($status,$k); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select></label>
                <label>Type<select name="request_type"><option value="all">All</option><option value="part" <?php selected($type,'part'); ?>>Part request</option><option value="pricing" <?php selected($type,'pricing'); ?>>Pricing request</option></select></label>
                <label>Search<input type="search" name="request_search" value="<?php echo esc_attr($search); ?>" placeholder="Name, part, vehicle..."></label>
                <button class="button button-primary">Filter</button>
            </form>
            <?php if (!$requests): ?><div class="echo-empty">No requests match this filter.</div><?php else: ?>
            <table class="echo-table"><thead><tr><th>Request</th><th>Customer</th><th>Vehicle / Product</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            <?php foreach($requests as $request):
                $rid=$request->ID; $request_type=get_post_meta($rid,'_em_request_type',true) ?: 'part'; $request_status=get_post_meta($rid,'_echo_request_status',true) ?: 'open';
                $name=get_post_meta($rid,'_em_name',true); $email=get_post_meta($rid,'_em_email',true); $vehicle=get_post_meta($rid,'_em_vehicle',true); $part=get_post_meta($rid,'_em_part_requested',true); $message=get_post_meta($rid,'_em_message',true);
                $reference='EM-'.str_pad((string)$rid,6,'0',STR_PAD_LEFT);
            ?>
            <tr><td><strong><?php echo esc_html($reference); ?></strong><br><?php echo esc_html(ucfirst($request_type).' request'); ?><br><small><?php echo esc_html(get_the_date('M j, Y g:i a',$rid)); ?></small></td>
            <td><strong><?php echo esc_html($name ?: 'Unknown'); ?></strong><br><a href="mailto:<?php echo esc_attr($email); ?>?subject=<?php echo rawurlencode('Echo Motorworks request '.$reference); ?>"><?php echo esc_html($email); ?></a></td>
            <td><strong><?php echo esc_html($vehicle ?: 'Vehicle not provided'); ?></strong><br><?php echo esc_html($part); ?><?php if($message): ?><br><small><?php echo esc_html(wp_trim_words($message,18,'…')); ?></small><?php endif; ?></td>
            <td><span class="echo-status echo-status-<?php echo esc_attr($request_status==='in_progress'?'progress':$request_status); ?>"><?php echo esc_html(ucwords(str_replace('_',' ',$request_status))); ?></span></td>
            <td><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="echo_platform_request_status"><input type="hidden" name="request_id" value="<?php echo esc_attr($rid); ?>"><?php wp_nonce_field('echo_platform_request_status_'.$rid); ?><select name="status"><option value="open" <?php selected($request_status,'open'); ?>>Open</option><option value="in_progress" <?php selected($request_status,'in_progress'); ?>>In progress</option><option value="waiting" <?php selected($request_status,'waiting'); ?>>Waiting</option><option value="complete" <?php selected($request_status,'complete'); ?>>Completed</option></select> <button class="button">Save</button></form><p><a class="button button-primary" href="mailto:<?php echo esc_attr($email); ?>?subject=<?php echo rawurlencode('Re: Echo Motorworks request '.$reference); ?>">Reply by email</a> <a class="button" href="<?php echo esc_url(get_edit_post_link($rid)); ?>">Open</a></p></td></tr>
            <?php endforeach; ?></tbody></table><?php endif; ?>
        </div>
        <?php
    }

    private function tab_reports(): void {
        $h=$this->health();
        $supplier_rows=array(); foreach($this->suppliers() as $key=>$supplier){$supplier_rows[]=array($supplier['name'],$this->supplier_product_count($supplier));}
        usort($supplier_rows,fn($a,$b)=>$b[1]<=>$a[1]);
        ?>
        <div class="echo-manager-grid">
            <?php $this->metric('Catalog products',$h['products'],'Published, draft, and private'); ?>
            <?php $this->metric('Image coverage',$h['image_pct'].'%',$h['missing_images'].' missing images'); ?>
            <?php $this->metric('Fitment coverage',$h['fitment_pct'].'%',$h['missing_fitment'].' need fitment'); ?>
            <?php $this->metric('Open support',$this->request_count('open')+$this->request_count('in_progress')+$this->request_count('waiting'),'Requests not completed'); ?>
        </div>
        <div class="echo-manager-card" style="margin-top:18px"><h2>Products by supplier</h2><table class="echo-table"><thead><tr><th>Supplier</th><th>Products</th></tr></thead><tbody><?php foreach($supplier_rows as $r): ?><tr><td><?php echo esc_html($r[0]); ?></td><td><?php echo esc_html($r[1]); ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php
    }

    private function tab_settings(): void {
        $settings=$this->settings();
        ?>
        <div class="echo-manager-card">
            <h2>Platform settings</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="echo_platform_save_settings"><?php wp_nonce_field('echo_platform_save_settings'); ?>
                <div class="echo-form-grid">
                    <p><label>Company support email<input type="email" required name="support_email" value="<?php echo esc_attr($settings['support_email']); ?>"></label><small>Parts and pricing requests should route here.</small></p>
                    <p><label>Typical response time<input type="text" name="response_time" value="<?php echo esc_attr($settings['response_time']); ?>"></label><small>Displayed in customer confirmation messaging.</small></p>
                    <p><label>Default new-product status<select name="new_product_status"><option value="draft" <?php selected($settings['new_product_status'],'draft'); ?>>Draft</option><option value="publish" <?php selected($settings['new_product_status'],'publish'); ?>>Published</option></select></label></p>
                    <p><label><input type="checkbox" name="show_universal" value="1" <?php checked($settings['show_universal']); ?>> Show Universal products alongside verified garage matches</label></p>
                </div>
                <button class="button button-primary">Save settings</button>
            </form>
        </div>
        <div class="echo-manager-card" style="margin-top:18px"><h2>Current routing</h2><p>Support email: <strong><?php echo esc_html($settings['support_email']); ?></strong></p><p>Customer response target: <strong><?php echo esc_html($settings['response_time']); ?></strong></p></div>
        <?php
    }

    private function tab_health(): void {
        $h = Echo_Motorworks_Operations::health_snapshot();
        if ( ! $h ) $h = Echo_Motorworks_Operations::calculate_health();
        ?>
        <div class="echo-health-hero">
            <div class="echo-score-ring" style="--score:<?php echo esc_attr( $h['score'] ); ?>"><div><strong><?php echo esc_html( $h['score'] ); ?>%</strong><span>Store Health</span></div></div>
            <div><span class="echo-eyebrow">CATALOG COMPLETION</span><h2><?php echo esc_html( number_format_i18n( $h['products'] ) ); ?> products checked</h2><p><?php echo esc_html( $h['healthy'] ); ?> healthy · <?php echo esc_html( $h['review'] ); ?> need review · <?php echo esc_html( $h['critical'] ); ?> critical</p><small>Last scan: <?php echo esc_html( $h['scanned_at'] ?? 'Not saved yet' ); ?></small></div>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="echo_platform_run_health_scan"><?php wp_nonce_field( 'echo_platform_run_health_scan' ); ?><button class="button button-primary button-hero">Scan Store</button></form>
        </div>
        <div class="echo-manager-grid">
            <?php $this->metric('Missing images',$h['missing_images'],'Repair through Catalog → Images'); ?>
            <?php $this->metric('Missing prices',$h['missing_prices'],'Critical: products cannot sell correctly'); ?>
            <?php $this->metric('Missing SKU',$h['missing_sku'],'Add SKU or manufacturer part number'); ?>
            <?php $this->metric('Missing categories',$h['missing_categories'],'Improve store navigation'); ?>
            <?php $this->metric('Brand not detected',$h['missing_brand'],'Review supplier or brand fields'); ?>
            <?php $this->metric('Fitment not detected',$h['missing_fitment'],'Classify universal or verified fitment'); ?>
            <?php $this->metric('Draft products',$h['drafts'],'Review before publishing'); ?>
        </div>
        <div class="echo-manager-card" style="margin-top:18px"><h2>Today’s fix queue</h2><div class="echo-task-list"><a href="<?php echo esc_url( admin_url('admin.php?page=echo-catalog-manager&tab=images') ); ?>"><span>Repair missing images</span><strong><?php echo esc_html( $h['missing_images'] ); ?></strong></a><a href="<?php echo esc_url( admin_url('admin.php?page=echo-catalog-manager&tab=products') ); ?>"><span>Review missing prices and SKUs</span><strong><?php echo esc_html( $h['missing_prices'] + $h['missing_sku'] ); ?></strong></a><a href="<?php echo esc_url( admin_url('admin.php?page=echo-catalog-manager&tab=fitment') ); ?>"><span>Review fitment classifications</span><strong><?php echo esc_html( $h['missing_fitment'] ); ?></strong></a><a href="<?php echo esc_url( admin_url('admin.php?page=echo-catalog-manager&tab=cleanup') ); ?>"><span>Run exact part-number duplicate finder</span><strong>Open</strong></a></div></div>
        <?php
    }

    public function ensure_request_post_type(): void {
        if (post_type_exists('em_part_request')) return;
        register_post_type('em_part_request',array(
            'labels'=>array('name'=>'Part Requests','singular_name'=>'Part Request'),
            'public'=>false,'show_ui'=>true,'show_in_menu'=>false,'supports'=>array('title','editor','custom-fields'),
            'capability_type'=>'post','map_meta_cap'=>true,
        ));
    }

    public function save_settings(): void {
        $this->guard('echo_platform_save_settings');
        $settings=$this->settings();
        $settings['support_email']=sanitize_email(wp_unslash($_POST['support_email'] ?? '')) ?: 'accounts@echomotorworks.com';
        $settings['response_time']=sanitize_text_field(wp_unslash($_POST['response_time'] ?? 'Within 1 business day'));
        $settings['new_product_status']=in_array($_POST['new_product_status'] ?? '',array('draft','publish'),true)?sanitize_key($_POST['new_product_status']):'draft';
        $settings['show_universal']=!empty($_POST['show_universal']);
        update_option('echo_platform_settings_v1',$settings,false);
        update_option('em_part_request_email',$settings['support_email'],false);
        set_theme_mod('em_request_email',$settings['support_email']);
        $this->redirect('settings','Platform settings saved.');
    }

    public function update_request_status(): void {
        $id=absint($_POST['request_id'] ?? 0);
        if(!$id || get_post_type($id)!=='em_part_request') wp_die('Invalid request.');
        $this->guard('echo_platform_request_status_'.$id);
        $status=sanitize_key($_POST['status'] ?? 'open');
        if(!in_array($status,array('open','in_progress','waiting','complete'),true)) $status='open';
        update_post_meta($id,'_echo_request_status',$status);
        $this->redirect('support','Request status updated.');
    }

    private function tab_cleanup(): void {
        if ( ! empty( $_GET['cleanup_notice'] ) ) {
            $type = sanitize_key( $_GET['cleanup_notice_type'] ?? 'success' );
            $class = 'error' === $type ? 'notice notice-error' : 'notice notice-success';
            echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( wp_unslash( $_GET['cleanup_notice'] ) ) . '</p></div>';
        }
        if ( class_exists( 'Echo_Motorworks_Catalog_Cleanup' ) ) {
            echo_motorworks_core()->catalog_cleanup->render();
            return;
        }
        echo '<div class="notice notice-error"><p>Catalog Cleanup module is unavailable.</p></div>';
    }


    private function tab_sync(): void {
        $connections = Echo_Motorworks_Operations::connections();
        $suppliers = $this->suppliers();
        ?>
        <div class="echo-hero-card">
            <div><span class="echo-eyebrow">SUPPLIER OPERATIONS</span><h2>Sync Center</h2><p>Run suppliers manually, on demand, or automatically. Every update is queued and preview-first.</p></div>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="echo_platform_sync_all"><?php wp_nonce_field( 'echo_platform_sync_all' ); ?>
                <button class="button button-primary button-hero">Sync All Connected Suppliers</button>
            </form>
        </div>
        <div class="echo-manager-card" style="margin-bottom:18px"><div class="echo-card-row"><div><h2>Manual Feed Preview</h2><p>Upload CSV, JSON, or XML. Echo builds a preview before anything reaches the catalog.</p></div></div><form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="echo-form-grid"><input type="hidden" name="action" value="echo_sync_upload_feed"><?php wp_nonce_field('echo_sync_upload_feed'); ?><p><label>Supplier<select name="supplier" required><?php foreach($suppliers as $k=>$v): ?><option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($v['name']); ?></option><?php endforeach; ?></select></label></p><p><label>Feed file<input type="file" name="feed_file" accept=".csv,.json,.xml" required></label></p><p><button class="button button-primary">Upload & Build Preview</button></p></form></div>
        <div class="echo-manager-grid">
            <?php foreach ( $suppliers as $key => $supplier ) : $connection = Echo_Motorworks_Operations::connection( $key ); ?>
                <div class="echo-manager-card echo-supplier-status-card">
                    <div class="echo-card-row"><div><span class="echo-status-dot <?php echo ! empty( $connection['last_test_status'] ) ? esc_attr( $connection['last_test_status'] ) : 'manual'; ?>"></span><strong><?php echo esc_html( $supplier['name'] ); ?></strong></div><span class="echo-chip"><?php echo esc_html( strtoupper( $connection['connection_type'] ) ); ?></span></div>
                    <p><?php echo esc_html( ucfirst( $connection['mode'] ) ); ?> mode<?php echo ! empty( $connection['auto_enabled'] ) ? ' · Auto enabled' : ''; ?></p>
                    <dl class="echo-mini-stats"><div><dt>Last sync</dt><dd><?php echo esc_html( $connection['last_sync'] ?: 'Not run' ); ?></dd></div><div><dt>Last test</dt><dd><?php echo esc_html( $connection['last_test_status'] ?: 'Not tested' ); ?></dd></div></dl>
                    <div class="echo-card-actions">
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="echo_sync_build_preview"><input type="hidden" name="supplier" value="<?php echo esc_attr($key); ?>"><?php wp_nonce_field('echo_sync_build_preview'); ?><button class="button button-primary">Build Preview</button></form>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="echo_platform_sync_supplier"><input type="hidden" name="supplier" value="<?php echo esc_attr( $key ); ?>"><?php wp_nonce_field( 'echo_platform_sync_supplier' ); ?><button class="button button-primary">Sync Now</button></form>
                        <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=echo-catalog-manager&tab=api&supplier=' . $key ) ); ?>">Configure</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="echo-manager-card" style="margin-top:18px"><h2>Safe sync rules</h2><div class="echo-rule-grid"><div><strong>Preview first</strong><p>Supplier changes are staged before catalog records are changed.</p></div><div><strong>No silent deletes</strong><p>Missing supplier products are never deleted automatically.</p></div><div><strong>Manual always available</strong><p>CSV, XML and JSON uploads remain available even without an API.</p></div><div><strong>Automatic is optional</strong><p>Each supplier controls its own schedule and fields.</p></div></div></div>
        <?php
    }

    private function tab_discovery(): void {
        $suppliers = $this->suppliers();
        $selected = sanitize_key( $_GET['supplier'] ?? array_key_first( $suppliers ) );
        if ( ! isset( $suppliers[ $selected ] ) ) $selected = array_key_first( $suppliers );
        $supplier = $suppliers[ $selected ] ?? array( 'name' => 'Supplier', 'website' => '' );
        $result = Echo_Motorworks_Operations::discovery_result( $selected );
        $sources = $result['sources'] ?? array();
        ?>
        <div class="echo-discovery-hero">
            <div>
                <span class="echo-eyebrow">SUPPLIER ONBOARDING</span>
                <h2>Detect a live catalog source</h2>
                <p>Echo checks public API, feed, store, and sitemap endpoints. It never bypasses authentication and never imports products during discovery.</p>
            </div>
            <span class="dashicons dashicons-search"></span>
        </div>

        <div class="echo-manager-card echo-discovery-form-card" style="margin-top:18px">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="echo_platform_discover_catalog">
                <?php wp_nonce_field( 'echo_platform_discover_catalog' ); ?>
                <div class="echo-form-grid">
                    <p><label>Supplier<select name="supplier" id="echo-discovery-supplier" required><?php foreach ( $suppliers as $key => $item ) : ?><option value="<?php echo esc_attr( $key ); ?>" data-url="<?php echo esc_attr( $item['website'] ?? '' ); ?>" <?php selected( $selected, $key ); ?>><?php echo esc_html( $item['name'] ); ?></option><?php endforeach; ?></select></label></p>
                    <p class="echo-wide"><label>Public supplier website<input id="echo-discovery-url" type="url" name="website" required value="<?php echo esc_attr( $supplier['website'] ?? '' ); ?>" placeholder="https://supplier.example"></label></p>
                </div>
                <div class="echo-discovery-actions"><button class="button button-primary button-hero"><span class="dashicons dashicons-search"></span> Detect Catalog Source</button><span>Usually takes 10–45 seconds depending on the supplier website.</span></div>
            </form>
        </div>

        <?php if ( $result ) : ?>
            <div class="echo-manager-grid echo-discovery-summary" style="margin-top:18px">
                <?php $this->metric( 'Sources found', count( $sources ), empty( $sources ) ? 'Manual import may be required' : 'Review before connecting' ); ?>
                <?php $this->metric( 'Supplier', $supplier['name'], 'Scanned ' . ( $result['scanned_at'] ?? '' ) ); ?>
                <?php $this->metric( 'Status', empty( $sources ) ? 'Needs Review' : 'Detected', empty( $sources ) ? 'No confirmed public source' : 'Public source candidates available' ); ?>
            </div>

            <?php if ( ! empty( $result['clues'] ) ) : ?>
                <div class="echo-manager-card" style="margin-top:18px"><h2>Platform clues</h2><div class="echo-chip-row"><?php foreach ( $result['clues'] as $clue ) : ?><span class="echo-chip"><?php echo esc_html( $clue ); ?></span><?php endforeach; ?></div></div>
            <?php endif; ?>

            <div class="echo-manager-card" style="margin-top:18px">
                <div class="echo-section-heading"><div><h2>Detected catalog sources</h2><p>Higher-confidence sources are listed first. Copying a source only fills the connection form; it does not start a sync.</p></div></div>
                <?php if ( empty( $sources ) ) : ?>
                    <div class="echo-empty"><strong>No confirmed live catalog source found.</strong><p>Keep this supplier in Manual mode, ask them for API/feed access, or use the existing CSV importer.</p></div>
                <?php else : ?>
                    <div class="echo-source-list">
                    <?php foreach ( $sources as $index => $source ) : ?>
                        <article class="echo-source-card">
                            <div class="echo-source-main">
                                <div class="echo-source-icon"><span class="dashicons <?php echo 'graphql' === $source['type'] ? 'dashicons-networking' : 'dashicons-rest-api'; ?>"></span></div>
                                <div><div class="echo-source-title"><strong><?php echo esc_html( $source['label'] ); ?></strong><span class="echo-status-pill is-good"><?php echo esc_html( strtoupper( $source['type'] ) ); ?></span></div><code><?php echo esc_html( $source['url'] ); ?></code><p><?php echo esc_html( $source['notes'] ); ?> · HTTP <?php echo esc_html( $source['http_code'] ); ?></p></div>
                            </div>
                            <div class="echo-source-score"><span>Confidence</span><strong><?php echo esc_html( $source['confidence'] ); ?>%</strong><div><i style="width:<?php echo esc_attr( $source['confidence'] ); ?>%"></i></div></div>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="echo_platform_use_discovery_source"><input type="hidden" name="supplier" value="<?php echo esc_attr( $selected ); ?>"><input type="hidden" name="source_index" value="<?php echo esc_attr( $index ); ?>"><?php wp_nonce_field( 'echo_platform_use_discovery_source' ); ?><button class="button button-primary">Use This Source</button></form>
                        </article>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="echo-manager-card echo-safety-note" style="margin-top:18px"><span class="dashicons dashicons-shield"></span><div><strong>Safe by design</strong><p><?php echo esc_html( $result['notes'] ?? '' ); ?> Product changes still require connection setup, mapping, preview, and approval.</p></div></div>
        <?php endif; ?>
        <script>
        document.addEventListener('DOMContentLoaded',function(){var s=document.getElementById('echo-discovery-supplier'),u=document.getElementById('echo-discovery-url');if(s&&u){s.addEventListener('change',function(){var o=s.options[s.selectedIndex];if(o&&o.dataset.url)u.value=o.dataset.url;});}});
        </script>
        <style>
        .echo-discovery-hero{display:flex;align-items:center;justify-content:space-between;padding:28px;border:1px solid #24324a;border-radius:18px;background:linear-gradient(135deg,#111c31,#0a1020);overflow:hidden}.echo-discovery-hero h2{font-size:28px;margin:5px 0 8px}.echo-discovery-hero p{max-width:720px;margin:0;color:#aab7ca}.echo-discovery-hero>.dashicons{font-size:76px;width:76px;height:76px;color:#2f80ff;opacity:.3}.echo-discovery-actions{display:flex;gap:14px;align-items:center;flex-wrap:wrap}.echo-discovery-actions span{color:#8fa0b8}.echo-chip-row{display:flex;gap:8px;flex-wrap:wrap}.echo-chip{padding:7px 11px;border-radius:999px;background:#17243a;border:1px solid #2b3e5d;color:#bdd0eb}.echo-source-list{display:grid;gap:12px}.echo-source-card{display:grid;grid-template-columns:minmax(0,1fr) 150px auto;align-items:center;gap:18px;padding:17px;border:1px solid #273752;border-radius:14px;background:#0d1729}.echo-source-main{display:flex;gap:13px;min-width:0}.echo-source-icon{display:grid;place-items:center;width:42px;height:42px;flex:0 0 42px;border-radius:11px;background:#172a49;color:#49a0ff}.echo-source-title{display:flex;gap:9px;align-items:center;margin-bottom:6px}.echo-source-main code{display:block;max-width:680px;overflow:hidden;text-overflow:ellipsis;color:#72afff}.echo-source-main p{margin:6px 0 0;color:#8fa0b8}.echo-status-pill{font-size:10px;font-weight:700;letter-spacing:.08em;padding:4px 7px;border-radius:999px}.echo-status-pill.is-good{background:#113a2d;color:#6be6aa}.echo-source-score span{display:block;color:#8fa0b8;font-size:12px}.echo-source-score strong{font-size:21px}.echo-source-score div{height:5px;margin-top:7px;background:#202d40;border-radius:99px;overflow:hidden}.echo-source-score i{display:block;height:100%;background:#2f80ff}.echo-safety-note{display:flex;gap:14px;align-items:flex-start}.echo-safety-note>.dashicons{color:#56d7a0;font-size:28px;width:28px;height:28px}@media(max-width:1050px){.echo-source-card{grid-template-columns:1fr}.echo-source-score{max-width:240px}}
        </style>
        <?php
    }

    private function tab_api(): void {
        $suppliers = $this->suppliers();
        $selected = sanitize_key( $_GET['supplier'] ?? array_key_first( $suppliers ) );
        if ( ! isset( $suppliers[ $selected ] ) ) $selected = array_key_first( $suppliers );
        $connection = Echo_Motorworks_Operations::connection( (string) $selected );
        ?>
        <div class="echo-toolbar-card">
            <label>Supplier<select onchange="if(this.value) location.href=this.value"><?php foreach ( $suppliers as $key => $supplier ) : ?><option value="<?php echo esc_url( admin_url( 'admin.php?page=echo-catalog-manager&tab=api&supplier=' . $key ) ); ?>" <?php selected( $selected, $key ); ?>><?php echo esc_html( $supplier['name'] ); ?></option><?php endforeach; ?></select></label>
            <div><span class="echo-eyebrow">CONNECTION STATUS</span><strong><?php echo esc_html( ucfirst( $connection['last_test_status'] ?: 'Not tested' ) ); ?></strong></div>
        </div>
        <div class="echo-manager-card">
            <h2><?php echo esc_html( $suppliers[ $selected ]['name'] ); ?> connection</h2>
            <p>Choose manual, on-demand, or automatic operation. Credentials remain hidden after saving.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="echo_platform_save_connection"><input type="hidden" name="supplier" value="<?php echo esc_attr( $selected ); ?>"><?php wp_nonce_field( 'echo_platform_save_connection' ); ?>
                <div class="echo-form-grid">
                    <p><label>Operating mode<select name="mode"><option value="manual" <?php selected( $connection['mode'], 'manual' ); ?>>Manual uploads</option><option value="ondemand" <?php selected( $connection['mode'], 'ondemand' ); ?>>On demand / Sync Now</option><option value="automatic" <?php selected( $connection['mode'], 'automatic' ); ?>>Automatic schedule</option></select></label></p>
                    <p><label>Connection type<select name="connection_type"><option value="csv" <?php selected( $connection['connection_type'], 'csv' ); ?>>CSV file</option><option value="xml" <?php selected( $connection['connection_type'], 'xml' ); ?>>XML feed</option><option value="json" <?php selected( $connection['connection_type'], 'json' ); ?>>JSON feed</option><option value="rest" <?php selected( $connection['connection_type'], 'rest' ); ?>>REST API</option><option value="graphql" <?php selected( $connection['connection_type'], 'graphql' ); ?>>GraphQL API</option><option value="sftp" <?php selected( $connection['connection_type'], 'sftp' ); ?>>SFTP</option><option value="webhook" <?php selected( $connection['connection_type'], 'webhook' ); ?>>Webhook</option></select></label></p>
                    <p class="echo-wide"><label>Base API or feed URL<input type="url" name="base_url" value="<?php echo esc_attr( $connection['base_url'] ); ?>" placeholder="https://supplier.example/api/products"></label></p>
                    <p><label>Authentication<select name="auth_type"><option value="none" <?php selected( $connection['auth_type'], 'none' ); ?>>None</option><option value="bearer" <?php selected( $connection['auth_type'], 'bearer' ); ?>>Bearer token</option><option value="header" <?php selected( $connection['auth_type'], 'header' ); ?>>X-API-Key header</option><option value="basic" <?php selected( $connection['auth_type'], 'basic' ); ?>>Basic username/password</option></select></label></p>
                    <p><label>API key / token<input type="password" name="api_key" value="" placeholder="Saved securely — enter only to replace"></label></p>
                    <p><label>Username<input type="text" name="username" value="<?php echo esc_attr( $connection['username'] ); ?>"></label></p>
                    <p><label>Password<input type="password" name="password" value="" placeholder="Saved securely — enter only to replace"></label></p>
                    <p><label>Automatic schedule<select name="schedule"><option value="hourly" <?php selected( $connection['schedule'], 'hourly' ); ?>>Hourly</option><option value="six_hours" <?php selected( $connection['schedule'], 'six_hours' ); ?>>Every 6 hours</option><option value="daily" <?php selected( $connection['schedule'], 'daily' ); ?>>Daily</option><option value="weekly" <?php selected( $connection['schedule'], 'weekly' ); ?>>Weekly</option></select></label></p>
                    <p><label><input type="checkbox" name="auto_enabled" value="1" <?php checked( $connection['auto_enabled'] ); ?>> Enable automatic sync</label></p>
                </div>
                <h3>Allowed updates</h3>
                <div class="echo-check-grid">
                    <label><input type="checkbox" name="update_prices" value="1" <?php checked( $connection['update_prices'] ); ?>> Prices</label><label><input type="checkbox" name="update_inventory" value="1" <?php checked( $connection['update_inventory'] ); ?>> Inventory</label><label><input type="checkbox" name="update_images" value="1" <?php checked( $connection['update_images'] ); ?>> Images</label><label><input type="checkbox" name="update_content" value="1" <?php checked( $connection['update_content'] ); ?>> Descriptions</label><label><input type="checkbox" name="new_products" value="1" <?php checked( $connection['new_products'] ); ?>> New products</label><label><input type="checkbox" name="auto_publish" value="1" <?php checked( $connection['auto_publish'] ); ?>> Auto publish new products</label><label><input type="checkbox" name="disable_missing" value="1" <?php checked( $connection['disable_missing'] ); ?>> Draft products missing from supplier</label>
                </div>
                <p><button class="button button-primary">Save Connection</button></p>
            </form>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="echo_platform_test_connection"><input type="hidden" name="supplier" value="<?php echo esc_attr( $selected ); ?>"><?php wp_nonce_field( 'echo_platform_test_connection' ); ?><button class="button">Test Connection</button></form>
        </div>
        <?php
    }
    private function tab_jobs(): void { Echo_Platform_OS_UI::jobs(); }

    private function tab_smart(): void {
        $advice = Echo_Motorworks_Smart_Engine::advice();
        $items = $advice['items'] ?? array();
        ?>
        <div class="echo-discovery-hero"><div><span class="echo-eyebrow">ECHO INTELLIGENCE</span><h2>Your next best actions, already prioritized</h2><p>This is not a chatbot. It reads catalog health, image recovery, fitment and supplier connections, then points you to the work that matters most.</p></div><span class="dashicons dashicons-lightbulb"></span></div>
        <div class="echo-manager-card"><div class="echo-card-row"><div><h2>Smart Action Queue</h2><p>Generated <?php echo esc_html($advice['generated_at']??'just now'); ?></p></div><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="echo_platform_refresh_advice"><?php wp_nonce_field('echo_platform_refresh_advice'); ?><button class="button button-primary">Refresh Recommendations</button></form></div>
        <div class="echo-advice-list"><?php foreach($items as $item):?><article class="echo-advice echo-priority-<?php echo esc_attr($item['priority']); ?>"><span class="echo-advice-priority"><?php echo esc_html(strtoupper($item['priority'])); ?></span><div><h3><?php echo esc_html($item['title']); ?></h3><p><?php echo esc_html($item['detail']); ?></p></div><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=echo-catalog-manager&tab='.$item['tab'])); ?>"><?php echo esc_html($item['action']); ?></a></article><?php endforeach;?></div></div>
        <div class="echo-manager-card"><h2>How Echo decides</h2><div class="echo-check-grid"><div><strong>Rules first</strong><p>Exact identifiers, missing data, source status and catalog facts.</p></div><div><strong>Confidence second</strong><p>Fuzzy matches are suggestions, never facts.</p></div><div><strong>You approve</strong><p>No silent deletes, product rewrites or image replacement.</p></div><div><strong>Improves with workflow</strong><p>Approvals and ignored suggestions make the queue more useful over time.</p></div></div></div>
        <?php
    }

    private function tab_mapping(): void {
        $suppliers=$this->suppliers(); $selected=sanitize_key($_GET['supplier']??array_key_first($suppliers)); if(!isset($suppliers[$selected]))$selected=array_key_first($suppliers);
        $connection=Echo_Motorworks_Operations::connection((string)$selected); $mapping=Echo_Motorworks_Smart_Engine::mapping_suggestions((string)$selected);
        ?>
        <div class="echo-toolbar-card"><label>Supplier<select onchange="if(this.value)location.href=this.value"><?php foreach($suppliers as $key=>$supplier):?><option value="<?php echo esc_url(admin_url('admin.php?page=echo-catalog-manager&tab=mapping&supplier='.$key)); ?>" <?php selected($selected,$key); ?>><?php echo esc_html($supplier['name']); ?></option><?php endforeach;?></select></label><div><span class="echo-eyebrow">SOURCE</span><strong><?php echo esc_html($connection['base_url']?:'Not configured'); ?></strong></div></div>
        <div class="echo-manager-card"><div class="echo-card-row"><div><h2>Smart Field Mapping</h2><p>Echo samples a public JSON or REST source and suggests which supplier fields belong in WooCommerce.</p></div><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="echo_platform_analyze_mapping"><input type="hidden" name="supplier" value="<?php echo esc_attr($selected); ?>"><?php wp_nonce_field('echo_platform_analyze_mapping'); ?><button class="button button-primary">Analyze Source Fields</button></form></div>
        <?php if(empty($mapping)):?><div class="echo-empty">No mapping analysis yet. Configure and test a JSON or REST source under API Connections, then analyze it here.</div><?php else:?><p>Analyzed <?php echo esc_html($mapping['analyzed_at']); ?> · <?php echo esc_html(count($mapping['fields']??array())); ?> source fields detected.</p><table class="echo-table"><thead><tr><th>WooCommerce field</th><th>Suggested source field</th><th>Confidence</th><th>Decision</th></tr></thead><tbody><?php foreach(($mapping['suggestions']??array()) as $target=>$suggestion):?><tr><td><strong><?php echo esc_html(ucwords(str_replace('_',' ',$target))); ?></strong></td><td><code><?php echo esc_html($suggestion['source']?:'No match found'); ?></code></td><td><?php echo esc_html((int)$suggestion['confidence']); ?>%</td><td><?php echo (int)$suggestion['confidence']>=90?'<span class="echo-status echo-status-complete">Strong</span>':'<span class="echo-status echo-status-review">Review</span>'; ?></td></tr><?php endforeach;?></tbody></table><p><strong>Safety:</strong> These are suggestions only. Import preview and approval remain required before product updates.</p><?php endif;?></div>
        <?php
    }
    private function tab_previews(): void {
        $run_id=absint($_GET['run_id']??0); $runs=Echo_Platform_Sync_Engine::runs(30);
        if(!$run_id && $runs) $run_id=(int)$runs[0]['id'];
        echo '<div class="echo-card-head"><div><h2>Sync Preview</h2><p>Nothing changes here. Select only the supplier changes you want sent to Review Center.</p></div></div>';
        if(!$runs){echo '<div class="echo-empty">No supplier previews yet. Open Sync Center and queue a supplier preview.</div>';return;}
        echo '<div class="echo-toolbar-card"><label>Preview run<select onchange="location.href=this.value">';foreach($runs as $r){$url=admin_url('admin.php?page=echo-catalog-manager&tab=previews&run_id='.(int)$r['id']);echo '<option value="'.esc_url($url).'" '.selected($run_id,(int)$r['id'],false).'>#'.(int)$r['id'].' · '.esc_html($r['supplier']).' · '.esc_html($r['status']).'</option>';}echo '</select></label></div>';
        $rows=Echo_Platform_Sync_Engine::previews($run_id,500);if(!$rows){echo '<div class="echo-empty">This preview is still processing or contains no changes. Check Background Jobs.</div>';return;}
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="echo_sync_apply_preview"><input type="hidden" name="run_id" value="'.absint($run_id).'">';wp_nonce_field('echo_sync_apply_preview');
        echo '<div class="echo-bulk-bar"><label><input type="checkbox" id="echo-sync-all"> Select actionable changes</label><button class="button button-primary">Send Selected to Review Center</button></div><div class="echo-table-wrap"><table class="widefat striped"><thead><tr><th></th><th>Type</th><th>Product</th><th>SKU / Key</th><th>Confidence</th><th>Status</th></tr></thead><tbody>';
        foreach($rows as $r){$after=json_decode((string)$r['after_value'],true)?:array();$actionable=in_array($r['change_type'],array('new','update'),true);echo '<tr><td>'.($actionable?'<input class="echo-sync-check" type="checkbox" name="preview_ids[]" value="'.absint($r['id']).'">':'').'</td><td><span class="echo-pill">'.esc_html($r['change_type']).'</span></td><td><strong>'.esc_html($after['name']??'Unnamed product').'</strong></td><td><code>'.esc_html($r['item_key']).'</code></td><td>'.esc_html(round((float)$r['confidence'])).'%</td><td>'.esc_html($r['status']).'</td></tr>';}
        echo '</tbody></table></div></form><script>document.addEventListener("DOMContentLoaded",function(){var a=document.getElementById("echo-sync-all");if(a)a.addEventListener("change",function(){document.querySelectorAll(".echo-sync-check").forEach(function(c){c.checked=a.checked;});});});</script>';
    }

    private function tab_synchistory(): void {
        $runs=Echo_Platform_Sync_Engine::runs(100);echo '<div class="echo-card-head"><div><h2>Sync History</h2><p>Every supplier preview is logged before any catalog decision is made.</p></div></div>';
        if(!$runs){echo '<div class="echo-empty">No supplier sync history yet.</div>';return;}
        echo '<div class="echo-table-wrap"><table class="widefat striped"><thead><tr><th>#</th><th>Supplier</th><th>Status</th><th>Total</th><th>New</th><th>Changed</th><th>Unchanged</th><th>Errors</th><th>Created</th></tr></thead><tbody>';
        foreach($runs as $r)echo '<tr><td><a href="'.esc_url(admin_url('admin.php?page=echo-catalog-manager&tab=previews&run_id='.(int)$r['id'])).'">#'.(int)$r['id'].'</a></td><td>'.esc_html($r['supplier']).'</td><td>'.esc_html($r['status']).'</td><td>'.(int)$r['total_items'].'</td><td>'.(int)$r['new_items'].'</td><td>'.(int)$r['changed_items'].'</td><td>'.(int)$r['unchanged_items'].'</td><td>'.(int)$r['error_items'].'</td><td>'.esc_html($r['created_at']).'</td></tr>';
        echo '</tbody></table></div>';
    }

    private function tab_modules(): void { Echo_Platform_OS_UI::modules(); }

    public function add_supplier(): void {
        $this->guard( 'echo_catalog_add_supplier' );
        $name = sanitize_text_field( wp_unslash($_POST['name'] ?? '') );
        $website = esc_url_raw( wp_unslash($_POST['website'] ?? '') );
        $prefix = strtoupper( preg_replace('/[^A-Z0-9-]/i','', wp_unslash($_POST['prefix'] ?? '') ) );
        $slug = sanitize_title( wp_unslash($_POST['slug'] ?? $name) );
        if ( ! $name || ! $website || ! $prefix ) $this->redirect('suppliers','Supplier name, website, and SKU prefix are required.','error');
        if ( substr($prefix,-1) !== '-' ) $prefix .= '-';
        $custom = get_option(self::OPTION,array());
        $custom[$slug] = array('name'=>$name,'website'=>$website,'prefix'=>$prefix,'slug'=>$slug,'logo'=>esc_url_raw(wp_unslash($_POST['logo'] ?? '')),'tool'=>'');
        update_option(self::OPTION,$custom,false);
        $this->redirect('suppliers','Supplier added.');
    }

    public function import_products(): void {
        $this->guard( 'echo_catalog_import_products' );
        if ( ! function_exists('wc_get_product_id_by_sku') ) $this->redirect('products','WooCommerce is not active.','error');
        $key = sanitize_key($_POST['supplier'] ?? ''); $suppliers=$this->suppliers();
        if ( ! isset($suppliers[$key]) ) $this->redirect('products','Choose a valid supplier.','error');
        if ( empty($_FILES['catalog_csv']['tmp_name']) || ! is_uploaded_file($_FILES['catalog_csv']['tmp_name']) ) $this->redirect('products','Choose a CSV file.','error');
        $fh=fopen($_FILES['catalog_csv']['tmp_name'],'r'); if(!$fh)$this->redirect('products','Could not read the CSV.','error');
        $headers=fgetcsv($fh); if(!$headers){fclose($fh);$this->redirect('products','CSV is empty.','error');}
        $headers=array_map(fn($h)=>sanitize_key(trim((string)$h)),$headers); $created=0;$updated=0;$failed=0;
        while(($row=fgetcsv($fh))!==false){$data=array_combine($headers,array_pad($row,count($headers),'')); if(!$data){$failed++;continue;} $name=sanitize_text_field($data['name']??'');$sku=sanitize_text_field($data['sku']??'');if(!$name){$failed++;continue;}if(!$sku)$sku=$suppliers[$key]['prefix'].strtoupper(substr(md5($name),0,10));
            try{$id=wc_get_product_id_by_sku($sku);$product=$id?wc_get_product($id):new WC_Product_Simple();if(!$product){$failed++;continue;}$is_new=!$id;$product->set_name($name);$product->set_sku($sku);$product->set_status($is_new&&!empty($_POST['publish_new'])?'publish':($is_new?'draft':$product->get_status()));
                if(isset($data['description']))$product->set_description(wp_kses_post($data['description']));if(isset($data['short_description']))$product->set_short_description(wp_kses_post($data['short_description']));
                $regular=$data['regular_price']??($data['price']??'');$sale=$data['sale_price']??'';if($regular!==''&&is_numeric($regular))$product->set_regular_price(wc_format_decimal($regular));if($sale!==''&&is_numeric($sale))$product->set_sale_price(wc_format_decimal($sale));
                if(!empty($data['stock_status'])&&in_array($data['stock_status'],array('instock','outofstock','onbackorder'),true))$product->set_stock_status($data['stock_status']);$id=$product->save();
                update_post_meta($id,'_echo_supplier',$suppliers[$key]['name']);update_post_meta($id,'_echo_brand',$suppliers[$key]['name']);if(!empty($data['source_url']))update_post_meta($id,'_echo_source_url',esc_url_raw($data['source_url']));if(!empty($data['fitment_type']))update_post_meta($id,'_echo_fitment_type',sanitize_key($data['fitment_type']));if(!empty($data['fitment_raw']))update_post_meta($id,'_echo_fitment_raw',sanitize_textarea_field($data['fitment_raw']));
                if(!empty($data['category'])){$term=wp_insert_term(sanitize_text_field($data['category']),'product_cat');$term_id=is_wp_error($term)?get_term_by('name',sanitize_text_field($data['category']),'product_cat')->term_id:($term['term_id']??0);if($term_id)wp_set_object_terms($id,array((int)$term_id),'product_cat',true);}
                if(!empty($_POST['download_images'])&&!get_post_thumbnail_id($id)&&!empty($data['image_url']))$this->sideload(esc_url_raw($data['image_url']),$id,$name);
                $is_new?$created++:$updated++;
            }catch(Throwable $e){$failed++;}
        } fclose($fh); $this->redirect('products',sprintf('Import complete: %d created, %d updated, %d failed.',$created,$updated,$failed),$failed?'warning':'success');
    }

    public function export_template(): void {
        $this->guard( 'echo_catalog_export_template' );
        nocache_headers();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename=echo-catalog-import-template.csv');
        $out=fopen('php://output','w');fputcsv($out,array('name','sku','price','regular_price','sale_price','description','short_description','category','image_url','source_url','fitment_type','fitment_raw','stock_status'));fputcsv($out,array('Example Product','EXP-001','99.99','99.99','','Long description','Short description','Turbo & Boost','https://example.com/image.jpg','https://example.com/product','universal','Universal; verify dimensions','instock'));fclose($out);exit;
    }

    public function notices(): void {
        if(empty($_GET['echo_catalog_notice']))return;$type=sanitize_key($_GET['echo_catalog_type']??'success');$allowed=array('success','error','warning','info');if(!in_array($type,$allowed,true))$type='info';echo '<div class="notice notice-'.esc_attr($type).' is-dismissible"><p>'.esc_html(wp_unslash($_GET['echo_catalog_notice'])).'</p></div>';
    }

    private function settings(): array {
        return wp_parse_args((array)get_option('echo_platform_settings_v1',array()),array(
            'support_email'=>'accounts@echomotorworks.com',
            'response_time'=>'Within 1 business day',
            'new_product_status'=>'draft',
            'show_universal'=>true,
        ));
    }

    private function request_count(string $status): int {
        $q=new WP_Query(array('post_type'=>'em_part_request','post_status'=>array('publish','private','draft'),'posts_per_page'=>1,'fields'=>'ids','meta_query'=>array(array('key'=>'_echo_request_status','value'=>$status))));
        if($status==='open'){
            $legacy=new WP_Query(array('post_type'=>'em_part_request','post_status'=>array('publish','private','draft'),'posts_per_page'=>1,'fields'=>'ids','meta_query'=>array('relation'=>'OR',array('key'=>'_echo_request_status','compare'=>'NOT EXISTS'),array('key'=>'_echo_request_status','value'=>''))));
            return (int)$q->found_posts+(int)$legacy->found_posts;
        }
        return (int)$q->found_posts;
    }

    private function tab_intake(): void {
        if ( function_exists( 'echo_motorworks_core' ) && isset( echo_motorworks_core()->supplier_intake ) ) {
            echo_motorworks_core()->supplier_intake->render();
            return;
        }
        echo '<div class="notice notice-error"><p>Supplier Intake module is unavailable.</p></div>';
    }

    private function suppliers(): array {
        $built=array(
            'mabotech'=>array('name'=>'Mabotech','website'=>'https://mabotech.net','prefix'=>'MAB-','slug'=>'mabotech','tool'=>'admin.php?page=echo-mabotech-builder'),
            'leistune'=>array('name'=>'Leistune','website'=>'https://leistune.com','prefix'=>'LEI-','slug'=>'leistune','tool'=>'admin.php?page=echo-leistune-builder'),
            'eldoc'=>array('name'=>'El Doc Solutions','website'=>'https://eldocsolutions.com','prefix'=>'ELD-','slug'=>'el-doc-solutions','tool'=>'admin.php?page=echo-eldoc-builder'),
            'flf'=>array('name'=>'FLF Racing Supply','website'=>'https://www.finishlinefactory.com','prefix'=>'FLF-','slug'=>'flf-racing-supply','tool'=>'admin.php?page=echo-flf-builder'),
            'ats'=>array('name'=>'Applied Torque Solutions','website'=>'https://appliedtorquesolutions.com','prefix'=>'ATS-','slug'=>'applied-torque-solutions','tool'=>'admin.php?page=echo-motorworks-fitment'),
            'evilenergy'=>array('name'=>'EVIL ENERGY','website'=>'https://www.ievilenergy.com','prefix'=>'EVE-','slug'=>'evil-energy','tool'=>'admin.php?page=echo-evilenergy-builder'),
            'pds'=>array('name'=>'Pure Drivetrain Solutions','website'=>'https://www.puredrivetrainsolutions.com','prefix'=>'PDS-','slug'=>'pure-drivetrain-solutions','tool'=>'admin.php?page=echo-pds-builder'),
        );
        return array_merge($built,(array)get_option(self::OPTION,array()));
    }

    private function health(): array {
        global $wpdb;$products=(int)wp_count_posts('product')->publish+(int)wp_count_posts('product')->draft+(int)wp_count_posts('product')->private;$drafts=(int)wp_count_posts('product')->draft;
        $with_images=(int)$wpdb->get_var("SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_thumbnail_id' WHERE p.post_type='product' AND p.post_status IN ('publish','draft','private')");
        $universal=(int)$wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key='_echo_fitment_type' AND meta_value IN ('universal','universal_restricted','universal-with-restrictions')");
        $review=(int)$wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key='_echo_fitment_type' AND meta_value IN ('needs_review','unknown','review')");
        $fit_table=class_exists('Echo_Motorworks_DB')?Echo_Motorworks_DB::fitment_table():'';$fitted=$fit_table?(int)$wpdb->get_var("SELECT COUNT(DISTINCT product_id) FROM {$fit_table} WHERE fitment_status IN ('confirmed','conditional')"):0;$covered=min($products,$fitted+$universal);
        return array('products'=>$products,'drafts'=>$drafts,'missing_images'=>max(0,$products-$with_images),'image_pct'=>$products?round(100*$with_images/$products,1):100,'universal'=>$universal,'review'=>$review,'missing_fitment'=>max(0,$products-$covered),'fitment_pct'=>$products?round(100*$covered/$products,1):100);
    }

    private function supplier_product_count(array $s): int {global $wpdb;$like=$wpdb->esc_like($s['name']);$prefix=$wpdb->esc_like($s['prefix']).'%';return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='_echo_supplier' LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id=p.ID AND sku.meta_key='_sku' WHERE p.post_type='product' AND p.post_status IN ('publish','draft','private') AND (m.meta_value=%s OR sku.meta_value LIKE %s)",$s['name'],$prefix));}
    private function metric(string $label,$value,string $note): void {echo '<div class="echo-manager-card"><h3>'.esc_html($label).'</h3><div class="echo-metric">'.esc_html((string)$value).'</div><p>'.esc_html($note).'</p></div>';}
    private function guard(string $action): void {if(!current_user_can('manage_woocommerce'))wp_die('Not allowed.');check_admin_referer($action);}
    private function redirect(string $tab,string $message,string $type='success'): void {wp_safe_redirect(add_query_arg(array('page'=>'echo-catalog-manager','tab'=>$tab,'echo_catalog_notice'=>$message,'echo_catalog_type'=>$type),admin_url('admin.php')));exit;}
    private function sideload(string $url,int $post_id,string $name): void {if(!$url||!wp_http_validate_url($url))return;require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/media.php';require_once ABSPATH.'wp-admin/includes/image.php';$tmp=download_url($url,45);if(is_wp_error($tmp))return;$path=parse_url($url,PHP_URL_PATH);$filename=sanitize_file_name(basename($path?:''));if(!$filename||!preg_match('/\.(jpe?g|png|webp|gif)$/i',$filename))$filename=sanitize_file_name($name).'.jpg';$file=array('name'=>$filename,'tmp_name'=>$tmp);$id=media_handle_sideload($file,$post_id,$name);if(is_wp_error($id)){@unlink($tmp);return;}set_post_thumbnail($post_id,$id);}

    private function tab_diagnostics(): void {
        if ( ! class_exists( 'Echo_Motorworks_Diagnostics' ) ) {
            echo '<div class="notice notice-error"><p>Diagnostics module is unavailable.</p></div>';
            return;
        }

        $diagnostics = new Echo_Motorworks_Diagnostics();
        $summary = $diagnostics->summary();
        $supplier_health = $diagnostics->supplier_health();

        $vehicle_args = array(
            'year'   => isset( $_GET['diag_year'] ) ? absint( $_GET['diag_year'] ) : 0,
            'make'   => isset( $_GET['diag_make'] ) ? sanitize_text_field( wp_unslash( $_GET['diag_make'] ) ) : '',
            'model'  => isset( $_GET['diag_model'] ) ? sanitize_text_field( wp_unslash( $_GET['diag_model'] ) ) : '',
            'engine' => isset( $_GET['diag_engine'] ) ? sanitize_text_field( wp_unslash( $_GET['diag_engine'] ) ) : '',
        );
        $vehicle_id = isset( $_GET['diag_vehicle_id'] ) ? absint( $_GET['diag_vehicle_id'] ) : 0;
        $vehicles = array_filter( $vehicle_args ) ? $diagnostics->vehicle_search( $vehicle_args ) : array();
        $selected_vehicle = null;
        if ( $vehicle_id ) {
            global $wpdb;
            $selected_vehicle = $wpdb->get_row(
                $wpdb->prepare( 'SELECT * FROM ' . Echo_Motorworks_DB::vehicles_table() . ' WHERE id = %d', $vehicle_id ),
                ARRAY_A
            );
        }
        $inspection = $selected_vehicle ? $diagnostics->inspect_vehicle( $selected_vehicle ) : null;

        $product_query = isset( $_GET['diag_product'] ) ? sanitize_text_field( wp_unslash( $_GET['diag_product'] ) ) : '';
        $product = $product_query ? $diagnostics->inspect_product( $product_query ) : array();
        ?>
        <style>
            .echo-control-hero{background:#111827;color:#fff;border-radius:10px;padding:22px;margin:0 0 18px}.echo-control-hero h2{color:#fff;font-size:26px;margin:0 0 6px}.echo-control-hero p{color:#cbd5e1;margin:0}.echo-health-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:16px 0}.echo-health-tile{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:15px}.echo-health-tile strong{display:block;font-size:25px;line-height:1.1}.echo-health-tile span{display:block;color:#646970;margin-top:5px}.echo-inspector{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;margin:18px 0}.echo-inspector h2{margin-top:0}.echo-inspector-grid{display:grid;grid-template-columns:repeat(4,minmax(130px,1fr));gap:10px}.echo-inspector-grid input,.echo-inspector-grid select{width:100%}.echo-result-group{margin:18px 0}.echo-result-group h3{display:flex;align-items:center;gap:8px}.echo-count-pill{display:inline-block;background:#f0f0f1;border-radius:999px;padding:3px 9px;font-size:12px}.echo-diagnostic-reason{color:#50575e}.echo-checks{font-size:12px;color:#646970}.echo-badge-exact{background:#d1e7dd;color:#0f5132}.echo-badge-could{background:#fff3cd;color:#664d03}.echo-badge-rejected{background:#f8d7da;color:#842029}.echo-diag-badge{display:inline-block;border-radius:999px;padding:4px 9px;font-weight:700;font-size:12px}@media(max-width:782px){.echo-inspector-grid{grid-template-columns:1fr 1fr}}
        </style>
        <div class="echo-control-hero">
            <h2>Echo Control — Read-Only Diagnostics</h2>
            <p>Inspect store health, supplier coverage, vehicle matching, and product fitment without modifying live data.</p>
        </div>

        <div class="echo-health-grid">
            <?php $this->diagnostic_tile( 'Products', $summary['products'], 'Published catalog' ); ?>
            <?php $this->diagnostic_tile( 'Vehicle records', $summary['vehicles'], 'Saved lookup configurations' ); ?>
            <?php $this->diagnostic_tile( 'Fitment rows', $summary['fitment_rows'], 'Exact and conditional relationships' ); ?>
            <?php $this->diagnostic_tile( 'Products with fitment', $summary['fitment_products'], 'Linked to at least one vehicle row' ); ?>
            <?php $this->diagnostic_tile( 'Universal', $summary['universal'], 'Size/spec-based products' ); ?>
            <?php $this->diagnostic_tile( 'Needs review', $summary['needs_review'], 'Missing or uncertain classification' ); ?>
            <?php $this->diagnostic_tile( 'Missing images', $summary['missing_images'], 'No featured image' ); ?>
            <?php $this->diagnostic_tile( 'Missing descriptions', $summary['missing_description'], 'No long description' ); ?>
            <?php $this->diagnostic_tile( 'Missing categories', $summary['missing_category'], 'No useful product category' ); ?>
            <?php $this->diagnostic_tile( 'Missing brands', $summary['missing_brand'], 'No WooCommerce brand term' ); ?>
            <?php $this->diagnostic_tile( 'Duplicate SKUs', $summary['duplicate_skus'], 'Duplicate SKU values' ); ?>
        </div>

        <div class="echo-inspector">
            <h2>Fitment Inspector</h2>
            <p>Search the vehicle records currently stored by Echo. Select a result to see which fitment rows match, require confirmation, or are rejected.</p>
            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                <input type="hidden" name="page" value="echo-catalog-manager"><input type="hidden" name="tab" value="diagnostics">
                <div class="echo-inspector-grid">
                    <label>Year<input type="number" min="1900" max="2100" name="diag_year" value="<?php echo esc_attr( $vehicle_args['year'] ?: '' ); ?>" placeholder="2015"></label>
                    <label>Make<input type="text" name="diag_make" value="<?php echo esc_attr( $vehicle_args['make'] ); ?>" placeholder="Volkswagen"></label>
                    <label>Model<input type="text" name="diag_model" value="<?php echo esc_attr( $vehicle_args['model'] ); ?>" placeholder="GTI"></label>
                    <label>Engine (optional)<input type="text" name="diag_engine" value="<?php echo esc_attr( $vehicle_args['engine'] ); ?>" placeholder="2.0L"></label>
                </div>
                <p><button class="button button-primary">Find stored vehicle</button></p>
            </form>

            <?php if ( array_filter( $vehicle_args ) ) : ?>
                <h3>Stored vehicle records <span class="echo-count-pill"><?php echo esc_html( count( $vehicles ) ); ?></span></h3>
                <?php if ( ! $vehicles ) : ?><div class="echo-empty">No stored vehicle record matched. Save this vehicle through Shop by Vehicle first, then inspect it again.</div><?php else : ?>
                    <table class="echo-table"><thead><tr><th>Vehicle</th><th>Engine / transmission</th><th>Source</th><th></th></tr></thead><tbody>
                    <?php foreach ( $vehicles as $vehicle ) : ?>
                        <tr><td><strong><?php echo esc_html( $vehicle['year'] . ' ' . $vehicle['make'] . ' ' . $vehicle['model'] ); ?></strong><br><?php echo esc_html( $vehicle['submodel'] ); ?></td><td><?php echo esc_html( $vehicle['engine'] ?: 'Engine not stored' ); ?><br><?php echo esc_html( $vehicle['transmission'] ); ?></td><td><?php echo esc_html( $vehicle['source'] . ' / ' . $vehicle['source_vehicle_id'] ); ?></td><td><a class="button" href="<?php echo esc_url( add_query_arg( array_merge( array( 'page'=>'echo-catalog-manager','tab'=>'diagnostics','diag_vehicle_id'=>$vehicle['id'] ), array_filter( $vehicle_args ) ), admin_url( 'admin.php' ) ) ); ?>">Inspect matches</a></td></tr>
                    <?php endforeach; ?>
                    </tbody></table>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ( $selected_vehicle && $inspection ) : ?>
                <div class="echo-result-group"><h3><?php echo esc_html( $selected_vehicle['year'] . ' ' . $selected_vehicle['make'] . ' ' . $selected_vehicle['model'] ); ?></h3><p class="echo-diagnostic-reason"><?php echo esc_html( $selected_vehicle['engine'] ); ?> · <?php echo esc_html( $selected_vehicle['transmission'] ); ?> · <?php echo esc_html( $selected_vehicle['drivetrain'] ); ?></p></div>
                <?php $this->diagnostic_fitment_table( 'Exact matches', $inspection['exact'], 'exact' ); ?>
                <?php $this->diagnostic_fitment_table( 'Could fit / confirmation needed', $inspection['could'], 'could' ); ?>
                <?php $this->diagnostic_fitment_table( 'Rejected candidate rows', $inspection['rejected'], 'rejected' ); ?>
                <p><strong>Universal catalog:</strong> <?php echo esc_html( $inspection['universal_count'] ); ?> published products. These are separate from vehicle-specific fitment.</p>
            <?php endif; ?>
        </div>

        <div class="echo-inspector">
            <h2>Product Inspector</h2>
            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                <input type="hidden" name="page" value="echo-catalog-manager"><input type="hidden" name="tab" value="diagnostics">
                <p><label>Product ID or exact SKU<br><input type="text" name="diag_product" value="<?php echo esc_attr( $product_query ); ?>" placeholder="MAB-INT-015" style="width:min(480px,100%)"></label> <button class="button button-primary">Inspect product</button></p>
            </form>
            <?php if ( $product_query ) : ?>
                <?php if ( ! $product ) : ?><div class="echo-empty">No product found for that ID or exact SKU.</div><?php else : ?>
                    <div class="echo-health-grid">
                        <?php $this->diagnostic_tile( 'Product ID', $product['id'], $product['name'] ); ?>
                        <?php $this->diagnostic_tile( 'SKU', $product['sku'] ?: 'Missing', $product['supplier'] ?: 'Supplier unassigned' ); ?>
                        <?php $this->diagnostic_tile( 'Fitment type', $product['fitment_type'] ?: 'Missing', count( $product['fitment_rows'] ) . ' vehicle rows' ); ?>
                        <?php $this->diagnostic_tile( 'Image', $product['has_image'] ? 'Yes' : 'Missing', $product['has_description'] ? 'Description present' : 'Description missing' ); ?>
                    </div>
                    <?php if ( $product['fitment_rows'] ) : ?><table class="echo-table"><thead><tr><th>Years</th><th>Vehicle</th><th>Restrictions</th><th>Status</th><th>Source</th></tr></thead><tbody><?php foreach ( $product['fitment_rows'] as $row ) : ?><tr><td><?php echo esc_html( trim( $row['year_start'] . '–' . $row['year_end'], '–' ) ); ?></td><td><?php echo esc_html( $row['make'] . ' ' . $row['model'] ); ?><br><?php echo esc_html( $row['chassis'] ); ?></td><td><?php echo esc_html( implode( ' · ', array_filter( array( $row['engine'], $row['transmission'], $row['drivetrain'] ) ) ) ?: 'None' ); ?></td><td><?php echo esc_html( $row['fitment_status'] ); ?><br><small><?php echo esc_html( $row['fitment_notes'] ); ?></small></td><td><?php echo esc_html( $row['supplier'] . ' / ' . $row['source'] ); ?></td></tr><?php endforeach; ?></tbody></table><?php else : ?><div class="echo-empty">This product has no rows in Echo’s vehicle-fitment table.</div><?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="echo-inspector">
            <h2>Supplier Health</h2>
            <table class="echo-table"><thead><tr><th>Supplier</th><th>Products</th><th>Health</th><th>Missing images</th><th>Missing descriptions</th><th>Missing fitment type</th></tr></thead><tbody>
            <?php foreach ( $supplier_health as $supplier ) : ?><tr><td><strong><?php echo esc_html( $supplier['supplier'] ); ?></strong></td><td><?php echo esc_html( $supplier['products'] ); ?></td><td><?php echo esc_html( $supplier['health_pct'] ); ?>%</td><td><?php echo esc_html( $supplier['missing_images'] ); ?></td><td><?php echo esc_html( $supplier['missing_descriptions'] ); ?></td><td><?php echo esc_html( $supplier['missing_fitment'] ); ?></td></tr><?php endforeach; ?>
            </tbody></table>
        </div>
        <?php
    }

    private function diagnostic_tile( string $label, $value, string $detail ): void {
        ?><div class="echo-health-tile"><strong><?php echo esc_html( (string) $value ); ?></strong><span><?php echo esc_html( $label ); ?></span><small><?php echo esc_html( $detail ); ?></small></div><?php
    }

    private function diagnostic_fitment_table( string $title, array $rows, string $type ): void {
        $class = 'echo-badge-' . sanitize_key( $type );
        ?><div class="echo-result-group"><h3><?php echo esc_html( $title ); ?> <span class="echo-count-pill"><?php echo esc_html( count( $rows ) ); ?></span></h3><?php if ( ! $rows ) : ?><div class="echo-empty">None found.</div><?php else : ?><table class="echo-table"><thead><tr><th>Product</th><th>Supplier data</th><th>Diagnostic result</th><th>Stored checks</th></tr></thead><tbody><?php foreach ( $rows as $row ) : ?><tr><td><strong><?php echo esc_html( $row['post_title'] ?: 'Missing product #' . $row['product_id'] ); ?></strong><br><code><?php echo esc_html( $row['product_sku'] ); ?></code><br><small>Status: <?php echo esc_html( $row['post_status'] ?: 'product missing' ); ?></small></td><td><?php echo esc_html( trim( $row['year_start'] . '–' . $row['year_end'], '–' ) . ' ' . $row['make'] . ' ' . $row['model'] ); ?><br><?php echo esc_html( implode( ' · ', array_filter( array( $row['engine'], $row['transmission'], $row['drivetrain'] ) ) ) ?: 'No restrictions' ); ?><br><small><?php echo esc_html( $row['fitment_notes'] ); ?></small></td><td><span class="echo-diag-badge <?php echo esc_attr( $class ); ?>"><?php echo esc_html( ucfirst( $type ) ); ?></span><p class="echo-diagnostic-reason"><?php echo esc_html( $row['diagnostic_reason'] ); ?></p></td><td class="echo-checks"><?php foreach ( $row['diagnostic_checks'] as $check => $value ) : ?><strong><?php echo esc_html( ucfirst( $check ) ); ?>:</strong> <?php echo esc_html( $value ); ?><br><?php endforeach; ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div><?php
    }

    private function tab_review(): void { Echo_Platform_OS_UI::review_center(); }
    private function tab_automation(): void { Echo_Platform_OS_UI::automation_rules(); }
    private function tab_notifications(): void { Echo_Platform_OS_UI::notifications(); }
    private function tab_activity(): void { Echo_Platform_OS_UI::activity(); }
    private function tab_developer(): void { Echo_Platform_OS_UI::developer(); }

}
