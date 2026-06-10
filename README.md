# Public Announcement System

> 🇫🇷 [Documentation en français](README.fr.md)

A MediaWiki extension that broadcasts recent changes to a **Discord channel** as public announcements: edits, page creations, deletions, moves, uploads, protections, blocks, account creations and more.

Built for performance and reliability:

- **Zero impact on page saves** — no network call ever happens inside the hook; delivery is deferred (POSTSEND) or queued (JobQueue).
- **Discord rate-limit aware** — HTTP 429 responses are honored using Discord's `Retry-After`, with automatic re-queueing (capped at 5 attempts).
- **Fully localizable and customizable** — every sentence sent to Discord comes from the i18n system and can be overridden on-wiki; icons, colors and message parts are configurable.

## Requirements

- MediaWiki ≥ 1.43
- PHP ≥ 8.1
- A Discord webhook URL ([Discord docs](https://support.discord.com/hc/en-us/articles/228383668))

## Installation

1. Clone or download this repository into your `extensions/` directory:

   ```bash
   cd extensions
   git clone https://github.com/GenerationFallout/Public-Announcement-System.git PublicAnnouncementSystem
   ```

2. Add to your `LocalSettings.php`:

   ```php
   wfLoadExtension( 'PublicAnnouncementSystem' );
   $wgPASystemWebhookUrl = 'https://discord.com/api/webhooks/…/…';
   ```

3. Visit `Special:Version` to confirm the extension is loaded, then go to `Special:PASystemTest` (sysop only) and broadcast a test announcement.

The extension is **disabled** as long as `$wgPASystemWebhookUrl` is empty.

## Configuration reference

All settings go in `LocalSettings.php`.

### Connection

| Setting | Default | Description |
|---|---|---|
| `$wgPASystemWebhookUrl` | `''` | Discord webhook URL (must be HTTPS). Empty = extension disabled. |
| `$wgPASystemBotName` | `''` | Bot display name in Discord. Empty = uses `$wgSitename`. |
| `$wgPASystemBotAvatarUrl` | `''` | Avatar URL for the bot (optional). |
| `$wgPASystemWikiBaseUrl` | `''` | Base URL used for links. Empty = derived from `$wgServer` + `$wgScriptPath`. |

### Delivery

| Setting | Default | Description |
|---|---|---|
| `$wgPASystemDeliveryMode` | `'immediate'` | `'immediate'`: POSTSEND deferred update, sub-second latency, no retry. `'job'`: JobQueue with automatic retries, latency depends on your JobRunner. |
| `$wgPASystemFormat` | `'line'` | `'line'`: compact one-line message. `'embed'`: rich embed with fields. |

### Filtering

| Setting | Default | Description |
|---|---|---|
| `$wgPASystemNotifyBots` | `false` | Announce edits made by bot accounts. |
| `$wgPASystemNotifyMinor` | `true` | Announce minor edits. |
| `$wgPASystemExcludedNamespaces` | `[]` | Namespace numbers to skip, e.g. `[ 2, 3 ]` for User and User_talk. |
| `$wgPASystemExcludedUsers` | `[]` | User names whose actions are never announced. |
| `$wgPASystemExcludedLogTypes` | `[]` | Log types to skip, e.g. `[ 'patrol', 'thanks' ]`. |
| `$wgPASystemMinDiffSize` | `0` | Minimum byte difference required to announce an edit. `0` = all edits. |
| `$wgPASystemStripAutoSummaries` | `true` | Hide MediaWiki auto-generated summaries (e.g. *Created page with "…"*). Detection uses the core `autosumm-*` messages in your wiki's content language, so it works in any language. Manually typed summaries are always shown. |

### Appearance

| Setting | Default | Description |
|---|---|---|
| `$wgPASystemDisplay` | `{}` | Display toggles, merged over the defaults (see below). |
| `$wgPASystemActionIcons` | `{}` | Emoji per action kind, merged over the defaults (see below). |
| `$wgPASystemEmbedColors` | *(see extension.json)* | Embed color (decimal RGB) per action kind, merged over the defaults. |
| `$wgPASystemDebug` | `false` | Verbose logging in the `PublicAnnouncementSystem` log channel. |

#### Display toggles (`$wgPASystemDisplay`)

Every part of an announcement can be switched off individually. All keys default to `true`; you only need to list the ones you want to disable:

```php
$wgPASystemDisplay = [
    'icons'     => false, // emoji in front of each announcement
    'delta'     => false, // byte difference (`+123`)
    'summary'   => true,  // edit summary
    'diffLink'  => true,  // link to the diff
    'links'     => true,  // links field (embed format)
    'flags'     => true,  // m/b flags (embed format)
    'footer'    => true,  // footer (embed format)
    'timestamp' => true,  // timestamp (embed format)
];
```

#### Action icons (`$wgPASystemActionIcons`)

Override any of the default emoji per action kind. Available kinds: `edit`, `new`, `upload`, `delete`, `restore`, `move`, `protect`, `unprotect`, `block`, `unblock`, `newuser`, `rights`, `log`.

```php
$wgPASystemActionIcons = [
    'edit' => '✏️',
    'new'  => '✨',
];
```

#### Embed colors (`$wgPASystemEmbedColors`)

Decimal RGB values per action kind (used in embed format):

```php
$wgPASystemEmbedColors = [
    'delete' => 0xED4245, // you can use hex notation
];
```

## Customizing the announcement texts

**Every sentence** sent to Discord is an i18n message rendered in your wiki's **content language**. You can rewrite any of them — without touching the code — by editing the corresponding page in the `MediaWiki:` namespace on your wiki (requires the `editinterface` right).

For instance, to change the edit announcement on a French wiki, edit the page `MediaWiki:Pasystem-line-edit/fr` (or `MediaWiki:Pasystem-line-edit` if your content language is English).

### Line format messages

| Message | Parameters |
|---|---|
| `pasystem-line-edit` | $1 icon, $2 user link, $3 page link, $4 byte delta, $5 summary, $6 diff link |
| `pasystem-line-new` | $1 icon, $2 user link, $3 page link, $4 byte delta, $5 summary |
| `pasystem-line-upload` / `-delete` / `-restore` / `-protect` / `-unprotect` | $1 icon, $2 user link, $3 page link, $4 summary |
| `pasystem-line-move` | $1 icon, $2 user link, $3 "old → new" (see `pasystem-line-move-arrow`), $4 summary |
| `pasystem-line-block` / `-unblock` | $1 icon, $2 user link, $3 blocked user, $4 summary |
| `pasystem-line-newuser` | $1 icon, $2 user link |
| `pasystem-line-rights` | $1 icon, $2 user link, $3 target user link, $4 summary |
| `pasystem-line-log` | $1 icon, $2 user link, $3 summary |
| `pasystem-line-summary` | wrapper around the summary ($1) |
| `pasystem-line-diff` | wrapper around the diff link ($1) |

Optional parameters (icon, delta, summary, diff link) may be empty depending on your display settings — leftover double spaces are collapsed automatically, so you can write natural templates.

Example — make edit announcements more enthusiastic:

```
Page MediaWiki:Pasystem-line-edit:
$1 $2 just improved $3$4$5$6 🎉
```

### Embed format messages

| Message | Role |
|---|---|
| `pasystem-embed-title` | embed title: $1 icon, $2 user, $3 verb (`pasystem-action-*`), $4 page |
| `pasystem-embed-title-newuser` | embed title for account creations: $1 icon, $2 user |
| `pasystem-action-edit`, `-new`, `-upload`, … | action verbs used in the embed title |
| `pasystem-embed-size`, `-flags`, `-summary`, `-links` | field names |
| `pasystem-embed-footer` | footer text, $1 = bot name |
| `pasystem-bytes-added` / `-removed` / `-neutral` | size field values, $1 = formatted byte count |
| `pasystem-link-diff`, `-page`, `-history`, `-contribs` | link labels |

Discord markdown (`**bold**`, `` `code` ``, emoji) is allowed in all of these messages.

## Special page

`Special:PASystemTest` (right: `pasystem-admin`, granted to sysops by default) sends a test announcement to the configured webhook, so you can validate the integration and the rendering without editing the wiki.

## Logging & troubleshooting

The extension logs to the `PublicAnnouncementSystem` channel. To capture it:

```php
$wgDebugLogGroups['PublicAnnouncementSystem'] = '/var/log/mediawiki/pasystem.log';
$wgPASystemDebug = true; // verbose: filtered changes, dispatches, HTTP status
```

Common issues:

- **Nothing arrives in Discord** — check `$wgPASystemWebhookUrl`, then use `Special:PASystemTest`. In `'job'` mode, make sure your JobQueue is being processed (`php maintenance/run.php runJobs`).
- **Announcements are delayed** — you are in `'job'` mode with a cron-based runner; switch to `'immediate'` or run the JobRunner more often.
- **HTTP 400 in the logs** — the payload was rejected by Discord; the log entry includes the payload excerpt.

## Development

```bash
composer install
composer test     # parallel-lint + phpcs (MediaWiki standard) + minus-x
composer fix      # auto-fix code style
```

PHPUnit tests run inside a MediaWiki core checkout:

```bash
# from the MediaWiki core directory, with the extension in extensions/
composer phpunit:unit -- extensions/PublicAnnouncementSystem/tests/phpunit/unit
```

## License

GPL-2.0-or-later. See [COPYING](COPYING).
