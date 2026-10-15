# Architecture technique d'UpdateGuard

## Ce qui a été vérifié dans FreeScout (pas d'API inventée)

| Élément | Source FreeScout | Utilisation |
|---|---|---|
| `latestVersionUrl` = texte brut de la dernière version | `app/Console/Commands/ModuleUpdate.php` (`trim($response->getBody())`) | version distante |
| `latestVersionZipUrl` = ZIP décompressé dans `Modules/`, dossier `Nom` ou `Nom-<branche>` | `app/Module.php::updateFromUrl()` | téléchargement, dispositions d'archive acceptées |
| `App\Module::updateModule()` : pas de retour arrière | `app/Module.php` | raison d'être d'UpdateGuard |
| `freescout:module-install <alias>` | lien public, migrations, caches (comme Gérer › Modules) | installation et restauration |
| `App\Module::isActive()`, `App\Module::isOfficial()` | `app/Module.php` | état, modules officiels exclus |
| Routes nommées `login`, `dashboard`, `conversations.view`, `mailboxes.view` | `routes/web.php` | routes essentielles de la validation générique |
| Hooks `menu.manage.after_mailboxes`, `schedule` | `layouts/app.blade.php`, `app/Console/Kernel.php` | menu, planificateur |
| `refresh.rail_items` | module Refresh (point d'extension public) | entrée dans la barre de Refresh si actif |
| `App\Option::get/set` | `app/Option.php` | réglages |

## Flux

```text
UI (admin) ──POST──▶ updateguard_jobs (queued) ──▶ planificateur : updateguard:run (chaque minute)
                                                     │
                                                     ▼
                         UpdateRunner ── verrou global ── rapport + journal à chaque étape
   checking → preflight → downloading → verifying (SHA-256) → staging (ZipInspector) → tests génériques
   → Security Gate (Services/Security : analyse locale, fournisseurs externes, score, politique)
        bloqué → blocked ; confirmation / accord d'envoi → awaiting_decision (rien n'est modifié)
   → backing_up (BackupService) → installing (swap + freescout:module-install, nouveau processus)
   → clearing_cache → health_check (natif + updateguard:probe, nouveaux processus)
   → observing (LogScanner sur storage/logs) → successful
                       │ échec après installation
                       ▼
   rolling_back (dossier précédent ou sauvegarde) → module-install → health_check → rolled_back | rollback_failed
```

Toute erreur **avant** l'installation : ABORT, rien n'est modifié dans `Modules/`.

## Machine d'état (`updateguard_jobs.status`)

`queued` `checking` `downloading` `verifying` `staging` `backing_up` `installing` `clearing_cache` `health_check`
`observing` `successful` `failed` `rolling_back` `rolled_back` `rollback_failed` `blocked`

État du module (`updateguard_modules.state`) : `stable`, `pending_validation` (pendant l'installation et
l'observation), `failed` (échec sans retour arrière automatique), `critical` (retour arrière ou restauration en
échec, opération interrompue après remplacement des fichiers).

Une opération trouvée dans un état intermédiaire après un verrou abandonné est marquée `failed` (rien installé) ou
`rollback_failed` / `critical` (fichiers déjà remplacés) : jamais reprise automatiquement.

## Modèle de données

| Table | Contenu |
|---|---|
| `updateguard_modules` | nom, alias, version installée / distante, actif, niveau de sécurité, capacités (JSON : URLs, SHA-256, commande de santé, migrations), santé, état, dernière version saine, version bloquée + raison, mise à jour automatique autorisée, dates |
| `updateguard_jobs` | ID `UG-YYYYMMDD-NNNNN`, type (update, restore, health, check), module, versions, sauvegarde, état, progression, automatique, force, déclencheur, nature du retour arrière (`file-safe`, `database-safe`, `unknown`), dates d'installation / fin d'observation |
| `updateguard_backups` | module, version, chemin relatif, raison, ID, taille, empreinte (hash d'arborescence), source, santé, version saine |
| `updateguard_logs` | ID, gravité, module, versions, étape, message, contexte |

Rapports : `storage/updateguard/reports/<ID>.json` (format `updateguard-report/1`), exportés en TXT / JSON / HTML.

## Security Gate

| Classe (`Services/Security`) | Rôle |
|---|---|
| `SecurityGate` | orchestre : intégrité → structure → `LocalStaticScanner` → fournisseurs externes → `RiskScore` → `SecurityPolicy` ; sorties texte / JSON / code de sortie |
| `CodeAnalyzer` | analyse heuristique par jetons PHP (jamais d'exécution), scripts shell, binaires, PHP caché |
| `Rules` | règles : famille, gravité, poids, message ; plafonds par famille |
| `RiskScore` | score 0-100 (une règle comptée une fois, plafonds) et niveau selon les seuils |
| `SecurityPolicy` | allowed / blocked / confirm / upload_consent ; passage outre ; jamais d'attente pour une mise à jour automatique |
| `SecurityScannerProviderInterface`, `ScanResult`, `ConnectionResult` | contrat des fournisseurs, statuts standard |
| `LocalStaticScanner`, `VirusTotalScanner` | fournisseurs inclus (HTTP injecté : tests sans réseau) |
| `ScanCache` | cache SHA-256 + fournisseur (résultats définitifs seulement) |
| `Providers`, `Secrets`, `SecurityReports` | fabrique (filtre `updateguard.security_providers`), clé API chiffrée, rapports et rétention |

Table `updateguard_security_reports` ; colonnes ajoutées à `updateguard_jobs` : `security_decision`,
`security_sha256` (les décisions d'une page ne valent que pour le paquet vu), `security_report_id`, `source_path`,
`sums_path` (paquet local). État `awaiting_decision` : opération en pause avant toute modification, reprise (nouveau
téléchargement, même paquet exigé) après la décision.

## Sauvegarde / retour arrière

- Sauvegarde avant **chaque** mise à jour et **chaque** restauration (toujours active), copie sans suivre les liens
  symboliques, empreinte vérifiée contre l'original puis avant chaque restauration.
- Installation par **échange de dossiers** : `Modules/<Nom>` → `staging/<ID>/previous`, nouveau dossier →
  `Modules/<Nom>` ; le retour arrière réutilise `previous` (identique, plus rapide) ou, à défaut, la sauvegarde.
- Fichiers uniquement en v1 : affichage `File-safe rollback` / `Database-safe rollback` / `Database state unknown`.
  Nouvelles migrations sans déclaration → retour arrière automatique désactivé, mise à jour jamais automatique.
- Après un retour arrière, la version cible est bloquée : pas de boucle mise à jour → échec → retour arrière.

## Détection de crash

| Cause (réglage) | Détection |
|---|---|
| `bootstrap` | `freescout:module-install` en erreur, ou `updateguard:probe` ne démarre pas (ServiceProvider, classe absente, erreur fatale) |
| `generic` | probe : routes essentielles, providers chargés, routes du module → contrôleurs/méthodes existants, vues Blade compilées + `php -l`, contrôles ajoutés par d'autres modules |
| `native` | commande de santé du module, code de sortie ≠ 0 |
| `http500`, `fatal` | entrées ERROR+ du journal Laravel écrites depuis l'installation et citant `Modules/<Nom>/` ou `Modules\<Nom>\` |
| `timeout` | sous-processus au-delà du délai configuré |
| `validation` | exception dans la validation elle-même après l'installation |

## Sécurité

- HTTPS uniquement, options Guzzle de FreeScout (proxy, SSL).
- Archive inspectée **avant** extraction, puis chaque chemin revérifié à l'extraction (`realpath` dans le staging).
- Aucune archive ne peut toucher le cœur, UpdateGuard (refusé par nom), ni écrire hors de `Modules/<Nom>`.
- La commande de santé vient du code **installé** et doit être dans l'espace `<alias>:` du module.
- Pages réservées aux administrateurs, CSRF de Laravel sur toutes les actions, ID de rapport validés par expression
  régulière (pas de chemin arbitraire).

## Interfaces

Bureau : tableaux FreeScout (Bootstrap 3). Téléphone (< 768 px) : chaque ligne devient une carte avec libellés,
action principale en bouton large, actions secondaires dans `⋮`, journaux sur plusieurs lignes, traces repliées
(`<details>`), confirmation de restauration sur une page dédiée. États toujours symbole + texte (✓ ! × ↺ ↑ ●).
Refresh actif : `updateguard-refresh.css` utilise ses variables CSS avec repli sur les valeurs FreeScout.
