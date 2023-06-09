<?php
/**
 * Plugin Name: WooCommerce EU VAT Number
 * Plugin URI: https://woocommerce.com/products/eu-vat-number/
 * Description: The EU VAT Number extension lets you collect and validate EU VAT numbers during checkout to identify B2B transactions verses B2C. IP Addresses can also be validated to ensure they match the billing address. EU businesses with a valid VAT number can have their VAT removed prior to payment.
 * Version: 2.8.3
 * Author: WooCommerce
 * Author URI: https://woocommerce.com/
 * Text Domain: woocommerce-eu-vat-number
 * Domain Path: /languages
 * Requires at least: 5.6
 * Tested up to: 6.1
 * WC requires at least: 6.8
 * WC tested up to: 7.4
 * Requires PHP: 7.2
 *
 * Copyright: © 2023 WooCommerce
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package woocommerce-eu-vat-number
 * Woo: 18592:d2720c4b4bb8d6908e530355b7a2d734
 */

// phpcs:disable WordPress.Files.FileName

define( 'WC_EU_VAT_VERSION', '2.8.3' ); // WRCS: DEFINED_VERSION.
define( 'WC_EU_VAT_FILE', __FILE__ );
define( 'WC_EU_VAT_PLUGIN_URL', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) );

/**
 * WC_EU_VAT_Number_Init class.
 */
class WC_EU_VAT_Number_Init {

	/**
	 * Min version of WooCommerce supported.
	 *
	 * @var string
	 */
	const WC_MIN_VERSION = '6.8';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );

		add_action( 'plugins_loaded', array( $this, 'localization' ), 0 );

		// Subscribe to automated translations.
		add_filter( 'woocommerce_translations_updates_for_' . basename( __FILE__, '.php' ), '__return_true' );

		register_activation_hook( __FILE__, array( $this, 'install' ) );
	}

	/**
	 * Checks that WooCommerce is loaded before doing anything else.
	 *
	 * @return bool True if supported
	 */
	private function check_dependencies() {
		$dependencies = array(
			'wc_installed'       => array(
				'callback'        => array( $this, 'is_woocommerce_active' ),
				'notice_callback' => array( $this, 'woocommerce_inactive_notice' ),
			),
			'wc_minimum_version' => array(
				'callback'        => array( $this, 'is_woocommerce_version_supported' ),
				'notice_callback' => array( $this, 'woocommerce_wrong_version_notice' ),
			),
			'soap_required'      => array(
				'callback'        => array( $this, 'is_soap_supported' ),
				'notice_callback' => array( $this, 'requires_soap_notice' ),
			),
		);
		foreach ( $dependencies as $check ) {
			if ( ! call_user_func( $check['callback'] ) ) {
				add_action( 'admin_notices', $check['notice_callback'] );
				return false;
			}
		}
		return true;
	}

	/**
	 * Checks if the WooCommerce plugin is active.
	 * Note: Must be run after the "plugins_loaded" action fires.
	 *
	 * @since 1.0
	 * @return bool
	 */
	public function is_woocommerce_active() {
		return class_exists( 'woocommerce' );
	}

	/**
	 * Checks if the current WooCommerce version is supported.
	 * Note: Must be run after the "plugins_loaded" action fires.
	 *
	 * @since 1.0
	 * @return bool
	 */
	public function is_woocommerce_version_supported() {
		return version_compare(
			get_option( 'woocommerce_db_version' ),
			self::WC_MIN_VERSION,
			'>='
		);
	}

	/**
	 * Checks if the WooCommerce Blocks is active.
	 * Note: Must be run after the "plugins_loaded" action fires.
	 *
	 * @return bool
	 */
	public function is_woocommerce_blocks_active() {
		return class_exists( 'Automattic\WooCommerce\Blocks\Package' );
	}

	/**
	 * Checks if the current WooCommerce Blocks version is supported.
	 * Note: Must be run after the "plugins_loaded" action fires.
	 *
	 * @return bool
	 */
	public function is_woocommerce_blocks_version_supported() {
		return version_compare(
			\Automattic\WooCommerce\Blocks\Package::get_version(),
			'7.3.0',
			'>='
		);
	}

	/**
	 * Checks if the server supports SOAP.
	 *
	 * @since 2.3.7
	 * @return bool
	 */
	public function is_soap_supported() {
		return class_exists( 'SoapClient' );
	}

	/**
	 * WC inactive notice.
	 *
	 * @since 1.0.0
	 */
	public function woocommerce_inactive_notice() {
		if ( current_user_can( 'activate_plugins' ) ) {
			/* translators: %1$s: Plugin page link start %2$s Link end */
			echo '<div class="error"><p><strong>' . wp_kses_post( __( 'WooCommerce EU VAT Number is inactive.', 'woocommerce-eu-vat-number' ) . '</strong> ' . sprintf( __( 'The WooCommerce plugin must be active for EU VAT Number to work. %1$sPlease install and activate WooCommerce%2$s.', 'woocommerce-eu-vat-number' ), '<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">', '</a>' ) ) . '</p></div>';
		}
	}

	/**
	 * Wrong version notice.
	 *
	 * @since 1.0.0
	 */
	public function woocommerce_wrong_version_notice() {
		if ( current_user_can( 'activate_plugins' ) ) {
			/* translators: %1$s: Minimum version %2$s: Plugin page link start %3$s Link end */
			echo '<div class="error"><p><strong>' . wp_kses_post( __( 'WooCommerce EU VAT Number is inactive.', 'woocommerce-eu-vat-number' ) . '</strong> ' . sprintf( __( 'The WooCommerce plugin must be at least version %1$s for EU VAT Number to work. %2$sPlease upgrade WooCommerce%3$s.', 'woocommerce-eu-vat-number' ), self::WC_MIN_VERSION, '<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">', '</a>' ) ) . '</p></div>';
		}
	}

	/**
	 * No SOAP support notice.
	 *
	 * @since 2.3.7
	 */
	public function requires_soap_notice() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="error"><p><strong>' . esc_html__( 'WooCommerce EU VAT Number is inactive.', 'woocommerce-eu-vat-number' ) . '</strong> ' . esc_html__( 'Your server does not provide SOAP support which is required functionality for communicating with VIES. You will need to reach out to your web hosting provider to get information on how to enable this functionality on your server.', 'woocommerce-eu-vat-number' ) . '</p></div>';
		}
	}

	/**
	 * Init the plugin once WP is loaded.
	 */
	public function init() {
		if ( $this->check_dependencies() ) {
			if ( version_compare( get_option( 'woocommerce_eu_vat_version', 0 ), WC_EU_VAT_VERSION, '<' ) ) {
				add_action( 'init', array( $this, 'install' ) );
			}

			include_once __DIR__ . '/includes/wc-eu-vat-functions.php';
			include_once __DIR__ . '/includes/class-wc-eu-vat-privacy.php';

			if ( ! class_exists( 'WC_EU_VAT_Number' ) ) {
				include_once __DIR__ . '/includes/class-wc-eu-vat-number.php';
				include_once __DIR__ . '/includes/class-wc-eu-vat-my-account.php';
			}

			if ( is_admin() ) {
				include_once __DIR__ . '/includes/class-wc-eu-vat-admin.php';
				include_once __DIR__ . '/includes/class-wc-eu-vat-reports.php';
			}

			add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
			add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );
			add_filter( 'woocommerce_get_order_item_totals', 'wc_eu_vat_maybe_add_zero_tax_display', 10, 3 );
			add_action( 'init', array( $this, 'wc_eu_vat_number_block_init' ) );
			add_filter(
				'__experimental_woocommerce_blocks_add_data_attributes_to_block',
				function ( $allowed_blocks ) {
					if ( $this->is_woocommerce_blocks_active() && $this->is_woocommerce_blocks_version_supported() ) {
						$allowed_blocks[] = 'woocommerce/eu-vat-number';
					}
					return $allowed_blocks;
				},
				10,
				1
			);
		}
	}

	/**
	 * Load translations.
	 */
	public function localization() {
		/**
		 * Filters plugin locale.
		 *
		 * @since 2.1.11
		 */
		$locale = apply_filters( 'plugin_locale', get_locale(), 'woocommerce-eu-vat-number' );
		$dir    = trailingslashit( WP_LANG_DIR );
		load_textdomain( 'woocommerce-eu-vat-number', $dir . 'woocommerce-eu-vat-number/woocommerce-eu-vat-number-' . $locale . '.mo' );
		load_plugin_textdomain( 'woocommerce-eu-vat-number', false, dirname( plugin_basename( WC_EU_VAT_FILE ) ) . '/languages' );
	}

	/**
	 * Installer
	 */
	public function install() {
		update_option( 'woocommerce_eu_vat_version', WC_EU_VAT_VERSION );
		add_rewrite_endpoint( 'vat-number', EP_ROOT | EP_PAGES );
		flush_rewrite_rules();
	}

	/**
	 * Add custom action links on the plugin screen.
	 *
	 * @param  mixed $actions Plugin Actions Links.
	 * @return array
	 */
	public function plugin_action_links( $actions ) {
		$custom_actions = array(
			'settings' => sprintf( '<a href="%s">%s</a>', admin_url( 'admin.php?page=wc-settings&tab=tax' ), __( 'Settings', 'woocommerce-eu-vat-number' ) ),
		);
		return array_merge( $custom_actions, $actions );
	}

	/**
	 * Show row meta on the plugin screen.
	 *
	 * @param  mixed $links Plugin Row Meta.
	 * @param  mixed $file  Plugin Base file.
	 * @return array
	 */
	public function plugin_row_meta( $links, $file ) {
		if ( 'woocommerce-eu-vat-number/woocommerce-eu-vat-number.php' === $file ) {
			$row_meta = array(
				/**
				 * Filters the plugin docs URL.
				 *
				 * @since 2.1.8
				 */
				'docs'      => '<a href="' . esc_url( apply_filters( 'wc_eu_vat_number_docs_url', 'https://docs.woocommerce.com/document/eu-vat-number/' ) ) . '" title="' . esc_attr( __( 'View Plugin Documentation', 'woocommerce-eu-vat-number' ) ) . '">' . __( 'Docs', 'woocommerce-eu-vat-number' ) . '</a>',
				/**
				 * Filters the plugin changelog URL.
				 *
				 * @since 2.1.8
				 */
				'changelog' => '<a href="' . esc_url( apply_filters( 'wc_eu_vat_number_changelog', 'https://woocommerce.com/changelogs/woocommerce-eu-vat-number/changelog.txt' ) ) . '" title="' . esc_attr( __( 'View Plugin Changelog', 'woocommerce-eu-vat-number' ) ) . '">' . __( 'Changelog', 'woocommerce-eu-vat-number' ) . '</a>',
				/**
				 * Filters the plugin support URL.
				 *
				 * @since 2.1.8
				 */
				'support'   => '<a href="' . esc_url( apply_filters( 'wc_eu_vat_number_support_url', 'https://woocommerce.com/my-account/create-a-ticket?select=18592' ) ) . '" title="' . esc_attr( __( 'Support', 'woocommerce-eu-vat-number' ) ) . '">' . __( 'Support', 'woocommerce-eu-vat-number' ) . '</a>',
			);
			return array_merge( $links, $row_meta );
		}
		return (array) $links;
	}

	/**
	 * Registers block type and registers with WC Blocks Integration Interface.
	 */
	public function wc_eu_vat_number_block_init() {
		if ( $this->is_woocommerce_blocks_active() && $this->is_woocommerce_blocks_version_supported() ) {
			include_once __DIR__ . '/includes/class-wc-eu-vat-blocks.php';
			add_action(
				'woocommerce_blocks_checkout_block_registration',
				function( $integration_registry ) {
					$integration_registry->register( new WC_EU_VAT_Blocks_Integration() );
				}
			);

			if ( ! class_exists( 'WC_EU_VAT_Number' ) ) {
				include_once __DIR__ . '/includes/class-wc-eu-vat-number.php';
				include_once __DIR__ . '/includes/class-wc-eu-vat-my-account.php';
			}
			add_action(
				'wp_enqueue_scripts',
				function() {
					WC_EU_VAT_Number::localize_wc_eu_vat_params( 'wc-blocks-eu-vat-scripts-frontend' );
				}
			);

			register_block_type(
				__DIR__ . '/block.json',
				array(
					'attributes' => array(
						'title'          => array(
							'type'    => 'string',
							'default' => __( 'VAT Number', 'woocommerce-eu-vat-number' ),
						),
						'description'    => array(
							'type'    => 'string',
							'default' => '',
						),
						'showStepNumber' => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				)
			);
		}
	}

	/**
	 * Declares support for HPOS.
	 */
	public function declare_hpos_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
}

new WC_EU_VAT_Number_Init();
