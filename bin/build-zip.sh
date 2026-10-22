#!/usr/bin/env bash
# Builds the release zip, dist/pegwork.zip, from the committed tree (HEAD).
#
#   bin/build-zip.sh [out-dir]
#
# What ships: `git archive HEAD` minus every `export-ignore` path in .gitattributes (tests, sources,
# docs, dev config), plus the built assets/build/ and a fresh `composer install --no-dev` (never the
# working vendor/). The script fails when a dev file or a dev Composer package would ship, so keep
# .gitattributes and the DENY list below in step.
#
# Needs git, composer, npm (11, see CONTRIBUTING.md) and zip. Set SKIP_BUILD=1 to reuse an existing
# assets/build/ instead of running `npm run build`. Uncommitted changes are not included.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$ROOT/dist}"
NPM="${NPM:-npm}"

STAGE="$(mktemp -d "${TMPDIR:-/tmp}/pegwork-zip.XXXXXX")"
cleanup() { [[ -n "$STAGE" && -d "$STAGE" ]] && rm -r "$STAGE"; }
trap cleanup EXIT

PLUGIN="$STAGE/pegwork"
mkdir -p "$PLUGIN" "$OUT"

git -C "$ROOT" archive --format=tar HEAD | tar -x -C "$PLUGIN"

if [[ "${SKIP_BUILD:-}" != 1 ]]; then
	(cd "$ROOT" && $NPM run build)
fi
[[ -f "$ROOT/assets/build/editor-panel.js" ]] || { echo "assets/build/ is missing; run npm run build" >&2; exit 1; }
mkdir -p "$PLUGIN/assets"
cp -R "$ROOT/assets/build" "$PLUGIN/assets/build"

# Production dependencies only, installed from the lockfile into the clean export.
cp "$ROOT/composer.lock" "$PLUGIN/composer.lock"
(cd "$PLUGIN" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet)

# Every package in the shipped vendor/ must be a production package of the lockfile.
allowed="$(cd "$PLUGIN" && composer show --locked --no-dev --name-only 2>/dev/null | awk '{print $1}' | sort -u)"
installed="$(php -r '
	$file = $argv[1] . "/vendor/composer/installed.json";
	$data = is_file( $file ) ? json_decode( file_get_contents( $file ), true ) : array();
	foreach ( $data["packages"] ?? $data as $package ) { echo $package["name"], "\n"; }
' "$PLUGIN" | sort -u)"
dev_shipped="$(comm -23 <(printf '%s\n' "$installed" | sed '/^$/d') <(printf '%s\n' "$allowed" | sed '/^$/d'))"
if [[ -n "$dev_shipped" ]]; then
	echo "Dev packages would ship in vendor/:" >&2
	echo "$dev_shipped" >&2
	exit 1
fi
for dev in phpunit wp-phpunit yoast squizlabs wp-coding-standards phpcompatibility phpstan dealerdirect opis; do
	if [[ -e "$PLUGIN/vendor/$dev" ]] || compgen -G "$PLUGIN/vendor/*/$dev*" >/dev/null; then
		echo "Dev package would ship: vendor/$dev" >&2
		exit 1
	fi
done
rm "$PLUGIN/composer.json" "$PLUGIN/composer.lock"

# Paths that must never ship (relative to the plugin folder).
DENY=(
	tests docs bin node_modules assets/src examples dist
	composer.json composer.lock package.json package-lock.json
	phpcs.xml.dist phpunit.xml.dist playwright.config.js webpack.config.js eslint.config.cjs
	README.md CONTRIBUTING.md SECURITY.md CODE_OF_CONDUCT.md CLAUDE.md
	architecture.md changelog.md memory.md roadmap.md
)
status=0
for path in "${DENY[@]}"; do
	if [[ -e "$PLUGIN/$path" ]]; then
		echo "Dev file would ship: $path" >&2
		status=1
	fi
done
hidden="$(cd "$PLUGIN" && find . -name '.*' ! -name '.' | sort)"
if [[ -n "$hidden" ]]; then
	echo "Hidden files would ship:" >&2
	echo "$hidden" >&2
	status=1
fi
[[ "$status" -eq 0 ]] || exit 1

ZIP="$OUT/pegwork.zip"
[[ -f "$ZIP" ]] && rm "$ZIP"
(cd "$STAGE" && zip -q -r -X "$ZIP" pegwork)

echo "Built $ZIP ($(unzip -Z1 "$ZIP" | grep -vc '/$') files)"
