<?php
namespace Groundskeeper\Commands;

use WP_CLI\Dispatcher\CommandNamespace;

/**
 * Bulk content imports, cleanup and site reports.
 *
 * ## EXAMPLES
 *
 *     # Import posts from a CSV file, previewing first
 *     $ wp groundskeeper import posts articles.csv --dry-run
 *
 *     # See what cleanup would remove, then run it
 *     $ wp groundskeeper cleanup all --dry-run
 *     $ wp groundskeeper cleanup all --yes
 *
 *     # Write a full site report to a Markdown file
 *     $ wp groundskeeper report all --file=site-report.md
 */
class GroundskeeperNamespace extends CommandNamespace {
}
