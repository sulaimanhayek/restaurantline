#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Shared entrypoint for every PHP service in the stack.
#
# The first argument selects the role: serve | horizon | reverb | schedule.
# Only the `serve` role performs first-boot setup (key generation, migrations,
# seeding); the others wait for it to finish so they never race the schema.
# ---------------------------------------------------------------------------
set -euo pipefail

ROLE="${1:-serve}"

log() { printf '\033[0;36m[entrypoint]\033[0m %s\n' "$*"; }

# --- blank variables from the container environment ------------------------
# Belt and braces for the compose.yaml `env_file` trap (docs/DECISIONS.md
# #0030): Laravel treats an environment variable that exists but is empty as
# set, and never overwrites it from .env. Unsetting the blanks keeps .env
# authoritative however this container was started.
for var in APP_KEY APP_URL DB_DATABASE DB_USERNAME DB_PASSWORD; do
    if [[ -z "${!var:-}" ]]; then
        unset "$var" || true
    fi
done

# --- .env ------------------------------------------------------------------
if [[ ! -f .env ]]; then
    log "No .env found — copying .env.example."
    cp .env.example .env
fi

# --- dependencies ----------------------------------------------------------
# The vendor directory lives in the bind mount, so it survives across runs but
# will be missing on a fresh clone.
if [[ ! -f vendor/autoload.php ]]; then
    log "Installing composer dependencies (first run only)."
    composer install --no-interaction --prefer-dist --no-progress
fi

# --- wait for postgres -----------------------------------------------------
log "Waiting for PostgreSQL at ${DB_HOST:-postgres}:${DB_PORT:-5432}…"
until pg_isready -h "${DB_HOST:-postgres}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-restaurantline}" >/dev/null 2>&1; do
    sleep 1
done
log "PostgreSQL is up."

if [[ "$ROLE" == "serve" ]]; then
    # --- application key ---------------------------------------------------
    if ! grep -qE '^APP_KEY=base64:.+' .env; then
        log "Generating application key."
        php artisan key:generate --force
    fi

    # --- storage link ------------------------------------------------------
    [[ -L public/storage ]] || php artisan storage:link || true

    # --- schema ------------------------------------------------------------
    # `migrate --seed` is idempotent here: the seeders are written to be safe
    # to re-run, so restarting the stack never duplicates the demo data.
    log "Running migrations."
    php artisan migrate --force

    if [[ "${RESTAURANTLINE_SEED_ON_BOOT:-true}" == "true" ]]; then
        log "Seeding demo restaurant and sample menu."
        php artisan db:seed --force
    fi

    php artisan optimize:clear >/dev/null 2>&1 || true

    printf '\n'
    log "restaurantline is ready."
    log "  Dashboard      http://localhost:${APP_PORT:-8000}/admin"
    log "  Kitchen        http://localhost:${APP_PORT:-8000}/admin/kitchen"
    log "  Mail           http://localhost:${MAILPIT_UI_PORT:-8025}"
    printf '\n'
else
    # Non-web roles wait until the schema exists before starting, otherwise a
    # cold `docker compose up` produces a wall of "relation does not exist".
    log "Waiting for migrations to complete…"
    until php artisan migrate:status >/dev/null 2>&1; do
        sleep 2
    done
    log "Schema ready."
fi

case "$ROLE" in
    serve)
        exec php artisan serve --host=0.0.0.0 --port=8000
        ;;
    horizon)
        exec php artisan horizon
        ;;
    reverb)
        exec php artisan reverb:start --host=0.0.0.0 --port=8080
        ;;
    schedule)
        exec php artisan schedule:work
        ;;
    *)
        # Anything else is treated as a raw command, which makes the image
        # convenient for one-off `docker compose run app <cmd>` invocations.
        exec "$@"
        ;;
esac
