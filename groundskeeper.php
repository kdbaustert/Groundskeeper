<?php
/**
 * Plugin Name:       Groundskeeper
 * Description:       WP-CLI commands for bulk imports, cleanup and site reports (wp groundskeeper).
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Kenny Baustert
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       groundskeeper
 *
 * @package Groundskeeper
 */

// CLI only. Nothing loads on front-end or admin requests.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || defined( 'GROUNDSKEEPER_VERSION' ) ) {
	return;
}

define( 'GROUNDSKEEPER_VERSION', '1.0.0' );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Groundskeeper\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

\Groundskeeper\Plugin::register();
