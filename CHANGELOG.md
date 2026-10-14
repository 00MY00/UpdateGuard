# Journal des modifications

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versions [SemVer](https://semver.org/lang/fr/).
La version du module est celle de `UpdateGuard/module.json`.

## [1.3.0] - 2026-10-14

### Ajouté
- **Alerte « même version republiée avec un contenu différent »** : UpdateGuard retient le SHA-256 de chaque version
  publiée (fichier `SHA256SUMS` du module). Si un même numéro revient avec un autre SHA-256, une bande d'alerte
  apparaît sur toutes les pages d'UpdateGuard (et « ! » dans le menu Gérer), les mises à jour automatiques du module
  sont suspendues, et un bouton **« Mettre à jour vers cette version »** permet de l'installer si l'on fait confiance
  à la nouvelle publication (sauvegarde, vérifications et retour arrière comme pour toute mise à jour). Une fois
  installée et validée, la nouvelle empreinte devient la référence.
- **Note sur la page Gérer › Modules de FreeScout** : à côté d'une mise à jour proposée par FreeScout pour un module
  communautaire, une note conseille de la faire avec UpdateGuard, avec un lien direct. Jamais pour UpdateGuard
  lui-même ni pour les modules officiels. Désactivable dans Réglages › Notifications.
- Réglages : les options marche / arrêt sont des **interrupteurs** (glissières).

### Corrigé
- **Affichage pas actualisé après une opération** : la page affichait encore les anciennes versions jusqu'à un
  rechargement manuel. Elle se recharge maintenant d'elle-même à la fin de l'opération, et au début de la période
  d'observation (la nouvelle version est déjà installée).

## [1.2.0] - 2026-10-14

### Corrigé
- **Accès depuis un téléphone avec Refresh** : Refresh remplace la barre de gauche par une barre d'onglets fixe, sans
  place pour un module, donc UpdateGuard n'était pas accessible. L'entrée « UpdateGuard » est maintenant ajoutée dans
  le panneau « Profil » de Refresh (son menu ☰), comme pour Workflow Packs. Réservée aux administrateurs. Sans
  Refresh, l'entrée reste dans le menu « Gérer » de FreeScout.

## [1.1.1] - 2026-10-13

### Corrigé
- Détection de l'extension PHP `zip` dans l'installeur via `extension_loaded('zip')`, pour éviter les faux échecs
  du pipeline `php -m | grep` avec `pipefail`.

## [1.1.0] - 2026-10-13

### Ajouté
- **7 langues**, comme Workflow Packs et RefreshGlobal : français, anglais, allemand, espagnol, italien, néerlandais,
  portugais (Brésil). La langue suit le profil FreeScout de l'utilisateur ; une langue absente utilise l'anglais.
- Test unitaire : les 7 fichiers ont les mêmes clés et les mêmes paramètres (`:module`, `:version`…), aucun texte vide.

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
