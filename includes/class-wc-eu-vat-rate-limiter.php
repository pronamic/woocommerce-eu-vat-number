<?php
/**
 * Rate limiting for outbound VAT registry lookups.
 *
 * A VAT number submitted from the cart, the checkout order review or the checkout itself ends
 * up in WC_EU_VAT_Number::vat_number_is_valid(), which calls VIES or HMRC whenever the number
 * has not been looked up before. The caller picks the number, and so the cache key, so those
 * public endpoints can be used to send an unlimited number of requests to the registries.
 * This caps how many a single requester can trigger.
 *
 * Two counters are kept: one for the session, one for the connecting address. The address
 * one is ten times looser, because a proxy, a load balancer or an office network puts many
 * customers behind one address, and on a host that does not pass the customer's address
 * through they all share it. Where that is a problem for a store, either tell WooCommerce
 * the site is behind a proxy so the forwarded address is used, or raise the address limit
 * with woocommerce_eu_vat_number_lookup_address_rate_limit, which turns it off at zero.
 *
 * Counts are kept in transients. A site whose object cache drop-in is loaded but whose
 * backing server is unreachable loses its counts, since WordPress stores transients there.
 * Database-backed transient updates are serialized with a short options-table lock.
 *
 * @package woocommerce-eu-vat-number
 * @since   x.x.x
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_EU_VAT_Rate_Limiter class.
 *
 * @since 3.2.2
 */
class WC_EU_VAT_Rate_Limiter {

	/**
	 * Error code returned when a requester has run out of lookups.
	 */
	const ERROR_CODE = 'wc-eu-vat-rate-limited';

	/**
	 * Prefix for the lookup counters.
	 */
	const COUNTER_PREFIX = 'wc_eu_vat_lookups_';

	/**
	 * Object cache group used when a persistent object cache is available.
	 */
	const CACHE_GROUP = 'wc_eu_vat_rate_limit';

	/**
	 * Prefix for database-backed counter locks.
	 */
	private const LOCK_PREFIX = 'wc_eu_vat_lookup_lock_';

	/**
	 * How long a counter lock remains valid if its owner exits unexpectedly.
	 */
	private const LOCK_TTL = 30;

	/**
	 * How many times to wait for another request to finish updating a counter.
	 */
	private const LOCK_ATTEMPTS = 100;

	/**
	 * Delay between counter lock attempts, in microseconds.
	 */
	private const LOCK_RETRY_DELAY = 1000;

	/**
	 * Default number of remote lookups allowed per session, per window.
	 */
	const DEFAULT_LIMIT = 30;

	/**
	 * How much looser the per address limit is than the default per session one.
	 *
	 * A CDN, a load balancer or an office network puts many customers behind one address, so
	 * that limit cannot be as tight as the per session one without locking them out.
	 */
	const ADDRESS_LIMIT_MULTIPLIER = 10;

	/**
	 * Default length of the rate limiting window, in seconds.
	 */
	const DEFAULT_WINDOW = 5 * MINUTE_IN_SECONDS;

	/**
	 * Number of remote lookups allowed per session, per window.
	 *
	 * @since 3.2.2
	 *
	 * @return int Zero or less disables rate limiting.
	 */
	public static function get_limit() {
		/**
		 * Filters how many remote VAT registry lookups one session may trigger per window.
		 *
		 * @since 3.2.2
		 *
		 * @param int $limit Number of lookups. Zero or less disables rate limiting.
		 */
		return (int) apply_filters( 'woocommerce_eu_vat_number_lookup_rate_limit', self::DEFAULT_LIMIT );
	}

	/**
	 * Number of remote lookups allowed from one address, per window.
	 *
	 * @since 3.2.2
	 *
	 * The default is derived from the constant rather than from get_limit(), so that each
	 * filter only turns off the counter it names: taking the per session limit to zero would
	 * otherwise take the per address one down with it and leave nothing throttled at all.
	 *
	 * @return int Zero or less disables the per address limit.
	 */
	public static function get_address_limit() {
		/**
		 * Filters how many remote VAT registry lookups one address may trigger per window.
		 *
		 * @since 3.2.2
		 *
		 * @param int $limit Number of lookups. Zero or less disables the per address limit.
		 */
		return (int) apply_filters( 'woocommerce_eu_vat_number_lookup_address_rate_limit', self::DEFAULT_LIMIT * self::ADDRESS_LIMIT_MULTIPLIER );
	}

	/**
	 * Length of the rate limiting window, in seconds.
	 *
	 * @since 3.2.2
	 *
	 * @return int
	 */
	public static function get_window() {
		/**
		 * Filters the length of the VAT lookup rate limiting window.
		 *
		 * @since 3.2.2
		 *
		 * @param int $window Window length in seconds.
		 */
		return max( 1, (int) apply_filters( 'woocommerce_eu_vat_number_lookup_rate_limit_window', self::DEFAULT_WINDOW ) );
	}

	/**
	 * Claims one lookup for the current requester, if any are left.
	 *
	 * Checking and claiming are deliberately one step: done separately, a burst of concurrent
	 * requests all read the same count, all pass the check, and all go on to call the
	 * registry. A persistent object cache provides an atomic increment; the database-backed
	 * transient fallback serializes its read-modify-write with a short options-table lock.
	 *
	 * Only lookups that actually reach the network are claimed, so a customer re-submitting a
	 * number that is already cached is never throttled.
	 *
	 * @since 3.2.2
	 *
	 * @return bool False when the requester has no lookups left.
	 */
	public static function claim_lookup() {
		if ( self::is_exempt() ) {
			return true;
		}

		foreach ( self::get_buckets() as $bucket ) {
			if ( $bucket['limit'] <= 0 ) {
				continue;
			}

			$count = self::increment( $bucket['key'] );

			// Fail closed when the fallback counter cannot be updated safely.
			if ( false === $count || $count > $bucket['limit'] ) {
				self::log_limit_reached( $bucket );

				return false;
			}
		}

		return true;
	}

	/**
	 * Adds one to a counter and returns the new value.
	 *
	 * @since 3.2.2
	 *
	 * @param string $key Storage key.
	 * @return int|false New count, or false when the counter could not be updated safely.
	 */
	protected static function increment( $key ) {
		$window = self::get_window();

		if ( wp_using_ext_object_cache() ) {
			// Atomic where a persistent object cache is available.
			wp_cache_add( $key, 0, self::CACHE_GROUP, $window );
			$count = wp_cache_incr( $key, 1, self::CACHE_GROUP );

			if ( false !== $count ) {
				return (int) $count;
			}

			// The counter expired between the two calls; start the window again.
			wp_cache_set( $key, 1, self::CACHE_GROUP, $window );

			return 1;
		}

		$lock = self::acquire_lock( $key );

		if ( false === $lock ) {
			return false;
		}

		try {
			$counter = self::get_counter( $key );
			++$counter['count'];

			set_transient( $key, $counter, max( 1, $counter['expires'] - time() ) );

			return $counter['count'];
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Acquires a short database-backed lock for a transient counter.
	 *
	 * The options table has a unique index on option_name, so INSERT IGNORE makes acquisition
	 * atomic across PHP workers. The value carries an expiry and a token: stale locks can be
	 * removed without deleting a newer request's lock, and the current owner releases only
	 * the row it created.
	 *
	 * @since 3.2.2
	 *
	 * @param string $key Counter storage key.
	 * @return array|false Lock name and value, or false when the lock was not acquired.
	 */
	private static function acquire_lock( $key ) {
		global $wpdb;

		$lock_name = self::LOCK_PREFIX . hash( 'sha256', $key );
		$token     = wp_generate_uuid4();

		for ( $attempt = 0; $attempt < self::LOCK_ATTEMPTS; $attempt++ ) {
			$lock_value = $token . '|' . ( microtime( true ) + self::LOCK_TTL );
			$inserted   = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The unique option_name index is the locking primitive.
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
					$lock_name,
					$lock_value
				)
			);

			if ( 1 === $inserted ) {
				return array(
					'name'  => $lock_name,
					'value' => $lock_value,
				);
			}

			$current_value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Locks must bypass per-process option caches.
				$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $lock_name )
			);
			$separator     = is_string( $current_value ) ? strrpos( $current_value, '|' ) : false;

			if ( false !== $separator && (float) substr( $current_value, $separator + 1 ) <= microtime( true ) ) {
				$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-delete avoids removing a replacement lock.
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
						$lock_name,
						$current_value
					)
				);
				continue;
			}

			usleep( self::LOCK_RETRY_DELAY );
		}

		return false;
	}

	/**
	 * Releases a database-backed counter lock when the caller still owns it.
	 *
	 * @since 3.2.2
	 *
	 * @param array $lock Lock name and value returned by acquire_lock().
	 * @return void
	 */
	private static function release_lock( $lock ) {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-delete releases only this request's lock.
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$lock['name'],
				$lock['value']
			)
		);
	}

	/**
	 * Records that a requester has run out of lookups.
	 *
	 * The customer is only told that too many checks were made. Without a log line a merchant
	 * looking into that report has nothing to go on, and in particular no way to tell the two
	 * causes apart: the address counter is shared by everyone whose requests arrive from the
	 * same connecting address, so a store where that counter is the one running out is either
	 * being abused or is behind a proxy that is not passing the customer's address through.
	 *
	 * @since 3.2.2
	 *
	 * @param array $bucket The counter that ran out. See get_buckets().
	 * @return void
	 */
	protected static function log_limit_reached( $bucket ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->notice(
			sprintf(
				'VAT lookup rate limit reached for one %1$s: more than %2$d lookups in %3$d seconds. Counter: %4$s.',
				$bucket['type'],
				$bucket['limit'],
				self::get_window(),
				$bucket['key']
			),
			array( 'source' => 'woocommerce-eu-vat-number' )
		);
	}

	/**
	 * Error returned to the customer when they have run out of lookups.
	 *
	 * @since 3.2.2
	 *
	 * @return WP_Error
	 */
	public static function get_error() {
		return new WP_Error(
			self::ERROR_CODE,
			__( 'We could not check this VAT number because too many checks have been made from your connection. Please try again in a few minutes.', 'woocommerce-eu-vat-number' )
		);
	}

	/**
	 * The counters the current request is measured against.
	 *
	 * Per session and per address, because neither alone is enough: a session can be thrown
	 * away, and an address is shared by everyone behind a proxy.
	 *
	 * @since 3.2.2
	 *
	 * @return array List of arrays with 'key', 'limit' and 'type'.
	 */
	protected static function get_buckets() {
		$buckets    = array();
		$requester  = self::get_requester_id();
		$ip_address = self::get_ip_address();

		// Identifiers are hashed so customer addresses are not written to the options table.
		if ( '' !== $requester ) {
			$buckets[] = array(
				'key'   => self::COUNTER_PREFIX . wp_hash( $requester ),
				'limit' => self::get_limit(),
				'type'  => 'session',
			);
		}

		if ( '' !== $ip_address ) {
			$buckets[] = array(
				'key'   => self::COUNTER_PREFIX . wp_hash( 'addr:' . $ip_address ),
				'limit' => self::get_address_limit(),
				'type'  => 'address',
			);
		}

		if ( ! $buckets ) {
			$buckets[] = array(
				'key'   => self::COUNTER_PREFIX . wp_hash( 'unknown' ),
				'limit' => self::get_limit(),
				'type'  => 'unidentified requester',
			);
		}

		return $buckets;
	}

	/**
	 * Returns a counter, resetting it when its window has passed.
	 *
	 * @since 3.2.2
	 *
	 * @param string $key Storage key.
	 * @return array {
	 *     @type int $count   Lookups made in the current window.
	 *     @type int $expires Timestamp the current window ends at.
	 * }
	 */
	protected static function get_counter( $key ) {
		$counter = get_transient( $key );

		if ( ! is_array( $counter ) || ! isset( $counter['count'], $counter['expires'] ) || $counter['expires'] <= time() ) {
			$counter = array(
				'count'   => 0,
				'expires' => time() + self::get_window(),
			);
		}

		return array(
			'count'   => (int) $counter['count'],
			'expires' => (int) $counter['expires'],
		);
	}

	/**
	 * Identifies the requester lookups are counted against.
	 *
	 * Only an established session counts: WooCommerce hands a fresh customer id to a caller
	 * that sends no cookie, so counting those would mean a new key on every request.
	 *
	 * @since 3.2.2
	 *
	 * @return string
	 */
	protected static function get_requester_id() {
		$user_id = get_current_user_id();

		if ( $user_id ) {
			return 'user:' . $user_id;
		}

		if ( function_exists( 'WC' ) && WC()->session && WC()->session->has_session() ) {
			$customer_id = WC()->session->get_customer_id();

			if ( $customer_id ) {
				return 'session:' . $customer_id;
			}
		}

		$ip_address = self::get_ip_address();

		return '' !== $ip_address ? 'ip:' . $ip_address : '';
	}

	/**
	 * The address the current request came from.
	 *
	 * WC_Geolocation::get_ip_address() returns X-Real-IP verbatim, so a requester could pick
	 * their own counter with it. Use the connecting address, and only consult forwarded
	 * headers when the store has told WooCommerce it is behind a proxy.
	 *
	 * @since 3.2.2
	 *
	 * @return string Empty when no valid address is available.
	 */
	protected static function get_ip_address() {
		$ip_address = '';

		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip_address = (string) rest_is_ip_address( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) );
		}

		$proxy_support = false;

		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Utilities\RateLimits' ) ) {
			$proxy_support = (bool) \Automattic\WooCommerce\StoreApi\Utilities\RateLimits::get_options()->proxy_support;
		}

		/**
		 * Filters whether forwarded headers may identify the requester for VAT lookup rate limiting.
		 *
		 * @since 3.2.2
		 *
		 * @param bool $proxy_support Whether to trust forwarded headers.
		 */
		if ( apply_filters( 'woocommerce_eu_vat_number_lookup_rate_limit_proxy_support', $proxy_support ) ) {
			$forwarded = (string) rest_is_ip_address( WC_Geolocation::get_ip_address() );

			if ( '' !== $forwarded ) {
				$ip_address = $forwarded;
			}
		}

		return $ip_address;
	}

	/**
	 * Whether the current request is exempt from rate limiting.
	 *
	 * Store managers validating numbers in wp-admin, WP-CLI, cron and Action Scheduler
	 * (which runs subscription renewals) are trusted, non public callers.
	 *
	 * @since 3.2.2
	 *
	 * @return bool
	 */
	protected static function is_exempt() {
		$is_exempt = ( function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' ) ) // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Capability registered by WooCommerce.
			|| wp_doing_cron()
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| did_action( 'action_scheduler_before_execute' ) > 0
			|| doing_action( 'action_scheduler_run_queue' );

		/**
		 * Filters whether the current request may trigger remote VAT lookups without being rate limited.
		 *
		 * @since 3.2.2
		 *
		 * @param bool $is_exempt Whether the request is exempt.
		 */
		return (bool) apply_filters( 'woocommerce_eu_vat_number_lookup_rate_limit_exempt', $is_exempt );
	}
}
