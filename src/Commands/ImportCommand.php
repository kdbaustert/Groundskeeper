<?php
namespace Groundskeeper\Commands;

use RuntimeException;
use Groundskeeper\Support\DataReader;
use Groundskeeper\Support\ImportLog;
use WP_CLI;
use WP_CLI\Utils;
use WP_Error;

/**
 * Bulk import posts, terms and users from CSV, JSON or JSON Lines.
 *
 * Every importer accepts --dry-run, so you can check a file before anything
 * is written.
 */
class ImportCommand extends BaseCommand {

	const IMPORT_ID_KEY  = '_groundskeeper_import_id';
	const SOURCE_URL_KEY = '_groundskeeper_source_url';

	// Column aliases, so exports from other systems mostly work as-is.
	const POST_ALIASES = array(
		'post_title'    => 'title',
		'post_content'  => 'content',
		'post_excerpt'  => 'excerpt',
		'post_status'   => 'status',
		'post_name'     => 'slug',
		'post_date'     => 'date',
		'post_author'   => 'author',
		'post_parent'   => 'parent',
		'post_password' => 'password',
		'type'          => 'post_type',
		'external_id'   => 'import_id',
		'thumbnail'     => 'featured_image',
		'image'         => 'featured_image',
		'category'      => 'categories',
		'tag'           => 'tags',
	);

	private $sep          = '|';
	private $authors      = array();
	private $images       = array();
	private $term_cache   = array();
	private $create_terms = true;
	private $base_dir     = '';
	private $import_ids   = array();

	/**
	 * Import posts, pages or any custom post type.
	 *
	 * Columns (CSV header or JSON keys). Only `title` or `content` is required:
	 *
	 *   title, content, excerpt, status, post_type, slug, date, author, parent,
	 *   menu_order, comment_status, password, page_template
	 *   import_id       Your own unique ID. Stored on the post so re-running
	 *                   the same file updates instead of duplicating.
	 *   id              An existing post ID (use with --match=id).
	 *   categories      "News|Events" or "Parent > Child" for hierarchy.
	 *   tags            "launch|q3".
	 *   tax:<taxonomy>  Terms for any taxonomy, e.g. tax:product_cat.
	 *   meta:<key>      Post meta, e.g. meta:price.
	 *   featured_image  Image URL, local file path, or attachment ID.
	 *
	 * In JSON, list columns can be arrays, and `meta` / `terms` can be objects.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to a .csv, .json or .jsonl file.
	 *
	 * [--post_type=<type>]
	 * : Post type for rows without a post_type column.
	 * ---
	 * default: post
	 * ---
	 *
	 * [--status=<status>]
	 * : Status for new posts without a status column.
	 * ---
	 * default: draft
	 * ---
	 *
	 * [--author=<user>]
	 * : Default author (ID, login or email) for rows without an author column.
	 *
	 * [--match=<field>]
	 * : How to find a post that already exists.
	 * ---
	 * default: import_id
	 * options:
	 *   - import_id
	 *   - slug
	 *   - id
	 *   - none
	 * ---
	 *
	 * [--update]
	 * : Update posts that already exist. Without this they're skipped.
	 *
	 * [--skip-images]
	 * : Don't download or attach featured images.
	 *
	 * [--[no-]create-terms]
	 * : Create categories, tags and other terms that don't exist yet. On by
	 * default; --no-create-terms only uses existing terms.
	 *
	 * [--raw-html]
	 * : Don't run content through kses. Use this for trusted files that contain
	 * iframes, scripts or embeds you want to keep.
	 *
	 * [--format=<format>]
	 * : File format. Detected from the extension by default.
	 * ---
	 * default: auto
	 * options:
	 *   - auto
	 *   - csv
	 *   - json
	 *   - jsonl
	 * ---
	 *
	 * [--delimiter=<char>]
	 * : CSV field delimiter.
	 * ---
	 * default: ','
	 * ---
	 *
	 * [--separator=<char>]
	 * : Separator for multi-value columns like categories and tags.
	 * ---
	 * default: '|'
	 * ---
	 *
	 * [--dry-run]
	 * : Validate the file and show what would happen without writing anything.
	 *
	 * [--log=<file>]
	 * : Write a per-row CSV of results.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp groundskeeper import posts articles.csv --dry-run
	 *     $ wp groundskeeper import posts articles.csv --status=publish --author=editor
	 *     $ wp groundskeeper import posts products.json --post_type=product --update
	 *     $ wp groundskeeper import posts pages.csv --post_type=page --match=slug --update --log=import.csv
	 */
	public function posts( $args, $assoc_args ) {
		$this->load_admin_includes();

		$default_type = $assoc_args['post_type'];
		if ( ! post_type_exists( $default_type ) ) {
			WP_CLI::error( "Post type '{$default_type}' isn't registered." );
		}

		$default_status = $assoc_args['status'];
		$match_by       = $assoc_args['match'];
		$update         = $this->flag( $assoc_args, 'update' );
		$dry_run        = $this->flag( $assoc_args, 'dry-run' );
		$skip_images    = $this->flag( $assoc_args, 'skip-images' );

		$this->sep          = $assoc_args['separator'];
		$this->create_terms = Utils\get_flag_value( $assoc_args, 'create-terms', true );

		$default_author = 0;
		if ( isset( $assoc_args['author'] ) ) {
			$default_author = $this->resolve_user( $assoc_args['author'] );
			if ( ! $default_author ) {
				WP_CLI::error( "Couldn't find user '{$assoc_args['author']}'." );
			}
		}

		if ( $this->flag( $assoc_args, 'raw-html' ) ) {
			kses_remove_filters();
		}

		$reader = $this->reader( $args[0], $assoc_args );
		$total  = $this->count_rows( $reader );

		// Relative image paths are relative to the import file, not the shell.
		$this->base_dir = dirname( realpath( $args[0] ) );

		if ( ! $total ) {
			WP_CLI::warning( 'No rows found.' );
			return;
		}

		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}
		wp_defer_term_counting( true );

		$log      = new ImportLog();
		$progress = Utils\make_progress_bar( ( $dry_run ? 'Checking' : 'Importing' ) . " {$total} rows", $total );

		foreach ( $this->rows( $reader ) as $line => $raw ) {
			$row   = $this->normalize_post_row( $raw );
			$label = $this->row_label( $row );

			try {
				$type   = ! empty( $row['post_type'] ) ? $row['post_type'] : $default_type;
				$result = $this->import_post( $row, $type, $default_status, $default_author, $match_by, $update, $dry_run, $skip_images );
				$log->add( $line, $result[0], $result[1], $label, $result[2] );
			} catch ( RuntimeException $e ) {
				$log->add( $line, 'failed', '', $label, $e->getMessage() );
				WP_CLI::debug( "Row {$line}: " . $e->getMessage(), 'groundskeeper' );
			}

			$progress->tick();

			if ( 0 === $line % 100 ) {
				$this->free_memory();
			}
		}

		$progress->finish();
		wp_defer_term_counting( false );

		$this->finish( $log, $assoc_args, $dry_run );
	}

	/**
	 * Import categories, tags or terms for any taxonomy.
	 *
	 * Columns: name (required), taxonomy, slug, parent, description, meta:<key>.
	 * `parent` can be the parent's slug, name or term ID. Rows can come in any
	 * order; children whose parent is further down the file are retried at
	 * the end.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to a .csv, .json or .jsonl file.
	 *
	 * [--taxonomy=<taxonomy>]
	 * : Taxonomy for rows without a taxonomy column.
	 * ---
	 * default: category
	 * ---
	 *
	 * [--update]
	 * : Update description, parent and meta of terms that already exist.
	 *
	 * [--format=<format>]
	 * : File format.
	 * ---
	 * default: auto
	 * options:
	 *   - auto
	 *   - csv
	 *   - json
	 *   - jsonl
	 * ---
	 *
	 * [--delimiter=<char>]
	 * : CSV field delimiter.
	 * ---
	 * default: ','
	 * ---
	 *
	 * [--dry-run]
	 * : Show what would happen without writing anything.
	 *
	 * [--log=<file>]
	 * : Write a per-row CSV of results.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp groundskeeper import terms categories.csv
	 *     $ wp groundskeeper import terms product-cats.csv --taxonomy=product_cat --update
	 */
	public function terms( $args, $assoc_args ) {
		$update  = $this->flag( $assoc_args, 'update' );
		$dry_run = $this->flag( $assoc_args, 'dry-run' );
		$reader  = $this->reader( $args[0], $assoc_args );
		$log     = new ImportLog();
		$waiting = array();

		// In a dry run nothing gets created, so remember what "would" exist
		// to keep parent lookups honest.
		$planned = array();

		foreach ( $this->rows( $reader ) as $line => $raw ) {
			$row             = $this->normalize_keys( $raw );
			$row['taxonomy'] = ! empty( $row['taxonomy'] ) ? $row['taxonomy'] : $assoc_args['taxonomy'];

			$outcome = $this->import_term( $row, $update, $dry_run, $planned );

			if ( 'waiting' === $outcome[0] ) {
				$waiting[ $line ] = $row;
				continue;
			}

			$log->add( $line, $outcome[0], $outcome[1], isset( $row['name'] ) ? $row['name'] : '', $outcome[2] );
		}

		// Keep retrying rows with missing parents until a pass makes no progress.
		while ( $waiting ) {
			$before = count( $waiting );

			foreach ( $waiting as $line => $row ) {
				$outcome = $this->import_term( $row, $update, $dry_run, $planned );
				if ( 'waiting' !== $outcome[0] ) {
					$log->add( $line, $outcome[0], $outcome[1], $row['name'], $outcome[2] );
					unset( $waiting[ $line ] );
				}
			}

			if ( count( $waiting ) === $before ) {
				foreach ( $waiting as $line => $row ) {
					$log->add( $line, 'failed', '', $row['name'], "Parent '{$row['parent']}' not found." );
				}
				break;
			}
		}

		$this->finish( $log, $assoc_args, $dry_run );
	}

	/**
	 * Import users.
	 *
	 * Columns: user_email (required), user_login, display_name, first_name,
	 * last_name, nickname, user_url, description, role, user_pass,
	 * user_registered, meta:<key>.
	 *
	 * Existing users are matched by email, then login. If no user_login is
	 * given, one is made from the email address. If no password is given, a
	 * random one is generated (send --send-email so people can set their own).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to a .csv, .json or .jsonl file.
	 *
	 * [--role=<role>]
	 * : Role for rows without a role column.
	 * ---
	 * default: subscriber
	 * ---
	 *
	 * [--update]
	 * : Update users that already exist. Passwords are only changed if the
	 * row has a user_pass value.
	 *
	 * [--send-email]
	 * : Send new users the standard WordPress welcome email.
	 *
	 * [--format=<format>]
	 * : File format.
	 * ---
	 * default: auto
	 * options:
	 *   - auto
	 *   - csv
	 *   - json
	 *   - jsonl
	 * ---
	 *
	 * [--delimiter=<char>]
	 * : CSV field delimiter.
	 * ---
	 * default: ','
	 * ---
	 *
	 * [--dry-run]
	 * : Show what would happen without writing anything.
	 *
	 * [--log=<file>]
	 * : Write a per-row CSV of results.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp groundskeeper import users members.csv --role=customer --dry-run
	 *     $ wp groundskeeper import users staff.csv --role=editor --send-email
	 */
	public function users( $args, $assoc_args ) {
		$default_role = $assoc_args['role'];
		if ( ! get_role( $default_role ) ) {
			WP_CLI::error( "Role '{$default_role}' doesn't exist." );
		}

		$update     = $this->flag( $assoc_args, 'update' );
		$dry_run    = $this->flag( $assoc_args, 'dry-run' );
		$send_email = $this->flag( $assoc_args, 'send-email' );
		$reader     = $this->reader( $args[0], $assoc_args );
		$total      = $this->count_rows( $reader );
		$log        = new ImportLog();
		$progress   = Utils\make_progress_bar( ( $dry_run ? 'Checking' : 'Importing' ) . " {$total} users", $total );

		$seen_emails = array();

		foreach ( $this->rows( $reader ) as $line => $raw ) {
			$row   = $this->normalize_keys( $raw );
			$email = isset( $row['user_email'] ) ? trim( $row['user_email'] ) : ( isset( $row['email'] ) ? trim( $row['email'] ) : '' );

			try {
				if ( ! is_email( $email ) ) {
					throw new RuntimeException( "Invalid or missing email '{$email}'." );
				}

				if ( isset( $seen_emails[ strtolower( $email ) ] ) ) {
					throw new RuntimeException( "Duplicate of row {$seen_emails[ strtolower( $email ) ]}." );
				}
				$seen_emails[ strtolower( $email ) ] = $line;

				$outcome = $this->import_user( $row, $email, $default_role, $update, $dry_run, $send_email );
				$log->add( $line, $outcome[0], $outcome[1], $email, $outcome[2] );
			} catch ( RuntimeException $e ) {
				$log->add( $line, 'failed', '', $email, $e->getMessage() );
			}

			$progress->tick();

			if ( 0 === $line % 200 ) {
				$this->free_memory();
			}
		}

		$progress->finish();
		$this->finish( $log, $assoc_args, $dry_run );
	}

	/*
	 * Posts
	 */

	/**
	 * @return array [ action, post ID, message ]
	 */
	private function import_post( array $row, $type, $default_status, $default_author, $match_by, $update, $dry_run, $skip_images ) {
		if ( ! post_type_exists( $type ) ) {
			throw new RuntimeException( "Unknown post type '{$type}'." );
		}

		$existing = $this->find_post( $row, $type, $match_by );

		if ( $existing && ! $update ) {
			return array( 'skipped', $existing, 'Already exists. Pass --update to overwrite.' );
		}

		if ( ! $existing && '' === trim( (string) $this->value( $row, 'title' ) ) && '' === trim( (string) $this->value( $row, 'content' ) ) ) {
			throw new RuntimeException( 'Row needs a title or content.' );
		}

		$postarr = $this->build_postarr( $row, $type, ! $existing, $default_status, $default_author );

		if ( $existing ) {
			$postarr['ID'] = $existing;
		}

		if ( $dry_run ) {
			$this->check_taxonomies( $row );
			return $existing ? array( 'updated', $existing, '' ) : array( 'created', '', '' );
		}

		$post_id = $existing
			? wp_update_post( wp_slash( $postarr ), true )
			: wp_insert_post( wp_slash( $postarr ), true );

		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( $post_id->get_error_message() );
		}

		$notes = array();

		if ( ! empty( $row['import_id'] ) ) {
			update_post_meta( $post_id, self::IMPORT_ID_KEY, wp_slash( (string) $row['import_id'] ) );
			$this->import_ids[ $type ][ (string) $row['import_id'] ] = (int) $post_id;
		}

		foreach ( $row['_meta'] as $key => $value ) {
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}

		foreach ( $row['_terms'] as $taxonomy => $names ) {
			$notes = array_merge( $notes, $this->assign_terms( $post_id, $type, $taxonomy, $names ) );
		}

		if ( ! $skip_images && ! empty( $row['featured_image'] ) ) {
			$thumb = $this->attach_image( $row['featured_image'], $post_id );

			if ( is_wp_error( $thumb ) ) {
				$notes[] = 'Image: ' . $thumb->get_error_message();
			} else {
				set_post_thumbnail( $post_id, $thumb );
			}
		}

		foreach ( $notes as $note ) {
			WP_CLI::debug( "Post {$post_id}: {$note}", 'groundskeeper' );
		}

		return array( $existing ? 'updated' : 'created', $post_id, implode( '; ', $notes ) );
	}

	private function find_post( array $row, $type, $match_by ) {
		switch ( $match_by ) {
			case 'id':
				if ( empty( $row['id'] ) ) {
					return 0;
				}
				$post = get_post( (int) $row['id'] );
				return $post && 'revision' !== $post->post_type ? $post->ID : 0;

			case 'slug':
				if ( empty( $row['slug'] ) ) {
					return 0;
				}
				$post = get_page_by_path( sanitize_title( $row['slug'] ), OBJECT, $type );
				return $post ? $post->ID : 0;

			case 'import_id':
				if ( ! isset( $row['import_id'] ) || '' === (string) $row['import_id'] ) {
					return 0;
				}
				$map = $this->import_id_map( $type );
				return isset( $map[ (string) $row['import_id'] ] ) ? $map[ (string) $row['import_id'] ] : 0;
		}

		return 0;
	}

	/**
	 * Loads every import_id for a post type in one query. Looking them up
	 * row by row means an unindexed meta_value scan each time, which gets
	 * slow fast on big sites.
	 */
	private function import_id_map( $type ) {
		global $wpdb;

		if ( ! isset( $this->import_ids[ $type ] ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.meta_value, m.post_id FROM {$wpdb->postmeta} m
					INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
					WHERE m.meta_key = %s AND p.post_type = %s AND p.post_status NOT IN ( 'trash', 'auto-draft' )",
					self::IMPORT_ID_KEY,
					$type
				)
			);

			$this->import_ids[ $type ] = array();
			foreach ( $rows as $row ) {
				$this->import_ids[ $type ][ $row->meta_value ] = (int) $row->post_id;
			}
		}

		return $this->import_ids[ $type ];
	}

	private function build_postarr( array $row, $type, $is_new, $default_status, $default_author ) {
		$postarr = array( 'post_type' => $type );

		// Only fields present in the row are set, so an update with a
		// partial file doesn't blank out everything else.
		$map = array(
			'title'          => 'post_title',
			'content'        => 'post_content',
			'excerpt'        => 'post_excerpt',
			'slug'           => 'post_name',
			'menu_order'     => 'menu_order',
			'comment_status' => 'comment_status',
			'password'       => 'post_password',
			'page_template'  => 'page_template',
		);

		foreach ( $map as $column => $field ) {
			if ( array_key_exists( $column, $row ) ) {
				$postarr[ $field ] = $row[ $column ];
			}
		}

		if ( isset( $postarr['menu_order'] ) ) {
			$postarr['menu_order'] = (int) $postarr['menu_order'];
		}

		$status = $this->value( $row, 'status' );
		if ( '' !== $status ) {
			if ( ! get_post_status_object( $status ) ) {
				throw new RuntimeException( "Unknown status '{$status}'." );
			}
			$postarr['post_status'] = $status;
		} elseif ( $is_new ) {
			$postarr['post_status'] = $default_status;
		}

		$author = $this->value( $row, 'author' );
		if ( '' !== $author ) {
			$postarr['post_author'] = $this->resolve_user( $author );
			if ( ! $postarr['post_author'] ) {
				throw new RuntimeException( "Unknown author '{$author}'." );
			}
		} elseif ( $is_new && $default_author ) {
			$postarr['post_author'] = $default_author;
		}

		$date = $this->value( $row, 'date' );
		if ( '' !== $date ) {
			$parsed = date_create( $date, wp_timezone() );
			if ( ! $parsed ) {
				throw new RuntimeException( "Can't parse date '{$date}'." );
			}
			$postarr['post_date']     = $parsed->format( 'Y-m-d H:i:s' );
			$postarr['post_date_gmt'] = get_gmt_from_date( $postarr['post_date'] );
			$postarr['edit_date']     = true;
		}

		$parent = $this->value( $row, 'parent' );
		if ( '' !== $parent ) {
			$postarr['post_parent'] = $this->resolve_parent( $parent, $type );
		}

		return $postarr;
	}

	private function resolve_parent( $ref, $type ) {
		if ( is_numeric( $ref ) ) {
			return (int) $ref;
		}

		$post = get_page_by_path( $ref, OBJECT, $type );
		if ( $post ) {
			return $post->ID;
		}

		// Could also be the import_id of an earlier row.
		$id = $this->find_post( array( 'import_id' => $ref ), $type, 'import_id' );
		if ( ! $id ) {
			throw new RuntimeException( "Parent '{$ref}' not found. Put parents before children in the file." );
		}

		return $id;
	}

	private function normalize_post_row( array $raw ) {
		$row = array(
			'_meta'  => array(),
			'_terms' => array(),
		);

		foreach ( $raw as $key => $value ) {
			$key = trim( (string) $key );

			// Meta keys and taxonomy names stay as written; the rest is case-insensitive.
			if ( 0 !== strpos( $key, 'meta:' ) && 0 !== strpos( $key, 'tax:' ) ) {
				$key = strtolower( $key );
			}

			if ( isset( self::POST_ALIASES[ $key ] ) ) {
				$key = self::POST_ALIASES[ $key ];
			}

			if ( 0 === strpos( $key, 'meta:' ) ) {
				$row['_meta'][ substr( $key, 5 ) ] = $value;
			} elseif ( 0 === strpos( $key, 'tax:' ) ) {
				$row['_terms'][ substr( $key, 4 ) ] = $value;
			} elseif ( 'meta' === $key && is_array( $value ) ) {
				$row['_meta'] = array_merge( $row['_meta'], $value );
			} elseif ( 'terms' === $key && is_array( $value ) ) {
				$row['_terms'] = array_merge( $row['_terms'], $value );
			} elseif ( 'categories' === $key ) {
				$row['_terms']['category'] = $value;
			} elseif ( 'tags' === $key ) {
				$row['_terms']['post_tag'] = $value;
			} else {
				$row[ $key ] = is_string( $value ) ? trim( $value ) : $value;
			}
		}

		foreach ( $row['_terms'] as $taxonomy => $value ) {
			$row['_terms'][ $taxonomy ] = $this->split_list( $value );
		}

		return $row;
	}

	private function row_label( array $row ) {
		foreach ( array( 'title', 'slug', 'import_id', 'id' ) as $key ) {
			if ( ! empty( $row[ $key ] ) ) {
				return mb_strimwidth( (string) $row[ $key ], 0, 60, '…' );
			}
		}
		return '';
	}

	/*
	 * Terms
	 */

	private function assign_terms( $post_id, $type, $taxonomy, array $names ) {
		if ( ! is_object_in_taxonomy( $type, $taxonomy ) ) {
			return array( "Taxonomy '{$taxonomy}' isn't registered for {$type}, skipped." );
		}

		$ids   = array();
		$notes = array();

		foreach ( $names as $name ) {
			$term_id = $this->term_id_for_path( $name, $taxonomy, $this->create_terms );

			if ( is_wp_error( $term_id ) ) {
				$notes[] = $term_id->get_error_message();
			} elseif ( $term_id ) {
				$ids[] = $term_id;
			} else {
				$notes[] = "Term '{$name}' doesn't exist in {$taxonomy}.";
			}
		}

		wp_set_object_terms( $post_id, $ids, $taxonomy );

		return $notes;
	}

	private function check_taxonomies( array $row ) {
		foreach ( array_keys( $row['_terms'] ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				throw new RuntimeException( "Unknown taxonomy '{$taxonomy}'." );
			}
		}
	}

	/**
	 * Resolves "Parent > Child" to the child's term ID, creating any missing
	 * levels along the way when $create is true.
	 */
	private function term_id_for_path( $path, $taxonomy, $create ) {
		if ( is_numeric( $path ) ) {
			$term = get_term( (int) $path, $taxonomy );
			return $term && ! is_wp_error( $term ) ? $term->term_id : 0;
		}

		$cache_key = $taxonomy . '|' . strtolower( $path );
		if ( isset( $this->term_cache[ $cache_key ] ) ) {
			return $this->term_cache[ $cache_key ];
		}

		$parts  = is_taxonomy_hierarchical( $taxonomy ) ? array_map( 'trim', explode( '>', $path ) ) : array( trim( $path ) );
		$parent = 0;

		foreach ( $parts as $name ) {
			if ( '' === $name ) {
				continue;
			}

			$found = term_exists( $name, $taxonomy, $parent );

			if ( ! $found && 0 === $parent && count( $parts ) === 1 ) {
				// Top-level lookup failed; the term may live deeper in the tree.
				$found = term_exists( $name, $taxonomy );
			}

			if ( $found ) {
				$parent = (int) $found['term_id'];
				continue;
			}

			if ( ! $create ) {
				return 0;
			}

			$created = wp_insert_term( wp_slash( $name ), $taxonomy, array( 'parent' => $parent ) );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$parent = (int) $created['term_id'];
		}

		$this->term_cache[ $cache_key ] = $parent;

		return $parent;
	}

	private function import_term( array $row, $update, $dry_run, array &$planned ) {
		$taxonomy = $row['taxonomy'];
		$name     = isset( $row['name'] ) ? trim( $row['name'] ) : '';

		if ( '' === $name ) {
			return array( 'failed', '', 'Missing name.' );
		}
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array( 'failed', '', "Unknown taxonomy '{$taxonomy}'." );
		}

		$parent_id = 0;
		if ( ! empty( $row['parent'] ) ) {
			$parent_id = $this->lookup_term( $row['parent'], $taxonomy );

			if ( ! $parent_id && isset( $planned[ $taxonomy ][ strtolower( $row['parent'] ) ] ) ) {
				$parent_id = -1; // Will exist once the real import runs.
			}

			if ( ! $parent_id ) {
				return array( 'waiting', '', '' );
			}
		}

		$slug     = ! empty( $row['slug'] ) ? sanitize_title( $row['slug'] ) : sanitize_title( $name );
		$existing = get_term_by( 'slug', $slug, $taxonomy );

		$fields = array( 'slug' => $slug );
		if ( isset( $row['description'] ) ) {
			$fields['description'] = $row['description'];
		}
		if ( $parent_id > 0 ) {
			$fields['parent'] = $parent_id;
		}

		$meta = array();
		foreach ( $row as $key => $value ) {
			if ( 0 === strpos( $key, 'meta:' ) ) {
				$meta[ substr( $key, 5 ) ] = $value;
			}
		}

		if ( $existing && ! $update ) {
			return array( 'skipped', $existing->term_id, 'Already exists.' );
		}

		if ( $dry_run ) {
			$planned[ $taxonomy ][ strtolower( $name ) ] = true;
			$planned[ $taxonomy ][ $slug ]               = true;
			return array( $existing ? 'updated' : 'created', $existing ? $existing->term_id : '', '' );
		}

		if ( $existing ) {
			$fields['name'] = $name;
			$result         = wp_update_term( $existing->term_id, $taxonomy, wp_slash( $fields ) );
		} else {
			$result = wp_insert_term( wp_slash( $name ), $taxonomy, wp_slash( $fields ) );
		}

		if ( is_wp_error( $result ) ) {
			return array( 'failed', '', $result->get_error_message() );
		}

		foreach ( $meta as $key => $value ) {
			update_term_meta( $result['term_id'], $key, wp_slash( $value ) );
		}

		return array( $existing ? 'updated' : 'created', $result['term_id'], '' );
	}

	private function lookup_term( $ref, $taxonomy ) {
		if ( is_numeric( $ref ) ) {
			$term = get_term( (int) $ref, $taxonomy );
		} else {
			$term = get_term_by( 'slug', sanitize_title( $ref ), $taxonomy );
			if ( ! $term ) {
				$term = get_term_by( 'name', $ref, $taxonomy );
			}
		}

		return $term && ! is_wp_error( $term ) ? (int) $term->term_id : 0;
	}

	/*
	 * Users
	 */

	private function import_user( array $row, $email, $default_role, $update, $dry_run, $send_email ) {
		$existing = get_user_by( 'email', $email );

		if ( ! $existing && ! empty( $row['user_login'] ) ) {
			$existing = get_user_by( 'login', $row['user_login'] );
		}

		if ( $existing && ! $update ) {
			return array( 'skipped', $existing->ID, 'Already exists.' );
		}

		$role = ! empty( $row['role'] ) ? $row['role'] : ( $existing ? '' : $default_role );
		if ( $role && ! get_role( $role ) ) {
			throw new RuntimeException( "Unknown role '{$role}'." );
		}

		$userdata = array( 'user_email' => $email );
		$fields   = array( 'display_name', 'first_name', 'last_name', 'nickname', 'user_url', 'description', 'user_registered' );

		foreach ( $fields as $field ) {
			if ( isset( $row[ $field ] ) && '' !== $row[ $field ] ) {
				$userdata[ $field ] = $row[ $field ];
			}
		}

		if ( $role ) {
			$userdata['role'] = $role;
		}

		if ( ! empty( $row['user_pass'] ) ) {
			$userdata['user_pass'] = $row['user_pass'];
		}

		if ( $existing ) {
			$userdata['ID'] = $existing->ID;
		} else {
			$userdata['user_login'] = ! empty( $row['user_login'] ) ? $row['user_login'] : $this->login_from_email( $email );

			if ( ! validate_username( $userdata['user_login'] ) ) {
				throw new RuntimeException( "Invalid username '{$userdata['user_login']}'." );
			}
			if ( empty( $userdata['user_pass'] ) ) {
				$userdata['user_pass'] = wp_generate_password( 24 );
			}
		}

		if ( $dry_run ) {
			return array( $existing ? 'updated' : 'created', $existing ? $existing->ID : '', '' );
		}

		$user_id = $existing ? wp_update_user( $userdata ) : wp_insert_user( $userdata );

		if ( is_wp_error( $user_id ) ) {
			throw new RuntimeException( $user_id->get_error_message() );
		}

		foreach ( $row as $key => $value ) {
			if ( 0 === strpos( $key, 'meta:' ) ) {
				update_user_meta( $user_id, substr( $key, 5 ), wp_slash( $value ) );
			} elseif ( 'meta' === $key && is_array( $value ) ) {
				foreach ( $value as $meta_key => $meta_value ) {
					update_user_meta( $user_id, $meta_key, wp_slash( $meta_value ) );
				}
			}
		}

		if ( ! $existing && $send_email ) {
			wp_new_user_notification( $user_id, null, 'user' );
		}

		return array( $existing ? 'updated' : 'created', $user_id, '' );
	}

	private function login_from_email( $email ) {
		$base  = sanitize_user( strstr( $email, '@', true ), true );
		$base  = $base ? $base : 'user';
		$login = $base;
		$i     = 2;

		while ( username_exists( $login ) ) {
			$login = $base . $i;
			++$i;
		}

		return $login;
	}

	/*
	 * Media
	 */

	/**
	 * Accepts an attachment ID, URL or local path. URLs that were imported
	 * before are reused instead of downloaded again.
	 *
	 * @return int|WP_Error Attachment ID.
	 */
	private function attach_image( $source, $post_id ) {
		$source = trim( (string) $source );

		// Local files are keyed by real path so ./img.jpg and img.jpg match.
		if ( ! preg_match( '#^https?://#i', $source ) && ! is_numeric( $source ) ) {
			$local = path_is_absolute( $source ) ? $source : $this->base_dir . '/' . $source;
			if ( realpath( $local ) ) {
				$source = realpath( $local );
			}
		}

		if ( is_numeric( $source ) ) {
			return 'attachment' === get_post_type( (int) $source )
				? (int) $source
				: new WP_Error( 'groundskeeper_bad_attachment', "Attachment {$source} doesn't exist." );
		}

		if ( isset( $this->images[ $source ] ) ) {
			return $this->images[ $source ];
		}

		$found = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'meta_key'         => self::SOURCE_URL_KEY,
				'meta_value'       => $source,
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		if ( $found ) {
			$this->images[ $source ] = (int) $found[0];
			return $this->images[ $source ];
		}

		if ( preg_match( '#^https?://#i', $source ) ) {
			$id = media_sideload_image( $source, $post_id, null, 'id' );
		} elseif ( is_readable( $source ) ) {
			$tmp = wp_tempnam( basename( $source ) );
			copy( $source, $tmp );
			$file = array(
				'name'     => basename( $source ),
				'tmp_name' => $tmp,
			);
			$id   = media_handle_sideload( $file, $post_id );
			if ( is_wp_error( $id ) && file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		} else {
			return new WP_Error( 'groundskeeper_bad_image', "Not a URL, readable file or attachment ID: {$source}" );
		}

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		update_post_meta( $id, self::SOURCE_URL_KEY, $source );
		$this->images[ $source ] = (int) $id;

		return (int) $id;
	}

	/*
	 * Helpers
	 */

	private function reader( $path, array $assoc_args ) {
		try {
			$format    = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'auto';
			$delimiter = isset( $assoc_args['delimiter'] ) ? $assoc_args['delimiter'] : ',';

			if ( '\t' === $delimiter || 'tab' === $delimiter ) {
				$delimiter = "\t";
			}

			return new DataReader( $path, $format, $delimiter );
		} catch ( RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	private function count_rows( DataReader $reader ) {
		try {
			return $reader->count();
		} catch ( RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Wraps the reader so a parse error halfway through a file stops the
	 * command cleanly. Rows already imported stay imported.
	 */
	private function rows( DataReader $reader ) {
		try {
			foreach ( $reader->rows() as $line => $row ) {
				yield $line => $row;
			}
		} catch ( RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	private function finish( ImportLog $log, array $assoc_args, $dry_run ) {
		WP_CLI::log( '' );
		$log->print_summary( $dry_run );

		if ( ! empty( $assoc_args['log'] ) ) {
			if ( $log->write_csv( $assoc_args['log'] ) ) {
				WP_CLI::log( "Row log written to {$assoc_args['log']}" );
			} else {
				WP_CLI::warning( "Couldn't write log to {$assoc_args['log']}" );
			}
		}

		$totals = $log->totals();

		if ( $dry_run ) {
			WP_CLI::success( 'Dry run finished. Nothing was written.' );
		} elseif ( $totals['failed'] && ! $totals['created'] && ! $totals['updated'] ) {
			WP_CLI::error( 'Every row failed.' );
		} elseif ( $totals['failed'] ) {
			/* translators: %d: number of failed rows */
			WP_CLI::warning( sprintf( _n( 'Finished with %d failed row.', 'Finished with %d failed rows.', $totals['failed'], 'groundskeeper' ), $totals['failed'] ) );
		} else {
			WP_CLI::success( 'Import finished.' );
		}
	}

	private function normalize_keys( array $raw ) {
		$row = array();
		foreach ( $raw as $key => $value ) {
			$key = trim( (string) $key );
			// Keep meta keys case-sensitive, lowercase everything else.
			$key         = 0 === strpos( $key, 'meta:' ) ? $key : strtolower( $key );
			$row[ $key ] = is_string( $value ) ? trim( $value ) : $value;
		}
		return $row;
	}

	private function split_list( $value ) {
		if ( is_array( $value ) ) {
			return array_values( array_filter( array_map( 'trim', array_map( 'strval', $value ) ), 'strlen' ) );
		}

		return array_values( array_filter( array_map( 'trim', explode( $this->sep, (string) $value ) ), 'strlen' ) );
	}

	private function value( array $row, $key ) {
		return isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? trim( (string) $row[ $key ] ) : '';
	}

	private function resolve_user( $ref ) {
		$ref = trim( (string) $ref );

		if ( isset( $this->authors[ $ref ] ) ) {
			return $this->authors[ $ref ];
		}

		if ( is_numeric( $ref ) ) {
			$user = get_user_by( 'id', (int) $ref );
		} elseif ( is_email( $ref ) ) {
			$user = get_user_by( 'email', $ref );
		} else {
			$user = get_user_by( 'login', $ref );
			if ( ! $user ) {
				$user = get_user_by( 'slug', $ref );
			}
		}

		$this->authors[ $ref ] = $user ? (int) $user->ID : 0;

		return $this->authors[ $ref ];
	}
}
