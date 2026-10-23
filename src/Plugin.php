<?php
namespace Groundskeeper;

use Groundskeeper\Commands\ImportCommand;
use Groundskeeper\Commands\GroundskeeperNamespace;
use WP_CLI;

/**
 * Registers the `wp groundskeeper` command tree, plus `wp gk` as an alias.
 */
final class Plugin {

	public static function register() {
		// `wp gk` is a shorter alias for the same commands.
		foreach ( array( 'groundskeeper', 'gk' ) as $root ) {
			WP_CLI::add_command( $root, GroundskeeperNamespace::class );
			WP_CLI::add_command( "{$root} import", ImportCommand::class );
		}
	}
}
