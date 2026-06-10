# Système d'annonces publiques (Public Announcement System)

> 🇬🇧 [English documentation](README.md)

Extension MediaWiki qui diffuse les modifications récentes vers un **canal Discord** sous forme d'annonces publiques : éditions, créations de pages, suppressions, renommages, téléversements, protections, blocages, créations de compte, etc.

Conçue pour la performance et la fiabilité :

- **Zéro impact sur l'enregistrement des pages** — aucun appel réseau dans le hook ; l'envoi est différé (POSTSEND) ou mis en file (JobQueue).
- **Respect du rate limit Discord** — les réponses HTTP 429 sont honorées via le `Retry-After` de Discord, avec re-programmation automatique (plafonnée à 5 tentatives).
- **Entièrement localisable et personnalisable** — chaque phrase envoyée vers Discord provient du système i18n et peut être surchargée directement sur le wiki ; icônes, couleurs et éléments des messages sont configurables.

## Prérequis

- MediaWiki ≥ 1.43
- PHP ≥ 8.1
- Une URL de webhook Discord ([documentation Discord](https://support.discord.com/hc/fr/articles/228383668))

## Installation

1. Clonez ou téléchargez ce dépôt dans votre répertoire `extensions/` :

   ```bash
   cd extensions
   git clone https://github.com/GenerationFallout/Public-Announcement-System.git PublicAnnouncementSystem
   ```

2. Ajoutez dans votre `LocalSettings.php` :

   ```php
   wfLoadExtension( 'PublicAnnouncementSystem' );
   $wgPASystemWebhookUrl = 'https://discord.com/api/webhooks/…/…';
   ```

3. Vérifiez sur `Special:Version` que l'extension est chargée, puis rendez-vous sur `Special:PASystemTest` (réservé aux sysops) pour diffuser une annonce de test.

L'extension reste **désactivée** tant que `$wgPASystemWebhookUrl` est vide.

## Référence de configuration

Tous les réglages se font dans `LocalSettings.php`.

### Connexion

| Réglage | Défaut | Description |
|---|---|---|
| `$wgPASystemWebhookUrl` | `''` | URL du webhook Discord (HTTPS obligatoire). Vide = extension désactivée. |
| `$wgPASystemWebhookRoutes` | `{}` | Routage optionnel par type d'action (voir ci-dessous). |
| `$wgPASystemBotName` | `''` | Nom affiché du bot dans Discord. Vide = utilise `$wgSitename`. |
| `$wgPASystemBotAvatarUrl` | `''` | URL de l'avatar du bot (optionnel). |
| `$wgPASystemWikiBaseUrl` | `''` | URL de base pour les liens. Vide = déduite de `$wgServer` + `$wgScriptPath`. |

#### Routage par action (`$wgPASystemWebhookRoutes`)

Chaque type d'action peut être envoyé vers son propre webhook (donc son propre canal). Les types sans route retombent sur `$wgPASystemWebhookUrl`. Types disponibles : `edit`, `new`, `upload`, `delete`, `restore`, `move`, `protect`, `unprotect`, `block`, `unblock`, `newuser`, `rights`, `log`, plus `flood` pour les avis de forte activité.

```php
// Les actions de modération partent vers le canal privé #admin,
// tout le reste vers le canal public ($wgPASystemWebhookUrl) :
$wgPASystemWebhookRoutes = [
    'delete' => 'https://discord.com/api/webhooks/…/admin…',
    'block'  => 'https://discord.com/api/webhooks/…/admin…',
    'rights' => 'https://discord.com/api/webhooks/…/admin…',
];
```

### Envoi

| Réglage | Défaut | Description |
|---|---|---|
| `$wgPASystemDeliveryMode` | `'immediate'` | `'immediate'` : envoi différé POSTSEND, latence < 1 s, sans retry. `'job'` : JobQueue avec retries automatiques, latence dépendant de votre JobRunner. |
| `$wgPASystemFormat` | `'line'` | `'line'` : message compact sur une ligne. `'embed'` : embed riche avec champs. |
| `$wgPASystemMaxPerMinute` | `0` | Plafond d'annonces par minute (fenêtre fixe, partagée entre requêtes web et job runners). Quand il est franchi, un unique avis de forte activité (message `pasystem-flood-notice`) est envoyé puis les annonces sont ignorées jusqu'à la fin de la fenêtre. `0` = illimité. |

### Filtrage

| Réglage | Défaut | Description |
|---|---|---|
| `$wgPASystemNotifyBots` | `false` | Annoncer les modifications des comptes bots. |
| `$wgPASystemNotifyMinor` | `true` | Annoncer les modifications mineures. |
| `$wgPASystemNotifyCategorization` | `false` | Annoncer les changements d'appartenance aux catégories (entrées techniques `RC_CATEGORIZE` ; une seule édition peut en produire plusieurs). |
| `$wgPASystemNotifyExternal` | `false` | Annoncer les changements externes (`RC_EXTERNAL`, ex. mises à jour Wikidata). |
| `$wgPASystemIncludedNamespaces` | `[]` | **Liste blanche** de namespaces : si non vide, seuls ces namespaces sont annoncés, ex. `[ 0 ]` pour le main uniquement. Vide = tous. |
| `$wgPASystemExcludedNamespaces` | `[]` | Numéros de namespaces à ignorer, ex. `[ 2, 3 ]` pour User et User_talk. |
| `$wgPASystemExcludedUsers` | `[]` | Utilisateurs dont les actions ne sont jamais annoncées. |
| `$wgPASystemExcludedLogTypes` | `[]` | Types de log à ignorer, ex. `[ 'patrol', 'thanks' ]`. |
| `$wgPASystemMinDiffSize` | `0` | Différence minimale en octets pour annoncer une édition. `0` = toutes. |
| `$wgPASystemStripAutoSummaries` | `true` | Masque les résumés auto-générés par MediaWiki (ex. *Page créée avec « … »*). La détection s'appuie sur les messages core `autosumm-*` dans la langue de contenu du wiki, elle fonctionne donc dans toutes les langues. Les résumés saisis manuellement sont toujours affichés. |

### Apparence

| Réglage | Défaut | Description |
|---|---|---|
| `$wgPASystemDisplay` | `{}` | Interrupteurs d'affichage, fusionnés avec les défauts (voir ci-dessous). |
| `$wgPASystemActionIcons` | `{}` | Emoji par type d'action, fusionnés avec les défauts (voir ci-dessous). |
| `$wgPASystemEmbedColors` | *(voir extension.json)* | Couleur d'embed (RGB décimal) par type d'action, fusionnées avec les défauts. |
| `$wgPASystemDebug` | `false` | Logs verbeux dans le canal `PublicAnnouncementSystem`. |

#### Interrupteurs d'affichage (`$wgPASystemDisplay`)

Chaque élément d'une annonce peut être désactivé individuellement. Toutes les clés valent `true` par défaut ; ne listez que celles à désactiver :

```php
$wgPASystemDisplay = [
    'icons'     => false, // emoji devant chaque annonce
    'delta'     => false, // différence d'octets (`+123`)
    'summary'   => true,  // résumé d'édition
    'diffLink'  => true,  // lien vers le diff
    'links'     => true,  // champ liens (format embed)
    'flags'     => true,  // drapeaux m/b (format embed)
    'footer'    => true,  // pied de page (format embed)
    'timestamp' => true,  // horodatage (format embed)
];
```

#### Icônes d'action (`$wgPASystemActionIcons`)

Surchargez n'importe quel emoji par type d'action. Types disponibles : `edit`, `new`, `upload`, `delete`, `restore`, `move`, `protect`, `unprotect`, `block`, `unblock`, `newuser`, `rights`, `log`.

```php
$wgPASystemActionIcons = [
    'edit' => '✏️',
    'new'  => '✨',
];
```

#### Couleurs d'embed (`$wgPASystemEmbedColors`)

Valeurs RGB décimales par type d'action (format embed) :

```php
$wgPASystemEmbedColors = [
    'delete' => 0xED4245, // la notation hexadécimale fonctionne
];
```

## Personnaliser les textes des annonces

**Chaque phrase** envoyée vers Discord est un message i18n rendu dans la **langue de contenu** du wiki. Vous pouvez réécrire n'importe laquelle — sans toucher au code — en modifiant la page correspondante du namespace `MediaWiki:` sur votre wiki (droit `editinterface` requis).

Par exemple, pour changer l'annonce d'édition sur un wiki francophone, modifiez la page `MediaWiki:Pasystem-line-edit/fr` (ou `MediaWiki:Pasystem-line-edit` si la langue de contenu est l'anglais).

### Messages du format ligne

| Message | Paramètres |
|---|---|
| `pasystem-line-edit` | $1 icône, $2 lien utilisateur, $3 lien page, $4 delta d'octets, $5 résumé, $6 lien diff |
| `pasystem-line-new` | $1 icône, $2 lien utilisateur, $3 lien page, $4 delta d'octets, $5 résumé |
| `pasystem-line-upload` / `-delete` / `-restore` / `-protect` / `-unprotect` | $1 icône, $2 lien utilisateur, $3 lien page, $4 résumé |
| `pasystem-line-move` | $1 icône, $2 lien utilisateur, $3 « ancien → nouveau » (voir `pasystem-line-move-arrow`), $4 résumé |
| `pasystem-line-block` / `-unblock` | $1 icône, $2 lien utilisateur, $3 utilisateur bloqué, $4 résumé |
| `pasystem-line-newuser` | $1 icône, $2 lien utilisateur |
| `pasystem-line-rights` | $1 icône, $2 lien utilisateur, $3 lien utilisateur cible, $4 résumé |
| `pasystem-line-log` | $1 icône, $2 lien utilisateur, $3 libellé du type de log, $4 lien page, $5 résumé |
| `pasystem-line-log-nopage` | $1 icône, $2 lien utilisateur, $3 libellé du type de log, $4 résumé |
| `pasystem-flood-notice` | $1 nom du wiki — envoyé une fois quand `$wgPASystemMaxPerMinute` est franchi |
| `pasystem-line-summary` | habillage du résumé ($1) |
| `pasystem-line-diff` | habillage du lien diff ($1) |

Les paramètres optionnels (icône, delta, résumé, lien diff) peuvent être vides selon vos réglages d'affichage — les doubles espaces résiduels sont nettoyés automatiquement, vous pouvez donc écrire des gabarits naturels.

Exemple — rendre les annonces d'édition plus enthousiastes :

```
Page MediaWiki:Pasystem-line-edit/fr :
$1 $2 vient d'améliorer $3$4$5$6 🎉
```

### Messages du format embed

| Message | Rôle |
|---|---|
| `pasystem-embed-title` | titre de l'embed : $1 icône, $2 utilisateur, $3 verbe (`pasystem-action-*`), $4 page |
| `pasystem-embed-title-newuser` | titre de l'embed pour les créations de compte : $1 icône, $2 utilisateur |
| `pasystem-action-edit`, `-new`, `-upload`, … | verbes d'action utilisés dans le titre de l'embed |
| `pasystem-embed-size`, `-flags`, `-summary`, `-links` | noms des champs |
| `pasystem-embed-footer` | texte du pied de page, $1 = nom du bot |
| `pasystem-bytes-added` / `-removed` / `-neutral` | valeurs du champ taille, $1 = nombre d'octets formaté |
| `pasystem-link-diff`, `-page`, `-history`, `-contribs` | libellés des liens |

Le markdown Discord (`**gras**`, `` `code` ``, emoji) est autorisé dans tous ces messages.

## Limites et confidentialité

- **Les annonces sont permanentes côté Discord.** Si une révision est masquée
  a posteriori sur le wiki (RevisionDelete, oversight — par exemple parce
  qu'elle contenait des données personnelles), le message Discord qui l'a
  annoncée **reste visible** dans le canal. Gardez-le en tête avant de
  pointer le webhook vers un serveur Discord public, et pensez à exclure les
  namespaces sensibles.
- Le plafond anti-flood utilise une fenêtre fixe d'une minute, pas une
  fenêtre glissante : une rafale à cheval sur deux fenêtres peut brièvement
  dépasser le débit configuré.
- En mode `immediate`, il n'y a pas de retry sur les erreurs réseau (seuls
  les rate limits retombent sur la JobQueue). Utilisez le mode `job` si la
  livraison doit être garantie.

## Page spéciale

`Special:PASystemTest` (droit : `pasystem-admin`, accordé aux sysops par défaut) envoie une annonce de test vers le webhook configuré, pour valider l'intégration et le rendu sans modifier le wiki.

## Journalisation et dépannage

L'extension journalise dans le canal `PublicAnnouncementSystem`. Pour le capturer :

```php
$wgDebugLogGroups['PublicAnnouncementSystem'] = '/var/log/mediawiki/pasystem.log';
$wgPASystemDebug = true; // verbeux : changements filtrés, dispatchs, statuts HTTP
```

Problèmes courants :

- **Rien n'arrive dans Discord** — vérifiez `$wgPASystemWebhookUrl`, puis utilisez `Special:PASystemTest`. En mode `'job'`, vérifiez que votre JobQueue est traitée (`php maintenance/run.php runJobs`).
- **Les annonces arrivent en retard** — vous êtes en mode `'job'` avec un runner en cron ; passez en `'immediate'` ou exécutez le JobRunner plus souvent.
- **HTTP 400 dans les logs** — le payload a été refusé par Discord ; l'entrée de log inclut un extrait du payload.

## Développement

```bash
composer install
composer test     # parallel-lint + phpcs (standard MediaWiki) + minus-x
composer fix      # correction automatique du style
```

Les tests PHPUnit s'exécutent depuis un checkout de MediaWiki core :

```bash
# depuis le répertoire de MediaWiki core, avec l'extension dans extensions/
composer phpunit:unit -- extensions/PublicAnnouncementSystem/tests/phpunit/unit
```

## Licence

GPL-2.0-or-later. Voir [COPYING](COPYING).
