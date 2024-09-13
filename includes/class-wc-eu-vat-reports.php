<?php
/**
 * EU VAT Reports
 *
 * @package woocommerce-eu-vat-number
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_EU_VAT_Reports class
 */
class WC_EU_VAT_Reports {

	/**
	 * OrderUtil object.
	 *
	 * @var \Automattic\WooCommerce\Utilities\OrderUtil object.
	 */
	public static $order_util;

	/**
	 * Constructor
	 */
	public static function init() {
		if ( ! function_exists( 'wc_get_container' ) ) {
			return;
		}

		try {
			self::$order_util = wc_get_container()->get( Automattic\WooCommerce\Utilities\OrderUtil::class );
		} catch ( Exception $e ) {
			self::$order_util = false;
		}

		// The EU VAT reports are incompatible with stores running HPOS with syncing disabled.
		if ( self::is_cot_enabled() && ! self::is_cot_sync_enabled() ) {
			add_action( 'admin_notices', array( __CLASS__, 'display_hpos_incompatibility_notice' ) );
			return;
		}

		add_action( 'woocommerce_admin_reports', array( __CLASS__, 'init_reports' ) );
	}

	/**
	 * Helper function to get whether custom order tables are enabled or not.
	 *
	 * @return bool
	 */
	public static function is_cot_enabled() {
		return self::$order_util && self::$order_util::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Helper function to check whether custom order tables are in sync or not.
	 *
	 * @return bool
	 */
	public static function is_cot_sync_enabled() {
		return self::$order_util && self::$order_util::is_custom_order_tables_in_sync();
	}

	/**
	 * Displays an admin notice indicating EU VAT reports are disabled on HPOS environments with no syncing.
	 */
	public static function display_hpos_incompatibility_notice() {
		$screen = get_current_screen();

		// Only display the admin notice on report admin screens.
		if ( ! $screen || 'woocommerce_page_wc-reports' !== $screen->id ) {
			return;
		}

		if ( current_user_can( 'activate_plugins' ) ) {
			/* translators: %1$s: Minimum version %2$s: Plugin page link start %3$s Link end */
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong></p><p>%s</p></div>',
				esc_html__( 'WooCommerce EU VAT Number - Reports Not Available', 'woocommerce-eu-vat-number' ),
				sprintf(
					// translators: placeholders $1 and $2 are opening <a> tags linking to the WooCommerce documentation on HPOS and data synchronization. Placeholder $3 is a closing link (<a>) tag.
					esc_html__( 'EU VAT reports are incompatible with the %1$sWooCommerce data storage features%3$s enabled on your store. Please enable %2$stable synchronization%3$s if you wish to use EU VAT reports.', 'woocommerce-eu-vat-number' ),
					'<a href="https://woocommerce.com/document/high-performance-order-storage/">',
					'<a href="https://woocommerce.com/document/high-performance-order-storage/#synchronization">',
					'</a>',
				)
			);
		}
	}

	/**
	 * Add reports
	 *
	 * @param array $reports EU VAT reports.
	 * @return array
	 */
	public static function init_reports( $reports ) {
		if ( isset( $reports['taxes'] ) ) {
			$reports['taxes']['reports']['ec_sales_list'] = array(
				'title'       => __( 'EC Sales List', 'woocommerce-eu-vat-number' ),
				'description' => '',
				'hide_title'  => true,
				'callback'    => array( __CLASS__, 'ec_sales_list' ),
			);
			$reports['taxes']['reports']['eu_vat']        = array(
				'title'       => __( 'EU VAT by state', 'woocommerce-eu-vat-number' ),
				'description' => '',
				'hide_title'  => true,
				'callback'    => array( __CLASS__, 'eu_vat' ),
			);
			$reports['taxes']['reports']['non_eu_vat']    = array(
				'title'       => __( 'Non EU Sales', 'woocommerce-eu-vat-number' ),
				'description' => '',
				'hide_title'  => true,
				'callback'    => array( __CLASS__, 'non_eu_vat' ),
			);
		}
		return $reports;
	}

	/**
	 * Get a report
	 */
	public static function ec_sales_list() {
		include_once 'class-wc-eu-vat-report-ec-sales-list.php';
		$report = new WC_EU_VAT_Report_EC_Sales_List();
		$report->output_report();
	}

	/**
	 * Get a report
	 */
	public static function eu_vat() {
		include_once 'class-wc-eu-vat-report-eu-vat.php';
		$report = new WC_EU_VAT_Report_EU_VAT();
		$report->output_report();
	}

	/**
	 * Get a report
	 */
	public static function non_eu_vat() {
		include_once 'class-wc-non-eu-sales-report.php';
		$report = new WC_Non_EU_Sales_Report();
		$report->output_report();
	}
}

WC_EU_VAT_Reports::init();
