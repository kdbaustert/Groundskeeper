<?php
namespace Groundskeeper\Commands;

use WP_CLI;
use WP_CLI\Utils;

abstract class BaseCommand {

	protected function flag( $assoc_args, $name ) {
		return (bool) Utils\get_flag_value( $assoc_args, $name, false );
	}

	protected function int_arg( $assoc_args, $name, $fallback ) {
		if ( ! isset( $assoc_args[ $name ] ) ) {
			return $fallback;
		}

		if ( ! is_numeric( $assoc_args[ $name ] ) || (int) $assoc_args[ $name ] < 0 ) {
			WP_CLI::error( "--{$name} must be a whole number of 0 or more." );
		}

		return (int) $assoc_args[ $name ];
	}

	protected function render( array $items, array $fields, $format = 'table' ) {
		if ( ! $items && 'table' === $format ) {
			WP_CLI::log( 'Nothing to show.' );
			return;
		}

		Utils\format_items( $format, $items, $fields );
	}

	/**
	 * Turns an associative array into two-column rows for format_items().
	 */
	protected function pairs( array $data, $key_label = 'field', $value_label = 'value' ) {
		$rows = array();
		foreach ( $data as $key => $value ) {
			$rows[] = array(
				$key_label   => $key,
				$value_label => $value,
			);
		}
		return $rows;
	}

	/**
	 * Long-running loops leak memory through the query log and the object
	 * cache, which is what usually kills a big import halfway through.
	 */
	protected function free_memory() {
		global $wpdb;

		$wpdb->queries = array();

		if ( function_exists( 'WP_CLI\Utils\wp_clear_object_cache' ) ) {
			Utils\wp_clear_object_cache();
		} elseif ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}

	protected function days_ago_gmt( $days ) {
		return gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
	}

	protected function format_bytes( $bytes ) {
		if ( null === $bytes ) {
			return 'n/a';
		}

		$formatted = size_format( (int) $bytes, $bytes >= MB_IN_BYTES ? 1 : 0 );

		return $formatted ? $formatted : '0 B';
	}

	protected function load_admin_includes() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
}
