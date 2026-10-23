<?php
namespace Groundskeeper\Support;

use RuntimeException;

/**
 * Reads rows from CSV, JSON or JSON Lines files.
 *
 * CSV is streamed. JSON has to be decoded in one go, so for very large
 * imports CSV or .jsonl is the better choice.
 */
class DataReader {

	private $path;
	private $format;
	private $delimiter;

	public function __construct( $path, $format = 'auto', $delimiter = ',' ) {
		if ( ! is_readable( $path ) ) {
			throw new RuntimeException( "Can't read file: {$path}" );
		}

		$this->path      = $path;
		$this->delimiter = $delimiter;
		$this->format    = 'auto' === $format ? self::guess_format( $path ) : $format;

		if ( ! in_array( $this->format, array( 'csv', 'json', 'jsonl' ), true ) ) {
			throw new RuntimeException( "Unsupported format '{$this->format}'. Use csv, json or jsonl." );
		}
	}

	public static function guess_format( $path ) {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( 'ndjson' === $ext ) {
			return 'jsonl';
		}

		return in_array( $ext, array( 'json', 'jsonl' ), true ) ? $ext : 'csv';
	}

	public function format() {
		return $this->format;
	}

	/**
	 * Counting means a second pass over the file, but a progress bar
	 * without a total isn't much use.
	 */
	public function count() {
		$count = 0;
		foreach ( $this->rows() as $row ) {
			++$count;
		}
		return $count;
	}

	/**
	 * @return \Generator<int, array> Row number (1-based, header excluded) => row.
	 */
	public function rows() {
		switch ( $this->format ) {
			case 'json':
				return $this->json_rows();
			case 'jsonl':
				return $this->jsonl_rows();
			default:
				return $this->csv_rows();
		}
	}

	private function csv_rows() {
		$handle = fopen( $this->path, 'r' );
		$header = fgetcsv( $handle, 0, $this->delimiter, '"', '\\' );

		if ( ! $header ) {
			fclose( $handle );
			throw new RuntimeException( 'CSV file is empty or has no header row.' );
		}

		// Excel likes to prepend a BOM.
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );
		$header    = array_map( 'trim', $header );
		$columns   = count( $header );
		$line      = 0;

		while ( true ) {
			$values = fgetcsv( $handle, 0, $this->delimiter, '"', '\\' );
			if ( false === $values ) {
				break;
			}
			++$line;

			if ( array( null ) === $values ) {
				continue; // Blank line.
			}

			if ( count( $values ) < $columns ) {
				$values = array_pad( $values, $columns, '' );
			} elseif ( count( $values ) > $columns ) {
				$values = array_slice( $values, 0, $columns );
			}

			yield $line => array_combine( $header, $values );
		}

		fclose( $handle );
	}

	private function json_rows() {
		$data = json_decode( file_get_contents( $this->path ), true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new RuntimeException( 'Invalid JSON: ' . json_last_error_msg() );
		}

		// Accept either a bare array or {"items": [...]}.
		if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
			$data = $data['items'];
		}

		if ( ! is_array( $data ) || ( $data && array_keys( $data ) !== range( 0, count( $data ) - 1 ) ) ) {
			throw new RuntimeException( 'JSON must be an array of objects.' );
		}

		foreach ( $data as $i => $row ) {
			if ( is_array( $row ) ) {
				yield $i + 1 => $row;
			}
		}
	}

	private function jsonl_rows() {
		$handle = fopen( $this->path, 'r' );
		$line   = 0;

		while ( ! feof( $handle ) ) {
			$raw = fgets( $handle );
			++$line;
			$raw = trim( (string) $raw );

			if ( '' === $raw ) {
				continue;
			}

			$row = json_decode( $raw, true );

			if ( ! is_array( $row ) ) {
				fclose( $handle );
				throw new RuntimeException( "Invalid JSON on line {$line}." );
			}

			yield $line => $row;
		}

		fclose( $handle );
	}
}
