<?php
namespace Groundskeeper\Support;

use WP_CLI\Utils;

/**
 * Tallies what happened to each row and optionally writes it out as CSV.
 */
class ImportLog {

	private $entries = array();
	private $totals  = array(
		'created' => 0,
		'updated' => 0,
		'skipped' => 0,
		'failed'  => 0,
	);

	public function add( $row, $action, $id = '', $label = '', $message = '' ) {
		if ( isset( $this->totals[ $action ] ) ) {
			++$this->totals[ $action ];
		}

		$this->entries[] = array(
			'row'     => $row,
			'action'  => $action,
			'id'      => $id,
			'label'   => $label,
			'message' => $message,
		);
	}

	public function totals() {
		return $this->totals;
	}

	public function failures() {
		return $this->filter(
			static function ( $entry ) {
				return 'failed' === $entry['action'];
			}
		);
	}

	/**
	 * Rows that went in but with something worth knowing, like an image
	 * that couldn't be downloaded or a term that didn't exist.
	 */
	public function notes() {
		return $this->filter(
			static function ( $entry ) {
				return '' !== $entry['message'] && in_array( $entry['action'], array( 'created', 'updated' ), true );
			}
		);
	}

	private function filter( callable $callback ) {
		return array_values( array_filter( $this->entries, $callback ) );
	}

	public function write_csv( $path ) {
		$handle = fopen( $path, 'w' );

		if ( ! $handle ) {
			return false;
		}

		fputcsv( $handle, array( 'row', 'action', 'id', 'label', 'message' ), ',', '"', '\\' );
		foreach ( $this->entries as $entry ) {
			fputcsv( $handle, array_values( $entry ), ',', '"', '\\' );
		}
		fclose( $handle );

		return true;
	}

	public function print_summary( $dry_run = false ) {
		$rows = array();
		foreach ( $this->totals as $action => $count ) {
			$rows[] = array(
				'result' => $dry_run && in_array( $action, array( 'created', 'updated' ), true ) ? "would be {$action}" : $action,
				'count'  => $count,
			);
		}

		Utils\format_items( 'table', $rows, array( 'result', 'count' ) );

		$this->print_rows( 'Imported with notes:', $this->notes() );
		$this->print_rows( 'Failed rows:', $this->failures() );
	}

	private function print_rows( $heading, array $rows ) {
		if ( ! $rows ) {
			return;
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( $heading );
		Utils\format_items( 'table', array_slice( $rows, 0, 25 ), array( 'row', 'label', 'message' ) );

		if ( count( $rows ) > 25 ) {
			\WP_CLI::log( sprintf( '...and %d more. Use --log=<file> for the full list.', count( $rows ) - 25 ) );
		}
	}
}
