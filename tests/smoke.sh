#!/usr/bin/env bash
#
# End-to-end check of every command against a throwaway WordPress install.
# It creates and deletes content, so never point it at a real site.
#
#   GROUNDSKEEPER_TEST_SITE=1 tests/smoke.sh --path=/path/to/scratch/wordpress
#
set -euo pipefail

if [[ "${GROUNDSKEEPER_TEST_SITE:-}" != "1" ]]; then
	echo "Refusing to run: this script writes to and deletes from the target site."
	echo "Set GROUNDSKEEPER_TEST_SITE=1 and pass --path to a scratch install."
	exit 1
fi

WP="wp $* --skip-themes"
HERE="$(cd "$(dirname "$0")/.." && pwd)"
EXAMPLES="$HERE/examples"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

pass=0
fail=0

check() {
	local label="$1" expected="$2" actual="$3"
	if [[ "$actual" == "$expected" ]]; then
		pass=$((pass + 1))
		printf '  ok   %s\n' "$label"
	else
		fail=$((fail + 1))
		printf '  FAIL %s (expected %s, got %s)\n' "$label" "$expected" "$actual"
	fi
}

count_posts() {
	$WP post list --post_type="$1" --post_status=any --format=count
}

echo "Import"

before=$(count_posts post)
$WP groundskeeper import posts "$EXAMPLES/posts.csv" --dry-run > /dev/null || true
check "dry run writes nothing" "$before" "$(count_posts post)"

$WP groundskeeper import posts "$EXAMPLES/posts.csv" > /dev/null 2>&1 || true
check "posts created (bad row skipped)" "$((before + 3))" "$(count_posts post)"

$WP groundskeeper import posts "$EXAMPLES/posts.csv" > /dev/null 2>&1 || true
check "re-run doesn't duplicate" "$((before + 3))" "$(count_posts post)"

post_id=$($WP post list --post_type=post --title="Spring open house announced" --field=ID)
check "featured image attached" "1" "$($WP post meta get "$post_id" _thumbnail_id > /dev/null && echo 1 || echo 0)"
check "meta imported" "3" "$($WP post meta get "$post_id" reading_time)"

$WP groundskeeper import posts "$EXAMPLES/pages.json" > /dev/null
team_parent=$($WP post list --post_type=page --name=team --field=post_parent)
about_id=$($WP post list --post_type=page --name=about --field=ID)
check "page parent resolved by slug" "$about_id" "$team_parent"

$WP groundskeeper import terms "$EXAMPLES/terms.csv" > /dev/null 2>&1 || true
local_parent=$($WP term get category local --by=slug --field=parent)
regional_id=$($WP term get category regional --by=slug --field=term_id)
check "child term before parent in file" "$regional_id" "$local_parent"

$WP groundskeeper import users "$EXAMPLES/users.csv" > /dev/null 2>&1 || true
check "users created" "editor" "$($WP user get jane --field=roles)"
check "user meta imported" "Sales" "$($WP user meta get sam department)"

printf '[{"title":' > "$TMP/broken.json"
if $WP groundskeeper import posts "$TMP/broken.json" > /dev/null 2>&1; then
	check "broken JSON exits non-zero" "error" "success"
else
	check "broken JSON exits non-zero" "error" "error"
fi

echo "Cleanup"

$WP eval '
	for ( $i = 0; $i < 8; $i++ ) {
		wp_update_post( array( "ID" => '"$post_id"', "post_content" => "rev $i" ) );
	}
	global $wpdb;
	$wpdb->insert( $wpdb->postmeta, array( "post_id" => 999999, "meta_key" => "ghost", "meta_value" => "x" ) );
	set_transient( "groundskeeper_smoke_live", "x", HOUR_IN_SECONDS );
	wp_insert_comment( array( "comment_post_ID" => '"$post_id"', "comment_content" => "spam", "comment_approved" => "spam" ) );
'

$WP groundskeeper cleanup all --yes > /dev/null
revisions=$($WP post list --post_type=revision --post_parent="$post_id" --format=count)
check "revisions trimmed to 5" "5" "$revisions"
check "orphaned meta removed" "0" "$($WP db query "SELECT COUNT(*) FROM $($WP db prefix)postmeta WHERE post_id = 999999" --skip-column-names 2>/dev/null || echo 0)"
check "live transient kept" "x" "$($WP transient get groundskeeper_smoke_live)"
check "spam removed" "0" "$($WP comment list --status=spam --format=count)"
check "second run finds nothing" "Success: Dry run: 0 items would be deleted." "$($WP groundskeeper cleanup all --dry-run | tail -1)"

echo "Reports"

for report in overview content media database plugins users; do
	if $WP groundskeeper report "$report" --format=json > "$TMP/$report.json" && php -r 'exit( null === json_decode( file_get_contents( $argv[1] ) ) ? 1 : 0 );' "$TMP/$report.json"; then
		check "report $report" "ok" "ok"
	else
		check "report $report" "ok" "broken"
	fi
done

$WP groundskeeper report all --file="$TMP/report.md" --skip-size > /dev/null
check "markdown report written" "# Site report" "$(head -c 13 "$TMP/report.md")"

echo
echo "$pass passed, $fail failed"
[[ $fail -eq 0 ]]
