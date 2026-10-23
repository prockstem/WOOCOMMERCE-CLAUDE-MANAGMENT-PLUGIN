<?php
/**
 * Shared helpers for tools that change store data: value validation and change tracking.
 *
 * Every write tool reports exactly what changed as a list of { field, from, to }, so the
 * assistant can tell the store owner and later phases can log it.
 *
 * @package WCMCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validation and diff helpers for write tools.
 */
final class WCMCP_Write {

	/**
	 * Schema for a money value. Accepts strings or numbers; validated in code.
	 *
	 * @param string $description Description.
	 * @return array
	 */
	public static function price_schema( $description ) {
		return array(
			'type'        => array( 'string', 'number' ),
			'description' => $description . ' Decimal in the store currency, e.g. "19.99". An empty string clears it.',
		);
	}

	/**
	 * Schema for an optional date that can be cleared.
	 *
	 * @param string $description Description.
	 * @return array
	 */
	public static function clearable_date_schema( $description ) {
		return array(
			'type'        => 'string',
			'pattern'     => '^(\d{4}-\d{2}-\d{2})?$',
			'description' => $description . ' Format YYYY-MM-DD in the store timezone. An empty string clears it.',
		);
	}

	/**
	 * Validates a money value.
	 *
	 * @param mixed  $value Value.
	 * @param string $field Field name for messages.
	 * @return string|WP_Error Decimal string, or '' to clear.
	 */
	public static function price( $value, $field ) {
		if ( null === $value || '' === $value ) {
			return '';
		}
		if ( ! is_numeric( $value ) || (float) $value < 0 ) {
			return self::error( 'wcmcp_invalid_price', sprintf( '%s must be a number of 0 or more, e.g. "19.99".', $field ) );
		}
		return wc_format_decimal( $value );
	}

	/**
	 * Validates a clearable date.
	 *
	 * @param mixed  $value Value.
	 * @param string $field Field name for messages.
	 * @param bool   $end_of_day Use 23:59:59.
	 * @return int|string|WP_Error Timestamp, or '' to clear.
	 */
	public static function date( $value, $field, $end_of_day = false ) {
		if ( '' === $value || null === $value ) {
			return '';
		}
		$timestamp = WCMCP_Format::parse_date( $value, $end_of_day );
		if ( null === $timestamp ) {
			return self::error( 'wcmcp_invalid_date', sprintf( '%s must be a real date in YYYY-MM-DD format.', $field ) );
		}
		return $timestamp;
	}

	/**
	 * Rich text for product descriptions: post-safe HTML, with script/style blocks removed
	 * entirely (wp_kses_post() would drop the tags but keep their contents as text).
	 *
	 * @param mixed $text Text.
	 * @return string
	 */
	public static function html( $text ) {
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
		return wp_kses_post( $text );
	}

	/**
	 * Reads several values through getters, normalised for comparison and display.
	 *
	 * @param object               $object  CRUD object.
	 * @param array<string,string> $getters Field => getter method.
	 * @return array<string,mixed>
	 */
	public static function snapshot( $object, array $getters ) {
		$values = array();
		foreach ( $getters as $field => $getter ) {
			$value = is_callable( array( $object, $getter ) ) ? $object->$getter( 'edit' ) : null;
			if ( $value instanceof WC_DateTime ) {
				$value = $value->date( 'Y-m-d' );
			} elseif ( is_array( $value ) ) {
				$value = array_values( $value );
				sort( $value );
			} elseif ( is_string( $value ) && strlen( $value ) > 200 ) {
				$value = WCMCP_Format::text( $value, 200 );
			}
			$values[ $field ] = $value;
		}
		return $values;
	}

	/**
	 * Fields whose values differ between two snapshots.
	 *
	 * @param array $before Snapshot before.
	 * @param array $after  Snapshot after.
	 * @return array<int, array{field:string, from:mixed, to:mixed}>
	 */
	public static function diff( array $before, array $after ) {
		$changes = array();
		foreach ( $after as $field => $value ) {
			$old = array_key_exists( $field, $before ) ? $before[ $field ] : null;
			if ( self::normalise( $old ) !== self::normalise( $value ) ) {
				$changes[] = array(
					'field' => $field,
					'from'  => $old,
					'to'    => $value,
				);
			}
		}
		return $changes;
	}

	/**
	 * Comparable form of a value (so "10" and 10.0 compare equal).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function normalise( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_numeric( $value ) ) {
			return (string) ( 0 + $value );
		}
		return (string) wp_json_encode( $value );
	}

	/**
	 * Fails when the input contains nothing to change.
	 *
	 * @param array    $input  Input.
	 * @param string[] $ignore Keys that identify the object rather than change it.
	 * @return true|WP_Error
	 */
	public static function require_changes( array $input, array $ignore ) {
		if ( empty( array_diff( array_keys( $input ), $ignore ) ) ) {
			return self::error( 'wcmcp_nothing_to_update', 'Nothing to update: pass at least one field to change.' );
		}
		return true;
	}

	/**
	 * Validation error.
	 *
	 * @param string $code    Code.
	 * @param string $message Message for the assistant.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	public static function error( $code, $message, $status = 400 ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
