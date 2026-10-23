<?php
namespace Groundskeeper\Commands;

use WP_CLI;
use WP_CLI\Utils;

/**
 * Clean up database clutter: revisions, auto-drafts, trash, spam, orphaned
 * meta, expired transients and stale oEmbed caches.
 *
 * Every subcommand shows what it found and asks before deleting. Use
 * --dry-run to only look, or --yes to skip the prompt in scripts.
 *
 * Take a backup first (`wp db export`). Deleted rows can't be recovered.
 */
class CleanupCommand extends BaseCommand {

	private $dry_run = false;
	private $yes     = false;

	/**
	 * Delete old post revisions, keeping the newest few per post.
	 *
	 * ## OPTIONS
	 *
	 * [--keep=<n>]
	 * : Revisions to keep for each post.
	 * ---
	 * default: 5
	 * ---
	 *
	 * [--post_type=<type>]
	 * : Only clean revisions of this post type.
	 *
	 * [--dry-run]
	 * : Show counts without deleting anything.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp groundskeeper cleanup revisions --keep=3 --dry-run
	 *     $ wp groundskeeper cleanup revisions --keep=0 --post_type=page --yes
	 */
	public function revisions( $args, $assoc_args ) {
		$this->setup( $assoc_args );
		$this->report( array( $this->clean_revisions( $this->int_arg( $assoc_args, 'keep', 5 ), Utils\get_flag_value( $assoc_args, 'post_type' ) ) ) );
	}

	/**
	 * Delete auto-drafts older than a number of days.
	 *
	 * WordPress normally does this itself after a week, but only when cron
	 * runs, and on some sites it never does.
	 *
	 * ## OPTIONS
	 *
	 * [--older-than=<days>]
	 * : Only delete auto-drafts older than this.
	 * ---
	 * default: 7
	 * ---
	 *
	 * [--dry-run]
	 * : Show counts without deleting anything.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * @subcommand auto-drafts
	 */
	public function auto_drafts( $args, $assoc_args ) {
		$this->setup( $assoc_args );
		$this->report( array( $this->clean_auto_drafts( $this->int_arg( $assoc_args, 'older-than', 7 ) ) ) );
	}

	/**
	 * Permanently delete trashed posts.
	 *
	 * ## OPTIONS
	 *
	 * [--older-than=<days>]
	 * : Only delete posts that have been in the trash longer than this.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--post_type=<type>]
	 * : Only empty the trash for this post type.
	 *
	 * [--dry-run]
	 * : Show counts without deleting anything.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp groundskeeper cleanup trash --older-than=0 --yes
	 */
	public function trash( $args, $assoc_args ) {
		$this->setup( $assoc_args );
		$this->report( array( $this->clean_trash( $this->int_arg( $assoc_args, 'older-than', 30 ), Utils\get_flag_value( $assoc_args, 'post_type' ) ) ) );
	}

	/**
	 * Delete spam and/or trashed comments.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Which comments to delete.
	 * ---
	 * default: spam
	 * options:
	 *   - spam
	 *   - trash
	 *   - all
	 * ---
	 *
	 * [--older-than=<days>]
	 * : Only delete comments older than this.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--dry-run]
	 * : Show counts without deleting anything.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 */
	public function comments( $args, $assoc_args ) {
		$this->setup( $assoc_args );

		$statuses = 'all' === $assoc_args['status'] ? array( 'spam', 'trash' ) : array( $assoc_args['status'] );
		$days     = $this->int_arg( $assoc_args, 'older-than', 0 );
		$results  = array();

		foreach ( $statuses as $status ) {
			$results[] = $this->clean_comments( $status, $days );
		}

		$this->report( $results );
	}

	/**
	 * Delete meta rows and term relationships that point at things that no
	 * longer exist.
	 *
	 * These pile up when plugins or direct SQL deletes skip the WordPress
	 * API. They're invisible in the admin but still bloat queries.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show counts without deleting anything.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * @subcommand orphans
	 */
	public function orphans( $args, $assoc_args ) {
		$this->setup( $assoc_args );
		$this->report( $this->clean_orphans() );
	}

	/**
	 * Delete transients from the options table.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Delete every transient, not just expired ones. Plugins rebuild
	 * them on demand, so this is safe but the next few page loads may be slower.
	 *
	 * [--dry-run]
	 * : Show counts without deleting anything.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 */
	public function transients( $args, $assoc_args ) {
		$this->setup( $assoc_args );
		$this->report( array( $this->clean_transients( $this->flag( $assoc_args, 'all' ) ) ) );
	}

	/**
	 * Delete cached oEmbed responses. They're refetched the next time a post
	 * with an embed is viewed.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show counts without deleting anything.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 */
	public function oembed( $args, $assoc_args ) {
		$this->setup( $assoc_args );
		$this->report( $this->clean_oembed() );
	}

	/**
	 * Run every cleanup task with its default settings.
	 *
	 * Keeps 5 revisions per post, removes auto-drafts older than 7 days,
	 * trash older than 30 days, spam comments, orphaned rows, expired
	 * transients and oEmbed caches.
	 *
	 * ## OPTIONS
	 *
	 * [--keep-revisions=<n>]
	 * : Revisions to keep per post.
	 * ---
	 * default: 5
	 * ---
	 *
	 * [--skip=<tasks>]
	 * : Comma-separated tasks to leave out: revisions, auto-drafts, trash,
	 * comments, orphans, transients, oembed.
	 *
	 * [--dry-run]
	 * : Show counts without deleting anything.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp groundskeeper cleanup all --dry-run
	 *     $ wp groundskeeper cleanup all --skip=trash,comments --yes
	 */
	public function all( $args, $assoc_args ) {
		$this->setup( $assoc_args );

		$skip = array_map( 'trim', explode( ',', Utils\get_flag_value( $assoc_args, 'skip', '' ) ) );
		$keep = $this->int_arg( $assoc_args, 'keep-revisions', 5 );

		$tasks = array(
			'revisions'   => function () use ( $keep ) {
				return array( $this->clean_revisions( $keep ) );
			},
			'auto-drafts' => function () {
				return array( $this->clean_auto_drafts( 7 ) );
			},
			'trash'       => function () {
				return array( $this->clean_trash( 30 ) );
			},
			'comments'    => function () {
				return array( $this->clean_comments( 'spam', 0 ) );
			},
			'orphans'     => function () {
				return $this->clean_orphans();
			},
			'transients'  => function () {
				return array( $this->clean_transients( false ) );
			},
			'oembed'      => function () {
				return $this->clean_oembed();
			},
		);

		$unknown = array_diff( array_filter( $skip ), array_keys( $tasks ) );
		if ( $unknown ) {
			WP_CLI::error( 'Unknown task in --skip: ' . implode( ', ', $unknown ) );
		}

		// Ask once up front rather than once per task.
		if ( ! $this->dry_run && ! $this->yes ) {
			WP_CLI::confirm( 'Run all cleanup tasks? Make sure you have a database backup' );
			$this->yes = true;
		}

		$results = array();
		foreach ( $tasks as $name => $task ) {
			if ( ! in_array( $name, $skip, true ) ) {
				$results = array_merge( $results, $task() );
			}
		}

		$this->report( $results );
	}

	/*
	 * Tasks. Each returns [ 'task' => ..., 'found' => n, 'deleted' => n ].
	 */

	private function clean_revisions( $keep, $post_type = null ) {
		global $wpdb;

		$type_sql = '';
		if ( $post_type ) {
			$type_sql = $wpdb->prepare( ' AND parent.post_type = %s', $post_type );
		}

		$parents = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.post_parent AS id, COUNT(*) AS total
				FROM {$wpdb->posts} r
				INNER JOIN {$wpdb->posts} parent ON parent.ID = r.post_parent
				WHERE r.post_type = 'revision' {$type_sql}
				GROUP BY r.post_parent
				HAVING COUNT(*) > %d",
				$keep
			)
		);

		$ids = array();
		foreach ( $parents as $parent ) {
			$revision_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					WHERE post_type = 'revision' AND post_parent = %d
					ORDER BY post_date DESC, ID DESC",
					$parent->id
				)
			);
			$ids          = array_merge( $ids, array_slice( $revision_ids, $keep ) );
		}

		$label = $keep ? "Revisions (keeping {$keep} per post)" : 'Revisions';

		return $this->delete_ids(
			$label,
			$ids,
			static function ( $id ) {
				return (bool) wp_delete_post_revision( $id );
			}
		);
	}

	private function clean_auto_drafts( $days ) {
		global $wpdb;

		// Auto-drafts have no GMT date yet, so go by local time like core does.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_date < %s",
				wp_date( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) )
			)
		);

		return $this->delete_ids( 'Auto-drafts', $ids, array( $this, 'force_delete_post' ) );
	}

	private function clean_trash( $days, $post_type = null ) {
		global $wpdb;

		$sql = "SELECT p.ID, p.post_modified_gmt, m.meta_value AS trashed_at
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_trash_meta_time'
			WHERE p.post_status = 'trash'";

		if ( $post_type ) {
			$sql .= $wpdb->prepare( ' AND p.post_type = %s', $post_type );
		}

		$cutoff = time() - ( $days * DAY_IN_SECONDS );
		$ids    = array();

		foreach ( $wpdb->get_results( $sql ) as $row ) {
			$trashed = $row->trashed_at ? (int) $row->trashed_at : strtotime( $row->post_modified_gmt . ' UTC' );
			if ( $trashed <= $cutoff ) {
				$ids[] = $row->ID;
			}
		}

		$label = $days ? "Trashed posts (over {$days} days)" : 'Trashed posts';

		return $this->delete_ids( $label, $ids, array( $this, 'force_delete_post' ) );
	}

	private function clean_comments( $status, $days ) {
		global $wpdb;

		$sql = $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = %s", $status );

		// With no age limit, skip the date check so comments from this very
		// second aren't missed.
		if ( $days > 0 ) {
			$sql .= $wpdb->prepare( ' AND comment_date_gmt < %s', $this->days_ago_gmt( $days ) );
		}

		$ids = $wpdb->get_col( $sql );

		return $this->delete_ids(
			ucfirst( $status ) . ' comments',
			$ids,
			static function ( $id ) {
				return wp_delete_comment( $id, true );
			}
		);
	}

	private function clean_orphans() {
		global $wpdb;

		$checks = array(
			'Orphaned post meta'    => array( $wpdb->postmeta, 'post_id', $wpdb->posts, 'ID' ),
			'Orphaned comment meta' => array( $wpdb->commentmeta, 'comment_id', $wpdb->comments, 'comment_ID' ),
			'Orphaned term meta'    => array( $wpdb->termmeta, 'term_id', $wpdb->terms, 'term_id' ),
			'Orphaned user meta'    => array( $wpdb->usermeta, 'user_id', $wpdb->users, 'ID' ),
		);

		$results = array();

		foreach ( $checks as $label => list( $table, $column, $parent_table, $parent_column ) ) {
			$where     = "NOT EXISTS ( SELECT 1 FROM {$parent_table} p WHERE p.{$parent_column} = {$table}.{$column} )";
			$results[] = $this->delete_where( $label, $table, $where );
		}

		// Term relationships are trickier: object_id isn't always a post.
		// Links and user taxonomies use the same table, so only look at
		// taxonomies attached to a post type.
		$tt_ids = $this->post_term_taxonomy_ids();

		if ( $tt_ids ) {
			$table  = $wpdb->term_relationships;
			$where  = 'term_taxonomy_id IN (' . implode( ',', $tt_ids ) . ')';
			$where .= " AND NOT EXISTS ( SELECT 1 FROM {$wpdb->posts} p WHERE p.ID = {$table}.object_id )";
			$result = $this->delete_where( 'Orphaned term relationships', $table, $where );

			if ( $result['deleted'] ) {
				$this->recount_terms();
			}

			$results[] = $result;
		}

		return $results;
	}

	private function clean_transients( $all ) {
		global $wpdb;

		if ( wp_using_ext_object_cache() ) {
			WP_CLI::warning( 'An external object cache is active, so most transients live there, not in the database. Only database rows are counted.' );
		}

		$timeout      = $wpdb->esc_like( '_transient_timeout_' ) . '%';
		$site_timeout = $wpdb->esc_like( '_site_transient_timeout_' ) . '%';

		if ( $all ) {
			$where = $wpdb->prepare(
				'option_name LIKE %s OR option_name LIKE %s',
				$wpdb->esc_like( '_transient_' ) . '%',
				$wpdb->esc_like( '_site_transient_' ) . '%'
			);
			return $this->delete_where( 'All transients', $wpdb->options, $where );
		}

		// Count expired values (not their timeout rows) to match what people expect.
		$found = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE ( option_name LIKE %s OR option_name LIKE %s ) AND CAST( option_value AS UNSIGNED ) < %d",
				$timeout,
				$site_timeout,
				time()
			)
		);

		$result = array(
			'task'    => 'Expired transients',
			'found'   => $found,
			'deleted' => 0,
		);

		if ( $found && $this->should_delete( $result['task'], $found ) ) {
			delete_expired_transients( true );
			$result['deleted'] = $found;
		}

		return $result;
	}

	private function clean_oembed() {
		global $wpdb;

		$meta_where = $wpdb->prepare( 'meta_key LIKE %s', $wpdb->esc_like( '_oembed_' ) . '%' );
		$results    = array( $this->delete_where( 'oEmbed post meta', $wpdb->postmeta, $meta_where ) );

		$ids       = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'oembed_cache'" );
		$results[] = $this->delete_ids( 'oEmbed cache posts', $ids, array( $this, 'force_delete_post' ) );

		return $results;
	}

	/*
	 * Plumbing
	 */

	private function setup( array $assoc_args ) {
		$this->dry_run = $this->flag( $assoc_args, 'dry-run' );
		$this->yes     = $this->flag( $assoc_args, 'yes' );
	}

	private function should_delete( $label, $count ) {
		if ( $this->dry_run || ! $count ) {
			return false;
		}

		if ( ! $this->yes ) {
			WP_CLI::confirm( sprintf( 'Delete %s %s?', number_format_i18n( $count ), strtolower( $label ) ) );
		}

		return true;
	}

	/**
	 * Deletes through the WordPress API one at a time so hooks fire and
	 * related rows (meta, term relationships, attachments) go with them.
	 */
	private function delete_ids( $label, array $ids, callable $delete ) {
		$result = array(
			'task'    => $label,
			'found'   => count( $ids ),
			'deleted' => 0,
		);

		if ( ! $this->should_delete( $label, $result['found'] ) ) {
			return $result;
		}

		$progress = Utils\make_progress_bar( $label, $result['found'] );

		foreach ( $ids as $i => $id ) {
			if ( $delete( (int) $id ) ) {
				++$result['deleted'];
			}

			$progress->tick();

			if ( 0 === ( $i + 1 ) % 250 ) {
				$this->free_memory();
			}
		}

		$progress->finish();

		return $result;
	}

	/**
	 * Raw SQL delete for rows that have no API (orphans, transients). The
	 * object cache gets flushed afterwards so nothing serves stale values.
	 */
	private function delete_where( $label, $table, $where ) {
		global $wpdb;

		$result = array(
			'task'    => $label,
			'found'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ),
			'deleted' => 0,
		);

		if ( $this->should_delete( $label, $result['found'] ) ) {
			$deleted = $wpdb->query( "DELETE FROM {$table} WHERE {$where}" );

			if ( false === $deleted ) {
				WP_CLI::warning( "{$label}: " . $wpdb->last_error );
			} else {
				$result['deleted'] = (int) $deleted;
				wp_cache_flush();
			}
		}

		return $result;
	}

	private function force_delete_post( $id ) {
		return (bool) wp_delete_post( $id, true );
	}

	private function post_term_taxonomy_ids() {
		global $wpdb;

		$post_types = get_post_types();
		$taxonomies = array();

		foreach ( get_taxonomies( array(), 'objects' ) as $taxonomy ) {
			if ( array_intersect( (array) $taxonomy->object_type, $post_types ) ) {
				$taxonomies[] = $taxonomy->name;
			}
		}

		if ( ! $taxonomies ) {
			return array();
		}

		$in = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );

		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ($in)", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					$taxonomies
				)
			)
		);
	}

	private function recount_terms() {
		foreach ( get_taxonomies() as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'fields'     => 'tt_ids',
				)
			);

			if ( $terms && ! is_wp_error( $terms ) ) {
				wp_update_term_count_now( $terms, $taxonomy );
			}
		}
	}

	private function report( array $results ) {
		$rows  = array();
		$freed = 0;

		foreach ( $results as $result ) {
			$rows[] = array(
				'task'    => $result['task'],
				'found'   => number_format_i18n( $result['found'] ),
				'deleted' => $this->dry_run ? '-' : number_format_i18n( $result['deleted'] ),
			);
			$freed += $result['deleted'];
		}

		WP_CLI::log( '' );
		Utils\format_items( 'table', $rows, array( 'task', 'found', 'deleted' ) );

		$found = array_sum( wp_list_pluck( $results, 'found' ) );

		if ( $this->dry_run ) {
			/* translators: %s: number of items */
			WP_CLI::success( sprintf( _n( 'Dry run: %s item would be deleted.', 'Dry run: %s items would be deleted.', $found, 'groundskeeper' ), number_format_i18n( $found ) ) );
		} elseif ( ! $found ) {
			WP_CLI::success( 'Nothing to clean up.' );
		} else {
			/* translators: %s: number of items */
			WP_CLI::success( sprintf( _n( 'Deleted %s item.', 'Deleted %s items.', $freed, 'groundskeeper' ), number_format_i18n( $freed ) ) );
		}
	}
}
