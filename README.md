# Groundskeeper

WP-CLI commands for the jobs that come up on every site: getting content in, clearing out the junk, and reporting on what's there.

```
wp groundskeeper import   posts | terms | users
wp groundskeeper cleanup  revisions | auto-drafts | trash | comments | orphans | transients | oembed | all
wp groundskeeper report   overview | content | media | database | plugins | users | all
```

Requires WordPress 6.0+, PHP 7.4+ and WP-CLI 2.5+. Tested on MySQL/MariaDB and the SQLite drop-in.

## Install

Drop the folder into `wp-content/plugins/` and activate it:

```sh
wp plugin activate groundskeeper
```

It only loads under WP-CLI, so it adds nothing to front-end or admin requests.

To use it on a site without installing anything, point WP-CLI at the main file instead:

```sh
wp --require=/path/to/groundskeeper/groundskeeper.php groundskeeper report overview
```

`wp gk` works anywhere `wp groundskeeper` does, if you'd rather type less:

```sh
wp gk cleanup all --dry-run
```

Run `wp help groundskeeper <command> <subcommand>` for the full option list on anything below.

## Import

All three importers read CSV, JSON (an array of objects) or JSON Lines, picked by file extension. Each one supports `--dry-run` to validate a file without writing anything and `--log=results.csv` for a per-row record of what happened.

### Posts

```sh
wp groundskeeper import posts articles.csv --dry-run
wp groundskeeper import posts articles.csv --status=publish --author=editor
wp groundskeeper import posts products.json --post_type=product --update
```

| Column | Notes |
| --- | --- |
| `title`, `content`, `excerpt` | One of title or content is required for new posts. |
| `status`, `post_type`, `slug`, `date`, `menu_order`, `comment_status`, `password`, `page_template` | Use `--status` and `--post_type` for defaults. Dates are anything `strtotime` understands, in the site's timezone. |
| `author` | User ID, login or email. Defaults to `--author`. |
| `parent` | Parent post ID, slug, or the `import_id` of an earlier row. |
| `import_id` | Your own unique ID. It's stored on the post, so running the same file again matches instead of duplicating. |
| `categories`, `tags` | Pipe-separated: `News\|Events`. Use `Parent > Child` for nested categories. Missing terms are created unless you pass `--no-create-terms`. |
| `tax:<taxonomy>` | Any other taxonomy, e.g. `tax:product_cat`. |
| `meta:<key>` | Post meta, e.g. `meta:price`. |
| `featured_image` | URL, local path (relative to the import file), or attachment ID. Each source is downloaded once and reused on later rows and runs. |

Common export column names such as `post_title`, `post_content`, `post_status` and `external_id` work as aliases. In JSON, list columns can be arrays, and `meta` and `terms` can be nested objects (see `examples/pages.json`).

Existing posts are matched by `import_id` by default. Use `--match=slug` or `--match=id` to match differently. Matches are skipped unless you pass `--update`. Updates only touch the columns in the file, so a two-column CSV won't blank out everything else.

Content is filtered by kses like any other save. If you need iframes or scripts kept, pass `--raw-html`, but only for files you trust.

### Terms

```sh
wp groundskeeper import terms categories.csv
wp groundskeeper import terms product-cats.csv --taxonomy=product_cat --update
```

Columns: `name` (required), `taxonomy`, `slug`, `parent` (slug, name or ID), `description`, `meta:<key>`. Rows don't need to be in parent-first order.

### Users

```sh
wp groundskeeper import users members.csv --role=customer --dry-run
wp groundskeeper import users staff.csv --role=editor --send-email
```

Columns: `user_email` (required), `user_login`, `first_name`, `last_name`, `display_name`, `nickname`, `user_url`, `description`, `role`, `user_pass`, `user_registered`, `meta:<key>`.

Users are matched by email, then login. A login is generated from the email address when there isn't one. Rows with no password get a random one, so use `--send-email` if people need to log in. Updates only change the password when the row has a `user_pass`.

## Cleanup

Back up first (`wp db export`), because deletes are permanent. Every cleanup command shows what it found and asks before deleting. Use `--dry-run` to only look, or `--yes` to skip the prompt in scripts.

```sh
wp groundskeeper cleanup all --dry-run
wp groundskeeper cleanup all --yes
wp groundskeeper cleanup all --skip=trash,comments --keep-revisions=10 --yes
```

| Task | What it removes | Defaults |
| --- | --- | --- |
| `revisions` | Old revisions, keeping the newest per post | `--keep=5`, optional `--post_type` |
| `auto-drafts` | Abandoned auto-drafts | `--older-than=7` days |
| `trash` | Trashed posts | `--older-than=30` days, optional `--post_type` |
| `comments` | Spam and/or trashed comments | `--status=spam` (or `trash`, `all`), `--older-than=0` |
| `orphans` | Post, comment, term and user meta whose object is gone, plus term relationships pointing at deleted posts | |
| `transients` | Expired transients (`--all` for every transient) | |
| `oembed` | Cached oEmbed responses | |

Posts, revisions and comments are deleted through the WordPress API, so hooks fire and attached data goes with them. Orphans and transients have no API to delete through, so they're removed with SQL and the object cache is flushed afterwards.

## Reports

Every report takes `--format=table|csv|json|yaml`.

```sh
wp groundskeeper report overview
wp groundskeeper report content --list=no-featured-image --post_type=post
wp groundskeeper report content --list=stale --stale-days=730 --format=csv > stale.csv
wp groundskeeper report media --top=20
wp groundskeeper report database
wp groundskeeper report plugins --updates
wp groundskeeper report all --file=reports/$(date +%F).md
```

- **overview**: versions, theme, plugin and update counts, published content, comments, users, database and autoload size, cron health, and key settings like search engine visibility and `WP_DEBUG`.
- **content**: counts by type and status, plus published items with no featured image, no excerpt, empty content, still in Uncategorized, or not updated in `--stale-days`. Add `--list=<check>` to get the actual posts.
- **media**: file counts and disk use by type (including generated sizes), unattached items, files missing from disk, and the largest uploads. `--skip-size` skips the disk scan on huge libraries.
- **database**: table sizes and the heaviest autoloaded options.
- **plugins**: status, version and available updates (from WordPress's last check; `--refresh` checks again). Includes must-use plugins and drop-ins.
- **users**: counts by role and the list of administrators.
- **all**: everything above in one Markdown file, or JSON if `--file` ends in `.json`.

## Development

`tests/smoke.sh` runs every command end to end against a scratch install. It writes and deletes data, so it refuses to run unless you set `GROUNDSKEEPER_TEST_SITE=1`:

```sh
GROUNDSKEEPER_TEST_SITE=1 tests/smoke.sh --path=/path/to/scratch/wordpress
```

Code follows the WordPress Coding Standards. With [WPCS](https://github.com/WordPress/WordPress-Coding-Standards) installed, run `phpcs` from the plugin folder; `phpcs.xml.dist` notes the few sniffs that don't fit a CLI tool.

## License

GPL-2.0-or-later
