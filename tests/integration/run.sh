#!/usr/bin/env bash
#
# End-to-end tests of UpdateGuard inside a disposable FreeScout (Docker: PHP 8.2 + Apache, MariaDB 10.11).
#
#   bash tests/integration/run.sh                 # downloads FreeScout (branch dist) from GitHub
#   FREESCOUT_SRC=/path/to/freescout bash tests/integration/run.sh   # or FREESCOUT_REF=<tag or branch>
#   KEEP=1 bash tests/integration/run.sh          # leave the containers running (debugging)
#
# Scenarios (test module "UgTestModule", packages built by fixtures.sh, offline updates with --zip):
#   1. clean package whose native health check fails → Security Gate PASS, installed, health FAIL, rollback to 1.0.0
#   2. package that downloads and runs remote code    → Security Gate BLOCKED, nothing installed
#   3. Zip Slip package                               → refused, never extracted
#   4. healthy package                                → installed, version 1.3.0, report "successful"
#   5. updateguard:scan --json                        → valid JSON, exit codes 0 / 1
#   6. security reports saved with the update results
# No external service is contacted (VirusTotal stays disabled).
#
set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
if (cd "$ROOT" && pwd -W) >/dev/null 2>&1; then
    export MSYS_NO_PATHCONV=1
    ROOT="$(cd "$ROOT" && pwd -W)"
fi
IMAGE="ug-freescout-test"
NET="ugtest"
DB="ug-test-db"
APP="ug-test-app"

log() { printf '\n== %s\n' "$*" >&2; }
cleanup() { docker rm -f "$APP" "$DB" >/dev/null 2>&1 || true; docker network rm "$NET" >/dev/null 2>&1 || true; }
trap '[ -n "${KEEP:-}" ] || cleanup' EXIT

debug() {
    trap - ERR
    log "Debug: FreeScout setup and Laravel log"
    docker exec "$APP" sh -c 'tail -n 60 /tmp/setup.log 2>/dev/null; tail -n 80 /var/www/html/storage/logs/*.log 2>/dev/null' || true
    if [ -n "${GITHUB_ACTIONS:-}" ]; then
        # last lines of the setup and of Laravel's log as an annotation (logs of the run are not public)
        printf '::error title=integration debug::%s\n' "$(docker exec "$APP" sh -c 'tail -n 15 /tmp/setup.log 2>/dev/null; tail -n 25 /var/www/html/storage/logs/*.log 2>/dev/null' 2>&1 | cut -c1-300 | sed ':a;N;$!ba;s/\n/%0A/g')"
    fi
}
trap debug ERR

FAILED=0
pass() { printf '  PASS  %s\n' "$*"; }
fail() {
    printf '  FAIL  %s\n' "$*"
    FAILED=$((FAILED + 1))
    # visible in the run summary (annotations)
    if [ -n "${GITHUB_ACTIONS:-}" ]; then printf '::error title=integration::%s\n' "$*"; fi
}
# expect <description> <condition command...>
expect() { local d="$1"; shift; if "$@"; then pass "$d"; else fail "$d"; fi; }

in_app() { docker exec -u www-data -w /var/www/html "$APP" "$@"; }
artisan() { in_app php artisan "$@"; }
# php code run in the booted FreeScout application
php_app() {
    in_app php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); '"$1"
}
version_of() { in_app php -r 'echo json_decode(file_get_contents("Modules/UgTestModule/module.json"), true)["version"];'; }

FREESCOUT_REF="${FREESCOUT_REF:-dist}"

log "Image and containers"
docker image inspect "$IMAGE" >/dev/null 2>&1 || docker build -q -t "$IMAGE" "$ROOT/tests/integration" >/dev/null
cleanup
docker network create "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" \
    -e MARIADB_ROOT_PASSWORD=test-root -e MARIADB_DATABASE=freescout -e MARIADB_USER=freescout -e MARIADB_PASSWORD=test-only \
    mariadb:10.11 --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci >/dev/null
docker run -d --name "$APP" --network "$NET" "$IMAGE" >/dev/null

log "FreeScout + UpdateGuard + test module"
if [ -n "${FREESCOUT_SRC:-}" ]; then
    docker cp "$FREESCOUT_SRC/." "$APP:/var/www/html/"
else
    docker exec "$APP" sh -c "curl -fsSL https://codeload.github.com/freescout-helpdesk/freescout/tar.gz/refs/heads/$FREESCOUT_REF | tar xz --strip-components=1 -C /var/www/html"
fi
docker cp "$ROOT/UpdateGuard" "$APP:/var/www/html/Modules/UpdateGuard"
docker cp "$ROOT/tests/integration/fixtures.sh" "$APP:/tmp/fixtures.sh"
docker exec "$APP" bash /tmp/fixtures.sh
docker exec "$APP" sh -c 'cp -a /tmp/ug-it/src/installed/UgTestModule /var/www/html/Modules/UgTestModule && rm -rf /var/www/html/.git && chown -R www-data:www-data /var/www/html /tmp/ug-it'

for _ in $(seq 1 60); do
    docker exec "$DB" healthcheck.sh --connect >/dev/null 2>&1 && break
    sleep 1
done

docker exec -u www-data -w /var/www/html "$APP" bash -c '
set -e
exec >/tmp/setup.log 2>&1
cat > .env <<EOF
APP_URL=http://localhost
APP_ENV=production
APP_KEY=
DB_CONNECTION=mysql
DB_HOST=ug-test-db
DB_PORT=3306
DB_DATABASE=freescout
DB_USERNAME=freescout
DB_PASSWORD=test-only
EOF
php artisan key:generate --force
php artisan migrate --force
php artisan module:enable UpdateGuard
php artisan module:enable UgTestModule
php artisan freescout:module-install updateguard >/dev/null 2>&1 || true
php artisan freescout:module-install ugtestmodule >/dev/null 2>&1 || true
php artisan migrate --force
php artisan config:clear
' || { docker exec "$APP" cat /tmp/setup.log >&2; debug; exit 1; }

# no observation period (the update is validated at once), scheduler not needed
php_app '\App\Option::set("updateguard.observation_minutes", 0);'
PKG=/tmp/ug-it/pkg

log "UpdateGuard health"
expect "updateguard:health (UpdateGuard itself) answers JSON with status ok" \
    bash -c "docker exec -u www-data -w /var/www/html $APP php artisan updateguard:health --json | grep -q '\"status\":\"ok\"'"
expect "native health command of the test module is registered" \
    bash -c "docker exec -u www-data -w /var/www/html $APP php artisan list --format=json | grep -q 'ugtestmodule:health'"
expect "installed test module is 1.0.0" test "$(version_of)" = "1.0.0"

log "1. Security Gate passes, health check fails → rollback"
set +e
out="$(artisan updateguard:update UgTestModule --zip=$PKG/healthfail-1.1.0.zip --sums=$PKG/SHA256SUMS --yes 2>&1)"
code=$?
set -e
printf '%s\n' "$out" | tail -n 25
expect "exit code 1 (rolled back)" test "$code" -eq 1
expect "job status rolled_back" bash -c "printf '%s' \"\$0\" | grep -q 'rolled_back'" "$out"
expect "Security Gate ran and passed (SHA-256 verified)" bash -c "printf '%s' \"\$0\" | grep -q 'Security Gate: risk'" "$out"
expect "module back to 1.0.0" test "$(version_of)" = "1.0.0"

log "2. Malicious package → blocked before installation"
set +e
scan="$(artisan updateguard:scan UgTestModule --zip=$PKG/malicious-1.2.0.zip --sums=$PKG/SHA256SUMS --json 2>&1)"
scan_code=$?
out="$(artisan updateguard:update UgTestModule --zip=$PKG/malicious-1.2.0.zip --sums=$PKG/SHA256SUMS --yes 2>&1)"
code=$?
set -e
printf '%s\n' "$out" | tail -n 8
expect "updateguard:scan exit code 1 (blocked)" test "$scan_code" -eq 1
expect "updateguard:scan --json is valid JSON with decision blocked" \
    bash -c "printf '%s' \"\$0\" | php -r '\$r = json_decode(stream_get_contents(STDIN), true); exit(is_array(\$r) && \$r[\"decision\"] === \"blocked\" && \$r[\"level\"] === \"CRITICAL\" ? 0 : 1);'" "$scan"
expect "update blocked by the security policy" bash -c "printf '%s' \"\$0\" | grep -q 'blocked by the security policy'" "$out"
expect "update exit code 1" test "$code" -eq 1
expect "module still 1.0.0 (nothing installed)" test "$(version_of)" = "1.0.0"

log "3. Zip Slip package → refused"
set +e
out="$(artisan updateguard:scan UgTestModule --zip=$PKG/zipslip.zip --json 2>&1)"
code=$?
set -e
expect "scan exit code 1" test "$code" -eq 1
expect "structure FAIL in the JSON" bash -c "printf '%s' \"\$0\" | php -r '\$r = json_decode(stream_get_contents(STDIN), true); exit(\$r[\"checks\"][\"structure\"][\"status\"] === \"FAIL\" && !\$r[\"overridable\"] ? 0 : 1);'" "$out"
expect "nothing written outside the staging folder" bash -c "! docker exec $APP test -e /tmp/ug-zip-slip.php"

log "4. Healthy package → installed"
set +e
out="$(artisan updateguard:update UgTestModule --zip=$PKG/healthy-1.3.0.zip --sums=$PKG/SHA256SUMS 2>&1)"
code=$?
set -e
printf '%s\n' "$out" | tail -n 6
expect "exit code 0" test "$code" -eq 0
expect "module is 1.3.0" test "$(version_of)" = "1.3.0"

log "5. updateguard:scan on the installed module"
set +e
artisan updateguard:scan UgTestModule --installed --json >/tmp/ug-scan.json 2>&1
code=$?
set -e
expect "scan of the installed module passes (exit 0)" test "$code" -eq 0

log "6. Security reports"
reports="$(php_app 'echo json_encode(\DB::table("updateguard_security_reports")->orderBy("id")->get(["module","to_version","decision","result","rollback","integrity"]));')"
printf '%s\n' "$reports"
expect "reports saved for the updates and scans" bash -c "printf '%s' \"\$0\" | php -r 'exit(count(json_decode(stream_get_contents(STDIN), true)) >= 5 ? 0 : 1);'" "$reports"
expect "report of the failed health check records the rollback" \
    bash -c "printf '%s' \"\$0\" | php -r '\$ok = false; foreach (json_decode(stream_get_contents(STDIN), true) as \$r) { \$ok = \$ok || (\$r[\"to_version\"] === \"1.1.0\" && \$r[\"result\"] === \"rolled_back\" && \$r[\"rollback\"] === \"SUCCESS\" && \$r[\"integrity\"] === \"VERIFIED\"); } exit(\$ok ? 0 : 1);'" "$reports"
expect "report of the malicious package records the block" \
    bash -c "printf '%s' \"\$0\" | php -r '\$ok = false; foreach (json_decode(stream_get_contents(STDIN), true) as \$r) { \$ok = \$ok || (\$r[\"to_version\"] === \"1.2.0\" && \$r[\"decision\"] === \"blocked\"); } exit(\$ok ? 0 : 1);'" "$reports"
expect "report of the healthy update records success" \
    bash -c "printf '%s' \"\$0\" | php -r '\$ok = false; foreach (json_decode(stream_get_contents(STDIN), true) as \$r) { \$ok = \$ok || (\$r[\"to_version\"] === \"1.3.0\" && \$r[\"result\"] === \"successful\"); } exit(\$ok ? 0 : 1);'" "$reports"

log "Pages (HTTP 200 for an administrator)"
php_app '
$u = \App\User::where("role", \App\User::ROLE_ADMIN)->first();
if (!$u) {
    $u = new \App\User();
    $u->first_name = "Admin";
    $u->last_name = "Test";
    $u->email = "admin@example.test";
    $u->password = bcrypt("test-only-password");
    $u->role = \App\User::ROLE_ADMIN;
    $u->save();
}
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
foreach (["updateguard.dashboard", "updateguard.modules", "updateguard.restorations", "updateguard.logs", "updateguard.security", "updateguard.settings"] as $route) {
    $request = Illuminate\Http\Request::create(route($route, [], false), "GET");
    auth()->guard()->setUser($u);
    $response = $kernel->handle($request);
    echo $route, " ", $response->getStatusCode(), "\n";
    if ($response->getStatusCode() !== 200) {
        echo substr(strip_tags((string) $response->getContent()), 0, 600), "\n";
    }
}' > /tmp/ug-pages.txt 2>&1 || true
cat /tmp/ug-pages.txt
expect "every page answers 200" bash -c "test \$(grep -c ' 200$' /tmp/ug-pages.txt) -eq 6"

log "Versions"
docker exec -w /var/www/html "$APP" sh -c 'php -r "echo \"PHP \".PHP_VERSION.PHP_EOL;"; php artisan --version'

printf '\n%s failure(s)\n' "$FAILED"
[ "$FAILED" -eq 0 ] || debug
exit $(( FAILED > 0 ? 1 : 0 ))
