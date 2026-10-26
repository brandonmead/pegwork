#!/usr/bin/env bash
# Server time and query count of the Pegwork directory on a 500-record site (docs/phase-2-design.md section 10).
#
#   bin/perf-directory.sh <name>                    a bin/e2e-local-site.sh site (~/wp-envs/<name>): seeds 500 Projects if needed
#   bin/perf-directory.sh --url <url> --path <dir>  any other site; nothing is seeded unless you add --seed
#
# Options:
#   --seed             seed Projects up to --records on an explicit --url/--path site (always on for a <name> site)
#   --no-seed          never seed
#   --records N        Projects wanted (default 500)
#   --runs N           timed requests per scenario (default 20), after --warmup N (default 5)
#   --max-p95 MS       exit 1 when any scenario's p95 exceeds MS
#   --max-queries N    exit 1 when any scenario's query count exceeds N
#
# Scenarios: the Projects archive and a page holding the directory pattern ("Perf directory", created if missing),
# each unfiltered and filtered, pages 1 and 2, plus the home page as a baseline. Server time is curl's
# time to first byte (no page cache; add opcache to the PHP server for realistic numbers). Query counts come
# from a temporary mu-plugin (pegwork-perf-queries.php) installed in the target's wp-content and removed on exit.
#
# Never touches siforgood.local or any site unless you name it: pass --url and --path explicitly for those.
# Uses Local's PHP and MySQL socket like bin/e2e-local-site.sh (same overrides: LOCAL_PHP, LOCAL_SOCKET, WP_CLI_BIN).
set -euo pipefail

LOCAL_APP="$HOME/Library/Application Support/Local"
PHP="${LOCAL_PHP:-$LOCAL_APP/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php}"
SOCKET="${LOCAL_SOCKET:-$LOCAL_APP/run/iKB6dEYW-m/mysql/mysqld.sock}"
WP_CLI="${WP_CLI_BIN:-/opt/homebrew/bin/wp}"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

usage() {
	sed -n '2,23p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
	exit 2
}

name=""
url=""
dir=""
seed="auto"
records=500
runs=20
warmup=5
max_p95=""
max_queries=""

while [[ $# -gt 0 ]]; do
	case "$1" in
		--url) url="${2:?}"; shift 2 ;;
		--path) dir="${2:?}"; shift 2 ;;
		--seed) seed="yes"; shift ;;
		--no-seed) seed="no"; shift ;;
		--records) records="${2:?}"; shift 2 ;;
		--runs) runs="${2:?}"; shift 2 ;;
		--warmup) warmup="${2:?}"; shift 2 ;;
		--max-p95) max_p95="${2:?}"; shift 2 ;;
		--max-queries) max_queries="${2:?}"; shift 2 ;;
		-h|--help) usage ;;
		-*) echo "Unknown option $1" >&2; usage ;;
		*) name="$1"; shift ;;
	esac
done

if [[ -n "$name" ]]; then
	[[ -z "$dir" && -z "$url" ]] || { echo "Give a site name or --url with --path, not both." >&2; exit 2; }
	[[ "$name" =~ ^[a-z][a-z0-9_]{0,30}$ ]] || { echo "Bad site name." >&2; exit 2; }
	dir="$HOME/wp-envs/$name"
	[[ -f "$dir/wp-load.php" ]] || { echo "No throwaway site at $dir (bin/e2e-local-site.sh start $name <port>)." >&2; exit 1; }
	[[ "$seed" == "auto" ]] && seed="yes"
elif [[ -n "$url" && -n "$dir" ]]; then
	[[ "$seed" == "auto" ]] && seed="no"
else
	echo "Name a throwaway site, or pass both --url and --path." >&2
	usage
fi
[[ -f "$dir/wp-load.php" ]] || { echo "Not a WordPress folder: $dir" >&2; exit 1; }

wp() {
	"$PHP" -d memory_limit=512M -d "mysqli.default_socket=$SOCKET" "$WP_CLI" --path="$dir" "$@"
}

[[ -n "$url" ]] || url="$(wp option get home)"
url="${url%/}"
curl -fsS -o /dev/null "$url/wp-login.php" || { echo "Site not answering at $url (bin/e2e-local-site.sh start ...)." >&2; exit 1; }

MU="$dir/wp-content/mu-plugins/pegwork-perf-queries.php"
cleanup() { rm -f "$MU"; }
trap cleanup EXIT
mkdir -p "$dir/wp-content/mu-plugins"
cat >"$MU" <<'PHP'
<?php
/**
 * Temporary: appends the request's query count as an HTML comment when ?pegwork_perf_q=1.
 * Installed and removed by bin/perf-directory.sh.
 */
if ( isset( $_GET['pegwork_perf_q'] ) ) { // phpcs:ignore
	ob_start(
		function ( $html ) {
			global $wpdb;
			return $html . '<!-- pegwork-queries:' . (int) $wpdb->num_queries . ' -->';
		}
	);
}
PHP

wp plugin is-active pegwork || { echo "Pegwork is not active on $dir." >&2; exit 1; }
wp pegwork module enable listings >/dev/null 2>&1 || true
wp pegwork module enable filters >/dev/null 2>&1 || true

have="$(wp post list --post_type=sifg_project --post_status=publish --format=count)"
if [[ "$seed" == "yes" && "$have" -lt "$records" ]]; then
	echo "Seeding $((records - have)) Projects ($have exist)..."
	wp eval-file "$REPO/tests/perf/seed.php" "$((records - have))" "$((have + 42))" >/dev/null
	have="$(wp post list --post_type=sifg_project --post_status=publish --format=count)"
fi
echo "Site $url, $have published Projects."

wp rewrite flush >/dev/null 2>&1 || true
archive="$(wp eval 'echo wp_parse_url( (string) get_post_type_archive_link( "sifg_project" ), PHP_URL_PATH );')"
[[ -n "$archive" ]] || { echo "sifg_project has no archive (Listings module off or package missing)." >&2; exit 1; }

page_id="$(wp post list --post_type=page --name=perf-directory --post_status=publish --field=ID | head -n1)"
# The registered pattern, not Patterns::directory(): the Filters module adds the filters block to the
# registered one, and the page scenarios must run its count and controls too.
wp eval 'echo (string) ( WP_Block_Patterns_Registry::get_instance()->get_registered( "pegwork/sifg_project-directory" )["content"] ?? "" );' >"$dir/.perf-directory-pattern.html"
grep -q 'wp:pegwork/filters' "$dir/.perf-directory-pattern.html" || {
	rm -f "$dir/.perf-directory-pattern.html"
	echo "The registered Projects directory pattern has no filters block (Filters module off?)." >&2
	exit 1
}
if [[ -z "$page_id" ]]; then
	page_id="$(wp post create "$dir/.perf-directory-pattern.html" --post_type=page --post_status=publish --post_title='Perf directory' --post_name=perf-directory --porcelain)"
elif [[ "$(wp post get "$page_id" --field=post_content)" != *'wp:pegwork/filters'* ]]; then
	wp post update "$page_id" "$dir/.perf-directory-pattern.html" >/dev/null
fi
rm -f "$dir/.perf-directory-pattern.html"
page="$(wp eval "echo wp_parse_url( get_permalink( $page_id ), PHP_URL_PATH );" 2>/dev/null || true)"
[[ -n "$page" ]] || page="/perf-directory/"
qid="$(wp eval "preg_match( '/wp:query \{\"queryId\":(\d+)/', get_post( $page_id )->post_content, \$m ); echo \$m[1] ?? '';")"
[[ -n "$qid" ]] || { echo "No Query Loop found in the Perf directory page." >&2; exit 1; }

# The design scenario narrows to a few results; page 2 needs a wider filter or it would 404.
filt='project_type=nonprofit&impact=sdg-04,sdg-13&volunteers=1&q=data'
filt2='impact=sdg-04,sdg-13&volunteers=1'

# label|path
scenarios="baseline: home|/
archive p1|$archive
archive p2|${archive}page/2/
archive p1 filtered|$archive?$filt
archive p2 filtered*|${archive}page/2/?$filt2
page p1|$page
page p2|$page?query-$qid-page=2
page p1 filtered|$page?$filt
page p2 filtered*|$page?$filt2&query-$qid-page=2"

printf '\n%-24s %8s %8s %8s %8s\n' scenario p50_ms p95_ms max_ms queries
fail=0
while IFS='|' read -r label path; do
	status="$(curl -sS -o /dev/null -w '%{http_code}' "$url$path")"
	[[ "$status" == 200 ]] || { echo "HTTP $status for $url$path" >&2; fail=1; continue; }
	for _ in $(seq 1 "$warmup"); do curl -fsS -o /dev/null "$url$path" || true; done
	times="$(for _ in $(seq 1 "$runs"); do curl -fsS -o /dev/null -w '%{time_starttransfer}\n' "$url$path"; done | sort -n)"
	read -r p50 p95 max <<<"$(printf '%s\n' "$times" | awk '{ t[NR] = $1 * 1000 } END { i = int((NR + 1) / 2); j = int(0.95 * NR + 0.999); if (j < 1) j = 1; printf "%.0f %.0f %.0f", t[i], t[j], t[NR] }')"
	sep='?'
	[[ "$path" == *\?* ]] && sep='&'
	queries="$(curl -fsS "$url$path${sep}pegwork_perf_q=1" | grep -o 'pegwork-queries:[0-9]*' | tail -n1 | cut -d: -f2)"
	printf '%-24s %8s %8s %8s %8s\n' "$label" "$p50" "$p95" "$max" "${queries:-?}"
	if [[ "$label" != baseline* ]]; then
		[[ -n "$max_p95" && "$p95" -gt "$max_p95" ]] && fail=1
		[[ -n "$max_queries" && -n "$queries" && "$queries" -gt "$max_queries" ]] && fail=1
	fi
done <<<"$scenarios"

echo "* wider filter (impact sdg-04 or sdg-13, Needs volunteers) so page 2 exists."
if [[ "$fail" -ne 0 ]]; then
	echo "Failed: over budget (max p95 ${max_p95:-none} ms, max queries ${max_queries:-none})." >&2
	exit 1
fi
