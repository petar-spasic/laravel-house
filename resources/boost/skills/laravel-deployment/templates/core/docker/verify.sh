#!/usr/bin/env bash
# The probes of laravel-deployment's Verify that a script can make, run on the host from the project root:
#   docker/verify.sh        the local stack (docker-compose.local.yml, .env)
#   docker/verify.sh prod   the prod shape (docker-compose.yml, .env.prod), next to the local stack
# It brings the stack up, then prints one ✗ line per failed probe and exits 1 on any. What needs a browser or a
# judgement (HMR, the outer proxy's client IP, the test database's rows) stays in Verify.
set -uo pipefail

mode=${1:-local}
failed=0
fail() { echo "✗ $*"; failed=1; }
value() { sed -n "s/^$1=//p" "$2" 2>/dev/null | tail -n 1 | tr -d "\"'"; }

if [ "$mode" = prod ]; then
    env=.env.prod
    dc=(docker compose --env-file .env.prod -f docker-compose.yml)
else
    env=.env
    dc=(docker compose -f docker-compose.local.yml)
fi
port=$(value WEB_PORT "$env")
base="http://127.0.0.1:${port:-{{web_port}}}"

status() { curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$base$1"; }
expect() { # path code
    local got
    got=$(status "$1")
    [ "$got" = "$2" ] || fail "$1 answers $got, not $2"
}
programs() { # the names supervisorctl must list as RUNNING
    local listed
    listed=$("${dc[@]}" exec -T app supervisorctl status 2>&1)
    for program in "$@"; do
        grep -qE "^${program}[[:space:]]+RUNNING" <<<"$listed" || fail "supervisor program $program is not RUNNING"
    done
}
healthy() {
    "${dc[@]}" up -d --wait >/dev/null 2>&1 || fail "$1: the app container is not healthy"
}

"${dc[@]}" up -d --build --wait >/dev/null || { fail 'up --build --wait: the stack did not become healthy'; exit 1; }
health=$("${dc[@]}" exec -T app healthcheck.sh 2>&1) || fail "healthcheck.sh: $(grep '✗' <<<"$health" | paste -sd ' ')"
expect /up 200
expect /.env 404

if [ "$mode" = prod ]; then
    "${dc[@]}" exec -T app pgrep -u www-data -f 'artisan octane:frankenphp' >/dev/null || fail 'octane:frankenphp does not run as www-data'
    expect /frankenphp-worker.php 404
    expect /index.php 404
    # if:htmx
    asset=$("${dc[@]}" exec -T app sh -c 'ls public/build/assets 2>/dev/null | head -n 1')
    if [ -n "$asset" ]; then
        curl -sI --max-time 10 "$base/build/assets/$asset" | grep -qi '^cache-control:.*immutable' || fail "/build/assets/$asset is not immutable"
    fi
    # endif
    "${dc[@]}" exec -T app php artisan about --only=cache 2>&1 | grep -qi 'not cached' && fail 'php artisan about --only=cache shows something not cached'
    want=(app:web app:scheduler app:horizon)
    # if:spa
    want+=(app:ssr)
    # endif
    # if:reverb
    want+=(app:reverb)
    # endif
    programs "${want[@]}"
else
    [ -f public/frankenphp-worker.php ] && expect /frankenphp-worker.php 404
    # unless:htmx
    json=$(curl -s -o /dev/null -w '%{http_code} %{content_type}' --max-time 10 -H 'Accept: application/json' "$base/api/v1/x")
    [[ $json == "404 application/json"* ]] || fail "/api/v1/x answers $json, not Laravel's JSON 404"
    # endif
    if [ -x vendor/bin/kanban ] && [ "$(value KANBAN_UI .env)" != false ]; then
        token=$(value KANBAN_UI_TOKEN .env)
        expect "/kanban${token:+?token=$token}" 200
    fi
    want=(php-fpm caddy scheduler horizon)
    # if:htmx
    want+=(vite)
    # endif
    # if:spa
    [ -f frontend/package.json ] && want+=(vite)
    # endif
    # if:reverb
    want+=(reverb)
    # endif
    programs "${want[@]}"
    for round in 1 2; do
        "${dc[@]}" restart app >/dev/null 2>&1
        healthy "restart $round"
    done
fi

exit "$failed"
