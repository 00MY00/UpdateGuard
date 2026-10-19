# Journal des modifications

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versions [SemVer](https://semver.org/lang/fr/).
La version du module est celle de `UpdateGuard/module.json`.

## [1.8.1] - 2026-10-19

### Corrigé
- **Nouvelles versions des modules vues trop tard** : les versions n'étaient vérifiées qu'une fois par jour (03:20 par
  défaut) ou avec « Tout vérifier ». Elles sont maintenant aussi vérifiées en arrière-plan à l'ouverture du tableau de
  bord ou de la page Modules quand la dernière vérification date de plus d'une heure (comme la page Modules de
  FreeScout) ; la page se met à jour d'elle-même. « Dernière vérification des versions » est affichée.
- Quand la dernière version d'un module est inconnue, la raison est indiquée : module officiel (mis à jour par
  FreeScout), module qui ne publie pas ses versions (pas de `latestVersionUrl`), pas encore vérifié, ou erreur.
- Test d'intégration ajouté : détection d'une nouvelle version publiée sur GitHub (commande, « Tout vérifier » et
  vérification automatique à l'ouverture du tableau de bord) dans un vrai FreeScout.

## [1.8.0] - 2026-10-16

### Ajouté
- **Faire confiance aux constats d'un module** : depuis un rapport de sécurité, un administrateur qui a vérifié les
  comportements signalés (par exemple VirusTotal ne détecte rien) peut les accepter pour ce module, avec un motif
  obligatoire et une confirmation. Les constats acceptés restent visibles dans les rapports (« confiance accordée »)
  mais ne comptent plus dans le score ni dans le blocage. La confiance porte sur chaque constat précis (règle, fichier
  et détail : fonction, domaine…) : un nouveau comportement dans une version suivante est signalé de nouveau. Jamais
  pour une empreinte différente, une archive dangereuse ou une détection externe. Journalisé, révocable (« Retirer »
  sur la fiche du module), désactivable (Réglages › Règles de blocage › Mises à jour manuelles).
- **Fiche du module : état de chaque test de la dernière analyse** (intégrité, structure, PHP, obfuscation,
  exécutables, système de fichiers, réseau, scripts d'installation, scanners externes) et ce qui a été signalé
  (fichier et ligne), sans relancer d'analyse ; liste des constats acceptés.

## [1.7.0] - 2026-10-15

### Modifié
- **Réglages › Règles de blocage** : toutes les règles qui peuvent arrêter une mise à jour sont réunies dans une
  section, rangées par situation — **Toujours**, **Mises à jour manuelles**, **Mises à jour automatiques** — chacune
  avec un interrupteur et une explication. Les règles qui dépendent d'un interrupteur désactivé (porte de sécurité,
  VirusTotal, mises à jour automatiques, retour arrière automatique) sont grisées ; leur valeur est conservée.
- **Trois règles des mises à jour automatiques, jusqu'ici fixes, deviennent réglables** (activées par défaut, donc
  sans changement de comportement) : refuser un paquet sans SHA256SUMS, refuser une mise à jour qui apporte des
  migrations de base de données, refuser une version republiée avec un autre SHA-256.
- Le réglage « En cas d'échec de VirusTotal » faisait double emploi avec « Un scanner externe est indisponible » :
  il n'en reste qu'un, dans les règles de blocage.

## [1.6.0] - 2026-10-15

### Modifié
- **Mise à jour automatique d'un paquet inconnu de VirusTotal : plus installée tant qu'il n'a pas été vérifié**
  (nouveau réglage « Mises à jour automatiques : ne pas installer un paquet inconnu du scanner externe », activé par
  défaut, sans effet si VirusTotal est désactivé). Le tableau de bord propose alors « Envoyer et analyser » ; la mise
  à jour automatique est retentée chaque heure (sans requête VirusTotal grâce au cache) et passe dès que le paquet est
  connu et sain. Avant, une mise à jour automatique installait un paquet inconnu avec la seule analyse locale.
- Rappel du fonctionnement avant une mise à jour : paquet **déjà connu** de VirusTotal → son résultat est utilisé
  directement (une seule requête, aucun envoi) ; paquet **inconnu** → mise à jour manuelle en pause avec « Envoyer et
  analyser » / « Continuer sans envoyer » / « Annuler ».

## [1.5.1] - 2026-10-15

### Corrigé
- **Téléphone : le menu « ⋮ » s'ouvrait hors de l'écran.** Sur une carte sans bouton « Mettre à jour », le bouton ⋮
  était à gauche et son menu, aligné à droite, partait hors de l'écran. Le menu s'ouvre maintenant en panneau en bas
  de l'écran, toujours visible, avec des entrées plus grandes au doigt ; le bouton ⋮ est toujours à droite. Les
  pastilles longues passent à la ligne au lieu d'élargir la carte. Vérifié par capture à 390 px de large.

### Ajouté
- **Paquets inconnus de VirusTotal proposés à l'envoi depuis le tableau de bord** : après une analyse, un encadré
  liste les modules dont le paquet n'est pas encore connu de VirusTotal, avec « Envoyer et analyser » pour chacun et
  « Tout envoyer et analyser » (les paquets déjà connus viennent du cache, sans requête). Même bouton sur la fiche du
  module. Toujours après l'avertissement de confidentialité, jamais automatiquement.

## [1.5.0] - 2026-10-15

### Ajouté
- **Boutons d'analyse de sécurité** sans rien installer : « Analyser tous les modules » sur le tableau de bord et
  « Analyse de sécurité » sur la fiche d'un module. Le paquet publié du module est analysé (analyse locale + VirusTotal
  s'il est activé), ou les fichiers installés quand le module ne publie pas de paquet. À la fin, la page ouvre le
  rapport ; la fiche du module affiche la dernière analyse. Sur un rapport dont le paquet est inconnu de VirusTotal :
  bouton « Envoyer et analyser » (avec l'avertissement de confidentialité).
- **Réglages enregistrés automatiquement** à chaque changement, sans bouton (le bouton Enregistrer reste si
  JavaScript est désactivé).
- **Clé API VirusTotal testée dès sa saisie** : refusée par VirusTotal, elle n'est pas enregistrée ; VirusTotal
  injoignable ou limite atteinte, elle est enregistrée avec un avertissement.
- **Limites de l'API gratuite de VirusTotal** (4 recherches / minute, 500 / jour, 15,5 K / mois, modifiables) :
  compteur partagé, attente d'un créneau pour les tâches de fond, jamais d'attente dans une page, utilisation du jour
  et du mois affichée ; choix « Gratuit » / « Premium (sans limite) ». Économies : résultat en cache par empreinte,
  réponse « inconnu » gardée 1 jour, aucune recherche pour un paquet déjà bloqué par l'analyse locale, moins
  d'interrogations après un envoi.

## [1.4.0] - 2026-10-15

### Ajouté
- **Security Gate** : vérification de sécurité de chaque paquet de module **avant** son installation, dans le
  staging (les contrôles de santé restent après l'installation). Interrupteur général et un interrupteur par contrôle
  dans Réglages › Sécurité ; désactivé, UpdateGuard fonctionne comme avant.
- **Analyse locale sans service externe** : empreintes SHA-256 (`VERIFIED` / `MISMATCH` / `NOT AVAILABLE` /
  `DISABLED`), structure du paquet (Zip Slip, liens, **chemins en double ou en conflit**, **bombe ZIP**), motifs PHP
  dangereux et leurs combinaisons (`eval(base64_decode(…))`, nom de fonction construit…), obfuscation (chaînes
  encodées, couches de décodage, entropie, PHP caché dans une image), binaires exécutables, écritures hors du module
  (`/etc`, cron, `.env`, cœur de FreeScout, autre module, serveur web), comportement réseau (code distant exécuté,
  lecture de secrets), scripts d'installation (`curl | sh`, crontab…). Analyse par jetons PHP, sans jamais exécuter
  le code.
- **Score de risque** 0-100 (LOW / MEDIUM / HIGH / CRITICAL, seuils réglables), une règle comptée une fois et un
  plafond par famille pour limiter les faux positifs.
- **Politique de sécurité réglable** (empreinte différente, archive dangereuse, résultat critique, score ≥ seuil,
  scanner externe bloquant ou indisponible) ; **résumé avant installation** avec « Continuer la mise à jour » /
  « Annuler » pour les mises à jour manuelles ; **passage outre** par un administrateur (case + confirmation,
  journalisé en CRITICAL, jamais pour une empreinte différente ni une archive dangereuse).
- **VirusTotal facultatif** (désactivé par défaut) : clé API chiffrée, test de connexion, recherche par empreinte
  d'abord, **jamais d'envoi silencieux** (accord demandé pour un fichier inconnu, envoi automatique seulement s'il est
  activé), seuils de détection, comportement en cas d'échec, cache par SHA-256 + fournisseur.
- **Architecture de fournisseurs** (`SecurityScannerProviderInterface`, statuts standard) ; d'autres scanners peuvent
  être ajoutés par le filtre `updateguard.security_providers`.
- Page **Rapports de sécurité** (liste, rapport complet, décisions) et rétention (jours + nombre maximum).
- Commande **`updateguard:scan`** (`--json`, `--zip`, `--installed`, codes de sortie 0 / 1 / 2) ; `updateguard:update`
  accepte un **paquet local** (`--zip`, `--sums`, mise à jour hors ligne) et les décisions `--yes`, `--upload`,
  `--skip-upload`, `--override`.
- **Tests d'intégration dans un vrai FreeScout** (Docker, en CI) : retour arrière après un contrôle de santé raté,
  paquet malveillant bloqué, Zip Slip refusé, mise à jour saine, sortie JSON, rapports de sécurité, pages.

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
