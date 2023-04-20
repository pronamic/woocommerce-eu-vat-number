<?php
/**
 * Admin handling.
 *
 * @package woocommerce-eu-vat-number
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_EU_VAT_Admin class.
 */
class WC_EU_VAT_Admin {

	/**
	 * Admin settings array.
	 *
	 * @var array
	 */
	private static $settings = array();

	/**
	 * Constructor.
	 */
	public static function init() {
		self::$settings = require_once 'data/eu-vat-number-settings.php';
		add_action( 'woocommerce_admin_billing_fields', array( __CLASS__, 'admin_billing_fields' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'styles_and_scripts' ) );
		add_action( 'woocommerce_settings_tax_options_end', array( __CLASS__, 'admin_settings' ) );
		add_action( 'woocommerce_update_options_tax', array( __CLASS__, 'save_admin_settings' ) );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ), 20 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'show_column' ), 5, 2 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'show_column' ), 5, 2 );
		add_action( 'woocommerce_order_before_calculate_taxes', array( __CLASS__, 'admin_order' ), 10, 2 );
		add_filter( 'woocommerce_customer_meta_fields', array( __CLASS__, 'add_customer_meta_fields' ) );
		add_filter( 'woocommerce_ajax_get_customer_details', array( __CLASS__, 'get_customer_details' ), 10, 3 );
		add_action( 'admin_init', array( __CLASS__, 'dismiss_eu_vat_disclaimer' ), 10 );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_admin_notice' ) );
		add_action( 'update_option_woocommerce_default_country', array( __CLASS__, 'reset_admin_notice_display' ), 10, 3 );
		add_action( 'update_option_woocommerce_store_postcode', array( __CLASS__, 'reset_admin_notice_display' ), 10, 3 );
	}

	/**
	 * Add fields to admin. This also handles save.
	 *
	 * @param  array $fields Fields being shown in admin.
	 * @return array
	 */
	public static function admin_billing_fields( $fields ) {
		global $theorder;

		$vat_number = is_object( $theorder ) ? wc_eu_vat_get_vat_from_order( $theorder ) : '';

		$fields['vat_number'] = array(
			'label' => get_option( 'woocommerce_eu_vat_number_field_label', __( 'VAT number', 'woocommerce-eu-vat-number' ) ),
			'show'  => false,
			'id'    => '_billing_vat_number',
			'value' => $vat_number,
		);
		return $fields;
	}

	/**
	 * Add Meta Boxes.
	 */
	public static function add_meta_boxes() {
		if ( class_exists( 'Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ) {
			$screen = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';
		} else {
			$screen = 'shop_order';
		}

		add_meta_box( 'wc_eu_vat', __( 'EU VAT', 'woocommerce-eu-vat-number' ), array( __CLASS__, 'output' ), $screen, 'side' );
	}

	/**
	 * Enqueue admin styles and scripts
	 *
	 * @param string $hook The current admin page.
	 * @return void
	 */
	public static function styles_and_scripts( $hook ) {
		global $post;

		$is_order_edit_screen = false;

		if ( 'woocommerce_page_wc-orders' === $hook && isset( $_GET['id'] ) ) {
			$is_order_edit_screen = true;
		} else if ( 'woocommerce_page_wc-orders' === $hook && isset( $_GET['action'] ) && 'new' === wp_unslash( sanitize_text_field( $_GET['action'] ) ) ) {
			$is_order_edit_screen = true;
		} else if ( in_array( $hook, array( 'post-new.php', 'post.php' ), true ) && $post && 'shop_order' === $post->post_type ) {
			$is_order_edit_screen = true;
		}

		// Load admin style.
		wp_enqueue_style( 'wc_eu_vat_admin_css', plugins_url( 'assets/css/admin.css', WC_EU_VAT_FILE ), array(), WC_EU_VAT_VERSION );

		// Load script only on add/edit order page.
		if ( $is_order_edit_screen ) {
			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
			wp_enqueue_script( 'wc-eu-vat-admin', WC_EU_VAT_PLUGIN_URL . '/assets/js/admin' . $suffix . '.js', array( 'jquery' ), WC_EU_VAT_VERSION, true );
		}
	}

	/**
	 * Is this is an EU order?
	 *
	 * @param  WC_Order $order The order object.
	 * @return boolean
	 */
	protected static function is_eu_order( $order ) {
		return in_array( $order->get_billing_country(), WC_EU_VAT_Number::get_eu_countries(), true );
	}

	/**
	 * Get order VAT Number data in one object/array.
	 *
	 * @param  WC_Order $order The order object.
	 * @return object
	 */
	protected static function get_order_vat_data( $order ) {
		return (object) array(
			'vat_number'      => wc_eu_vat_get_vat_from_order( $order ),
			'valid'           => wc_string_to_bool( $order->get_meta( '_vat_number_is_valid', true ) ),
			'validated'       => wc_string_to_bool( $order->get_meta( '_vat_number_is_validated', true ) ),
			'billing_country' => $order->get_billing_country(),
			'ip_address'      => $order->get_customer_ip_address(),
			'ip_country'      => $order->get_meta( '_customer_ip_country', true ),
			'self_declared'   => wc_string_to_bool( $order->get_meta( '_customer_self_declared_country', true ) ),
		);
	}

	/**
	 * Output meta box.
	 */
	public static function output() {
		global $post, $theorder;

		if ( ! is_object( $theorder ) ) {
			$theorder = wc_get_order( $post->ID );
		}

		// We only need this box for EU orders.
		if ( ! self::is_eu_order( $theorder ) ) {
			?>
			<p>
				<?php esc_html_e( 'This order is out of scope for EU VAT.', 'woocommerce-eu-vat-number' ); ?>
			</p>
			<?php
			return;
		}

		$data      = self::get_order_vat_data( $theorder );
		$countries = WC()->countries->get_countries();
		?>
		<table class="wc-eu-vat-table" cellspacing="0">
			<tbody>
				<tr>
					<th><?php esc_html_e( 'B2B', 'woocommerce-eu-vat-number' ); ?></th>
					<td><?php echo $data->vat_number ? esc_html__( 'Yes', 'woocommerce-eu-vat-number' ) : esc_html__( 'No', 'woocommerce-eu-vat-number' ); ?></td>
					<td></td>
				</tr>

				<?php if ( $data->vat_number ) : ?>
					<tr>
						<th><?php echo esc_html( get_option( 'woocommerce_eu_vat_number_field_label', __( 'VAT number', 'woocommerce-eu-vat-number' ) ) ); ?></th>
						<td><?php echo esc_html( $data->vat_number ); ?></td>
						<td>
							<?php
							if ( ! $data->validated ) {
								echo '<span class="tips" data-tip="' . wc_sanitize_tooltip( __( 'Validation was not possible', 'woocommerce-eu-vat-number' ) ) . '">?<span>';
							} else {
								echo $data->valid ? '&#10004;' : '&#10008;';
							}
							?>
						</td>
					</tr>
				<?php else : ?>
					<tr>
						<th><?php esc_html_e( 'IP Address', 'woocommerce-eu-vat-number' ); ?></th>
						<td><?php echo $data->ip_address ? esc_html( $data->ip_address ) : esc_html__( 'Unknown', 'woocommerce-eu-vat-number' ); ?></td>
						<td></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'IP Country', 'woocommerce-eu-vat-number' ); ?></th>
						<td>
							<?php
							if ( $data->ip_country ) {
								echo esc_html( $countries[ $data->billing_country ] ) . ' ';

								if ( $data->billing_country === $data->ip_country ) {
									echo '<span style="color:green">&#10004;</span>';
								} elseif ( $data->self_declared ) {
									esc_html_e( '(self-declared)', 'woocommerce-eu-vat-number' );
								} else {
									echo '<span style="color:red">&#10008;</span>';
								}
							} else {
								esc_html_e( 'Unknown', 'woocommerce-eu-vat-number' );
							}
							?>
						</td>
						<td></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Billing Country', 'woocommerce-eu-vat-number' ); ?></th>
						<td><?php echo $data->billing_country ? esc_html( $countries[ $data->billing_country ] ) : esc_html__( 'Unknown', 'woocommerce-eu-vat-number' ); ?></td>
						<td></td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Add settings to WC.
	 */
	public static function admin_settings() {
		woocommerce_admin_fields( self::$settings );
	}

	/**
	 * Save settings.
	 */
	public static function save_admin_settings() {
		global $current_section;

		if ( ! $current_section ) {
			woocommerce_update_options( self::$settings );
		}
	}

	/**
	 * Add column.
	 *
	 * @param array $existing_columns Columns array.
	 */
	public static function add_column( $existing_columns ) {
		$columns = array();

		foreach ( $existing_columns as $existing_column_key => $existing_column ) {
			$columns[ $existing_column_key ] = $existing_column;

			if ( 'shipping_address' === $existing_column_key ) {
				$columns['eu_vat'] = __( 'EU VAT', 'woocommerce-eu-vat-number' );
			}
		}

		return $columns;
	}

	/**
	 * Show Column.
	 *
	 * @param string $column Column being shown.
	 */
	public static function show_column( $column, $order ) {

		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( 'eu_vat' === $column ) {
			echo '<p class="eu-vat-overview">';

			if ( ! self::is_eu_order( $order ) ) {
				echo '<span class="na">&ndash;</span>';
			} else {
				$data = self::get_order_vat_data( $order );

				if ( $data->vat_number ) {
					echo esc_html( $data->vat_number ) . ' ';

					if ( $data->validated && $data->valid ) {
						echo '<span style="color:green">&#10004;</span>';
					} elseif ( ! $data->validated ) {
						esc_html_e( '(validation failed)', 'woocommerce-eu-vat-number' );
					} else {
						echo '<span style="color:red">&#10008;</span>';
					}
				} else {
					$countries = WC()->countries->get_countries();

					echo esc_html( $countries[ $data->billing_country ] ) . ' ';

					if ( $data->billing_country === $data->ip_country ) {
						echo '<span style="color:green">&#10004;</span>';
					} elseif ( $data->self_declared ) {
						esc_html_e( '(self-declared)', 'woocommerce-eu-vat-number' );
					} else {
						echo '<span style="color:red">&#10008;</span>';
					}
				}
			}
			echo '</p>';
		}
	}

	/**
	 * Handles VAT when order is created/edited within admin manually.
	 *
	 * @since 2.3.14
	 * @param array  $args Additional arguments.
	 * @param object $order WooCommerce Order Object.
	 * @throws Exception Error message if VAT validation fails.
	 */
	public static function admin_order( $args, $order ) {
		if ( ! is_object( $order ) ) {
			return;
		}

		/*
		 * First try and get the billing country from the
		 * address form (adding new order). If it is not
		 * found, get it from the order (editing the order).
		 */
		$billing_country  = isset( $_POST['_billing_country'] ) ? wc_clean( wp_unslash( $_POST['_billing_country'] ) ) : $order->get_billing_country(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$shipping_country = isset( $_POST['_shipping_country'] ) ? wc_clean( wp_unslash( $_POST['_shipping_country'] ) ) : $order->get_shipping_country(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$billing_postcode = isset( $_POST['_billing_postcode'] ) ? wc_clean( wp_unslash( $_POST['_billing_postcode'] ) ) : $order->get_billing_postcode(); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		/*
		 * First try and get the VAT number from the
		 * address form (adding new order). If it is not
		 * found, get it from the order (editing the order).
		 */
		$vat_number = isset( $_POST['_billing_vat_number'] ) ? wc_clean( wp_unslash( $_POST['_billing_vat_number'] ) ) : wc_eu_vat_get_vat_from_order( $order ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// Ignore empty VAT Number and countries outside EU.
		if ( empty( $vat_number ) || ! in_array( $billing_country, WC_EU_VAT_Number::get_eu_countries(), true ) ) {
			return;
		}

		$valid              = WC_EU_VAT_Number::vat_number_is_valid( $vat_number, $billing_country, $billing_postcode );
		$base_country_match = WC_EU_VAT_Number::is_base_country_match( $billing_country, $shipping_country );

		if ( 'no' === get_option( 'woocommerce_eu_vat_number_deduct_in_base', 'yes' ) && $base_country_match ) {
			add_filter( 'woocommerce_order_is_vat_exempt', '__return_false' );
			return;
		}

		$order->update_meta_data( '_vat_number_is_validated', 'true' );

		try {
			if ( true === $valid ) {
				$order->update_meta_data( '_vat_number_is_valid', 'true' );
				add_filter( 'woocommerce_order_is_vat_exempt', '__return_true' );
				return;
			}

			$fail_handler = get_option( 'woocommerce_eu_vat_number_failure_handling', 'reject' );
			// Exempt VAT even if VAT number is not valid as per "Failed Validation Handling" settings.
			if ( 'accept' === $fail_handler ) {
				add_filter( 'woocommerce_order_is_vat_exempt', '__return_true' );
			}
			if ( is_wp_error( $valid ) ) {
				throw new Exception( $valid->get_error_message() );
			}

			if ( ! $valid ) {
				// translators: %1$s VAT number field label, %2$s VAT number, %3$s Billing Country.
				throw new Exception( sprintf( esc_html__( 'You have entered an invalid %1$s (%2$s) for your billing country (%3$s).', 'woocommerce-eu-vat-number' ), get_option( 'woocommerce_eu_vat_number_field_label', __( 'VAT number', 'woocommerce-eu-vat-number' ) ), $vat_number, $billing_country ) );
			}
		} catch ( Exception $e ) {
			$order->update_meta_data( '_vat_number_is_valid', 'false' );
			echo '<script>alert( "' . esc_js( $e->getMessage() ) . '" )</script>';
		}
	}

	/**
	 * Adds custom fields to user profile.
	 *
	 * @since 2.3.21
	 * @param array $fields WC defined user fields.
	 * @return array $fields Modified user fields.
	 */
	public static function add_customer_meta_fields( $fields ) {
		$fields['billing']['fields']['vat_number'] = array(
			'label'       => esc_html__( 'VAT number', 'woocommerce-eu-vat-number' ),
			'description' => '',
		);

		return $fields;
	}

	/**
	 * Return VAT information to get customer details via AJAX.
	 *
	 * @since 2.3.21
	 * @param array  $data The customer's data in context.
	 * @param object $customer The customer object in context.
	 * @param int    $user_id The user ID in context.
	 * @return array $data Modified user data.
	 */
	public static function get_customer_details( $data, $customer, $user_id ) {
		$data['billing']['vat_number'] = get_user_meta( $user_id, 'vat_number', true );

		return $data;
	}

	/**
	 * Display admin notice for EU VAT Number.
	 */
	public static function maybe_show_admin_notice() {

		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';

		if ( 'woocommerce_page_wc-settings' !== $screen_id ) {
			return;
		}

		// Check whether disclaimer is already dismissed or taxes are not enabled.
		if ( ! wc_tax_enabled() || 'yes' === get_option( 'woocommerce_eu_vat_number_dismiss_disclaimer', 'no' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ 'tab' ] ) && 'tax' === $_GET[ 'tab' ] ) {
			$screen_id .= '_tax';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ 'tab' ] ) || ( isset( $_GET[ 'tab' ] ) && 'general' === $_GET[ 'tab' ] ) ) {
			$screen_id .= '_general';
		}

		$show_on_screens = array(
			'woocommerce_page_wc-settings_tax',
			'woocommerce_page_wc-settings_general',
		);

		if ( ! in_array( $screen_id, $show_on_screens, true ) ) {
			return;
		}

		$base_country  = WC()->countries->get_base_country();
		$base_postcode = WC()->countries->get_base_postcode();

		// Create URL to dismiss the disclaimer.
		$dismiss_url = add_query_arg(
			array(
				'dismiss_eu_vat_disclaimer' => 'yes',
			),
			wc_get_current_admin_url()
		);
		?>

		<div class="notice notice-info is-dismissible">
			<?php
			// If store is based in Norther Ireland, show admin notice.
			if ( 'GB' === $base_country && preg_match( '/^(bt).*$/i', $base_postcode ) ) {
				?>
				<p>
					<?php
					// translators: %1$s Opening strong tag, %2$s Closing strong tag, %3$s Break tag.
					echo sprintf( esc_html__( 'Based on your store address, we\'ve identified your store location as %1$sNorthern Ireland%2$s, where European Union VAT laws apply. These laws require all goods to be %1$sshipped directly from Northern Ireland and not any other country%2$s. %3$s The %1$sWooCommerce EU VAT Number%2$s plugin assumes that the store abides by these laws and all shipments originate from %1$sNorthern Ireland%2$s.', 'woocommerce-eu-vat-number' ), '<strong>', '</strong>', '<br/>' );
					?>
				</p>
				<hr>
				<?php
			}
			?>

			<p>
				<?php
				// translators: %1$s Opening strong tag, %2$s Closing strong tag.
				echo sprintf( esc_html__( 'By using %1$sWooCommerce EU VAT Number%2$s plugin, you\'ve agreed that the use of this plugin cannot be considered as tax advice. We recommend consulting a local tax professional for tax compliance or if you have any tax specific questions.', 'woocommerce-eu-vat-number' ), '<strong>', '</strong>' );
				?>
			</p>

			<p>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button button-primary"><?php echo esc_html__( 'I understand', 'woocommerce-eu-vat-number' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Dismiss EU VAT Disclaimer.
	 */
	public static function dismiss_eu_vat_disclaimer() {
		$maybe_dismiss = wc_clean( filter_input( INPUT_GET, 'dismiss_eu_vat_disclaimer', FILTER_SANITIZE_STRING ) );

		if ( 'yes' === $maybe_dismiss ) {
			update_option( 'woocommerce_eu_vat_number_dismiss_disclaimer', $maybe_dismiss, false );
		}
	}

	/**
	 * Delete the disclaimer option so it shows up on store country/postcode change.
	 *
	 * @param mixed  $old_value Old value of option.
	 * @param mixed  $value New value of option.
	 * @param string $option Name of the option.
	 */
	public static function reset_admin_notice_display( $old_value, $value, $option ) {
		delete_option( 'woocommerce_eu_vat_number_dismiss_disclaimer' );
	}
}

WC_EU_VAT_Admin::init();
