<?php
/**
 * Action log: who ran which tool, when, and with what result.
 *
 * Emails, phone numbers and street addresses are masked before anything is written, secrets and
 * confirmation tokens are never stored, and rows older than the retention period are removed daily.
 *
 * @package WCMCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Log storage, masking and retention.
 */
final class WCMCP_Log {

	const DB_VERSION = '1';

	const CRON_HOOK = 'wcmcp_log_cleanup';

	const DEFAULT_RETENTION = 30;

	const MAX_DETAILS = 4000;

	/**
	 * Error codes that mean "not allowed" rather than "failed".
	 *
	 * @var string[]
	 */
	private static $denial_codes = array(
		'wcmcp_not_authenticated',
		'wcmcp_pack_disabled',
		'wcmcp_key_cannot_read',
		'wcmcp_key_read_only',
		'wcmcp_insufficient_capability',
		'wcmcp_cannot_publish',
		'wcmcp_cannot_delete',
		'wcmcp_cannot_edit_user',
	);

	/**
	 * Keys never written to the log.
	 *
	 * @var string[]
	 */
	private static $secret_keys = array( 'confirmation_token', 'consumer_key', 'consumer_secret', 'password', 'user_pass', 'token' );

	/**
	 * Hooks retention cleanup and makes sure the table exists.
	 *
	 * @return void
	 */
	public static function init() {
		if ( get_option( 'wcmcp_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
		add_action( self::CRON_HOOK, array( __CLASS__, 'cleanup' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Log table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wcmcp_log';
	}

	/**
	 * Creates or upgrades the log table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			tool varchar(100) NOT NULL,
			operation varchar(20) NOT NULL,
			result varchar(20) NOT NULL,
			code varchar(100) NOT NULL DEFAULT '',
			key_id bigint(20) unsigned NOT NULL DEFAULT 0,
			truncated_key varchar(7) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			details longtext NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY tool (tool)
			) {$collate};"
		);

		update_option( 'wcmcp_db_version', self::DB_VERSION );
	}

	/**
	 * Whether logging is switched on.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return 'yes' === get_option( 'wcmcp_log_enabled', 'yes' );
	}

	/**
	 * Whether successful read-only calls are logged too.
	 *
	 * @return bool
	 */
	public static function logs_reads() {
		return 'yes' === get_option( 'wcmcp_log_reads', 'no' );
	}

	/**
	 * Days to keep log rows (1–365).
	 *
	 * @return int
	 */
	public static function retention_days() {
		$days = absint( get_option( 'wcmcp_log_retention_days', self::DEFAULT_RETENTION ) );
		return min( 365, max( 1, $days ? $days : self::DEFAULT_RETENTION ) );
	}

	/**
	 * Records one tool call.
	 *
	 * @param string $tool   Ability name.
	 * @param string $op     Operation type.
	 * @param array  $input  Tool input.
	 * @param mixed  $result Tool result or WP_Error.
	 * @return void
	 */
	public static function record( $tool, $op, array $input, $result ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		$is_error = is_wp_error( $result );
		$code     = $is_error ? (string) $result->get_error_code() : '';
		if ( $is_error ) {
			$status = in_array( $code, self::$denial_codes, true ) ? 'denied' : 'error';
		} elseif ( is_array( $result ) && isset( $result['done'] ) && false === $result['done'] ) {
			$status = 'preview';
		} else {
			$status = 'success';
		}

		// Successful reads are frequent and change nothing; keep them only if asked to.
		if ( WCMCP_Permissions::OP_READ === $op && 'success' === $status && ! self::logs_reads() ) {
			return;
		}

		$details = array( 'input' => $input );
		if ( $is_error ) {
			$details['message'] = $result->get_error_message();
		} elseif ( is_array( $result ) ) {
			$details['result'] = self::summarise( $result );
		}

		$json = (string) wp_json_encode( self::mask( $details ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( strlen( $json ) > self::MAX_DETAILS ) {
			$json = (string) wp_json_encode( array( 'truncated' => substr( $json, 0, self::MAX_DETAILS - 100 ) ) );
		}

		$context = WCMCP_Auth::get_context();

		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			array(
				'created_at'    => current_time( 'mysql', true ),
				'tool'          => substr( (string) $tool, 0, 100 ),
				'operation'     => substr( (string) $op, 0, 20 ),
				'result'        => $status,
				'code'          => substr( $code, 0, 100 ),
				'key_id'        => $context ? (int) $context['key_id'] : 0,
				'truncated_key' => $context ? (string) $context['truncated_key'] : '',
				'user_id'       => get_current_user_id(),
				'details'       => $json,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
		);
	}

	/**
	 * The parts of a result worth keeping: IDs, counts and change lists.
	 *
	 * @param array $result Tool result.
	 * @return array
	 */
	private static function summarise( array $result ) {
		$summary = array();
		foreach ( array( 'done', 'created', 'refund_id', 'note_id', 'order_id', 'coupon_id', 'code', 'permanently', 'total' ) as $key ) {
			if ( array_key_exists( $key, $result ) && is_scalar( $result[ $key ] ) ) {
				$summary[ $key ] = $result[ $key ];
			}
		}
		foreach ( array( 'order' => 'order_id', 'product' => 'product_id', 'variation' => 'product_id', 'customer' => 'customer_id', 'coupon' => 'coupon_id' ) as $object => $id ) {
			if ( isset( $result[ $object ][ $id ] ) ) {
				$summary[ $object . '_id' ] = $result[ $object ][ $id ];
			}
		}
		if ( isset( $result['changes'] ) && is_array( $result['changes'] ) ) {
			$summary['changes'] = $result['changes'];
		}
		if ( isset( $result['refund']['amount'] ) ) {
			$summary['amount'] = $result['refund']['amount'];
		}
		if ( isset( $result['preview']['amount'] ) ) {
			$summary['amount'] = $result['preview']['amount'];
		}
		if ( isset( $result['items'] ) && is_array( $result['items'] ) ) {
			$summary['items_returned'] = count( $result['items'] );
		}
		return $summary;
	}

	/**
	 * Masks personal data and drops secrets, recursively.
	 *
	 * @param mixed  $value Value.
	 * @param string $key   Key the value is stored under.
	 * @return mixed
	 */
	public static function mask( $value, $key = '' ) {
		$key = strtolower( (string) $key );

		if ( is_array( $value ) ) {
			// Change-list rows: mask "from"/"to" according to the field they describe.
			if ( isset( $value['field'] ) && array_key_exists( 'from', $value ) && array_key_exists( 'to', $value ) ) {
				$field = (string) $value['field'];
				return array(
					'field' => $field,
					'from'  => self::mask( $value['from'], $field ),
					'to'    => self::mask( $value['to'], $field ),
				);
			}

			$masked = array();
			foreach ( $value as $child_key => $child ) {
				if ( in_array( strtolower( (string) $child_key ), self::$secret_keys, true ) ) {
					continue;
				}
				$masked[ $child_key ] = self::mask( $child, is_int( $child_key ) ? $key : (string) $child_key );
			}
			return $masked;
		}

		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		if ( preg_match( '/email/', $key ) ) {
			return self::mask_email( $value );
		}
		if ( preg_match( '/phone/', $key ) ) {
			return self::mask_phone( $value );
		}
		if ( preg_match( '/address|postcode|zip|street|city|state/', $key ) ) {
			return '[masked]';
		}

		return self::mask_text( $value );
	}

	/**
	 * a***@example.com. Wildcard restrictions like *@example.com stay readable.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	public static function mask_email( $email ) {
		$at = strrpos( $email, '@' );
		if ( false === $at ) {
			return self::mask_text( $email );
		}
		$local = substr( $email, 0, $at );
		if ( '*' === $local ) {
			return $email;
		}
		return substr( $local, 0, 1 ) . '***' . substr( $email, $at );
	}

	/**
	 * Keeps only the last two digits.
	 *
	 * @param string $phone Phone.
	 * @return string
	 */
	public static function mask_phone( $phone ) {
		$digits = preg_replace( '/\D/', '', $phone );
		return strlen( $digits ) > 2 ? '***' . substr( $digits, -2 ) : '***';
	}

	/**
	 * Masks emails and phone-like numbers inside free text (notes, reasons).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function mask_text( $text ) {
		$text = preg_replace_callback(
			'/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
			static function ( $m ) {
				return self::mask_email( $m[0] );
			},
			$text
		);
		// Sequences of 7+ digits, allowing spaces, dots, dashes and brackets: phone numbers.
		return preg_replace_callback(
			'/\+?\d[\d\s().\-]{5,}\d/',
			static function ( $m ) {
				// Dates (2026-10-23, 2026-10-23T10:00) have 8+ digits too; they are not personal data.
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $m[0] ) ) {
					return $m[0];
				}
				return strlen( preg_replace( '/\D/', '', $m[0] ) ) >= 7 ? self::mask_phone( $m[0] ) : $m[0];
			},
			$text
		);
	}

	/**
	 * Deletes rows older than the retention period.
	 *
	 * @return int Rows deleted.
	 */
	public static function cleanup() {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::retention_days() * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s', $cutoff ) );
	}

	/**
	 * Deletes every row.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . self::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * A page of log rows, newest first.
	 *
	 * @param int $page     Page.
	 * @param int $per_page Rows per page.
	 * @return array{rows:array, total:int}
	 */
	public static function query( $page, $per_page ) {
		global $wpdb;
		$offset = ( max( 1, (int) $page ) - 1 ) * $per_page;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d OFFSET %d', $per_page, $offset ), ARRAY_A );
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() );
		// phpcs:enable
		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}
}
