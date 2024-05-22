<?php
/**
 * EC Sales List Report.
 *
 * @package woocommerce-eu-vat-number
 * @phpcs:disable WordPress.DateTime.RestrictedFunctions.date_date
 * @phpcs:disable WordPress.DateTime.CurrentTimeTimestamp.Requested
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_EU_VAT_Report_EC_Sales_List class
 */
class WC_EU_VAT_Report_EC_Sales_List extends WC_Admin_Report {

	/**
	 * Get the legend for the main chart sidebar
	 *
	 * @return array
	 */
	public function get_chart_legend() {
		return array();
	}

	/**
	 * Output an export link
	 */
	public function get_export_button() {
		$current_range = ! empty( $_GET['range'] ) ? sanitize_text_field( wp_unslash( $_GET['range'] ) ) : 'last_month'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<a
			href="#"
			download="report-<?php echo esc_attr( $current_range ); ?>-<?php echo esc_attr( date_i18n( 'Y-m-d', current_time( 'timestamp' ) ) ); ?>.csv"
			class="export_csv"
			data-export="table"
		>
			<?php esc_html_e( 'Export CSV', 'woocommerce-eu-vat-number' ); ?>
		</a>
		<?php
	}

	/**
	 * Output the report
	 */
	public function output_report() {
		$ranges = array(
			'prev_quarter' => __( 'Previous Quarter', 'woocommerce-eu-vat-number' ),
			'last_quarter' => __( 'Last Quarter', 'woocommerce-eu-vat-number' ),
			'quarter'      => __( 'This Quarter', 'woocommerce-eu-vat-number' ),
		);

		$current_range = ! empty( $_GET['range'] ) ? sanitize_text_field( wp_unslash( $_GET['range'] ) ) : 'quarter'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $current_range, array( 'custom', 'prev_quarter', 'last_quarter', 'quarter' ), true ) ) {
			$current_range = 'quarter';
		}

		$this->calculate_current_range( $current_range );

		$hide_sidebar = true;

		include WC()->plugin_path() . '/includes/admin/views/html-report-by-date.php';
	}

	/**
	 * Get the current range and calculate the start and end dates
	 *
	 * @param  string $current_range Report Range.
	 */
	public function calculate_current_range( $current_range ) {
		$this->chart_groupby = 'month';
		$quarter             = absint( ceil( date( 'm', current_time( 'timestamp' ) ) / 3 ) );
		$year                = absint( date( 'Y', current_time( 'timestamp' ) ) );

		switch ( $current_range ) {
			case 'prev_quarter':
				$quarter = $quarter - 2;
				if ( 0 === $quarter ) {
					$quarter = 4;
					$year --;
				} elseif ( -1 === $quarter ) {
					$quarter = 3;
					$year --;
				}
				break;
			case 'last_quarter':
				--$quarter;
				if ( 0 === $quarter ) {
					$quarter = 4;
					$year --;
				}
				break;
			case 'custom':
				parent::calculate_current_range( $current_range );
				return;
		}

		if ( 1 === $quarter ) {
			$this->start_date = strtotime( $year . '-01-01' );
			$this->end_date   = strtotime( date( 'Y-m-t', strtotime( $year . '-03-01' ) ) );
		} elseif ( 2 === $quarter ) {
			$this->start_date = strtotime( $year . '-04-01' );
			$this->end_date   = strtotime( date( 'Y-m-t', strtotime( $year . '-06-01' ) ) );
		} elseif ( 3 === $quarter ) {
			$this->start_date = strtotime( $year . '-07-01' );
			$this->end_date   = strtotime( date( 'Y-m-t', strtotime( $year . '-09-01' ) ) );
		} elseif ( 4 === $quarter ) {
			$this->start_date = strtotime( $year . '-10-01' );
			$this->end_date   = strtotime( date( 'Y-m-t', strtotime( $year . '-12-01' ) ) );
		}
	}

	/**
	 * Get the main chart
	 *
	 * @return void
	 */
	public function get_main_chart() {
		$ec_sales1 = $this->get_order_report_data(
			array(
				'data'         => array(
					'_order_total'        => array(
						'type'     => 'meta',
						'function' => 'SUM',
						'name'     => 'total_sales',
					),
					'_billing_vat_number' => array(
						'type'     => 'meta',
						'function' => '',
						'name'     => '_billing_vat_number',
					),
					'_billing_country'    => array(
						'type'     => 'meta',
						'function' => '',
						'name'     => '_billing_country',
					),
					'_shipping_country'   => array(
						'type'     => 'meta',
						'function' => '',
						'name'     => '_shipping_country',
					),
					'_order_currency'     => array(
						'type'     => 'meta',
						'function' => '',
						'name'     => '_order_currency',
					),
				),
				'where'        => array(
					array(
						'key'      => 'meta__billing_vat_number.meta_value',
						'value'    => '',
						'operator' => '!=',
					),
				),
				'group_by'     => '_billing_vat_number',
				'order_by'     => '_billing_vat_number ASC',
				'query_type'   => 'get_results',
				'order_status' => array( 'completed' ),
				'filter_range' => true,
			)
		);
		$ec_sales2 = $this->get_order_report_data(
			array(
				'data'         => array(
					'_order_total'      => array(
						'type'     => 'meta',
						'function' => 'SUM',
						'name'     => 'total_sales',
					),
					'_vat_number'       => array(
						'type'     => 'meta',
						'function' => '',
						'name'     => '_vat_number',
					),
					'_billing_country'  => array(
						'type'     => 'meta',
						'function' => '',
						'name'     => '_billing_country',
					),
					'_shipping_country' => array(
						'type'     => 'meta',
						'function' => '',
						'name'     => '_shipping_country',
					),
					'_order_currency'   => array(
						'type'     => 'meta',
						'function' => '',
						'name'     => '_order_currency',
					),
				),
				'where'        => array(
					array(
						'key'      => 'meta__vat_number.meta_value',
						'value'    => '',
						'operator' => '!=',
					),
				),
				'group_by'     => '_vat_number',
				'order_by'     => '_vat_number ASC',
				'query_type'   => 'get_results',
				'order_status' => array( 'completed' ),
				'filter_range' => true,
			)
		);

		$ec_sales = array_merge( $ec_sales1, $ec_sales2 );
		?>
		<table class="widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Billing country', 'woocommerce-eu-vat-number' ); ?></th>
					<th><?php esc_html_e( 'Shipping country', 'woocommerce-eu-vat-number' ); ?></th>
					<th><?php echo esc_html( get_option( 'woocommerce_eu_vat_number_field_label', __( 'VAT number', 'woocommerce-eu-vat-number' ) ) ); ?></th>
					<th class="total_row"><?php esc_html_e( 'Value', 'woocommerce-eu-vat-number' ); ?></th>
				</tr>
			</thead>
			<?php if ( $ec_sales ) : ?>
				<tbody>
					<?php
					foreach ( $ec_sales as $ec_sale ) {
						if (
							! in_array( $ec_sale->_billing_country, WC_EU_VAT_Number::get_eu_countries(), true ) &&
							! in_array( $ec_sale->_shipping_country, WC_EU_VAT_Number::get_eu_countries(), true )
						) {
							continue;
						}
						$vat_number = ! empty( $ec_sale->_billing_vat_number ) ? $ec_sale->_billing_vat_number : $ec_sale->_vat_number;
						?>
						<tr>
							<th scope="row"><?php echo esc_html( $ec_sale->_billing_country ); ?></th>
							<th scope="row"><?php echo esc_html( $ec_sale->_shipping_country ); ?></th>
							<th scope="row"><?php echo esc_html( $vat_number ); ?></th>
							<th scope="row"><?php echo wc_price( $ec_sale->total_sales, array( 'currency', $ec_sale->_order_currency ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
						</tr>
						<?php
					}
					?>
				</tbody>
			<?php else : ?>
				<tbody>
					<tr>
					<td colspan="3"><?php esc_html_e( 'No B2B EU orders found within this period.', 'woocommerce-eu-vat-number' ); ?></td>
					</tr>
				</tbody>
			<?php endif; ?>
		</table>
		<?php
	}
}
