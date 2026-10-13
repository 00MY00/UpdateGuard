# Journal des modifications

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versions [SemVer](https://semver.org/lang/fr/).
La version du module est celle de `UpdateGuard/module.json`.

## [1.0.0] - 2026-10-13

### Ajouté
- Première version : mises à jour sécurisées des modules communautaires FreeScout.
- Détection des modules et des mises à jour (`latestVersionUrl`, `latestVersionZipUrl`), niveaux de sécurité
  Full Safe / Protected / Generic / Unsupported, rapport de compatibilité.
- Vérification SHA-256 (`SHA256SUMS`), staging avec refus des archives dangereuses (path traversal, chemins absolus,
  liens symboliques, dossiers inattendus, archives corrompues).
- Préflight (disque, droits, compatibilité FreeScout / PHP, dépendances), sauvegarde avant chaque opération,
  installation par échange de dossiers et `freescout:module-install`.
- Contrôles de santé natifs (`<alias>:health [--json]`) et validation générique (structure, `php -l`, démarrage
  Laravel, routes, providers, vues Blade).
- Détection de crash (journal Laravel : HTTP 500, erreurs fatales), période d'observation, retour arrière automatique
  configurable par cause, blocage des versions en échec, verrou global.
- Pages Tableau de bord, Modules, Restaurations, Journaux et rapports, Réglages ; responsive ; intégration Refresh
  facultative.
- Rapports TXT / JSON / HTML, journal détaillé, rétention configurable.
- Commandes `updateguard:run`, `check`, `update`, `health`, `restore`, `cleanup`.
- `install.sh` (installation, mise à jour, désinstallation) et publication automatique des versions.
