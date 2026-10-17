#!/usr/bin/env bash
# Fails when any PHP file under the given directories (default: modules) references a Pegwork\
# namespace other than Pegwork\Api. Keeps modules splittable into separate plugins.
#
# Usage: bin/check-module-boundary.sh [dir...]

set -u

dirs=("$@")
if [ "${#dirs[@]}" -eq 0 ]; then
	dirs=(modules)
fi

status=0

for dir in "${dirs[@]}"; do
	[ -d "$dir" ] || continue

	# Every Pegwork\... token (also inside strings, where backslashes may be doubled), plus a bare
	# "namespace Pegwork" declaration. Output: file:line:match.
	hits=$(grep -rnoiE --include='*.php' 'pegwork\\+[a-z0-9_\\]*|namespace[[:space:]]+\\?pegwork[[:space:];{]' "$dir" || true)

	while IFS= read -r hit; do
		[ -n "$hit" ] || continue
		file=${hit%%:*}
		rest=${hit#*:}
		line=${rest%%:*}
		match=$(printf '%s' "${rest#*:}" | tr -s '\\' | tr '[:upper:]' '[:lower:]')

		case "$match" in
			'pegwork\api' | 'pegwork\api\'*) ;;
			*)
				echo "Boundary violation: ${file}:${line}: ${match}"
				status=1
				;;
		esac
	done <<< "$hits"
done

if [ "$status" -ne 0 ]; then
	echo "Modules may only reference the Pegwork\\Api namespace."
fi

exit "$status"
