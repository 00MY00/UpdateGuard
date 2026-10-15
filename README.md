# Modules Updates Guard (UpdateGuard)
## UpdateGuard pour FreeScout

Module complémentaire de [FreeScout](https://freescout.net) qui ajoute une **couche de mise à jour sécurisée** pour les
modules communautaires : vérification SHA-256, staging, sauvegarde, contrôles de santé, détection de crash, retour
arrière automatique, restaurations, journal et rapports.

UpdateGuard n'est pas « un autre updater » : son rôle est
**intégrité + sauvegarde + validation + contrôle de santé + détection de crash + retour arrière + restauration + journal**.
Objectif : qu'une mauvaise mise à jour ait le moins de chances possible de rendre FreeScout inutilisable, et que
l'administrateur comprenne et restaure rapidement la situation.

- Ne modifie **ni le cœur de FreeScout ni les autres modules** (ni Refresh) : il utilise les mécanismes existants
  (`module.json`, `latestVersionUrl`, `latestVersionZipUrl`, `freescout:module-install`, hooks Eventy).
- **Refresh est facultatif** : sans lui, interface FreeScout standard ; avec lui, entrée dans sa barre latérale et
  feuille de style d'adaptation (`updateguard-refresh.css`) qui retombe sur le style standard s'il disparaît.
- Interface **responsive** (ordinateur, tablette, téléphone) : sur téléphone, les tableaux deviennent des cartes et les
  actions secondaires passent dans le menu `⋮`.
- **7 langues** : français, anglais, allemand, espagnol, italien, néerlandais, portugais (Brésil). La langue suit le
  profil FreeScout de l'utilisateur ; une langue absente utilise l'anglais. `install.sh` parle français ou anglais.
- Réservé aux **administrateurs** (routes `auth` + `roles:admin`, vérification répétée dans chaque contrôleur, CSRF).
- Les mises à jour ne s'exécutent **jamais dans une requête web** : l'interface crée une tâche, le planificateur de
  FreeScout l'exécute dans la minute (`updateguard:run`), la page affiche la progression.

## Installation

Prérequis : FreeScout ≥ 1.8, PHP ≥ 7.1 avec l'extension `zip`, la tâche cron de FreeScout
(`* * * * * php artisan schedule:run`).

```bash
curl -fsSLO https://github.com/00MY00/UpdateGuard/releases/latest/download/install.sh
sudo bash install.sh            # --path /var/www/html, --dry-run, --uninstall, --lang fr|en
```

Le script vérifie le SHA-256 de l'archive publiée, sauvegarde l'éventuelle version précédente (remise en place en cas
d'erreur), active le module, lance les migrations puis `php artisan updateguard:health`.

Installation manuelle : décompresser `UpdateGuard.zip` dans `Modules/`, puis **Gérer › Modules › UpdateGuard ›
Activer**.

UpdateGuard **ne se met pas à jour lui-même** (« UpdateGuard updates are handled by FreeScout ») : son `module.json`
déclare `latestVersionUrl` / `latestVersionZipUrl`, c'est le bouton natif de FreeScout (ou `install.sh`) qui le met à
jour.

## Utilisation (administrateurs)

Menu **Gérer › UpdateGuard**. Sur téléphone avec Refresh : panneau **Profil** (menu ☰) › UpdateGuard ; sans
Refresh : menu ☰ de FreeScout › Gérer › UpdateGuard.

| Page | Contenu |
|---|---|
| Tableau de bord | modules surveillés, mises à jour disponibles, modules sains, avertissements, échecs, dernière mise à jour, dernier retour arrière, opérations en cours, bouton « Tout vérifier » |
| Modules | version installée / dernière, niveau de sécurité, santé, dernière sauvegarde ; actions Mettre à jour, Vérifier, Contrôle de santé, Sauvegardes, Journal ; fiche détaillée avec **rapport de compatibilité**, retour à la dernière version saine, mises à jour automatiques autorisées ou non |
| Restaurations | toutes les sauvegardes (module, version, date, taille, santé, raison, version saine) ; Restaurer (page de confirmation « Actuelle / Restaurer »), Télécharger, Voir le rapport, Supprimer |
| Journaux et rapports | filtres module, date, gravité, état, ID de mise à jour, automatique/manuelle ; rapports TXT, JSON, HTML |
| Réglages | Mises à jour, Sécurité, Retour arrière, Rétention, Notifications, Avancé |

### Niveaux de sécurité

| Niveau | Condition | Automatique |
|---|---|---|
| ✓ Full Safe Update | version distante + ZIP + **SHA-256** + **contrôle de santé natif** | oui (si activé) |
| ! Protected Update | version distante + ZIP + SHA-256 **ou** contrôle natif | seulement si le réglage l'autorise |
| ! Generic Validation | version distante + ZIP, ni SHA-256 ni contrôle natif : tests génériques | jamais |
| × Unsupported Automatic Update | pas de `latestVersionUrl` / `latestVersionZipUrl`, module officiel sous licence, UpdateGuard lui-même | jamais |

La sauvegarde et le retour arrière des fichiers sont faits à **tous** les niveaux.

### Déroulement d'une mise à jour

```text
Mise à jour détectée → préflight → téléchargement (HTTPS) → SHA-256 → staging → tests génériques → sauvegarde
→ installation → vidage du cache → contrôle de santé → observation → SUCCÈS → version marquée stable

Contrôle de santé en ÉCHEC → retour arrière automatique → version précédente remise → cache reconstruit
→ contrôle de santé → version saine confirmée → version cible bloquée → rapport pour l'administrateur
```

- **Préflight** : espace disque, droits d'écriture (`Modules/`, `public/modules`, `bootstrap/cache`, stockage),
  extension zip, stockage des sauvegardes. Toute erreur critique : **ABORT avant toute modification**.
- **SHA-256** : `SHA256SUMS` publié à côté du ZIP (ou `sha256sumsUrl`). Empreinte différente : ABORT. Fichier annoncé
  mais illisible : ABORT. Pas de fichier : la mise à jour manuelle reste possible avec un avertissement,
  l'automatique est refusée.
- **Staging** dans `storage/updateguard/staging/` : jamais d'extraction directe dans `Modules/`. Refus des `../`,
  chemins absolus, liens symboliques, fichiers hors du dossier du module, dossiers de premier niveau inattendus,
  archives corrompues, `module.json` d'un autre module (nom ou alias différent).
- **Compatibilité** : `requiredAppVersion`, `requiredPhpVersion` et modules `requires` de la nouvelle version.
- **Migrations** : si la nouvelle version apporte des migrations, l'état de la base après un retour arrière est
  inconnu : le **retour arrière automatique est désactivé** pour cette mise à jour et elle n'est **jamais
  automatique** (sauf déclaration `"updateGuard": {"migrations": "backward-compatible"}` du module). En version 1,
  les migrations ne sont jamais annulées automatiquement.
- **Observation** (0, 5, 10, 30 ou 60 min) : la version reste « en attente de validation » ; les erreurs HTTP 500 et
  erreurs PHP fatales du module dans le journal Laravel déclenchent le retour arrière ; à la fin, un dernier contrôle
  de santé marque la version stable.
- **Protection contre les boucles** : une version en échec est **bloquée** (« Status: Blocked, Reason…, Last known
  good… ») et n'est plus retentée automatiquement ; une version plus récente est réévaluée. Une tentative automatique
  par version et par jour au maximum. L'administrateur peut réautoriser une version bloquée (action journalisée).
- **Verrou global** (`storage/updateguard/locks/`) : une seule mise à jour / restauration à la fois ; un verrou
  abandonné (processus tué) est nettoyé et l'opération interrompue est signalée, jamais reprise à l'aveugle.

### Même version republiée

Un numéro de version doit toujours désigner le même contenu. UpdateGuard retient le SHA-256 de chaque version vue
dans `SHA256SUMS`. Si la même version revient avec un autre SHA-256 (release modifiée après publication, ou source
compromise), rien n'est installé automatiquement : bande d'alerte, mises à jour automatiques du module suspendues, et
bouton « Mettre à jour vers cette version » pour l'administrateur qui fait confiance à la nouvelle publication.

### Restauration manuelle

Toujours : verrou → **sauvegarde de la version actuelle** → vérification de l'empreinte de la sauvegarde → restauration
→ `freescout:module-install` → vidage du cache → contrôle de santé → rapport. Si elle échoue : état **CRITICAL**,
aucune seconde restauration automatique.

### Sauvegardes et rétention

`storage/updateguard/backups/<Module>/<version>-<date>/` : `files/` (copie du module) et `metadata.json` (module,
version, date, empreinte, source, état de santé, ID de mise à jour, raison). Rétention : « garder les N dernières
versions » **ET** « supprimer les sauvegardes de plus de X jours » ; la **dernière version saine n'est jamais
supprimée automatiquement**. Rétention séparée du journal et des rapports.

### Commandes Artisan

| Commande | Rôle |
|---|---|
| `updateguard:run` | (planificateur, chaque minute) tâches en file, observations, mises à jour automatiques, verrous abandonnés |
| `updateguard:check [Module]` | versions distantes, SHA256SUMS, commandes de santé (planifié : chaque heure ou chaque jour) |
| `updateguard:update Module [--force] [--queue]` | mise à jour sécurisée |
| `updateguard:health [Module] [--all] [--json]` | contrôle de santé d'un module, de tous, ou d'UpdateGuard lui-même |
| `updateguard:restore Module [backup] [--list]` | restaurer une sauvegarde (par défaut la dernière version saine) |
| `updateguard:cleanup` | appliquer la rétention (planifié chaque jour) |

### Rapports

Chaque opération reçoit un ID (`UG-20261013-00042`) et un rapport (TXT, JSON facile à joindre à une issue GitHub,
HTML). Le rapport sépare clairement **« Native health check »** et **« Generic validation »**. Journal : horodatage,
gravité (DEBUG, INFO, WARNING, ERROR, CRITICAL), module, versions source et cible, étape, message.

### Conflit avec l'updater de FreeScout

FreeScout continue de fonctionner normalement et son bouton « Mettre à jour » n'est pas masqué (le cœur n'est pas
modifié). Sur la page Gérer › Modules, une note conseille de faire les mises à jour des modules communautaires avec
UpdateGuard (désactivable : Réglages › Notifications). Les modules gérés sont signalés « Managed by UpdateGuard » : ne les mettez pas à jour en même temps par un
autre mécanisme automatique.

## For module developers

UpdateGuard works with any community module that follows FreeScout's own update convention. To reach the
**Full Safe Update** level, publish four things:

1. **`module.json`** with the standard FreeScout keys:

   ```json
   {
     "name": "MyModule",
     "alias": "mymodule",
     "version": "1.2.0",
     "latestVersionUrl": "https://github.com/me/MyModule/releases/latest/download/version.txt",
     "latestVersionZipUrl": "https://github.com/me/MyModule/releases/latest/download/MyModule.zip",
     "healthCommand": "mymodule:health"
   }
   ```

   - `latestVersionUrl` answers the latest version as **plain text** (`1.2.0`), the same convention as
     `freescout:module-update`.
   - `latestVersionZipUrl`: a ZIP containing `MyModule/module.json` at its root (GitHub branch archives
     `MyModule-main/` are accepted too). HTTPS only.
   - Optional: `sha256sumsUrl` (default: `SHA256SUMS` next to the ZIP), `requiredAppVersion`, `requiredPhpVersion`,
     `requires` (aliases of required modules), `"updateGuard": {"migrations": "backward-compatible"}` when the
     previous version keeps working with your new tables/columns.

2. **`SHA256SUMS`** next to the ZIP (output of `sha256sum`):

   ```text
   3f5a…e1  MyModule.zip
   9b0c…77  module.json
   ```

3. **A health command** registered by your ServiceProvider, `<alias>:health` by convention (or `healthCommand`,
   which **must** stay in your own `<alias>:` namespace — any other command is ignored):

   ```bash
   php artisan mymodule:health --json
   ```

   - exit code `0` = healthy, anything else = failure;
   - with `--json` (optional, detected automatically), print one JSON object, kept in the report:

   ```json
   {
     "status": "ok",
     "module": "MyModule",
     "version": "1.2.0",
     "checks": { "files": "ok", "classes": "ok", "views": "ok", "routes": "ok", "assets": "ok", "database": "ok" }
   }
   ```

   Keep it fast, read-only and free of network calls. It is run in a new process right after the install, after a
   restore, and at the end of the observation period. UpdateGuard only runs a command already registered by the
   **installed** code: nothing downloaded is ever executed to find it.

4. **Nothing else.** Without a health command, UpdateGuard still runs its generic validation: structure
   (`module.json`, ServiceProvider files, namespace, files listed in `module.json`), `php -l` on every PHP file,
   Laravel bootstrap with the new code, core FreeScout routes, your routes resolved to existing controllers and
   methods, Blade templates compiled and linted.

### Hooks (no hard dependency)

```php
// extra checks run by the generic validation (new process, after install)
\Eventy::addFilter('updateguard.health_check', function ($checks, $moduleName, $alias) {
    if ($moduleName === 'MyModule') {
        $checks['mymodule cache'] = ['result' => is_writable(storage_path('mymodule')) ? 'PASS' : 'FAIL', 'detail' => ''];
    }
    return $checks;
}, 20, 3);
```

Actions: `updateguard.before_update`, `updateguard.after_update`, `updateguard.update_failed`,
`updateguard.before_rollback`, `updateguard.after_rollback` (job, report), `updateguard.backup_created` (backup),
`updateguard.health_check` (module name, result). Filter `updateguard.health_status` (status, module, native, generic).

## Développement

```bash
php tests/unit/run.php                       # tests unitaires purs PHP (archives malveillantes, SHA-256, verrou, 7 langues…)
php tests/blade/compile.php <autoload.php>   # compilation des vues Blade (illuminate/view)
bash tools/build-release.sh                  # dist/UpdateGuard.zip, version.txt, module.json, install.sh, SHA256SUMS
```

Architecture, modèle de données, machine d'état et stratégies : [docs/architecture.md](docs/architecture.md).

Licence : [AGPL-3.0](LICENSE), comme FreeScout.
