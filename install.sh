#!/usr/bin/env bash
#
# UpdateGuard — installation / update / uninstall on an existing FreeScout (Linux).
#
#   sudo bash install.sh                         install or update (latest version of the main branch)
#   sudo bash install.sh --path /var/www/html    FreeScout folder (otherwise detected, or asked)
#   sudo bash install.sh --source ./UpdateGuard.zip     local archive or folder instead of GitHub
#   sudo bash install.sh --dry-run               show what would be done, change nothing
#   sudo bash install.sh --uninstall             disable and remove the module (backups in storage/updateguard are kept)
#   sudo bash install.sh --yes                   no question
#   sudo bash install.sh --lang fr|en            language of the messages (default: from $LANG)
#
# Steps: checks -> backup (module folder + updateguard_* tables) -> copy -> activation -> migrations ->
# php artisan updateguard:health. On error, the previous version is put back. No password is shown or logged.
#
set -Eeuo pipefail

SCRIPT_VERSION="1.8.2"   # same as UpdateGuard/module.json (checked by tools/build-release.sh)
REPO_URL="${UG_REPO_URL:-https://github.com/00MY00/UpdateGuard}"
MODULE="UpdateGuard"
ALIAS="updateguard"
MIN_PHP="7.1.0"
DEFAULT_PATHS="/var/www/html /var/www/freescout /var/www/html/freescout /srv/freescout /var/www/helpdesk"

FS_PATH=""
SOURCE=""
DRY_RUN=0
YES=0
UNINSTALL=0
WEB_USER=""
WORK=""
BACKUP=""
STEP=0
STEPS=7
LOG="/tmp/updateguard-install-$(date +%Y%m%d-%H%M%S).log"
case "${LANG:-}${LC_ALL:-}" in fr*|*fr_*) L="fr" ;; *) L="en" ;; esac

if [ -t 1 ]; then C_RED=$'\e[31m'; C_GREEN=$'\e[32m'; C_YELLOW=$'\e[33m'; C_BLUE=$'\e[34m'; C_RESET=$'\e[0m'; else C_RED=""; C_GREEN=""; C_YELLOW=""; C_BLUE=""; C_RESET=""; fi

# t "texte français" "English text" : message in the chosen language
t() { if [ "$L" = "fr" ]; then printf '%s' "$1"; else printf '%s' "$2"; fi; }

log() { printf '%s %s\n' "$(date +%H:%M:%S)" "$*" >>"$LOG"; }
info() { printf '%s\n' "${C_BLUE}ℹ${C_RESET} $*"; log "INFO $*"; }
warn() { printf '%s\n' "${C_YELLOW}⚠ $*${C_RESET}" >&2; log "WARN $*"; }
ok() { printf '%s\n' "${C_GREEN}✔ $*${C_RESET}"; log "OK $*"; }
step() { STEP=$((STEP + 1)); printf '\n%s\n' "${C_BLUE}[${STEP}/${STEPS}]${C_RESET} $*"; log "STEP ${STEP} $*"; }
die() { printf '%s\n' "${C_RED}✖ $*${C_RESET}" >&2; log "ERROR $*"; exit 1; }

run() {
    # run CMD... : executes (or shows with --dry-run), output in the log
    if [ "$DRY_RUN" -eq 1 ]; then printf '  (dry-run) %s\n' "$*"; return 0; fi
    log "RUN $*"
    "$@" >>"$LOG" 2>&1
}

artisan() {
    if [ "$DRY_RUN" -eq 1 ]; then printf '  (dry-run) php artisan %s\n' "$*"; return 0; fi
    log "ARTISAN $*"
    (cd "$FS_PATH" && sudo -u "$WEB_USER" php artisan "$@") >>"$LOG" 2>&1
}

ask() {
    # ask "question" -> 0 when yes
    [ "$YES" -eq 1 ] && return 0
    local answer
    read -r -p "$1 $(t '[o/N]' '[y/N]') " answer || true
    [[ "$answer" =~ ^([oOyY]|oui|yes)$ ]]
}

version_ge() { [ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n 1)" = "$2" ]; }

json_version() { { sed -n 's/^[[:space:]]*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$1" 2>/dev/null | head -n 1; } || true; }

usage() { sed -n '3,16p' "$0" | sed 's/^# \{0,1\}//'; exit 0; }

parse_args() {
    while [ $# -gt 0 ]; do
        case "$1" in
            --path) FS_PATH="${2:-}"; shift 2 ;;
            --source) SOURCE="${2:-}"; shift 2 ;;
            --lang) L="${2:-en}"; [ "$L" = "fr" ] || L="en"; shift 2 ;;
            --dry-run) DRY_RUN=1; shift ;;
            --uninstall) UNINSTALL=1; shift ;;
            --yes|-y) YES=1; shift ;;
            -h|--help) usage ;;
            *) die "$(t "Option inconnue : $1 (voir --help)" "Unknown option: $1 (see --help)")" ;;
        esac
    done
}

on_error() {
    local code=$?
    printf '%s\n' "${C_RED}✖ $(t "Erreur à l'étape ${STEP} (code ${code}). Journal : ${LOG}" "Error at step ${STEP} (code ${code}). Log: ${LOG}")${C_RESET}" >&2
    if [ -n "$BACKUP" ] && [ -d "$BACKUP/module" ] && [ "$DRY_RUN" -eq 0 ]; then
        warn "$(t "Remise en place de la version précédente du module…" "Putting the previous version of the module back…")"
        rm -rf "${FS_PATH}/Modules/${MODULE}"
        cp -a "$BACKUP/module" "${FS_PATH}/Modules/${MODULE}"
        (cd "$FS_PATH" && sudo -u "$WEB_USER" php artisan freescout:clear-cache) >>"$LOG" 2>&1 || true
        warn "$(t "Version précédente restaurée (sauvegarde : $BACKUP)." "Previous version restored (backup: $BACKUP).")"
    fi
    exit "$code"
}
trap on_error ERR
trap '[ -n "$WORK" ] && rm -rf "$WORK"' EXIT

is_freescout() { [ -f "$1/artisan" ] && grep -q "freescout" "$1/config/app.php" 2>/dev/null; }

detect_freescout() {
    if [ -z "$FS_PATH" ]; then
        for candidate in $DEFAULT_PATHS; do
            if is_freescout "$candidate"; then FS_PATH="$candidate"; break; fi
        done
    fi
    # not in a default folder: ask for it (3 tries), unless --yes
    local tries=0
    while [ -z "$FS_PATH" ] || ! is_freescout "$FS_PATH"; do
        if [ -n "$FS_PATH" ]; then
            warn "$(t "« $FS_PATH » n'est pas un dossier FreeScout (artisan et config/app.php introuvables)." "\"$FS_PATH\" is not a FreeScout folder (artisan and config/app.php not found).")"
        fi
        if [ "$YES" -eq 1 ] || [ ! -t 0 ] || [ "$tries" -ge 3 ]; then
            die "$(t "FreeScout introuvable : indiquez son dossier avec --path /chemin/vers/freescout" "FreeScout not found: give its folder with --path /path/to/freescout")"
        fi
        tries=$((tries + 1))
        info "$(t "FreeScout n'est dans aucun dossier par défaut (${DEFAULT_PATHS})." "FreeScout is not in any default folder (${DEFAULT_PATHS}).")"
        read -r -p "$(t "Dossier de FreeScout : " "FreeScout folder: ")" FS_PATH || true
        FS_PATH="${FS_PATH%/}"
    done
    FS_PATH="$(cd "$FS_PATH" && pwd)"
    WEB_USER="$(stat -c '%U' "$FS_PATH/storage" 2>/dev/null || echo www-data)"
    [ "$WEB_USER" = "root" ] && WEB_USER="www-data"
    id "$WEB_USER" >/dev/null 2>&1 || die "$(t "Utilisateur du serveur web « $WEB_USER » introuvable." "Web server user \"$WEB_USER\" not found.")"
}

checks() {
    step "$(t "Vérifications" "Checks")"
    [ "$(id -u)" -eq 0 ] || die "$(t "Lancez le script avec sudo (il agit au nom de l'utilisateur du serveur web)." "Run the script with sudo (it acts as the web server user).")"
    command -v php >/dev/null || die "$(t "php introuvable." "php not found.")"
    local php_version fs_version
    php_version="$(php -r 'echo PHP_VERSION;')"
    version_ge "$php_version" "$MIN_PHP" || die "$(t "PHP $php_version : FreeScout demande au moins $MIN_PHP." "PHP $php_version: FreeScout needs at least $MIN_PHP.")"
    detect_freescout
    fs_version="$(sed -n "s/.*'version' => '\([^']*\)'.*/\1/p" "$FS_PATH/config/app.php" | head -n 1)"
    info "$(t "FreeScout ${fs_version:-?} dans $FS_PATH (utilisateur web : $WEB_USER), PHP $php_version" "FreeScout ${fs_version:-?} in $FS_PATH (web user: $WEB_USER), PHP $php_version")"
    version_ge "${fs_version:-0}" "1.8.0" || warn "$(t "FreeScout ${fs_version} : version minimale testée 1.8.0." "FreeScout ${fs_version}: minimum tested version 1.8.0.")"
    if [ ! -d "$SOURCE" ] || [ -z "$SOURCE" ]; then
        command -v unzip >/dev/null || die "$(t "unzip introuvable (apt install unzip)." "unzip not found (apt install unzip).")"
        [ -n "$SOURCE" ] || command -v curl >/dev/null || die "$(t "curl introuvable (apt install curl)." "curl not found (apt install curl).")"
    fi
    php -r 'exit(extension_loaded("zip") ? 0 : 1);' || die "$(t "Extension PHP zip absente : UpdateGuard en a besoin pour vérifier les archives." "PHP extension zip missing: UpdateGuard needs it to check archives.")"
    if [ -d "$FS_PATH/Modules/Refresh" ]; then ok "$(t "Module Refresh présent : les pages utiliseront son style." "Refresh module found: the pages will use its look.")"; else info "$(t "Module Refresh absent : style FreeScout standard." "Refresh module missing: standard FreeScout look.")"; fi
}

backup() {
    step "$(t "Sauvegarde" "Backup")"
    BACKUP="${FS_PATH}/storage/updateguard/install/install-$(date +%Y%m%d-%H%M%S)"
    if [ "$DRY_RUN" -eq 1 ]; then printf '  (dry-run) %s\n' "$BACKUP"; return 0; fi
    mkdir -p "$BACKUP"
    if [ -d "${FS_PATH}/Modules/${MODULE}" ]; then
        cp -a "${FS_PATH}/Modules/${MODULE}" "$BACKUP/module"
        ok "$(t "Dossier du module sauvegardé (version $(json_version "$BACKUP/module/module.json"))." "Module folder saved (version $(json_version "$BACKUP/module/module.json")).")"
    else
        info "$(t "Première installation : aucun dossier à sauvegarder." "First installation: no folder to save.")"
    fi
    # tables of the module (JSON export by FreeScout itself: no password on the command line)
    (cd "$FS_PATH" && sudo -u "$WEB_USER" php -r '
        require "vendor/autoload.php"; $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $out = [];
        foreach (["updateguard_modules", "updateguard_jobs", "updateguard_backups", "updateguard_logs"] as $t) {
            $out[$t] = Schema::hasTable($t) ? DB::table($t)->get() : null;
        }
        echo json_encode($out);
    ') >"$BACKUP/tables.json" 2>>"$LOG" || warn "$(t "Sauvegarde des tables impossible (base inaccessible ?) : voir le journal." "Cannot save the tables (database unreachable?): see the log.")"
    # the whole work folder: the scheduler (web server user) writes staging, backups, locks and reports there
    chown -R "$WEB_USER":"$WEB_USER" "${FS_PATH}/storage/updateguard"
    ok "$(t "Sauvegarde : $BACKUP" "Backup: $BACKUP")"
}

fetch_module() {
    step "$(t "Récupération du module" "Getting the module")"
    WORK="$(mktemp -d)"
    local src="$SOURCE"
    if [ -z "$src" ]; then
        if [ "$DRY_RUN" -eq 1 ]; then printf '  (dry-run) download\n'; return 0; fi
        # latest published release, checked against its SHA256SUMS (same source as the in-app update)
        src="$WORK/UpdateGuard.zip"
        if curl -fsSL "${REPO_URL}/releases/latest/download/UpdateGuard.zip" -o "$src" 2>>"$LOG" \
            && curl -fsSL "${REPO_URL}/releases/latest/download/SHA256SUMS" -o "$WORK/SHA256SUMS" 2>>"$LOG"; then
            info "$(t "Dernière version publiée téléchargée depuis ${REPO_URL}/releases." "Latest published release downloaded from ${REPO_URL}/releases.")"
            local expected actual
            expected="$(sed -n 's/^\([a-f0-9]\{64\}\)[[:space:]]\{1,\}\*\{0,1\}UpdateGuard\.zip[[:space:]]*$/\1/p' "$WORK/SHA256SUMS" | head -n 1)"
            actual="$(sha256sum "$src" | cut -d' ' -f1)"
            if [ -z "$expected" ] || [ "$expected" != "$actual" ]; then
                die "$(t "Empreinte SHA-256 de l'archive incorrecte : rien n'est installé." "Wrong SHA-256 of the archive: nothing is installed.")"
            fi
            ok "$(t "Empreinte SHA-256 vérifiée." "SHA-256 checked.")"
        else
            # no release published yet: current version of the main branch
            src="$WORK/main.zip"
            warn "$(t "Aucune version publiée : téléchargement de la branche main (sans fichier SHA256SUMS)." "No published release: downloading the main branch (no SHA256SUMS file).")"
            curl -fsSL "${REPO_URL}/archive/refs/heads/main.zip" -o "$src" || die "$(t "Téléchargement impossible." "Download failed.")"
        fi
    fi
    if [ -d "$src" ]; then
        if [ -f "$src/module.json" ]; then cp -a "$src" "$WORK/$MODULE"; else cp -a "$src/$MODULE" "$WORK/$MODULE"; fi
    else
        [ -f "$src" ] || die "$(t "Source introuvable : $src" "Source not found: $src")"
        unzip -q "$src" -d "$WORK/x"
        local found
        found="$(find "$WORK/x" -maxdepth 3 -type f -name module.json -path "*/${MODULE}/module.json" | head -n 1)"
        [ -n "$found" ] || die "$(t "L'archive ne contient pas ${MODULE}/module.json." "The archive does not contain ${MODULE}/module.json.")"
        cp -a "$(dirname "$found")" "$WORK/$MODULE"
    fi
    grep -q "\"alias\": \"${ALIAS}\"" "$WORK/$MODULE/module.json" || die "$(t "module.json inattendu dans la source." "Unexpected module.json in the source.")"
    rm -rf "$WORK/$MODULE/Tests"
    ok "$(t "UpdateGuard $(json_version "$WORK/$MODULE/module.json") prêt." "UpdateGuard $(json_version "$WORK/$MODULE/module.json") ready.")"
}

install_module() {
    step "$(t "Copie des fichiers" "Copying the files")"
    local current new
    current="$(json_version "${FS_PATH}/Modules/${MODULE}/module.json")"
    new="$(json_version "$WORK/$MODULE/module.json")"
    if [ -n "$current" ]; then info "$(t "Mise à jour ${current} → ${new:-?}" "Update ${current} → ${new:-?}")"; fi
    run rm -rf "${FS_PATH}/Modules/${MODULE}"
    run cp -a "$WORK/$MODULE" "${FS_PATH}/Modules/${MODULE}"
    run chown -R "$WEB_USER":"$WEB_USER" "${FS_PATH}/Modules/${MODULE}"
    ok "$(t "Fichiers en place." "Files in place.")"

    step "$(t "Activation" "Activation")"
    artisan cache:clear   # the list of modules is cached: a new folder is not seen without this
    artisan module:enable "$MODULE"
    # creates the link public/modules/updateguard, runs the migrations, clears the caches (like Manage › Modules)
    artisan freescout:module-install "$ALIAS"
    ok "$(t "Module activé." "Module enabled.")"

    step "$(t "Migrations" "Migrations")"
    artisan migrate --force
    artisan freescout:clear-cache
    ok "$(t "Base à jour." "Database up to date.")"
}

verify() {
    step "$(t "Vérification" "Verification")"
    if [ "$DRY_RUN" -eq 1 ]; then printf '  (dry-run) php artisan updateguard:health
'; return 0; fi
    mkdir -p "${FS_PATH}/storage/updateguard"
    chown -R "$WEB_USER":"$WEB_USER" "${FS_PATH}/storage/updateguard" 2>>"$LOG" || true
    local code=0
    (cd "$FS_PATH" && sudo -u "$WEB_USER" php artisan updateguard:health) || code=$?
    [ "$code" -eq 0 ] || die "$(t "updateguard:health a échoué (code $code)." "updateguard:health failed (code $code).")"
    # first look at the installed modules (versions, SHA256SUMS, health commands); network errors are only recorded
    (cd "$FS_PATH" && sudo -u "$WEB_USER" php artisan updateguard:check) >>"$LOG" 2>&1 || true
    ok "$(t "UpdateGuard est opérationnel : menu Gérer › UpdateGuard." "UpdateGuard is ready: menu Manage › UpdateGuard.")"
    info "$(t "Le planificateur de FreeScout doit tourner chaque minute (cron : php artisan schedule:run)." "FreeScout's scheduler must run every minute (cron: php artisan schedule:run).")"
}

uninstall() {
    STEPS=3
    step "$(t "Vérifications" "Checks")"
    [ "$(id -u)" -eq 0 ] || die "$(t "Lancez le script avec sudo." "Run the script with sudo.")"
    detect_freescout
    [ -d "${FS_PATH}/Modules/${MODULE}" ] || die "$(t "UpdateGuard n'est pas installé dans $FS_PATH." "UpdateGuard is not installed in $FS_PATH.")"
    warn "$(t "Les autres modules ne sont pas touchés ; les sauvegardes et rapports restent dans storage/updateguard." "Other modules are not touched; backups and reports stay in storage/updateguard.")"
    ask "$(t "Désinstaller UpdateGuard ?" "Uninstall UpdateGuard?")" || die "$(t "Annulé." "Cancelled.")"
    backup
    step "$(t "Retrait" "Removal")"
    artisan migrate:rollback --path="Modules/${MODULE}/Database/Migrations" --force || warn "$(t "Tables non supprimées (voir le journal)." "Tables not removed (see the log).")"
    artisan module:disable "$MODULE" || true
    run rm -rf "${FS_PATH}/Modules/${MODULE}" "${FS_PATH}/public/modules/${ALIAS}"
    # caches that still name the module's provider (module list + configuration + cache store): rebuilt below
    run rm -f "${FS_PATH}/bootstrap/cache/update_guard_module.php" "${FS_PATH}/bootstrap/cache/config.php"
    run find "${FS_PATH}/storage/framework/cache/data" -mindepth 1 -delete
    artisan freescout:clear-cache || true
    ok "$(t "UpdateGuard retiré (sauvegarde : $BACKUP)." "UpdateGuard removed (backup: $BACKUP).")"
}

main() {
    parse_args "$@"
    : >"$LOG"
    chmod 600 "$LOG"
    printf '%s\n' "UpdateGuard — $(t "script" "script") ${SCRIPT_VERSION} ($(t "journal" "log"): $LOG)"
    [ "$DRY_RUN" -eq 1 ] && info "$(t "Mode --dry-run : aucune modification." "--dry-run mode: no change.")"
    if [ "$UNINSTALL" -eq 1 ]; then uninstall; exit 0; fi
    checks
    backup
    fetch_module
    install_module
    verify
    printf '\n%s\n' "$(t "Terminé. Mises à jour d'UpdateGuard : relancer ce script, ou Gérer › Modules de FreeScout." "Done. UpdateGuard updates: run this script again, or FreeScout's Manage › Modules.")"
}

main "$@"
