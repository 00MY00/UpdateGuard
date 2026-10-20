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
# PHP code is passed in single quotes on purpose (expanded by PHP, not by the shell)
# shellcheck disable=SC2016
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

log "7. \"Scan all modules\" button: scan job run by the scheduler"
php_app 'Modules\UpdateGuard\Services\UpdateRunner::queue("scan", "*", ["triggered_by" => "integration test"]);'
artisan updateguard:run --no-auto >/tmp/ug-scan-run.txt 2>&1 || true
tail -n 8 /tmp/ug-scan-run.txt
scans="$(php_app 'echo json_encode(\DB::table("updateguard_security_reports")->where("result", "scan")->where("update_id", "!=", "")->get(["module","decision","score","level"]));')"
printf '%s\n' "$scans"
expect "one scan report per installed module, none for UpdateGuard itself (it checks its own files instead)" \
    bash -c "printf '%s' \"\$0\" | php -r '\$m = array_column(json_decode(stream_get_contents(STDIN), true), \"module\"); exit(!in_array(\"UpdateGuard\", \$m, true) && in_array(\"UgTestModule\", \$m, true) ? 0 : 1);'" "$scans"

log "Pages (HTTP 200 for an administrator)"
php_app '
if (!\App\User::where("role", \App\User::ROLE_ADMIN)->exists()) {
    $u = new \App\User();
    $u->first_name = "Admin";
    $u->last_name = "Test";
    $u->email = "admin@example.test";
    $u->password = bcrypt("test-only-password");
    $u->role = \App\User::ROLE_ADMIN;
    $u->save();
}'
security_id="$(php_app 'echo (int) \DB::table("updateguard_security_reports")->max("id");')"
: > /tmp/ug-pages.txt
# one PHP process per page: the HTTP kernel is not meant to handle several requests in one process
for page in "" /modules /modules/UgTestModule /restorations /logs /security "/security/$security_id" /settings; do
    php_app '
$path = "/update-guard'"$page"'";
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
auth()->guard()->setUser(\App\User::where("role", \App\User::ROLE_ADMIN)->first());
$response = $kernel->handle(Illuminate\Http\Request::create("http://localhost".$path, "GET"));
echo $path, " ", $response->getStatusCode(), "\n";
if ($response->getStatusCode() !== 200) {
    // the exception, from Laravel'"'"'s log
    $log = glob(storage_path("logs/*.log"));
    $lines = $log ? preg_grep("/production\\.ERROR/", file(end($log))) : [];
    echo "   ", substr((string) end($lines), 0, 700), "\n";
}' >> /tmp/ug-pages.txt 2>&1 || true
done
cat /tmp/ug-pages.txt
expect "every page answers 200" bash -c "test \$(grep -c ' 200$' /tmp/ug-pages.txt) -eq 8"
if [ "$(grep -c ' 200$' /tmp/ug-pages.txt || true)" != "8" ] && [ -n "${GITHUB_ACTIONS:-}" ]; then
    printf '::error title=pages::%s\n' "$(grep -v '^#' /tmp/ug-pages.txt | head -c 3000 | sed ':a;N;$!ba;s/\n/%0A/g')"
fi

log "9. Detection of a new version (latestVersionUrl, real GitHub release)"
# the test module (1.3.0 now) announces UpdateGuard's public version.txt as its latest version: a newer version
# must be found by "Check all" (scheduler) and by updateguard:check
php_app '
$f = base_path("Modules/UgTestModule/module.json");
$m = json_decode(file_get_contents($f), true);
$m["latestVersionUrl"] = "https://github.com/00MY00/UpdateGuard/releases/latest/download/version.txt";
file_put_contents($f, json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));'
remote="$(curl -fsSL https://github.com/00MY00/UpdateGuard/releases/latest/download/version.txt | tr -d '[:space:]')"
artisan updateguard:check UgTestModule > /tmp/ug-check.txt 2>&1 || true
cat /tmp/ug-check.txt
detected="$(php_app '$r = \DB::table("updateguard_modules")->where("name", "UgTestModule")->first(); echo $r->installed_version, " ", $r->latest_version, " ", (json_decode($r->capabilities, true)["error"] ?? "");')"
printf 'installed / latest / error: %s (published: %s)\n' "$detected" "$remote"
expect "latest version read from latestVersionUrl" bash -c "test \"\$(printf '%s' \"\$0\" | cut -d' ' -f2)\" = \"\$1\"" "$detected" "$remote"
expect "the update is shown as available on the dashboard" \
    bash -c "docker exec -u www-data -w /var/www/html $APP php -r 'require \"vendor/autoload.php\"; \$app = require \"bootstrap/app.php\"; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); exit(Modules\\UpdateGuard\\Entities\\ModuleHealth::named(\"UgTestModule\")->updateAvailable() ? 0 : 1);'"
# the same through the "Check all" button: a queued job run by the scheduler
php_app '\DB::table("updateguard_modules")->where("name", "UgTestModule")->update(["latest_version" => ""]); Modules\UpdateGuard\Services\UpdateRunner::queue("check", "*", ["triggered_by" => "integration test"]);'
artisan updateguard:run --no-auto >/dev/null 2>&1 || true
latest="$(php_app 'echo \DB::table("updateguard_modules")->where("name", "UgTestModule")->value("latest_version");')"
expect "\"Check all\" (scheduler) finds it too" test "$latest" = "$remote"
# a latestVersionUrl answering a module.json (as Refresh does): accepted by FreeScout's Modules page, so by UpdateGuard too
php_app '
$f = base_path("Modules/UgTestModule/module.json");
$m = json_decode(file_get_contents($f), true);
$m["latestVersionUrl"] = "https://github.com/00MY00/UpdateGuard/releases/latest/download/module.json";
file_put_contents($f, json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
\DB::table("updateguard_modules")->where("name", "UgTestModule")->update(["latest_version" => ""]);'
artisan updateguard:check UgTestModule >/dev/null 2>&1 || true
latest="$(php_app '$r = \DB::table("updateguard_modules")->where("name", "UgTestModule")->first(); echo $r->latest_version, (json_decode($r->capabilities, true)["error"] ?? "");')"
expect "latest version read from a module.json ($latest)" test "$latest" = "$remote"

log "8. Trust: findings accepted by an administrator stop blocking, removing the trust blocks again"
code_of() { set +e; artisan updateguard:scan UgTestModule --zip=$PKG/malicious-1.2.0.zip --sums=$PKG/SHA256SUMS --json >/dev/null 2>&1; echo $?; set -e; }
expect "before: the package is blocked (exit 1)" test "$(code_of)" = "1"
php_app '
$row = \Modules\UpdateGuard\Entities\SecurityReport::where("module", "UgTestModule")->where("to_version", "1.2.0")->orderBy("id", "desc")->first();
$user = \App\User::where("role", \App\User::ROLE_ADMIN)->first();
echo \Modules\UpdateGuard\Services\Security\Trusts::trustReport($row, $user, "integration test: reviewed"), "\n";' > /tmp/ug-trust.txt 2>&1 || true
cat /tmp/ug-trust.txt
expect "findings trusted" bash -c "test \"\$(head -n 1 /tmp/ug-trust.txt)\" -ge 1"
expect "after trust: the same package passes (exit 0)" test "$(code_of)" = "0"
php_app '
$user = \App\User::where("role", \App\User::ROLE_ADMIN)->first();
foreach (\Modules\UpdateGuard\Entities\Trust::active()->where("module", "UgTestModule")->get() as $t) {
    \Modules\UpdateGuard\Services\Security\Trusts::revoke($t, $user);
}'
expect "trust removed: blocked again (exit 1)" test "$(code_of)" = "1"

log "Opening the dashboard checks the versions when the last check is more than an hour old"
php_app '
\DB::table("updateguard_jobs")->where("type", "check")->update(["finished_at" => now()->subHours(2), "updated_at" => now()->subHours(2)]);
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
auth()->guard()->setUser(\App\User::where("role", \App\User::ROLE_ADMIN)->first());
$response = $kernel->handle(Illuminate\Http\Request::create("http://localhost/update-guard", "GET"));
echo $response->getStatusCode(), " ", \DB::table("updateguard_jobs")->where("type", "check")->where("triggered_by", "Automatic (page opened)")->where("status", "queued")->count(), "\n";' > /tmp/ug-auto.txt 2>&1 || true
cat /tmp/ug-auto.txt
expect "dashboard queued an automatic check" grep -q '^200 1$' /tmp/ug-auto.txt
artisan updateguard:run --no-auto >/dev/null 2>&1 || true
php_app '
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
auth()->guard()->setUser(\App\User::where("role", \App\User::ROLE_ADMIN)->first());
$kernel->handle(Illuminate\Http\Request::create("http://localhost/update-guard", "GET"));
echo \DB::table("updateguard_jobs")->where("type", "check")->where("status", "queued")->count(), "\n";' > /tmp/ug-auto2.txt 2>&1 || true
expect "no new check while the last one is recent" grep -q '^0$' /tmp/ug-auto2.txt

log "Robustness: stuck operation, emptied setting"
php_app '\DB::table("updateguard_jobs")->insert(["update_id" => "UG-20261019-99999", "type" => "check", "module" => "*", "status" => "checking",
    "triggered_by" => "test", "created_at" => now()->subHours(3), "updated_at" => now()->subHours(3)]);'
artisan updateguard:run --no-auto >/dev/null 2>&1 || true
stuck="$(php_app 'echo \DB::table("updateguard_jobs")->where("update_id", "UG-20261019-99999")->value("status");')"
expect "an operation stuck for hours is marked interrupted (status: $stuck)" test "$stuck" = "failed"
saved="$(php_app 'Modules\UpdateGuard\Services\Settings::save(["sec_score_threshold" => "", "sec_level_high" => "55"]); echo Modules\UpdateGuard\Services\Settings::get("sec_score_threshold"), " ", Modules\UpdateGuard\Services\Settings::get("sec_level_high");')"
expect "an emptied number field keeps its value, another field is saved ($saved)" test "$saved" = "80 55"
php_app 'Modules\UpdateGuard\Services\Settings::save(["sec_level_high" => "50"]);'

log "Settings: blocking rules section"
php_app '
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
auth()->guard()->setUser(\App\User::where("role", \App\User::ROLE_ADMIN)->first());
$html = (string) $kernel->handle(Illuminate\Http\Request::create("http://localhost/update-guard/settings", "GET"))->getContent();
echo substr_count($html, "id=\"ug-blocking\""), " ", substr_count($html, "data-ug-depends="), " ", substr_count($html, "ug-setting-help"), " ",
    substr_count($html, "name=\"sec_auto_block_migrations\""), "\n";' > /tmp/ug-settings.txt 2>&1 || true
cat /tmp/ug-settings.txt
expect "blocking section, dependencies and explanations are rendered" \
    php -r '$p = explode(" ", trim(file_get_contents("/tmp/ug-settings.txt"))); exit(count($p) === 4 && $p[0] === "1" && $p[1] >= 15 && $p[2] >= 15 && $p[3] === "1" ? 0 : 1);'

log "Dashboard: packages unknown to VirusTotal are offered for upload"
php_app '
\App\Option::set("updateguard.vt_enabled", 1);
$report = ["module" => "UgTestModule", "scan_mode" => "published", "external" => ["VirusTotal" => ["status" => "NOT_AVAILABLE", "unknown" => true]]];
\DB::table("updateguard_security_reports")->insert(["module" => "UgTestModule", "to_version" => "1.3.0", "sha256" => str_repeat("a", 64),
    "result" => "scan", "decision" => "allowed", "report" => json_encode($report), "created_at" => now(), "updated_at" => now()]);'
php_app '
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
auth()->guard()->setUser(\App\User::where("role", \App\User::ROLE_ADMIN)->first());
$response = $kernel->handle(Illuminate\Http\Request::create("http://localhost/update-guard", "GET"));
echo $response->getStatusCode(), " ", substr_count((string) $response->getContent(), "ug-unknown-vt"), "\n";' > /tmp/ug-unknown.txt 2>&1 || true
cat /tmp/ug-unknown.txt
expect "dashboard shows the \"not known by VirusTotal\" panel" grep -q '^200 1$' /tmp/ug-unknown.txt
php_app '\App\Option::set("updateguard.vt_enabled", 0);'

log "UpdateGuard checks itself (files of its release) and points to FreeScout's Modules page for its updates"
# "Scan all modules" (scenario 7) ran SelfCheck for UpdateGuard, never the static analysis (its rules would flag it)
self_reports="$(php_app 'echo \DB::table("updateguard_security_reports")->where("module", "UpdateGuard")->count(), " ", json_decode((string) \DB::table("updateguard_modules")->where("name", "UpdateGuard")->value("capabilities"), true)["self_check"]["status"] ?? "none";')"
expect "scan of all modules: no security report on UpdateGuard itself, its own check instead ($self_reports)" \
    bash -c "printf '%s' \"\$0\" | grep -qE '^0 [a-z]+$'" "$self_reports"
# the release files built here (FILES.sha256, SHA256SUMS, UpdateGuard.zip) answered by a local DownloadService
bash "$ROOT/tools/build-release.sh" >/dev/null
docker exec "$APP" mkdir -p /tmp/ug-rel
for f in UpdateGuard.zip FILES.sha256 SHA256SUMS; do docker cp "$ROOT/dist/$f" "$APP:/tmp/ug-rel/$f"; done
docker exec "$APP" chown -R www-data:www-data /tmp/ug-rel
self_check="$(php_app '
$d = new class(5) extends Modules\UpdateGuard\Services\DownloadService {
    public function text($url) {
        $f = "/tmp/ug-rel/".basename(parse_url($url, PHP_URL_PATH));
        if (!is_file($f)) { throw new RuntimeException("HTTP 404: ".$url); }
        return file_get_contents($f);
    }
};
$z = new ZipArchive(); $z->open("/tmp/ug-rel/UpdateGuard.zip"); $z->extractTo("/tmp/ug-self"); $z->close();
$dir = "/tmp/ug-self/UpdateGuard";
$v = json_decode(file_get_contents($dir."/module.json"), true)["version"];
$a = Modules\UpdateGuard\Services\SelfCheck::run($dir, $v, $d);
file_put_contents($dir."/Services/Ui.php", "\n", FILE_APPEND);
file_put_contents($dir."/Public/extra.php", "<?php\n");
$b = Modules\UpdateGuard\Services\SelfCheck::run($dir, $v, $d);
echo $a["status"], " ", $a["files"], " ", $b["status"], " ", implode(",", $b["modified"]), " ", implode(",", $b["added"]);')"
echo "$self_check"
expect "files of the published release: intact; a changed and an added file: modified ($self_check)" \
    bash -c "printf '%s' \"\$0\" | grep -qE '^intact [0-9]+ modified Services/Ui.php Public/extra.php$'" "$self_check"
php_app '
$r = Modules\UpdateGuard\Entities\ModuleHealth::where("name", "UpdateGuard")->first();
$c = $r->capabilities();
$c["self_check"] = ["status" => "verified", "version" => $r->installed_version, "files" => 104, "vt" => null, "at" => date("c")];
$r->capabilities = json_encode($c);
$r->latest_version = "99.0.0";
$r->save();
\App\Option::set("updateguard.last_run", date("c"));'
: > /tmp/ug-self.txt
for page in "" /modules /modules/UpdateGuard; do
    php_app '
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
auth()->guard()->setUser(\App\User::where("role", \App\User::ROLE_ADMIN)->first());
$response = $kernel->handle(Illuminate\Http\Request::create("http://localhost/update-guard'"$page"'", "GET"));
$html = (string) $response->getContent();
echo "'"$page"'", " ", substr_count($html, "/modules/list\""), " ", substr_count($html, "Updated by FreeScout"), " ", substr_count($html, "✓ Verified"), "\n";
if ($response->getStatusCode() !== 200) {
    $log = glob(storage_path("logs/*.log"));
    $lines = $log ? preg_grep("/production\\.ERROR/", file(end($log))) : [];
    echo "#  HTTP ", $response->getStatusCode(), " ", substr((string) end($lines), 0, 900), "\n";
}' >> /tmp/ug-self.txt 2>&1 || true
done
cat /tmp/ug-self.txt
if grep -q '^#  HTTP' /tmp/ug-self.txt && [ -n "${GITHUB_ACTIONS:-}" ]; then
    printf '::error title=self pages::%s\n' "$(grep '^#  HTTP' /tmp/ug-self.txt | head -c 3000 | sed ':a;N;$!ba;s/\n/%0A/g')"
fi
expect "dashboard: update of UpdateGuard offered through FreeScout's Modules page, verified badge" grep -qE '^ [1-9][0-9]* [1-9][0-9]* [1-9]' /tmp/ug-self.txt
expect "Modules page: button to FreeScout's Modules page, neutral \"Updated by FreeScout\" on UpdateGuard" grep -qE '^/modules [1-9][0-9]* [1-9][0-9]* [1-9]' /tmp/ug-self.txt
expect "UpdateGuard page: button and verified check" grep -qE '^/modules/UpdateGuard [1-9][0-9]* 0 [1-9]' /tmp/ug-self.txt
php_app '\DB::table("updateguard_modules")->where("name", "UpdateGuard")->update(["latest_version" => ""]);'

log "10. Project compatibility: a declared conflict blocks, \"Install anyway\" installs (logged)"
before="$(version_of)"
set +e
out="$(artisan updateguard:update UgTestModule --zip=$PKG/compat-1.4.0.zip --sums=$PKG/SHA256SUMS --yes 2>&1)"
code=$?
set -e
printf '%s\n' "$out" | tail -n 25
expect "a conflict declared with the installed UpdateGuard blocks the update (exit 1)" test "$code" = "1"
expect "compatibility checks shown: UpdateGuard INCOMPATIBLE" bash -c "printf '%s' \"\$0\" | grep -qE '^UpdateGuard \.+ INCOMPATIBLE'" "$out"
expect "optional project absent: OPTIONAL / NOT INSTALLED (not blocking)" bash -c "printf '%s' \"\$0\" | grep -qE '^Refresh \.+ OPTIONAL / NOT INSTALLED'" "$out"
expect "nothing installed (still $before)" test "$(version_of)" = "$before"
compat="$(php_app '$j = \DB::table("updateguard_jobs")->where("module", "UgTestModule")->where("type", "update")->orderBy("id", "desc")->first();
$r = Modules\UpdateGuard\Services\ReportService::load(Modules\UpdateGuard\Services\Paths::reports(), $j->update_id);
echo $j->status, " ", $r["compatibility"]["overall"], " ", $r["compatibility"]["source"], " ", $r["steps"]["compatibility"]["result"], " ", isset($r["steps"]["backup"]) ? "backup" : "no-backup";')"
expect "report: blocked, INCOMPATIBLE, from the package interop.json, before any backup ($compat)" \
    bash -c "printf '%s' \"\$0\" | grep -q '^blocked INCOMPATIBLE package interop.json FAIL no-backup$'" "$compat"
set +e
out="$(artisan updateguard:update UgTestModule --zip=$PKG/compat-1.4.0.zip --sums=$PKG/SHA256SUMS --yes --override-compatibility 2>&1)"
code=$?
set -e
printf '%s\n' "$out" | tail -n 8
expect "\"Install anyway\" (--override-compatibility): installed (exit 0)" test "$code" = "0"
expect "module is 1.4.0" test "$(version_of)" = "1.4.0"
critical="$(php_app 'echo \DB::table("updateguard_logs")->where("module", "UgTestModule")->where("step", "compatibility")->where("level", "CRITICAL")->count();')"
expect "the override is recorded in the logs as CRITICAL ($critical)" test "$critical" -ge 1
php_app '\App\Option::set("updateguard.compat_allow_override", 0);'
set +e
artisan updateguard:update UgTestModule --zip=$PKG/compat-1.4.0.zip --sums=$PKG/SHA256SUMS --yes --force --override-compatibility >/dev/null 2>&1
code=$?
set -e
last="$(php_app '$j = \DB::table("updateguard_jobs")->where("module", "UgTestModule")->where("type", "update")->orderBy("id", "desc")->first(); echo $j->status, " ", substr((string) $j->message, 0, 14);')"
expect "overrides disabled in the settings: still blocked by the compatibility (exit $code, $last)" test "$code $last" = "1 blocked compatibility:"
php_app '\App\Option::set("updateguard.compat_allow_override", 1);'
# pages: confirmation before an update, module page with its compatibility section
: > /tmp/ug-compat.txt
for page in /modules/UgTestModule /modules/UgTestModule/update /modules/UpdateGuard; do
    php_app '
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
auth()->guard()->setUser(\App\User::where("role", \App\User::ROLE_ADMIN)->first());
$response = $kernel->handle(Illuminate\Http\Request::create("http://localhost/update-guard'"$page"'", "GET"));
$html = (string) $response->getContent();
echo "'"$page"'", " ", $response->getStatusCode(), " ", substr_count($html, "ug-compat"), "\n";
if ($response->getStatusCode() >= 500) {
    $log = glob(storage_path("logs/*.log"));
    $lines = $log ? preg_grep("/production\\.ERROR/", file(end($log))) : [];
    echo "#  ", substr((string) end($lines), 0, 900), "\n";
}' >> /tmp/ug-compat.txt 2>&1 || true
done
cat /tmp/ug-compat.txt
if grep -q '^#  ' /tmp/ug-compat.txt && [ -n "${GITHUB_ACTIONS:-}" ]; then
    printf '::error title=compat pages::%s\n' "$(grep '^#  ' /tmp/ug-compat.txt | head -c 3000 | sed ':a;N;$!ba;s/\n/%0A/g')"
fi
expect "module page: Project compatibility section" grep -qE '^/modules/UgTestModule 200 [1-9]' /tmp/ug-compat.txt
expect "update confirmation page answers (200 or redirect when up to date)" grep -qE '^/modules/UgTestModule/update (200|302)' /tmp/ug-compat.txt
expect "UpdateGuard page lists the modules that declare it" grep -qE '^/modules/UpdateGuard 200 [1-9]' /tmp/ug-compat.txt

log "Versions"
docker exec -w /var/www/html "$APP" sh -c 'php -r "echo \"PHP \".PHP_VERSION.PHP_EOL;"; php artisan --version'

printf '\n%s failure(s)\n' "$FAILED"
[ "$FAILED" -eq 0 ] || debug
exit $(( FAILED > 0 ? 1 : 0 ))
