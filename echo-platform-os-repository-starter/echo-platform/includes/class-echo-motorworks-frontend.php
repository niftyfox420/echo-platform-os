<?php

defined( 'ABSPATH' ) || exit;

final class Echo_Motorworks_Frontend {
    private Echo_Motorworks_API $api;
    private Echo_Motorworks_Garage $garage;
    private Echo_Motorworks_Fitment $fitment;

    public function __construct( Echo_Motorworks_API $api, Echo_Motorworks_Garage $garage, Echo_Motorworks_Fitment $fitment ) {
        $this->api = $api;
        $this->garage = $garage;
        $this->fitment = $fitment;

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_shortcode( 'echo_vehicle_finder', array( $this, 'shortcode_vehicle_finder' ) );
        add_action( 'woocommerce_before_shop_loop', array( $this, 'render_shop_vehicle_notice' ), 7 );
        add_action( 'woocommerce_single_product_summary', array( $this, 'render_single_fitment_badge' ), 11 );
        add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'render_loop_fitment_badge' ), 7 );
        add_filter( 'woocommerce_account_menu_items', array( $this, 'account_menu_item' ) );
        add_action( 'init', array( $this, 'register_account_endpoint' ) );
        add_action( 'woocommerce_account_my-garage_endpoint', array( $this, 'render_account_garage' ) );
        add_filter( 'query_vars', array( $this, 'account_query_var' ) );
    }

    public function enqueue_assets(): void {
        if ( is_admin() ) {
            return;
        }
        wp_enqueue_style(
            'echo-motorworks-vehicle-finder',
            ECHO_MOTORWORKS_CORE_URL . 'assets/css/vehicle-finder.css',
            array(),
            ECHO_MOTORWORKS_CORE_VERSION
        );
        wp_enqueue_script(
            'echo-motorworks-vehicle-finder',
            ECHO_MOTORWORKS_CORE_URL . 'assets/js/vehicle-finder.js',
            array(),
            ECHO_MOTORWORKS_CORE_VERSION,
            true
        );

        $active = $this->garage->get_active_vehicle();
        wp_localize_script(
            'echo-motorworks-vehicle-finder',
            'EchoVehicleFinder',
            array(
                'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
                'version'       => ECHO_MOTORWORKS_CORE_VERSION,
                'nonce'         => wp_create_nonce( 'echo_vehicle_lookup' ),
                'shopUrl'       => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ),
                'universalUrl'  => add_query_arg( 'em_fitment', 'universal', function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ) ),
                'isLoggedIn'    => is_user_logged_in(),
                'activeVehicle' => $active ? $this->garage->public_vehicle( $active ) : null,
                'accountGarage' => is_user_logged_in() ? $this->garage->get_user_garage( get_current_user_id() ) : array(),
                'strings'       => array(
                    'loading'       => 'Loading vehicle data…',
                    'error'         => 'Vehicle data could not be loaded. Please try again.',
                    'saved'         => 'Saved to My Garage.',
                    'noFitment'     => 'No products have verified fitment for this vehicle yet.',
                    'selectYear'    => 'Select year',
                    'selectMake'    => 'Select make',
                    'selectModel'   => 'Select model',
                    'selectOption'  => 'Select engine / option',
                ),
            )
        );
    }

    public function shortcode_vehicle_finder( array $atts = array() ): string {
        $atts = shortcode_atts( array( 'compact' => 'no' ), $atts, 'echo_vehicle_finder' );
        ob_start();
        ?>
        <div class="echo-vehicle-finder<?php echo 'yes' === $atts['compact'] ? ' is-compact' : ''; ?>" data-echo-vehicle-finder data-echo-version="<?php echo esc_attr( ECHO_MOTORWORKS_CORE_VERSION ); ?>">
            <div class="echo-garage-summary" data-echo-garage-summary hidden></div>
            <form class="em-static-finder echo-vehicle-form" action="<?php echo esc_url( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ) ); ?>" method="get" novalidate>
                <label><span>Year</span><select name="year" data-echo-field="year"><option value="">Select year</option></select></label>
                <label><span>Make</span><select name="make" data-echo-field="make" disabled><option value="">Select make</option></select></label>
                <label><span>Model</span><select name="model" data-echo-field="model" disabled><option value="">Select model</option></select></label>
                <label><span>Engine / Trim</span><select name="enginetrim" data-echo-field="option" disabled><option value="">Select engine / option</option></select></label>
                <input type="hidden" name="echo_vehicle_id" value="" data-echo-internal-id>
                <div class="echo-finder-actions">
                    <button class="btn btn-primary" type="submit" data-echo-show-parts disabled>Show Compatible Parts</button>
                    <button class="btn btn-secondary echo-save-vehicle" type="button" data-echo-save disabled>Save to My Garage</button>
                    <a class="btn btn-ghost echo-shop-universal" href="<?php echo esc_url( add_query_arg( 'em_fitment', 'universal', function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ) ) ); ?>">Shop Universal Parts</a>
                </div>
                <p class="fitment-note" data-echo-status aria-live="polite">Vehicle list supplied by U.S. government data. Product matches require verified supplier fitment.</p>
            </form>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function render_shop_vehicle_notice(): void {
        $vehicle_id = isset( $_GET['echo_vehicle_id'] ) ? absint( $_GET['echo_vehicle_id'] ) : 0;
        if ( ! $vehicle_id ) return;
        $vehicle = $this->garage->get_vehicle( $vehicle_id );
        if ( ! $vehicle ) return;

        $exact_count     = count( $this->fitment->get_vehicle_product_ids_by_status( $vehicle, array( 'confirmed' ) ) );
        $could_count     = count( $this->fitment->get_vehicle_product_ids_by_status( $vehicle, array( 'conditional' ) ) );
        $universal_count = count( $this->fitment->get_universal_product_ids() );
        $current         = isset( $_GET['em_fitment_scope'] ) ? sanitize_key( wp_unslash( $_GET['em_fitment_scope'] ) ) : 'all';
        $base_url        = add_query_arg( 'echo_vehicle_id', $vehicle_id, function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ) );
        $tabs = array(
            'all'       => array( 'All compatible', $exact_count + $could_count + $universal_count ),
            'exact'     => array( 'Exact fitment', $exact_count ),
            'could'     => array( 'Could fit', $could_count ),
            'universal' => array( 'Universal', $universal_count ),
        );

        echo '<div class="echo-shop-fitment-notice echo-fitment-filter-panel">';
        printf( '<div class="echo-fitment-filter-title"><div><strong>%s</strong><span>Choose how strict the fitment results should be.</span></div><a href="%s">Change vehicle</a></div>', esc_html( sprintf( '%d %s %s', $vehicle['year'], $vehicle['make'], $vehicle['model'] ) ), esc_url( home_url( '/#search-parts' ) ) );
        echo '<nav class="echo-fitment-filter-tabs" aria-label="Fitment results">';
        foreach ( $tabs as $key => $tab ) {
            $url = 'all' === $key ? remove_query_arg( 'em_fitment_scope', $base_url ) : add_query_arg( 'em_fitment_scope', $key, $base_url );
            printf( '<a class="%s" href="%s"><strong>%s</strong><span>%d</span></a>', $current === $key ? 'is-active' : '', esc_url( $url ), esc_html( $tab[0] ), absint( $tab[1] ) );
        }
        echo '</nav><p class="echo-fitment-filter-help"><b>Exact fitment</b> is supplier-verified. <b>Could fit</b> needs a detail such as engine or transmission confirmed. <b>Universal</b> is not vehicle-specific and may require measuring or adapters.</p></div>';
    }

    public function render_single_fitment_badge(): void {
        global $product;
        if ( ! $product ) {
            return;
        }
        $this->render_badge( $this->fitment->get_product_status( $product->get_id() ), 'single' );
    }

    public function render_loop_fitment_badge(): void {
        global $product;
        if ( ! $product ) {
            return;
        }
        $this->render_badge( $this->fitment->get_product_status( $product->get_id() ), 'loop' );
    }

    private function render_badge( array $status, string $context ): void {
        printf(
            '<span class="echo-fitment-badge is-%s echo-fitment-%s">%s</span>',
            esc_attr( sanitize_html_class( $status['status'] ) ),
            esc_attr( sanitize_html_class( $context ) ),
            esc_html( $status['label'] )
        );
    }

    public function register_account_endpoint(): void {
        add_rewrite_endpoint( 'my-garage', EP_ROOT | EP_PAGES );
    }

    public function account_query_var( array $vars ): array {
        $vars[] = 'my-garage';
        return $vars;
    }

    public function account_menu_item( array $items ): array {
        $logout = $items['customer-logout'] ?? null;
        unset( $items['customer-logout'] );
        $items['my-garage'] = __( 'My Garage', 'echo-motorworks-core' );
        if ( $logout ) {
            $items['customer-logout'] = $logout;
        }
        return $items;
    }

    public function render_account_garage(): void {
        $garage = $this->garage->get_user_garage( get_current_user_id() );
        echo '<div class="echo-account-garage" data-echo-account-garage>';
        echo '<h2>My Garage</h2>';
        echo '<p>Vehicles saved here are used for fitment badges and verified shop filtering.</p>';
        if ( ! $garage ) {
            echo '<p class="echo-garage-empty">No vehicles saved yet. Use the vehicle finder to add one.</p>';
        } else {
            echo '<div class="echo-garage-list">';
            foreach ( $garage as $vehicle ) {
                printf(
                    '<article class="echo-garage-card" data-vehicle-id="%d"><div><strong>%s</strong><span>%s</span></div><div><button type="button" data-echo-activate>Use this vehicle</button><button type="button" data-echo-remove>Remove</button></div></article>',
                    absint( $vehicle['id'] ?? 0 ),
                    esc_html( $vehicle['label'] ?? '' ),
                    esc_html( $vehicle['option_label'] ?? $vehicle['engine'] ?? '' )
                );
            }
            echo '</div>';
        }
        echo '<div class="echo-account-finder">' . do_shortcode( '[echo_vehicle_finder compact="yes"]' ) . '</div>';
        echo '</div>';
    }
}
