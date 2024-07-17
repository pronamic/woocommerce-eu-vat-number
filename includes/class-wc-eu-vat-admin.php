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
		add_action( 'woocommerce_admin_shipping_fields', array( __CLASS__, 'admin_shipping_fields' ) );
		add_filter( 'woocommerce_order_get__shipping_vat_number', array( __CLASS__, 'get_vat_number_from_order' ), 10, 2 );
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

		// Notice for adjust vat number field on Block checkout.
		add_action( 'wp_ajax_wc_eu_vat_dismiss_checkout_notice', array( __CLASS__, 'dismiss_block_checkout_notice' ), 10 );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_admin_notice_for_block_checkout' ) );
	}

	/**
	 * Add fields to admin. This also handles save.
	 *
	 * @param  array $fields Fields being shown in admin.
	 * @return array
	 */
	public static function admin_billing_fields( $fields ) {
		global $theorder;
		if ( ! wc_eu_vat_use_shipping_country() ) {
			return self::add_vat_number_field( $fields );
		} elseif ( $theorder && $theorder->has_billing_address() && ! $theorder->has_shipping_address() ) {
			return self::add_vat_number_field( $fields );
		}

		return $fields;
	}

	/**
	 * Add VAT Number fields to admin. This also handles save.
	 *
	 * @param  array $fields Fields being shown in admin.
	 * @return array
	 */
	public static function admin_shipping_fields( $fields ) {
		global $theorder;

		if ( wc_eu_vat_use_shipping_country() ) {
			if ( $theorder && $theorder->has_billing_address() && ! $theorder->has_shipping_address() ) {
				return $fields;
			}

			return self::add_vat_number_field( $fields );
		}

		return $fields;
	}

	/**
	 * Get VAT Number from order for shipping address edit form.
	 *
	 * @param string   $value The VAT Number value in DB.
	 * @param WC_Order $order The order object.
	 * @return string
	 */
	public static function get_vat_number_from_order( $value, $order ) {
		$value = is_object( $order ) ? wc_eu_vat_get_vat_from_order( $order ) : $value;
		return $value;
	}

	/**
	 * Add VAT Number fields to admin. This also handles save.
	 *
	 * @param  array $fields Fields being shown in admin.
	 * @return array
	 */
	public static function add_vat_number_field( $fields ) {
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

		$asset_file = require_once WC_EU_ABSPATH . '/build/admin.asset.php';

		if ( ! is_array( $asset_file ) ) {
			return;
		}

		// Load admin style.
		wp_enqueue_style( 'wc_eu_vat_admin_css', WC_EU_VAT_PLUGIN_URL . '/build/admin.css', array(), $asset_file['version'] );
		wp_enqueue_script( 'wc-eu-vat-admin', WC_EU_VAT_PLUGIN_URL . '/build/admin.js', $asset_file['dependencies'], $asset_file['version'], true );

		wp_localize_script(
			'wc-eu-vat-admin',
			'wc_eu_vat_admin_params',
			array(
				'ajax_url'      => admin_url( 'admin-ajax.php' ),
				'dismiss_nonce' => wp_create_nonce( 'dismiss_block_checkout_notice' ),
			)
		);
	}

	/**
	 * Is this is an EU order?
	 *
	 * @param  WC_Order $order The order object.
	 * @return boolean
	 */
	protected static function is_eu_order( $order ) {
		$country = $order->get_billing_country();
		if ( $order && $order->has_shipping_address() && $order->get_shipping_country() && wc_eu_vat_use_shipping_country() ) {
			$country = $order->get_shipping_country();
		}
		return in_array( $country, WC_EU_VAT_Number::get_eu_countries(), true );
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
		$billing_country   = isset( $_POST['_billing_country'] ) ? wc_clean( wp_unslash( $_POST['_billing_country'] ) ) : $order->get_billing_country(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$shipping_country  = isset( $_POST['_shipping_country'] ) ? wc_clean( wp_unslash( $_POST['_shipping_country'] ) ) : $order->get_shipping_country(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$billing_postcode  = isset( $_POST['_billing_postcode'] ) ? wc_clean( wp_unslash( $_POST['_billing_postcode'] ) ) : $order->get_billing_postcode(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$shipping_postcode = isset( $_POST['_shipping_postcode'] ) ? wc_clean( wp_unslash( $_POST['_shipping_postcode'] ) ) : $order->get_shipping_postcode(); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		/*
		 * First try and get the VAT number from the
		 * address form (adding new order). If it is not
		 * found, get it from the order (editing the order).
		 */
		$vat_number = isset( $_POST['_billing_vat_number'] ) ? wc_clean( wp_unslash( $_POST['_billing_vat_number'] ) ) : wc_eu_vat_get_vat_from_order( $order ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$country  = $billing_country;
		$postcode = $billing_postcode;
		$type     = __( 'billing', 'woocommerce-eu-vat-number' );
		if ( $order->has_shipping_address() && wc_eu_vat_use_shipping_country() ) {
			$country  = $shipping_country;
			$postcode = $shipping_postcode;
			$type     = __( 'shipping', 'woocommerce-eu-vat-number' );
		}

		// Ignore empty VAT Number and countries outside EU.
		if ( empty( $vat_number ) || ! in_array( $country, WC_EU_VAT_Number::get_eu_countries(), true ) ) {
			return;
		}

		$valid              = WC_EU_VAT_Number::vat_number_is_valid( $vat_number, $country, $postcode );
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
				// translators: %1$s VAT number field label, %2$s VAT number, %3$s Country %4$s country type: billing/shipping.
				throw new Exception( sprintf( esc_html__( 'You have entered an invalid %1$s (%2$s) for your %4$s country (%3$s).', 'woocommerce-eu-vat-number' ), get_option( 'woocommerce_eu_vat_number_field_label', __( 'VAT number', 'woocommerce-eu-vat-number' ) ), $vat_number, $country, $type ) );
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
		if ( wc_eu_vat_use_shipping_country() ) {
			$fields['shipping']['fields']['vat_number'] = array(
				'label'       => esc_html__( 'VAT number', 'woocommerce-eu-vat-number' ),
				'description' => '',
			);

			return $fields;
		}
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
		$data['billing']['vat_number']  = get_user_meta( $user_id, 'vat_number', true );
		$data['shipping']['vat_number'] = get_user_meta( $user_id, 'vat_number', true );

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
				'dismiss_eu_vat_disclaimer'       => 'yes',
				'dismiss_eu_vat_disclaimer_nonce' => wp_create_nonce( 'dismiss_eu_vat_disclaimer' ),
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
		if ( ! isset( $_GET['dismiss_eu_vat_disclaimer'] ) || ! isset( $_GET['dismiss_eu_vat_disclaimer_nonce'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( wp_verify_nonce( wp_unslash( $_GET['dismiss_eu_vat_disclaimer_nonce'] ), 'dismiss_eu_vat_disclaimer' ) === false ) {
			return;
		}

		$maybe_dismiss = wc_clean( wp_unslash( $_GET['dismiss_eu_vat_disclaimer'] ) );

		if ( 'yes' === $maybe_dismiss && current_user_can( 'manage_woocommerce' ) ) {
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

	/**
	 * Display admin notice for block checkout.
	 *
	 * @since 2.9.3
	 */
	public static function maybe_show_admin_notice_for_block_checkout() {
		// Check whether disclaimer is already dismissed or taxes are not enabled.
		if (
			! wc_tax_enabled() ||
			'yes' === get_option( 'woocommerce_eu_vat_number_dismiss_block_checkout_notice', 'no' ) ||
			! current_user_can( 'manage_woocommerce' ) // phpcs:ignore WordPress.WP.Capabilities.Unknown
		) {
			return;
		}

		// Check if the block checkout is the default checkout.
		if ( ! WC_Blocks_Utils::has_block_in_page( wc_get_page_id( 'checkout' ), 'woocommerce/checkout' ) ) {
			return;
		}

		// Check if it is already adjusted.
		if ( self::is_eu_vat_block_adjusted() ) {
			update_option( 'woocommerce_eu_vat_number_dismiss_block_checkout_notice', 'yes', false );
			return;
		}
		?>

		<div class="notice notice-info wc-eu-vat-block-checkout-notice is-dismissible">
			<p>
				<?php
				// translators: %1$s Opening strong tag, %2$s Closing strong tag.
				echo wp_kses_post(
					sprintf(
						/* translators: %1$s - <strong>, %2$s - </strong>, %3$s - Link to edit checkout page, %4$s - closing tag */
						esc_html__( '%1$sWooCommerce EU VAT Number%2$s plugin adds a VAT number field to the checkout block though it may appear after the "Place Order" button. Please %3$sedit%4$s the checkout block to adjust the position of the VAT Number field accordingly.', 'woocommerce-eu-vat-number' ),
						'<strong>',
						'</strong>',
						'<a href="' . esc_url( get_edit_post_link( wc_get_page_id( 'checkout' ) ) ) . '">',
						'</a>'
					)
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Dismiss Block checkout notice.
	 *
	 * @since 2.9.3
	 */
	public static function dismiss_block_checkout_notice() {
		check_ajax_referer( 'dismiss_block_checkout_notice', 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			return;
		}

		update_option( 'woocommerce_eu_vat_number_dismiss_block_checkout_notice', 'yes', false );
	}

	/**
	 * Check if EU VAT inner block is already adjusted in block checkout.
	 *
	 * @return boolean
	 */
	public static function is_eu_vat_block_adjusted() {
		$page = get_post( wc_get_page_id( 'checkout' ) );
		if ( ! $page ) {
			return false;
		}

		$transient_key = 'wc_eu_vat_block_adjusted';
		$cached_result = get_transient( $transient_key );

		if ( ! empty( $cached_result ) ) {
			return 'yes' === $cached_result;
		}

		$is_adjusted = false;
		$blocks      = parse_blocks( $page->post_content );
		foreach ( $blocks as $block ) {
			if ( 'woocommerce/checkout' === $block['blockName'] ) {
				foreach ( $block['innerBlocks'] as $inner_block ) {
					if ( 'woocommerce/checkout-fields-block' === $inner_block['blockName'] ) {
						$inner_blocks = $inner_block['innerBlocks'];
						$block_names  = wp_list_pluck( $inner_blocks, 'blockName' );
						$last_block   = $block_names[ array_key_last( $block_names ) ];
						if ( in_array( 'woocommerce/eu-vat-number', $block_names, true ) && 'woocommerce/eu-vat-number' !== $last_block ) {
							$is_adjusted = true;
							break;
						}
					}
				}
			}
		}

		set_transient( $transient_key, $is_adjusted ? 'yes' : 'no', HOUR_IN_SECONDS );
		return $is_adjusted;
	}
}

WC_EU_VAT_Admin::init();
