<?php
/**
 * My Account Handling.
 *
 * @package woocommerce-eu-vat-number
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_EU_VAT_Reports class
 */
class WC_EU_VAT_My_Account {

	/**
	 * URL endpoint.
	 *
	 * @var string
	 */
	public $endpoint = 'vat-number';

	/**
	 * Success Messages.
	 *
	 * @var array
	 */
	public $messages = array();

	/**
	 * Constructor
	 */
	public function __construct() {

		// New endpoint for vat-number WC >= 2.6.
		add_action( 'init', array( $this, 'add_endpoints' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'add_query_vars' ), 0 );

		// Change My Account page title.
		add_filter( 'the_title', array( $this, 'endpoint_title' ) );

		// Inserting new tab/page into My Account page.
		add_filter( 'woocommerce_account_menu_items', array( $this, 'new_menu_items' ) );
		add_action( 'woocommerce_account_' . $this->endpoint . '_endpoint', array( $this, 'endpoint_content' ) );

		// Save a VAT number from My Account form if one is submitted.
		add_action( 'init', array( $this, 'save_vat_number' ) );

		add_action( 'woocommerce_init', array( $this, 'set_vat_session_data' ) );
		add_action( 'woocommerce_init', array( $this, 'maybe_remove_vat' ) );
	}

	/**
	 * Sets the VAT session data when the billing country is updated.
	 *
	 * @since 2.9.0
	 */
	public function set_vat_session_data() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$vat_number = get_user_meta( get_current_user_id(), 'vat_number', true );

		if ( empty( $vat_number ) || empty( WC()->customer ) ) {
			return;
		}

		$billing_country = WC()->customer->get_billing_country();
		$is_valid        = false;

		try {
			$is_valid = $this->validate( $vat_number, $billing_country );
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Ignore Exception.
		}

		if ( $is_valid && WC()->session ) {
			WC()->session->set( 'vat-number', $vat_number );
		}
	}

	/**
	 * Checks to see if we need to remove vat from displaying in the cart and from product itself.
	 *
	 * @see https://github.com/woocommerce/woocommerce-eu-vat-number/issues/71
	 * @see https://github.com/woocommerce/woocommerce-eu-vat-number/issues/74
	 * @see https://github.com/woocommerce/woocommerce-eu-vat-number/issues/233
	 */
	public function maybe_remove_vat() {
		// Ignore checkout page as on checkout page VAT exempt based on VAT number from billing fields.
		if ( ( is_admin() && ! defined( 'DOING_AJAX' ) ) || ! wc_tax_enabled() || is_checkout() || ! is_user_logged_in() ) {
			return;
		}

		$vat_number = get_user_meta( get_current_user_id(), 'vat_number', true );
		if ( empty( $vat_number ) || empty( WC()->customer ) ) {
			return;
		}

		// Validate if VAT is valid. If valid, check for VAT exempt.
		try {
			$billing_country  = WC()->customer->get_billing_country();
			$shipping_country = WC()->customer->get_shipping_country();

			if ( $this->validate( $vat_number, $billing_country ) ) {
				WC_EU_VAT_Number::maybe_set_vat_exempt( true, $billing_country, $shipping_country );
			}
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Ignore Exception.
		}
	}

	/**
	 * Register new endpoint to use inside My Account page.
	 *
	 * @since 2.1.12
	 *
	 * @see https://developer.wordpress.org/reference/functions/add_rewrite_endpoint/
	 */
	public function add_endpoints() {
		add_rewrite_endpoint( $this->endpoint, EP_ROOT | EP_PAGES );
	}

	/**
	 * Add new query var.
	 *
	 * @since 2.1.12
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function add_query_vars( $vars ) {
		$vars[] = $this->endpoint;

		return $vars;
	}

	/**
	 * Set endpoint title.
	 *
	 * @since 2.1.12
	 *
	 * @param string $title Endpoint title.
	 * @return string
	 */
	public function endpoint_title( $title ) {
		global $wp_query;

		$is_endpoint = isset( $wp_query->query_vars[ $this->endpoint ] );

		if ( $is_endpoint && ! is_admin() && is_main_query() && in_the_loop() && is_account_page() ) {
			$title = get_option( 'woocommerce_eu_vat_number_field_label', __( 'VAT number', 'woocommerce-eu-vat-number' ) );

			remove_filter( 'the_title', array( $this, 'endpoint_title' ) );
		}

		return $title;
	}

	/**
	 * Insert new endpoint into My Account menu.
	 *
	 * @since 2.1.12
	 *
	 * @param array $items Menu items.
	 * @return array Menu items.
	 */
	public function new_menu_items( $items ) {
		// Remove logout menu item.
		$logout = $items['customer-logout'];
		unset( $items['customer-logout'] );

		// Insert VAT Number.
		$items[ $this->endpoint ] = get_option( 'woocommerce_eu_vat_number_field_label', __( 'VAT number', 'woocommerce-eu-vat-number' ) );

		// Insert back logout item.
		$items['customer-logout'] = $logout;

		return $items;
	}

	/**
	 * Endpoint HTML content.
	 *
	 * @since 2.1.12
	 */
	public function endpoint_content() {
		$this->render_my_vat_number_content();
	}

	/**
	 * Render My VAT Number content.
	 *
	 * @since 2.1.12
	 */
	public function render_my_vat_number_content() {
		$vars = array(
			'vat_number' => get_user_meta( get_current_user_id(), 'vat_number', true ),
			'messages'   => $this->messages,
		);

		wc_get_template(
			'my-account/my-vat-number.php',
			$vars,
			'woocommerce-eu-vat-number',
			untrailingslashit( plugin_dir_path( WC_EU_VAT_FILE ) ) . '/templates/'
		);
	}

	/**
	 * Validate a VAT number.
	 *
	 * @version 2.3.0
	 * @since 2.3.0
	 * @param  string $vat_number       VAT number passed by the form.
	 * @param  string $billing_country  Billing country of the order.
	 * @param  string $billing_postcode Billing postcode of the order.
	 * @param  string $current_vat      VAT number saved in database.
	 *
	 * @return boolean
	 * @throws Exception For invalid VAT Number.
	 */
	public function validate( $vat_number, $billing_country, $billing_postcode = '', $current_vat = '' ) {
		if ( empty( $vat_number ) ) {
			if ( empty( $current_vat ) ) {
				throw new Exception( esc_html__( 'VAT number cannot be empty.', 'woocommerce-eu-vat-number' ) );
			}
			// Allow empty input to clear VAT field.
			return true;
		}

		if ( empty( $billing_country ) ) {
			/* translators: 1: VAT Number */
			throw new Exception(
				sprintf(
					// translators: %1$s VAT number field label.
					esc_html__( '%1$s can not be validated because the billing country is missing. Please update your billing address.', 'woocommerce-eu-vat-number' ),
					'<strong>' . esc_html( get_option( 'woocommerce_eu_vat_number_field_label', __( 'VAT number', 'woocommerce-eu-vat-number' ) ) ) . '</strong>'
				)
			);
		}

		$valid = WC_EU_VAT_Number::vat_number_is_valid( $vat_number, $billing_country, $billing_postcode );

		if ( is_wp_error( $valid ) ) {
			throw new Exception( esc_html( $valid->get_error_message() ) );
		}

		if ( ! $valid ) {
			// translators: %1$s VAT number field label, %2$s VAT number, %3$s Billing Country.
			throw new Exception(
				sprintf(
					/* translators: %1$s - VAT field label, %2$s - VAT number, %2$s - billing country */
					esc_html__( 'You have entered an invalid %1$s (%2$s) for your billing country (%3$s).', 'woocommerce-eu-vat-number' ),
					esc_html( get_option( 'woocommerce_eu_vat_number_field_label' ) ),
					esc_html__( 'VAT number', 'woocommerce-eu-vat-number' ),
					esc_html( $vat_number ),
					esc_html( $billing_country )
				)
			);
		}

		return true;
	}

	/**
	 * Function to save VAT number from the my account form.
	 */
	public function save_vat_number() {
		if ( ! ( isset( $_POST['action'] ) && 'edit_vat_number' === wc_clean( wp_unslash( $_POST['action'] ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verifying nonce inside function.
			return;
		}

		if ( isset( $_POST['_wpnonce'] ) && wp_verify_nonce( wp_unslash( $_POST['_wpnonce'] ), 'woocommerce-edit_vat_number' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			try {
				$current_user_id  = get_current_user_id();
				$vat_number       = isset( $_POST['vat_number'] ) ? wc_clean( wp_unslash( $_POST['vat_number'] ) ) : '';
				$posted_vat       = strtoupper( str_replace( array( ' ', '.', '-', ',', ', ' ), '', $vat_number ) );
				$user             = get_userdata( $current_user_id );
				$current_vat      = $user->vat_number;
				$billing_country  = $user->billing_country;
				$billing_postcode = $user->billing_postcode;

				$this->validate( $posted_vat, $billing_country, $billing_postcode, $current_vat );

				update_user_meta( $current_user_id, 'vat_number', $posted_vat );
				update_user_meta( $current_user_id, 'billing_vat_number', $posted_vat );

				if ( empty( $vat_number ) && WC()->session ) {
					WC()->session->set( 'vat-number', null );
				}

				if ( empty( $posted_vat ) ) {
					$message = __( 'VAT number removed successfully!', 'woocommerce-eu-vat-number' );
				} elseif ( empty( $current_vat ) ) {
					$message = __( 'VAT number saved successfully!', 'woocommerce-eu-vat-number' );
				} else {
					$message = __( 'VAT number updated successfully!', 'woocommerce-eu-vat-number' );
				}
				$this->messages = array(
					'message' => $message,
					'status'  => 'info',
				);
			} catch ( Exception $e ) {
				$this->messages = array(
					'message' => $e->getMessage(),
					'status'  => 'error',
				);
			}
		}
	}
}

$wc_eu_vat_my_account = new WC_EU_VAT_My_Account();
