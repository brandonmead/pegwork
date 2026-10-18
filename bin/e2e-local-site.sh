#!/usr/bin/env bash
# Throwaway WordPress site for the Pegwork E2E specs, using Local's PHP and MySQL (no Docker).
#
#   bin/e2e-local-site.sh create  <name> <port>   download, install, link the plugin, add the mu-plugin
#   bin/e2e-local-site.sh start   <name> <port>   create if needed, then run `wp server` in the background
#   bin/e2e-local-site.sh stop    <name>          stop the server
#   bin/e2e-local-site.sh destroy <name>          stop, drop database wpenv_<name> (prints the folder to delete by hand)
#
# Idempotent. Only ever touches database wpenv_<name> and folder ~/wp-envs/<name>.
# The admin password is random and written only to ~/wp-envs/<name>/.e2e-env (source it before running specs).
#
# Overrides: LOCAL_PHP, LOCAL_SOCKET, LOCAL_MYSQL_BIN, WP_CLI_BIN, PEGWORK_DIR.
set -euo pipefail

LOCAL_APP="$HOME/Library/Application Support/Local"
PHP="${LOCAL_PHP:-$LOCAL_APP/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php}"
SOCKET="${LOCAL_SOCKET:-$LOCAL_APP/run/iKB6dEYW-m/mysql/mysqld.sock}"
MYSQL_BIN="${LOCAL_MYSQL_BIN:-$LOCAL_APP/lightning-services/mysql-8.4.0+2/bin/darwin-arm64/bin}"
# wp db create/drop shell out to the mysql client.
[[ -x "$MYSQL_BIN/mysql" ]] && PATH="$MYSQL_BIN:$PATH"
WP_CLI="${WP_CLI_BIN:-/opt/homebrew/bin/wp}"
REPO="${PEGWORK_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
ENVS="$HOME/wp-envs"

cmd="${1:-}"
name="${2:-}"
port="${3:-}"

usage() {
	sed -n '2,12p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
	exit 2
}

[[ -n "$cmd" && -n "$name" ]] || usage
[[ "$name" =~ ^[a-z][a-z0-9_]{0,30}$ ]] || { echo "name must match ^[a-z][a-z0-9_]{0,30}$" >&2; exit 2; }

DIR="$ENVS/$name"
DB="wpenv_$name"
PIDFILE="$DIR/.server.pid"
CHILDPID="$DIR/.server.child.pid"
ENVFILE="$DIR/.e2e-env"

wp() {
	"$PHP" -d memory_limit=512M -d "mysqli.default_socket=$SOCKET" "$WP_CLI" --path="$DIR" "$@"
}

need_port() {
	[[ "$port" =~ ^[0-9]+$ ]] || { echo "port required for $cmd" >&2; exit 2; }
}

server_running() {
	[[ -f "$PIDFILE" ]] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null
}

do_create() {
	need_port
	[[ -x "$PHP" ]] || { echo "Local PHP not found: $PHP" >&2; exit 1; }
	[[ -S "$SOCKET" ]] || { echo "Local MySQL socket not found (is the Local site running?): $SOCKET" >&2; exit 1; }
	mkdir -p "$ENVS"

	if [[ ! -f "$DIR/wp-load.php" ]]; then
		"$PHP" -d memory_limit=512M "$WP_CLI" core download --path="$DIR" --quiet
	fi
	if [[ ! -f "$DIR/wp-config.php" ]]; then
		wp config create --dbname="$DB" --dbuser=root --dbpass=root \
			--dbhost="localhost:$SOCKET" --skip-check --quiet
		wp config set WP_DEBUG true --raw --quiet
		wp config set WP_DEBUG_LOG true --raw --quiet
		wp config set WP_DEBUG_DISPLAY false --raw --quiet
	fi
	# Fails when the database already exists; the install check below catches real problems.
	wp db create >/dev/null 2>&1 || true
	wp db check >/dev/null 2>&1 || { echo "Cannot reach database $DB" >&2; exit 1; }

	if ! wp core is-installed 2>/dev/null; then
		local pass
		pass="$(openssl rand -hex 16)"
		wp core install --url="http://127.0.0.1:$port" --title="Pegwork E2E $name" \
			--admin_user=admin --admin_password="$pass" --admin_email=e2e@example.com --skip-email --quiet
		umask 077
		{
			echo "export PEGWORK_E2E_BASE_URL=http://127.0.0.1:$port"
			echo "export PEGWORK_E2E_USER=admin"
			echo "export PEGWORK_E2E_PASSWORD=$pass"
		} >"$ENVFILE"
	fi
	# Keep the site URL in step with the requested port.
	wp option update home "http://127.0.0.1:$port" --quiet
	wp option update siteurl "http://127.0.0.1:$port" --quiet
	if [[ -f "$ENVFILE" ]]; then
		sed -i.bak "s#^export PEGWORK_E2E_BASE_URL=.*#export PEGWORK_E2E_BASE_URL=http://127.0.0.1:$port#" "$ENVFILE" && rm -f "$ENVFILE.bak"
	fi

	mkdir -p "$DIR/wp-content/mu-plugins"
	ln -sfn "$REPO" "$DIR/wp-content/plugins/pegwork"
	for mu in "$REPO"/tests/e2e/mu-plugins/*.php; do
		ln -sfn "$mu" "$DIR/wp-content/mu-plugins/$(basename "$mu")"
	done
	wp plugin activate pegwork --quiet 2>/dev/null || true
	wp rewrite structure '/%postname%/' --quiet
	echo "Site ready: http://127.0.0.1:$port (credentials: $ENVFILE)"
}

do_start() {
	need_port
	do_create
	if server_running; then
		echo "Already running (pid $(cat "$PIDFILE"))."
		return
	fi
	nohup "$PHP" -d memory_limit=512M "$WP_CLI" --path="$DIR" server --host=127.0.0.1 --port="$port" \
		>"$DIR/.server.log" 2>&1 &
	echo $! >"$PIDFILE"
	for _ in $(seq 1 30); do
		if curl -fsS -o /dev/null "http://127.0.0.1:$port/wp-login.php"; then
			# wp server runs php -S as a child; record the listener's exact pid so stop can end it.
			lsof -ti "tcp:$port" -sTCP:LISTEN 2>/dev/null | head -n1 >"$CHILDPID" || true
			echo "Serving http://127.0.0.1:$port. Run: source $ENVFILE && npm run test:e2e"
			return
		fi
		sleep 1
	done
	echo "Server did not answer; see $DIR/.server.log" >&2
	exit 1
}

do_stop() {
	if server_running; then
		local pid
		pid="$(cat "$PIDFILE")"
		kill "$pid" 2>/dev/null || true
		echo "Stopped server (pid $pid)."
	else
		echo "Server not running."
	fi
	# Only the exact pids this script recorded.
	if [[ -s "$CHILDPID" ]] && kill -0 "$(cat "$CHILDPID")" 2>/dev/null; then
		kill "$(cat "$CHILDPID")" 2>/dev/null || true
	fi
	rm -f "$PIDFILE" "$CHILDPID"
}

do_destroy() {
	do_stop
	if [[ -f "$DIR/wp-config.php" ]]; then
		wp db drop --yes >/dev/null 2>&1 || true
	fi
	echo "Dropped database $DB. The folder is left in place; remove it yourself: rm -rf '$DIR'"
}

case "$cmd" in
	create) do_create ;;
	start) do_start ;;
	stop) do_stop ;;
	destroy) do_destroy ;;
	*) usage ;;
esac
