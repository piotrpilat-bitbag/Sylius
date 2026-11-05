#!/bin/sh
# Static worker-mode audit of this checkout with Igor-PHP
# (https://github.com/igor-php/igor-php).
#
# ADVISORY ONLY - this never decides whether the build is green. The step that
# runs it is `continue-on-error`, and the audit itself is piped through `|| true`.
# That is a deliberate choice, not laziness: Igor works on the AST and cannot
# tell a mutation of an injected dependency from an ordinary method call on it,
# so on a codebase this size the majority of what it prints is noise. A job that
# goes red on noise teaches everyone to ignore the job, and then the handful of
# real findings go with it.
#
# What it adds next to the Behat suite in the same workflow: Behat only sees
# state that a scenario actually walks into, and only for the services those
# scenarios touch. This reads every shared service in the compiled container,
# including the ones no feature file reaches yet.
#
# Run from the repository root.
set -u

# Resolved from $0, not assumed to be ./.github/igor - APP_DIR may be somewhere
# else entirely when this is run by hand against an installed application.
SCRIPT_DIR="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"

IGOR_VERSION="${IGOR_VERSION:-0.10.1}"
APP_DIR="${APP_DIR:-$PWD}"
IGOR_ENV="${IGOR_ENV:-prod}"
OUT_DIR="${OUT_DIR:-${APP_DIR}/var/igor}"

cd "$APP_DIR" || exit 1

if [ ! -f vendor/autoload.php ]; then
    echo "::warning::no vendor/autoload.php - the application has not been built, skipping the audit"
    exit 0
fi

# prod, not the APP_ENV the rest of the job runs in. test_cached pulls in the
# profiler and the fixture/alice services, none of which exist in a production
# worker - auditing them only adds findings nobody can act on.
echo "==> environment: ${IGOR_ENV}"

ARCH="$(uname -m)"
case "$ARCH" in
    x86_64|amd64) ARCH="amd64" ;;
    aarch64|arm64) ARCH="arm64" ;;
    *) echo "::warning::unsupported architecture ${ARCH}, skipping the audit"; exit 0 ;;
esac

IGOR_BIN="${RUNNER_TEMP:-/tmp}/igor-php"
if [ ! -x "$IGOR_BIN" ]; then
    URL="https://github.com/igor-php/igor-php/releases/download/v${IGOR_VERSION}/igor-php_${IGOR_VERSION}_linux_${ARCH}.tar.gz"
    echo "==> downloading igor-php v${IGOR_VERSION} (${ARCH})"
    if ! curl -fsSL "$URL" | tar -xz -C "$(dirname "$IGOR_BIN")" igor-php; then
        echo "::warning::could not download igor-php v${IGOR_VERSION}, skipping the audit"
        exit 0
    fi
    chmod +x "$IGOR_BIN"
fi

# The map lands inside var/cache, which the running FrankenPHP worker is also
# reading. It is an extra file, not a replacement for anything the container
# uses, but it has no business outliving the audit.
cleanup() {
    rm -f "${APP_DIR}/var/cache/${IGOR_ENV}/igor_service_map.json" \
          "${APP_DIR}/var/cache/igor_service_map.json"
}
trap cleanup EXIT INT TERM

mkdir -p "$OUT_DIR"

# Without the map Igor falls back to `debug:container --show-hidden` and audits
# roughly nothing while reporting success - see the comment in igor-map.php.
echo "==> building the service map"
if ! php "${SCRIPT_DIR}/igor-map.php" "$APP_DIR" "$IGOR_ENV"; then
    echo "::warning::could not build the service map - skipping, because without it the audit would report a clean container it never looked at"
    exit 0
fi

echo "==> auditing"
"$IGOR_BIN" --env "$IGOR_ENV" . | tee "${OUT_DIR}/igor.txt" || true
"$IGOR_BIN" --env "$IGOR_ENV" --output json . > "${OUT_DIR}/igor.json" 2>/dev/null || true

if [ -s "${OUT_DIR}/igor.json" ]; then
    php "${SCRIPT_DIR}/summary.php" "${OUT_DIR}/igor.json" >> "${GITHUB_STEP_SUMMARY:-/dev/stdout}" || true
fi

echo "==> wrote ${OUT_DIR}/igor.txt and ${OUT_DIR}/igor.json"
exit 0
