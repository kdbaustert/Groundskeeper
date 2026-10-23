<?php
namespace Groundskeeper\Commands;

use WP_CLI;
use WP_CLI\Utils;

/**
 * Site reports: overview, content, media, database, plugins and users.
 *
 * Every report takes --format (table, csv, json, yaml). `report all` puts
 * them together into one Markdown or JSON file, handy for client handoffs
 * and monthly maintenance notes.
 */
class ReportCommand extends BaseCommand {

	/**
	 * Show versions, settings and headline counts for the site.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp groundskeeper report overview
	 *     $ wp groundskeeper report overview --format=json
	 */
	public function overview( $args, $assoc_args ) {
		$data = $this->overview_data();

		if ( 'table' === $assoc_args['format'] ) {
			$this->render( $this->pairs( $data ), array( 'field', 'value' ) );
		} else {
			WP_CLI::print_value( $data, array( 'format' => $assoc_args['format'] ) );
		}
	}

	/**
	 * Count content by type and status, and flag common quality gaps.
	 *
	 * Checks published content for: no featured image, no excerpt, empty
	 * content, still in Uncategorized, and not updated in a long time.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<type>]
	 * : Only report on one post type.
	 *
	 * [--stale-days=<days>]
	 * : Published content not modified in this many days counts as stale.
	 * ---
	 * default: 365
	 * ---
	 *
	 * [--list=<check>]
	 * : List the posts failing one check instead of showing counts.
	 * ---
	 * options:
	 *   - no-featured-image
	 *   - no-excerpt
	 *   - empty-content
	 *   - uncategorized
	 *   - stale
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp groundskeeper report content
	 *     $ wp groundskeeper report content --list=no-featured-image --post_type=post
	 *     $ wp groundskeeper report content --list=stale --stale-days=730 --format=csv > stale.csv
	 */
	public function content( $args, $assoc_args ) {
		$types = $this->content_types( Utils\get_flag_value( $assoc_args, 'post_type' ) );
		$stale = $this->int_arg( $assoc_args, 'stale-days', 365 );

		if ( ! empty( $assoc_args['list'] ) ) {
			$posts = $this->failing_posts( $assoc_args['list'], $types, $stale );
			$this->render( $posts, array( 'ID', 'post_type', 'title', 'date', 'modified', 'url' ), $assoc_args['format'] );
			return;
		}

		$fields = array( 'type', 'published', 'drafts', 'pending', 'scheduled', 'private', 'trash', 'no_featured_image', 'no_excerpt', 'empty_content', 'uncategorized', 'stale' );
		$this->render( $this->content_data( $types, $stale ), $fields, $assoc_args['format'] );
	}

	/**
	 * Summarise the media library: counts and disk use by type, unattached
	 * files, files missing from disk and the largest uploads.
	 *
	 * ## OPTIONS
	 *
	 * [--top=<n>]
	 * : How many of the largest files to list.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--skip-size]
	 * : Don't check files on disk. Much faster on big libraries, but no
	 * sizes or missing-file counts.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 */
	public function media( $args, $assoc_args ) {
		$data = $this->media_data( $this->int_arg( $assoc_args, 'top', 10 ), ! $this->flag( $assoc_args, 'skip-size' ) );

		if ( 'table' !== $assoc_args['format'] ) {
			WP_CLI::print_value( $data, array( 'format' => $assoc_args['format'] ) );
			return;
		}

		$this->render( $this->pairs( $data['summary'] ), array( 'field', 'value' ) );

		WP_CLI::log( "\nBy type:" );
		$this->render( $data['by_type'], array( 'type', 'files', 'size' ) );

		if ( $data['largest'] ) {
			WP_CLI::log( "\nLargest files:" );
			$this->render( $data['largest'], array( 'ID', 'file', 'size' ) );
		}

		if ( $data['missing'] ) {
			WP_CLI::log( "\nMissing from disk (first 25):" );
			$this->render( array_slice( $data['missing'], 0, 25 ), array( 'ID', 'file' ) );
		}
	}

	/**
	 * Show table sizes and the heaviest autoloaded options.
	 *
	 * Autoloaded options are read on every request, so a large total here
	 * (over ~800 KB) is worth chasing down.
	 *
	 * ## OPTIONS
	 *
	 * [--top=<n>]
	 * : How many autoloaded options to list.
	 * ---
	 * default: 15
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 */
	public function database( $args, $assoc_args ) {
		$data = $this->database_data( $this->int_arg( $assoc_args, 'top', 15 ) );

		if ( 'table' !== $assoc_args['format'] ) {
			WP_CLI::print_value( $data, array( 'format' => $assoc_args['format'] ) );
			return;
		}

		$this->render( $data['tables'], array( 'table', 'rows', 'size' ) );
		WP_CLI::log( sprintf( "\nTotal: %s\n", $data['total_size'] ) );

		WP_CLI::log( sprintf( 'Autoloaded options: %s across %s options', $data['autoload']['size'], number_format_i18n( $data['autoload']['count'] ) ) );
		$this->render( $data['autoload']['largest'], array( 'option', 'size' ) );
	}

	/**
	 * List plugins with their status and whether an update is available.
	 *
	 * Update info comes from WordPress's last update check. Pass --refresh
	 * to check again now.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by status.
	 * ---
	 * options:
	 *   - active
	 *   - inactive
	 *   - must-use
	 *   - dropin
	 * ---
	 *
	 * [--updates]
	 * : Only show plugins with an update available.
	 *
	 * [--refresh]
	 * : Check wordpress.org for updates before reporting.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 */
	public function plugins( $args, $assoc_args ) {
		if ( $this->flag( $assoc_args, 'refresh' ) ) {
			wp_update_plugins();
		}

		$plugins = $this->plugin_data();

		if ( ! empty( $assoc_args['status'] ) ) {
			$plugins = wp_list_filter( $plugins, array( 'status' => $assoc_args['status'] ) );
		}

		if ( $this->flag( $assoc_args, 'updates' ) ) {
			$plugins = array_filter(
				$plugins,
				static function ( $plugin ) {
					return '' !== $plugin['update'];
				}
			);
		}

		$this->render( array_values( $plugins ), array( 'name', 'file', 'status', 'version', 'update' ), $assoc_args['format'] );
	}

	/**
	 * Count users by role and list administrators.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 */
	public function users( $args, $assoc_args ) {
		$data = $this->user_data();

		if ( 'table' !== $assoc_args['format'] ) {
			WP_CLI::print_value( $data, array( 'format' => $assoc_args['format'] ) );
			return;
		}

		$this->render( $data['roles'], array( 'role', 'users' ) );
		WP_CLI::log( "\nAdministrators:" );
		$this->render( $data['administrators'], array( 'ID', 'login', 'email', 'registered' ) );
	}

	/**
	 * Build every report into one document.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : Write to this file. A .json extension writes JSON, anything else
	 * Markdown. Without it, Markdown goes to stdout.
	 *
	 * [--skip-size]
	 * : Don't check media files on disk.
	 *
	 * [--stale-days=<days>]
	 * : Days without changes before published content counts as stale.
	 * ---
	 * default: 365
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp groundskeeper report all --file=reports/$(date +%F).md
	 *     $ wp groundskeeper report all --file=report.json --skip-size
	 */
	public function all( $args, $assoc_args ) {
		$stale = $this->int_arg( $assoc_args, 'stale-days', 365 );

		$report = array(
			'generated' => wp_date( 'Y-m-d H:i T' ),
			'overview'  => $this->overview_data(),
			'content'   => $this->content_data( $this->content_types(), $stale ),
			'media'     => $this->media_data( 10, ! $this->flag( $assoc_args, 'skip-size' ) ),
			'database'  => $this->database_data( 10 ),
			'plugins'   => $this->plugin_data(),
			'users'     => $this->user_data(),
		);

		$file = Utils\get_flag_value( $assoc_args, 'file' );

		if ( $file && 'json' === strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) ) {
			$output = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		} else {
			$output = $this->markdown( $report, $stale );
		}

		if ( ! $file ) {
			WP_CLI::line( $output );
			return;
		}

		$dir = dirname( $file );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			WP_CLI::error( "Couldn't create directory {$dir}" );
		}

		if ( false === file_put_contents( $file, $output ) ) {
			WP_CLI::error( "Couldn't write {$file}" );
		}

		WP_CLI::success( "Report written to {$file}" );
	}

	/*
	 * Data
	 */

	private function overview_data() {
		global $wpdb, $wp_version;

		$this->load_admin_includes();

		$theme    = wp_get_theme();
		$plugins  = array_filter(
			$this->plugin_data(),
			static function ( $plugin ) {
				return in_array( $plugin['status'], array( 'active', 'network', 'inactive' ), true );
			}
		);
		$active   = wp_list_filter( $plugins, array( 'status' => 'inactive' ), 'NOT' );
		$updates  = array_filter( wp_list_pluck( $plugins, 'update' ) );
		$comments = wp_count_comments();
		$db       = $this->database_data( 0 );
		$cron     = $this->cron_stats();

		$published = array();
		foreach ( $this->content_types() as $type ) {
			$counts = wp_count_posts( $type );
			$object = get_post_type_object( $type );

			$count = isset( $counts->publish ) ? (int) $counts->publish : 0;

			$published[] = number_format_i18n( $count ) . ' ' . ( 1 === $count ? $object->labels->singular_name : $object->labels->name );
		}

		$core_update = '';
		$core        = get_site_transient( 'update_core' );
		if ( isset( $core->updates[0]->response ) && 'upgrade' === $core->updates[0]->response ) {
			$core_update = ' (update available: ' . $core->updates[0]->current . ')';
		}

		$attachments = wp_count_posts( 'attachment' );

		return array(
			'Site'               => get_bloginfo( 'name' ),
			'Home URL'           => home_url(),
			'WordPress'          => $wp_version . $core_update,
			'PHP'                => PHP_VERSION,
			'Database'           => $this->db_label(),
			'Multisite'          => is_multisite() ? 'yes' : 'no',
			'Theme'              => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) . ( $theme->parent() ? ' (child of ' . $theme->parent()->get( 'Name' ) . ')' : '' ),
			'Plugins'            => sprintf( '%d active, %d installed, %d with updates', count( $active ), count( $plugins ), count( $updates ) ),
			'Published'          => implode( ', ', $published ),
			'Media items'        => number_format_i18n( isset( $attachments->inherit ) ? $attachments->inherit : 0 ),
			'Comments'           => sprintf( '%s approved, %s pending, %s spam', number_format_i18n( $comments->approved ), number_format_i18n( $comments->moderated ), number_format_i18n( $comments->spam ) ),
			'Users'              => number_format_i18n( count_users()['total_users'] ),
			'Database size'      => $db['total_size'],
			'Autoloaded options' => $db['autoload']['size'],
			'Cron events'        => sprintf( '%d scheduled, %d overdue', $cron['total'], $cron['overdue'] ),
			'Permalinks'         => get_option( 'permalink_structure' ) ? get_option( 'permalink_structure' ) : 'plain',
			'Search engines'     => get_option( 'blog_public' ) ? 'allowed' : 'discouraged',
			'Timezone'           => wp_timezone_string(),
			'Language'           => get_locale(),
			'WP_DEBUG'           => defined( 'WP_DEBUG' ) && WP_DEBUG ? 'on' : 'off',
			'Object cache'       => wp_using_ext_object_cache() ? 'external' : 'none',
		);
	}

	private function content_types( $only = null ) {
		if ( $only ) {
			if ( ! post_type_exists( $only ) ) {
				WP_CLI::error( "Post type '{$only}' isn't registered." );
			}
			return array( $only );
		}

		$types = get_post_types( array( 'public' => true ) );
		unset( $types['attachment'] );

		return array_values( $types );
	}

	private function content_data( array $types, $stale_days ) {
		$rows = array();

		foreach ( $types as $type ) {
			$counts = wp_count_posts( $type );
			$get    = static function ( $status ) use ( $counts ) {
				return isset( $counts->$status ) ? (int) $counts->$status : 0;
			};

			$row = array(
				'type'      => $type,
				'published' => $get( 'publish' ),
				'drafts'    => $get( 'draft' ),
				'pending'   => $get( 'pending' ),
				'scheduled' => $get( 'future' ),
				'private'   => $get( 'private' ),
				'trash'     => $get( 'trash' ),
			);

			foreach ( array( 'no-featured-image', 'no-excerpt', 'empty-content', 'uncategorized', 'stale' ) as $check ) {
				$sql                                    = $this->check_sql( $check, $type, $stale_days );
				$row[ str_replace( '-', '_', $check ) ] = null === $sql ? '-' : (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) ' . $sql );
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * FROM/WHERE clause for a content check, or null when the check doesn't
	 * apply to the post type (e.g. excerpts on a type that doesn't support them).
	 */
	private function check_sql( $check, $type, $stale_days ) {
		global $wpdb;

		$base = $wpdb->prepare( "FROM {$wpdb->posts} p WHERE p.post_type = %s AND p.post_status = 'publish'", $type );

		switch ( $check ) {
			case 'no-featured-image':
				if ( ! post_type_supports( $type, 'thumbnail' ) || ! current_theme_supports( 'post-thumbnails' ) ) {
					return null;
				}
				return $base . " AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_thumbnail_id' AND m.meta_value > 0 )";

			case 'no-excerpt':
				return post_type_supports( $type, 'excerpt' ) ? $base . " AND p.post_excerpt = ''" : null;

			case 'empty-content':
				return post_type_supports( $type, 'editor' ) ? $base . " AND TRIM( p.post_content ) = ''" : null;

			case 'uncategorized':
				if ( ! is_object_in_taxonomy( $type, 'category' ) ) {
					return null;
				}
				$term = get_term( (int) get_option( 'default_category' ), 'category' );
				if ( ! $term || is_wp_error( $term ) ) {
					return null;
				}
				return $base . $wpdb->prepare(
					" AND EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} tr WHERE tr.object_id = p.ID AND tr.term_taxonomy_id = %d )",
					$term->term_taxonomy_id
				);

			case 'stale':
				return $base . $wpdb->prepare( ' AND p.post_modified_gmt < %s', $this->days_ago_gmt( $stale_days ) );
		}

		return null;
	}

	private function failing_posts( $check, array $types, $stale_days ) {
		global $wpdb;

		$posts = array();

		foreach ( $types as $type ) {
			$sql = $this->check_sql( $check, $type, $stale_days );

			if ( null === $sql ) {
				WP_CLI::debug( "{$check} doesn't apply to {$type}", 'groundskeeper' );
				continue;
			}

			$rows = $wpdb->get_results( "SELECT p.ID, p.post_type, p.post_title, p.post_date, p.post_modified {$sql} ORDER BY p.post_date DESC" );

			foreach ( $rows as $row ) {
				$posts[] = array(
					'ID'        => (int) $row->ID,
					'post_type' => $row->post_type,
					'title'     => $row->post_title,
					'date'      => $row->post_date,
					'modified'  => $row->post_modified,
					'url'       => get_permalink( $row->ID ),
				);
			}
		}

		return $posts;
	}

	private function media_data( $top, $check_disk ) {
		global $wpdb;

		$by_type = array();
		$largest = array();
		$missing = array();
		$total   = 0;
		$bytes   = 0;

		$unattached = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_parent = 0" );
		$offset     = 0;
		$uploads    = wp_get_upload_dir();

		do {
			$batch   = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_mime_type FROM {$wpdb->posts} WHERE post_type = 'attachment' ORDER BY ID LIMIT %d, 500",
					$offset
				)
			);
			$offset += 500;

			if ( $batch ) {
				update_meta_cache( 'post', wp_list_pluck( $batch, 'ID' ) );
			}

			foreach ( $batch as $attachment ) {
				++$total;
				$mime = $attachment->post_mime_type ? $attachment->post_mime_type : 'unknown';

				if ( ! isset( $by_type[ $mime ] ) ) {
					$by_type[ $mime ] = array(
						'type'  => $mime,
						'files' => 0,
						'bytes' => 0,
					);
				}
				++$by_type[ $mime ]['files'];

				if ( ! $check_disk ) {
					continue;
				}

				$file = get_attached_file( $attachment->ID, true );

				if ( ! $file || ! file_exists( $file ) ) {
					$missing[] = array(
						'ID'   => (int) $attachment->ID,
						'file' => (string) get_post_meta( $attachment->ID, '_wp_attached_file', true ),
					);
					continue;
				}

				// Count generated sizes too, since that's what's actually on disk.
				$size = (int) filesize( $file );
				$meta = wp_get_attachment_metadata( $attachment->ID );

				if ( ! empty( $meta['sizes'] ) ) {
					$dir = dirname( $file );
					foreach ( $meta['sizes'] as $variant ) {
						if ( ! empty( $variant['file'] ) && file_exists( "{$dir}/{$variant['file']}" ) ) {
							$size += (int) filesize( "{$dir}/{$variant['file']}" );
						}
					}
				}

				if ( ! empty( $meta['original_image'] ) && file_exists( dirname( $file ) . '/' . $meta['original_image'] ) ) {
					$size += (int) filesize( dirname( $file ) . '/' . $meta['original_image'] );
				}

				$by_type[ $mime ]['bytes'] += $size;
				$bytes                     += $size;

				$largest[] = array(
					'ID'    => (int) $attachment->ID,
					'file'  => ltrim( str_replace( $uploads['basedir'], '', $file ), '/' ),
					'bytes' => $size,
				);

				// Don't hold every file in memory on a huge library.
				if ( count( $largest ) > $top * 4 ) {
					$largest = $this->top_by_bytes( $largest, $top );
				}
			}

			$this->free_memory();
			$fetched = count( $batch );
		} while ( 500 === $fetched );

		$by_type = array_values( $by_type );
		usort(
			$by_type,
			static function ( $a, $b ) {
				if ( $a['bytes'] === $b['bytes'] ) {
					return $b['files'] <=> $a['files'];
				}
				return $b['bytes'] <=> $a['bytes'];
			}
		);

		foreach ( $by_type as &$row ) {
			$row['size'] = $check_disk ? $this->format_bytes( $row['bytes'] ) : 'n/a';
		}
		unset( $row );

		$largest = $this->top_by_bytes( $largest, $top );
		foreach ( $largest as &$row ) {
			$row['size'] = $this->format_bytes( $row['bytes'] );
		}
		unset( $row );

		return array(
			'summary' => array(
				'Attachments'    => number_format_i18n( $total ),
				'Size on disk'   => $check_disk ? $this->format_bytes( $bytes ) : 'not checked',
				'Unattached'     => number_format_i18n( $unattached ),
				'Missing files'  => $check_disk ? number_format_i18n( count( $missing ) ) : 'not checked',
				'Uploads folder' => $uploads['basedir'],
			),
			'by_type' => $by_type,
			'largest' => $largest,
			'missing' => $missing,
		);
	}

	private function top_by_bytes( array $rows, $limit ) {
		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['bytes'] <=> $a['bytes'];
			}
		);
		return array_slice( $rows, 0, $limit );
	}

	private function database_data( $top ) {
		global $wpdb;

		$tables = $this->table_sizes();
		$sized  = array_filter( wp_list_pluck( $tables, 'bytes' ), 'is_int' );

		foreach ( $tables as &$table ) {
			$table['size'] = $this->format_bytes( $table['bytes'] );
		}
		unset( $table );

		$autoload_values = function_exists( 'wp_autoload_values_to_autoload' ) ? wp_autoload_values_to_autoload() : array( 'yes' );
		$in              = implode( ',', array_fill( 0, count( $autoload_values ), '%s' ) );

		$autoload = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total, SUM( LENGTH( option_value ) ) AS bytes FROM {$wpdb->options} WHERE autoload IN ($in)", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$autoload_values
			)
		);

		$largest = array();
		if ( $top ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, LENGTH( option_value ) AS bytes FROM {$wpdb->options} WHERE autoload IN ($in) ORDER BY bytes DESC LIMIT %d",
					array_merge( $autoload_values, array( $top ) )
				)
			);

			foreach ( $rows as $row ) {
				$largest[] = array(
					'option' => $row->option_name,
					'size'   => $this->format_bytes( $row->bytes ),
				);
			}
		}

		$total_size = $sized ? array_sum( $sized ) : null;

		// The SQLite drop-in keeps everything in one file, so just measure that.
		if ( null === $total_size && $this->is_sqlite() && defined( 'FQDB' ) && file_exists( FQDB ) ) {
			$total_size = filesize( FQDB );
		}

		return array(
			'tables'     => $tables,
			'total_size' => $this->format_bytes( $total_size ),
			'autoload'   => array(
				'count'   => (int) $autoload->total,
				'size'    => $this->format_bytes( (int) $autoload->bytes ),
				'largest' => $largest,
			),
		);
	}

	/**
	 * Sizes come from information_schema on MySQL/MariaDB (row counts there are
	 * InnoDB estimates). Other engines
	 * (the SQLite drop-in, mostly) only get row counts.
	 */
	private function table_sizes() {
		global $wpdb;

		$like = $wpdb->esc_like( $wpdb->base_prefix ) . '%';
		$rows = array();

		if ( ! $this->is_sqlite() ) {
			$suppress = $wpdb->suppress_errors( true );
			$rows     = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT TABLE_NAME AS name, TABLE_ROWS AS row_count, DATA_LENGTH + INDEX_LENGTH AS bytes
					FROM information_schema.TABLES
					WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s
					ORDER BY bytes DESC',
					DB_NAME,
					$like
				)
			);
			$wpdb->suppress_errors( $suppress );
		}

		if ( $rows ) {
			return array_map(
				static function ( $row ) {
					return array(
						'table' => $row->name,
						'rows'  => number_format_i18n( (int) $row->row_count ),
						'bytes' => (int) $row->bytes,
					);
				},
				$rows
			);
		}

		$tables = array();
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) as $name ) {
			$tables[] = array(
				'table' => $name,
				'rows'  => number_format_i18n( (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$name}`" ) ),
				'bytes' => null,
			);
		}

		return $tables;
	}

	private function plugin_data() {
		$this->load_admin_includes();

		$updates = get_site_transient( 'update_plugins' );
		$updates = isset( $updates->response ) ? $updates->response : array();
		$plugins = array();

		foreach ( get_plugins() as $file => $plugin ) {
			if ( is_plugin_active_for_network( $file ) ) {
				$status = 'network';
			} elseif ( is_plugin_active( $file ) ) {
				$status = 'active';
			} else {
				$status = 'inactive';
			}

			$plugins[] = array(
				'name'    => $plugin['Name'],
				'file'    => $file,
				'status'  => $status,
				'version' => $plugin['Version'],
				'update'  => isset( $updates[ $file ]->new_version ) ? $updates[ $file ]->new_version : '',
			);
		}

		foreach ( get_mu_plugins() as $file => $plugin ) {
			$plugins[] = array(
				'name'    => $plugin['Name'] ? $plugin['Name'] : $file,
				'file'    => $file,
				'status'  => 'must-use',
				'version' => $plugin['Version'],
				'update'  => '',
			);
		}

		foreach ( get_dropins() as $file => $plugin ) {
			$plugins[] = array(
				'name'    => $plugin['Name'] ? $plugin['Name'] : $file,
				'file'    => $file,
				'status'  => 'dropin',
				'version' => $plugin['Version'],
				'update'  => '',
			);
		}

		return $plugins;
	}

	private function user_data() {
		$counts = count_users();
		$names  = wp_roles()->get_names();
		$roles  = array();

		foreach ( $counts['avail_roles'] as $role => $count ) {
			if ( 'none' === $role && ! $count ) {
				continue;
			}

			$roles[] = array(
				'role'  => isset( $names[ $role ] ) ? translate_user_role( $names[ $role ] ) : $role,
				'users' => (int) $count,
			);
		}

		$admins = array();
		foreach ( get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'registered',
			)
		) as $user ) {
			$admins[] = array(
				'ID'         => $user->ID,
				'login'      => $user->user_login,
				'email'      => $user->user_email,
				'registered' => $user->user_registered,
			);
		}

		return array(
			'total'          => (int) $counts['total_users'],
			'roles'          => $roles,
			'administrators' => $admins,
		);
	}

	private function cron_stats() {
		$total   = 0;
		$overdue = 0;
		$now     = time();

		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			foreach ( (array) $hooks as $events ) {
				$total += count( $events );

				// A few minutes late is normal on low-traffic sites.
				if ( $timestamp < $now - 10 * MINUTE_IN_SECONDS ) {
					$overdue += count( $events );
				}
			}
		}

		return compact( 'total', 'overdue' );
	}

	private function is_sqlite() {
		return ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) || defined( 'SQLITE_DB_DROPIN_VERSION' );
	}

	private function db_label() {
		global $wpdb;

		if ( $this->is_sqlite() ) {
			return 'SQLite (via drop-in)';
		}

		$info = $wpdb->db_server_info();
		$type = false !== stripos( $info, 'mariadb' ) ? 'MariaDB' : 'MySQL';

		return $type . ' ' . $wpdb->db_version();
	}

	/*
	 * Markdown output for `report all`
	 */

	private function markdown( array $r, $stale_days ) {
		$out   = array();
		$out[] = '# Site report: ' . $r['overview']['Site'];
		$out[] = '';
		$out[] = "Generated {$r['generated']} for {$r['overview']['Home URL']}";
		$out[] = '';

		$out[] = '## Overview' . "\n";
		$out[] = $this->md_table(
			$this->pairs( $r['overview'] ),
			array(
				'field' => 'Item',
				'value' => 'Value',
			)
		);

		$out[] = '## Content' . "\n";
		$out[] = "Quality columns count published items only. Stale means not modified in {$stale_days} days. A dash means the check doesn't apply to that type.";
		$out[] = '';
		$out[] = $this->md_table(
			$r['content'],
			array(
				'type'              => 'Type',
				'published'         => 'Published',
				'drafts'            => 'Drafts',
				'pending'           => 'Pending',
				'scheduled'         => 'Scheduled',
				'no_featured_image' => 'No image',
				'no_excerpt'        => 'No excerpt',
				'empty_content'     => 'Empty',
				'uncategorized'     => 'Uncategorized',
				'stale'             => 'Stale',
			)
		);

		$out[] = '## Media' . "\n";
		$out[] = $this->md_table(
			$this->pairs( $r['media']['summary'] ),
			array(
				'field' => 'Item',
				'value' => 'Value',
			)
		);
		if ( $r['media']['by_type'] ) {
			$out[] = $this->md_table(
				$r['media']['by_type'],
				array(
					'type'  => 'Type',
					'files' => 'Files',
					'size'  => 'Size',
				)
			);
		}
		if ( $r['media']['largest'] ) {
			$out[] = '### Largest files' . "\n";
			$out[] = $this->md_table(
				$r['media']['largest'],
				array(
					'ID'   => 'ID',
					'file' => 'File',
					'size' => 'Size',
				)
			);
		}

		$out[] = '## Database' . "\n";
		$out[] = "Total size: {$r['database']['total_size']}. Autoloaded options: {$r['database']['autoload']['size']} across {$r['database']['autoload']['count']} options.";
		$out[] = '';
		$out[] = $this->md_table(
			$r['database']['tables'],
			array(
				'table' => 'Table',
				'rows'  => 'Rows',
				'size'  => 'Size',
			)
		);
		$out[] = '### Largest autoloaded options' . "\n";
		$out[] = $this->md_table(
			$r['database']['autoload']['largest'],
			array(
				'option' => 'Option',
				'size'   => 'Size',
			)
		);

		$out[] = '## Plugins' . "\n";
		$out[] = $this->md_table(
			$r['plugins'],
			array(
				'name'    => 'Plugin',
				'status'  => 'Status',
				'version' => 'Version',
				'update'  => 'Update',
			)
		);

		$out[] = '## Users' . "\n";
		$out[] = $this->md_table(
			$r['users']['roles'],
			array(
				'role'  => 'Role',
				'users' => 'Users',
			)
		);
		$out[] = '### Administrators' . "\n";
		$out[] = $this->md_table(
			$r['users']['administrators'],
			array(
				'login'      => 'Login',
				'email'      => 'Email',
				'registered' => 'Registered',
			)
		);

		return implode( "\n", $out );
	}

	private function md_table( array $rows, array $columns ) {
		if ( ! $rows ) {
			return "_None._\n";
		}

		$escape = static function ( $value ) {
			return str_replace( array( '|', "\n" ), array( '\|', ' ' ), (string) $value );
		};

		$lines   = array();
		$lines[] = '| ' . implode( ' | ', $columns ) . ' |';
		$lines[] = '|' . str_repeat( ' --- |', count( $columns ) );

		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( array_keys( $columns ) as $key ) {
				$cells[] = $escape( isset( $row[ $key ] ) ? $row[ $key ] : '' );
			}
			$lines[] = '| ' . implode( ' | ', $cells ) . ' |';
		}

		return implode( "\n", $lines ) . "\n";
	}
}
