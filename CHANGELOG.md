# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-06-10

The "shareable" release: the extension no longer contains anything specific to
a single wiki and can be installed on any MediaWiki ≥ 1.43, in any language.

### Changed (breaking)

- **All announcement texts now come from the i18n system** and are rendered in
  the wiki's content language. Previously, every sentence sent to Discord was
  hardcoded in French. Wikis whose content language is not French will now see
  announcements in their own language (English and French shipped; other
  languages fall back to English until translated).
- **`$wgPASystemBotName` now defaults to empty**, which falls back to
  `$wgSitename`. The previous default was `Marcus`. Set
  `$wgPASystemBotName = 'Marcus';` in `LocalSettings.php` to keep the old name.
- Auto-generated summary detection (`$wgPASystemStripAutoSummaries`) is now
  based on the core `autosumm-*` messages in the wiki's content language
  instead of hardcoded French/English strings. It now also applies to
  auto-summaries on edits (blanking, content replacement, redirect changes),
  not only page creations.

### Added

- **On-wiki text customization**: every announcement sentence is a
  `pasystem-*` message that can be overridden by editing the corresponding
  `MediaWiki:` page on the wiki. Full sentence templates (with positional
  parameters) are used, so word order can be adapted freely per language.
- **`$wgPASystemDisplay`** — per-element display toggles: `icons`, `delta`,
  `summary`, `diffLink`, `links`, `flags`, `footer`, `timestamp`.
- **`$wgPASystemActionIcons`** — configurable emoji per action kind, merged
  over the defaults.
- Special page alias file (`Special:PASystemTest` is now translatable;
  French alias: `Spécial:Test_des_annonces_publiques`).
- Byte counts are now formatted with the wiki content language conventions
  (`Language::formatNum`).
- Documentation: `README.md` (English), `README.fr.md` (French), this
  changelog, and the full GPL-2.0 text in `COPYING`.
- PHPUnit unit test suite (filter, formatter, notifier) runnable against a
  MediaWiki core checkout.
- Development tooling: `composer test` (parallel-lint, MediaWiki phpcs,
  minus-x) and a GitHub Actions CI workflow (lint + phpcs + unit tests
  against MediaWiki REL1_43).

### Security

- **Masked-link injection fixed**: Discord renders markdown links
  (`[text](url)`) in webhook content and embed fields, so an edit summary
  like `[click here](https://evil.example)` would have displayed an
  arbitrary clickable link in the channel. Square brackets are now escaped
  in summaries (both formats — the embed summary field was previously not
  escaped at all), and link labels are markdown-escaped as well.
- The webhook URL must now use HTTPS (webhook URLs embed a secret token
  that must never travel in clear text).

### Fixed

- Rate-limited notifications are no longer lost on JobQueue backends
  without delayed-job support (e.g. the default DB queue): the retry job
  is requeued without a delay instead of throwing.
- **Embed format**: links in the links field were built with raw markdown
  `[label](url)`, so page titles containing parentheses (e.g.
  `Vulpes Inculta (Fallout: New Vegas)`) produced broken links in Discord.
  They now use the same escaped `[label](<url>)` form as the line format.
- The embed "primary URL" diff detection now uses the `RC_EDIT` constant
  instead of a magic `0`.
- In immediate delivery mode, the rate-limit fallback job is now queued with
  the actual page title instead of the main page.
- `Special:PASystemTest` no longer requires an unused `HttpRequestFactory`
  service.

### Internal

- English code comments and `extension.json` descriptions (was French).
- Namespaced MediaWiki 1.43 imports (`MediaWiki\Config\Config`,
  `MediaWiki\SpecialPage\SpecialPage`, `MediaWiki\HTMLForm\HTMLForm`,
  `MediaWiki\Language\Language`, `MediaWiki\Deferred\DeferredUpdates`)
  instead of deprecated global aliases.
- `DiscordEmbedFormatter` now receives an `ITextFormatter` (content language)
  through service wiring.

## [1.0.0] - 2026-05

### Added

- Initial release.
- `RecentChange_save` hook capturing edits, page creations, uploads,
  deletions, restores, moves, protections, blocks, user rights changes and
  account creations.
- Configurable filtering: bots, minor edits, namespaces, users, log types,
  minimum diff size.
- Two delivery modes: `immediate` (POSTSEND deferred update) and `job`
  (JobQueue with automatic retries).
- Discord rate-limit handling (HTTP 429 → requeue with `Retry-After`, capped
  at 5 attempts; 5xx → JobQueue retry).
- Two message formats: compact `line` and rich `embed`.
- `Special:PASystemTest` admin page to send a test announcement.
- Anti-mention (`allowed_mentions`), auto-preview suppression
  (`SUPPRESS_EMBEDS`), markdown escaping, 2000-character truncation.
