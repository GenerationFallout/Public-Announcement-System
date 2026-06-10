<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PublicAnnouncementSystem\Formatter;

use MediaWiki\Config\Config;
use MediaWiki\Language\Language;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\TitleFactory;
use Psr\Log\LoggerInterface;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageValue;

/**
 * Builds the Discord payload (line or embed) from the serialized data of a
 * RecentChange.
 *
 * The input is an array of parameters (not a RecentChange object) because the
 * class is called from a Job, and RecentChange objects do not serialize well
 * in job queue backends.
 *
 * All human-readable text comes from i18n messages rendered in the wiki's
 * content language, so announcements follow the wiki language and every
 * sentence can be customized on-wiki by editing the corresponding
 * MediaWiki:Pasystem-* page. Icons, colors and optional parts of the
 * announcements are configurable through $wgPASystemActionIcons,
 * $wgPASystemEmbedColors and $wgPASystemDisplay.
 */
class DiscordEmbedFormatter {

	/**
	 * Discord SUPPRESS_EMBEDS flag (1 << 2).
	 *
	 * When included in the payload, Discord does not auto-generate preview
	 * cards for URLs present in `content`. Without it, a message like
	 * "Alice edited Foo" generates 2-3 useless preview cards below the
	 * message, polluting the channel.
	 *
	 * Note: this flag does NOT affect embeds explicitly sent in the
	 * `embeds` array of the payload — only the ones auto-generated from
	 * `content`.
	 */
	private const FLAG_SUPPRESS_EMBEDS = 4;

	/** Default emoji per action kind, overridable via $wgPASystemActionIcons */
	private const DEFAULT_ICONS = [
		'edit'      => '📝',
		'new'       => '🆕',
		'upload'    => '📤',
		'delete'    => '🗑️',
		'restore'   => '♻️',
		'move'      => '📨',
		'protect'   => '🔒',
		'unprotect' => '🔓',
		'block'     => '🚫',
		'unblock'   => '✅',
		'newuser'   => '👤',
		'rights'    => '🛡️',
		'log'       => '📋',
	];

	/** Default display toggles, overridable via $wgPASystemDisplay */
	private const DEFAULT_DISPLAY = [
		'icons'     => true,
		'delta'     => true,
		'summary'   => true,
		'diffLink'  => true,
		'links'     => true,
		'flags'     => true,
		'footer'    => true,
		'timestamp' => true,
	];

	/** Core messages used to detect auto-generated edit summaries */
	private const AUTOSUMM_MESSAGES = [
		'autosumm-new',
		'autosumm-newblank',
		'autosumm-blank',
		'autosumm-replace',
		'autosumm-removed-redirect',
		'autosumm-changed-redirect-target',
	];

	private Config $config;
	private TitleFactory $titleFactory;
	private Language $contentLang;
	private ITextFormatter $msgFormatter;
	private LoggerInterface $logger;

	/** @var string[]|null Lazy-built list of auto-summary prefixes */
	private ?array $autoSummaryPrefixes = null;

	/** @var array|null Lazy-merged display toggles */
	private ?array $displayOptions = null;

	public function __construct(
		Config $config,
		TitleFactory $titleFactory,
		Language $contentLang,
		ITextFormatter $msgFormatter,
		LoggerInterface $logger
	) {
		$this->config = $config;
		$this->titleFactory = $titleFactory;
		$this->contentLang = $contentLang;
		$this->msgFormatter = $msgFormatter;
		$this->logger = $logger;
	}

	/**
	 * Builds the full Discord payload from the Job params.
	 *
	 * Depending on `$wgPASystemFormat`:
	 *   - 'line'  : compact one-line text message, markdown — default
	 *   - 'embed' : rich detailed embed
	 *
	 * @param array $params RecentChange data (see RecentChangeHooks)
	 * @return array Payload ready for json_encode
	 */
	public function build( array $params ): array {
		// Flood notice: single informational message replacing the
		// announcement that crossed the per-minute cap (see FloodGuard).
		if ( !empty( $params['_flood_notice'] ) ) {
			$payload = [
				'username'         => $this->getBotName(),
				'content'          => $this->msg(
					'pasystem-flood-notice',
					(string)$this->config->get( 'Sitename' )
				),
				'allowed_mentions' => [ 'parse' => [] ],
			];
			$payload['flags'] = self::FLAG_SUPPRESS_EMBEDS;
			return $payload;
		}

		$format = (string)$this->config->get( 'PASystemFormat' );

		if ( $format === 'embed' ) {
			$payload = $this->buildEmbedPayload( $params );
		} else {
			$payload = $this->buildLinePayload( $params );
		}

		// No auto-previews: we do not want Discord to generate cards for
		// the wiki links present in the content.
		$payload['flags'] = self::FLAG_SUPPRESS_EMBEDS;

		$avatar = $this->config->get( 'PASystemBotAvatarUrl' );
		if ( $avatar ) {
			$payload['avatar_url'] = $avatar;
		}

		return $payload;
	}

	/**
	 * Renders an i18n message in the wiki content language.
	 *
	 * @param string $key Message key
	 * @param string ...$msgParams Plaintext parameters
	 */
	private function msg( string $key, string ...$msgParams ): string {
		return $this->msgFormatter->format(
			MessageValue::new( $key )->plaintextParams( ...$msgParams )
		);
	}

	/**
	 * Reads a display toggle from $wgPASystemDisplay, merged over defaults.
	 */
	private function display( string $key ): bool {
		if ( $this->displayOptions === null ) {
			$configured = $this->config->get( 'PASystemDisplay' );
			$this->displayOptions = ( is_array( $configured ) ? $configured : [] )
				+ self::DEFAULT_DISPLAY;
		}
		return (bool)( $this->displayOptions[ $key ] ?? true );
	}

	/**
	 * Bot display name: $wgPASystemBotName, falling back to $wgSitename.
	 */
	private function getBotName(): string {
		$name = (string)$this->config->get( 'PASystemBotName' );
		return $name !== '' ? $name : (string)$this->config->get( 'Sitename' );
	}

	/* ============================================================
	 * LINE format (default) — compact one-line text message
	 * ============================================================ */

	private function buildLinePayload( array $params ): array {
		$content = $this->buildLineContent( $params );
		// Discord rejects messages > 2000 chars in content. Truncate to 1990
		// to keep a margin (and add an ellipsis if needed).
		if ( mb_strlen( $content ) > 1990 ) {
			$content = mb_substr( $content, 0, 1989 ) . '…';
		}
		return [
			'username'         => $this->getBotName(),
			'content'          => $content,
			'allowed_mentions' => [ 'parse' => [] ],
		];
	}

	/**
	 * Public action kind resolver, used by callers to route the payload
	 * to the right webhook ($wgPASystemWebhookRoutes). Defensive about
	 * missing keys so it can be called with partial params.
	 *
	 * @param array $params RecentChange data (see RecentChangeHooks)
	 * @return string Action kind ('edit', 'delete', …), or 'flood' for
	 *   flood notices
	 */
	public function getActionKind( array $params ): string {
		if ( !empty( $params['_flood_notice'] ) ) {
			return 'flood';
		}
		return $this->resolveActionKind(
			(int)( $params['rc_type'] ?? RC_EDIT ),
			(string)( $params['rc_log_type'] ?? '' ),
			(string)( $params['rc_log_action'] ?? '' )
		);
	}

	private function buildLineContent( array $params ): string {
		$kind = $this->getActionKindFromParams( $params );
		$icon = $this->getActionIcon( $kind );
		$userText = (string)$params['rc_user_text'];
		$userLink = $this->makeMdLink( $userText, $this->getUserUrl( $userText ) );
		$summary = $this->summarySuffix( $params );
		$pageLink = $this->makeMdLink(
			$this->getPrefixedTitle( $params ),
			$this->getPageUrl( $params )
		);

		switch ( $kind ) {
			case 'edit':
				$line = $this->lineForEdit( $params, $icon, $userLink, true );
				break;

			case 'new':
				$line = $this->lineForEdit( $params, $icon, $userLink, false );
				break;

			case 'move':
				$line = $this->lineForMove( $params, $icon, $userLink );
				break;

			case 'block':
				$line = $this->msg( 'pasystem-line-block',
					$icon, $userLink, '`' . (string)$params['rc_title'] . '`', $summary );
				break;

			case 'unblock':
				$line = $this->msg( 'pasystem-line-unblock',
					$icon, $userLink, '`' . (string)$params['rc_title'] . '`', $summary );
				break;

			case 'newuser':
				// The created user IS the user signing up (rc_user_text)
				$line = $this->msg( 'pasystem-line-newuser', $icon, $userLink );
				break;

			case 'rights':
				$line = $this->msg( 'pasystem-line-rights',
					$icon,
					$userLink,
					$this->makeMdLink(
						(string)$params['rc_title'],
						$this->getUserUrl( (string)$params['rc_title'] )
					),
					$summary
				);
				break;

			case 'upload':
			case 'delete':
			case 'restore':
			case 'protect':
			case 'unprotect':
				$line = $this->msg( "pasystem-line-$kind",
					$icon, $userLink, $pageLink, $summary );
				break;

			default:
				$line = $this->lineForGenericLog( $params, $icon, $userLink, $pageLink, $summary );
				break;
		}

		return $this->cleanupLine( $line );
	}

	/**
	 * Generic line for log actions without a dedicated template (patrol,
	 * import, merge, actions added by other extensions…). Shows the log
	 * type and, when the entry targets a page, a link to it.
	 */
	private function lineForGenericLog(
		array $params,
		string $icon,
		string $userLink,
		string $pageLink,
		string $summary
	): string {
		$logType = (string)$params['rc_log_type'];
		$logAction = (string)$params['rc_log_action'];
		$name = trim( $logType . ( $logAction !== '' ? '/' . $logAction : '' ), '/ ' );
		$label = '`' . ( $name !== '' ? $name : 'log' ) . '`';

		if ( (string)$params['rc_title'] !== '' ) {
			return $this->msg( 'pasystem-line-log', $icon, $userLink, $label, $pageLink, $summary );
		}
		return $this->msg( 'pasystem-line-log-nopage', $icon, $userLink, $label, $summary );
	}

	/**
	 * Builds the line for a normal edit or a new page.
	 * Default rendering: ICON [User](url) edited [Page](url) `±X` — Summary ([diff](url))
	 */
	private function lineForEdit( array $params, string $icon, string $userLink, bool $withDiff ): string {
		$pageLink = $this->makeMdLink(
			$this->getPrefixedTitle( $params ),
			$this->getPageUrl( $params )
		);

		$deltaStr = '';
		if ( $this->display( 'delta' ) ) {
			$delta = (int)$params['rc_new_len'] - (int)$params['rc_old_len'];
			$deltaStr = ' ' . $this->formatDeltaCompact( $delta );
		}

		$summary = $this->summarySuffix( $params );

		if ( !$withDiff ) {
			return $this->msg( 'pasystem-line-new',
				$icon, $userLink, $pageLink, $deltaStr, $summary );
		}

		$diffSuffix = '';
		if ( $this->display( 'diffLink' )
			&& (int)$params['rc_this_oldid'] > 0
			&& (int)$params['rc_last_oldid'] > 0
		) {
			$diffSuffix = ' ' . $this->msg( 'pasystem-line-diff',
				$this->makeMdLink( $this->msg( 'pasystem-link-diff' ), $this->getDiffUrl( $params ) )
			);
		}

		return $this->msg( 'pasystem-line-edit',
			$icon, $userLink, $pageLink, $deltaStr, $summary, $diffSuffix );
	}

	/**
	 * For page moves, the new title lives in rc_params (PHP/JSON serialized).
	 * We try to extract it, otherwise fall back to a degraded format.
	 */
	private function lineForMove( array $params, string $icon, string $userLink ): string {
		$oldTitle = $this->getPrefixedTitle( $params );
		$newTitle = $this->extractMoveTarget( (string)$params['rc_params'] );

		if ( $newTitle ) {
			$arrow = $this->msg( 'pasystem-line-move-arrow',
				$this->makeMdLink( $oldTitle, $this->getPageUrl( $params ) ),
				$this->makeMdLink( $newTitle, $this->getPageUrlFromText( $newTitle ) )
			);
		} else {
			$arrow = $this->makeMdLink( $oldTitle, $this->getPageUrl( $params ) );
		}

		return $this->msg( 'pasystem-line-move',
			$icon, $userLink, $arrow, $this->summarySuffix( $params ) );
	}

	private function extractMoveTarget( string $rcParams ): ?string {
		if ( $rcParams === '' ) {
			return null;
		}
		// rc_params may be PHP-serialized or JSON depending on the MW version.
		// allowed_classes => false: protection against POP chains, we only
		// expect arrays/scalars anyway.
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		$decoded = @unserialize( $rcParams, [ 'allowed_classes' => false ] );
		if ( !is_array( $decoded ) ) {
			$decoded = json_decode( $rcParams, true );
		}
		if ( !is_array( $decoded ) ) {
			return null;
		}
		// Several possible keys depending on the version: 'target', '4::target', 'new_title'
		foreach ( [ 'target', '4::target', 'new_title', 'newTitle' ] as $k ) {
			if ( isset( $decoded[ $k ] ) && is_string( $decoded[ $k ] ) ) {
				return $decoded[ $k ];
			}
		}
		return null;
	}

	private function formatDeltaCompact( int $delta ): string {
		$formatted = $this->contentLang->formatNum( abs( $delta ) );
		if ( $delta > 0 ) {
			return '`+' . $formatted . '`';
		}
		if ( $delta < 0 ) {
			return '`−' . $formatted . '`';
		}
		return '`±' . $formatted . '`';
	}

	/**
	 * " — Truncated summary" suffix (with leading space). Empty when:
	 *   - the 'summary' display toggle is off, or
	 *   - there is no summary, or
	 *   - the summary was auto-generated by MediaWiki AND StripAutoSummaries
	 *     is enabled
	 *
	 * Auto-generated summaries are the ones MW pre-fills when the user typed
	 * nothing (typically "Created page with '<content excerpt>'"). We filter
	 * them out to only show real human comments.
	 *
	 * Truncated to ~80 chars to stay on one line in Discord.
	 */
	private function summarySuffix( array $params ): string {
		if ( !$this->display( 'summary' ) ) {
			return '';
		}

		$comment = trim( (string)$params['rc_comment'] );
		if ( $comment === '' ) {
			return '';
		}

		if ( (bool)$this->config->get( 'PASystemStripAutoSummaries' )
			&& $this->isAutoGeneratedSummary( $comment )
		) {
			return '';
		}

		$comment = $this->truncate( $comment, 80 );
		// Escape the summary's markdown so a summary containing * or _
		// does not break the rendering.
		$comment = $this->escapeMarkdown( $comment );
		return ' ' . $this->msg( 'pasystem-line-summary', $comment );
	}

	/**
	 * Detects summaries auto-generated by MediaWiki.
	 *
	 * Instead of hardcoding language-specific strings, we derive the
	 * detection prefixes from the core autosumm-* messages in the wiki's
	 * content language, so this works on any wiki regardless of language.
	 *
	 * Also handles the optional section arrows ("→", "←") MW may prepend.
	 */
	private function isAutoGeneratedSummary( string $comment ): bool {
		$normalized = ltrim( $comment, "←→ \t" );
		foreach ( $this->getAutoSummaryPrefixes() as $prefix ) {
			if ( str_starts_with( $normalized, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Builds the list of auto-summary prefixes from the core autosumm-*
	 * messages. Each message is rendered with a sentinel as parameter; the
	 * text before the sentinel is the static prefix to match against.
	 * Parameter-less messages (e.g. autosumm-blank) yield their full text.
	 *
	 * @return string[]
	 */
	private function getAutoSummaryPrefixes(): array {
		if ( $this->autoSummaryPrefixes !== null ) {
			return $this->autoSummaryPrefixes;
		}

		$sentinel = "\u{F0000}";
		$prefixes = [];
		foreach ( self::AUTOSUMM_MESSAGES as $key ) {
			$text = $this->msg( $key, $sentinel, $sentinel );
			$pos = strpos( $text, $sentinel );
			$prefix = $pos === false ? $text : substr( $text, 0, $pos );
			$prefix = trim( $prefix );
			if ( $prefix !== '' ) {
				$prefixes[] = $prefix;
			}
		}

		$this->autoSummaryPrefixes = $prefixes;
		return $prefixes;
	}

	private function makeMdLink( string $text, string $url ): string {
		// Escape the chars that break the text part of a Discord markdown link
		$safeText = str_replace( [ '[', ']', '(', ')' ], [ '⟦', '⟧', '⟨', '⟩' ], $text );
		// Also escape emphasis chars so a title/user name containing * or _
		// cannot style the rest of the message.
		$safeText = $this->escapeMarkdown( $safeText );
		// Wrap the URL in < >: required for URLs containing parentheses
		// (e.g. "Vulpes Inculta (Fallout: New Vegas)"), otherwise Discord
		// markdown breaks the link at the first ')'. Bonus: also disables
		// the auto-preview for this specific link.
		return '[' . $safeText . '](<' . $url . '>)';
	}

	private function escapeMarkdown( string $text ): string {
		// Escape * _ ` ~ > (quote) and | without hurting readability.
		// [ and ] are escaped too: Discord renders masked links
		// ("[text](url)") in webhook content and embed fields, so an edit
		// summary like "[click here](https://evil.example)" would otherwise
		// smuggle an arbitrary clickable link into the channel.
		return preg_replace( '/([\\\\*_`~>|\[\]])/', '\\\\$1', $text );
	}

	/**
	 * Final cleanup of a formatted line: collapses double spaces left by
	 * empty optional parameters (icon, delta, summary…) and trims the ends.
	 */
	private function cleanupLine( string $line ): string {
		return trim( preg_replace( '/\h{2,}/u', ' ', $line ) );
	}

	private function getUserUrl( string $userText ): string {
		$title = $this->titleFactory->makeTitle( NS_USER, $userText );
		return $title->getFullURL();
	}

	private function getPageUrl( array $params ): string {
		$title = $this->titleFactory->makeTitle(
			(int)$params['rc_namespace'],
			(string)$params['rc_title']
		);
		return $title->getFullURL();
	}

	/**
	 * URL of a diff between two revisions of the current page.
	 * Uses Title::getFullURL with a query so the URL follows the wiki's
	 * configuration ($wgArticlePath etc.).
	 */
	private function getDiffUrl( array $params ): string {
		$title = $this->titleFactory->makeTitle(
			(int)$params['rc_namespace'],
			(string)$params['rc_title']
		);
		return $title->getFullURL( [
			'diff'  => (int)$params['rc_this_oldid'],
			'oldid' => (int)$params['rc_last_oldid'],
		] );
	}

	/**
	 * URL of a page named by its text form (typically the target of a page
	 * move, recovered from rc_params).
	 */
	private function getPageUrlFromText( string $prefixedText ): string {
		$title = $this->titleFactory->newFromText( $prefixedText );
		if ( $title === null ) {
			// Fallback: approximate URL if the title is invalid
			return $this->getWikiBaseUrl()
				. '/index.php?title=' . rawurlencode( str_replace( ' ', '_', $prefixedText ) );
		}
		return $title->getFullURL();
	}

	/* ============================================================
	 * EMBED format — rich version
	 * ============================================================ */

	private function buildEmbedPayload( array $params ): array {
		$embed = $this->buildEmbed( $params );
		return [
			'username'         => $this->getBotName(),
			'embeds'           => [ $embed ],
			'allowed_mentions' => [ 'parse' => [] ],
		];
	}

	private function buildEmbed( array $params ): array {
		$rcType = (int)$params['rc_type'];
		$actionKind = $this->getActionKindFromParams( $params );

		$embed = [
			'title'  => $this->buildTitle( $params, $actionKind ),
			'color'  => $this->resolveColor( $actionKind ),
			'author' => $this->buildAuthor( $params ),
		];

		if ( $this->display( 'timestamp' ) ) {
			$embed['timestamp'] = $this->formatTimestamp( (string)$params['rc_timestamp'] );
		}

		$url = $this->buildPrimaryUrl( $params, $rcType );
		if ( $url ) {
			$embed['url'] = $url;
		}

		// Fields: size, flags, summary, links
		$fields = [];

		if ( $rcType === RC_EDIT || $rcType === RC_NEW ) {
			if ( $this->display( 'delta' ) ) {
				$delta = (int)$params['rc_new_len'] - (int)$params['rc_old_len'];
				$fields[] = [
					'name'   => $this->msg( 'pasystem-embed-size' ),
					'value'  => $this->formatDelta( $delta ),
					'inline' => true,
				];
			}

			if ( $this->display( 'flags' ) ) {
				$flags = $this->buildFlags( $params );
				if ( $flags !== '' ) {
					$fields[] = [
						'name'   => $this->msg( 'pasystem-embed-flags' ),
						'value'  => $flags,
						'inline' => true,
					];
				}
			}
		}

		if ( $this->display( 'summary' ) ) {
			$comment = trim( (string)$params['rc_comment'] );
			if ( $comment !== ''
				&& !( (bool)$this->config->get( 'PASystemStripAutoSummaries' )
					&& $this->isAutoGeneratedSummary( $comment ) )
			) {
				// Escaped for the same reason as the line format: embed
				// field values render masked links and markdown.
				$fields[] = [
					'name'   => $this->msg( 'pasystem-embed-summary' ),
					'value'  => $this->escapeMarkdown( $this->truncate( $comment, 1024 ) ),
					'inline' => false,
				];
			}
		}

		if ( $this->display( 'links' ) ) {
			$links = $this->buildLinks( $params, $rcType );
			if ( $links !== '' ) {
				$fields[] = [
					'name'   => $this->msg( 'pasystem-embed-links' ),
					'value'  => $links,
					'inline' => false,
				];
			}
		}

		if ( $fields ) {
			$embed['fields'] = $fields;
		}

		if ( $this->display( 'footer' ) ) {
			$embed['footer'] = [
				'text' => $this->msg( 'pasystem-embed-footer', $this->getBotName() ),
			];
		}

		return $embed;
	}

	/**
	 * Determines the action "kind" — used for the color, the icon and the
	 * action verb.
	 */
	private function resolveActionKind( int $rcType, string $logType, string $logAction ): string {
		if ( $rcType === RC_LOG ) {
			switch ( $logType ) {
				case 'delete':
					return $logAction === 'restore' ? 'restore' : 'delete';
				case 'move':
					return 'move';
				case 'protect':
					return $logAction === 'unprotect' ? 'unprotect' : 'protect';
				case 'block':
					return $logAction === 'unblock' ? 'unblock' : 'block';
				case 'newusers':
					return 'newuser';
				case 'upload':
					return 'upload';
				case 'rights':
					return 'rights';
				default:
					return 'log';
			}
		}

		if ( $rcType === RC_NEW ) {
			return 'new';
		}

		return 'edit';
	}

	private function resolveColor( string $kind ): int {
		$configured = $this->config->get( 'PASystemEmbedColors' );
		$colors = is_array( $configured ) ? $configured : [];
		// Map a few aliases
		$key = match ( $kind ) {
			'restore', 'unprotect', 'unblock' => 'edit',
			'rights'                          => 'log',
			default                           => $kind,
		};
		return (int)( $colors[ $key ] ?? $colors['edit'] ?? 3447003 );
	}

	private function buildTitle( array $params, string $actionKind ): string {
		$userText = (string)$params['rc_user_text'];
		$icon = $this->getActionIcon( $actionKind );

		// Special case: account creation → the "page title" is actually the user name
		if ( $actionKind === 'newuser' ) {
			return $this->cleanupLine(
				$this->msg( 'pasystem-embed-title-newuser', $icon, $userText )
			);
		}

		return $this->cleanupLine( $this->msg( 'pasystem-embed-title',
			$icon,
			$userText,
			$this->msg( 'pasystem-action-' . $actionKind ),
			$this->getPrefixedTitle( $params )
		) );
	}

	/**
	 * Icon for an action kind: $wgPASystemActionIcons merged over the
	 * defaults. Empty string when the 'icons' display toggle is off.
	 */
	private function getActionIcon( string $kind ): string {
		if ( !$this->display( 'icons' ) ) {
			return '';
		}
		$configured = $this->config->get( 'PASystemActionIcons' );
		$icons = ( is_array( $configured ) ? $configured : [] ) + self::DEFAULT_ICONS;
		return (string)( $icons[ $kind ] ?? $icons['log'] ?? '' );
	}

	private function buildAuthor( array $params ): array {
		$userText = (string)$params['rc_user_text'];
		return [
			'name' => $userText,
			'url'  => $this->getUserUrl( $userText ),
		];
	}

	private function buildPrimaryUrl( array $params, int $rcType ): ?string {
		// For edits with a parent revision: point to the diff
		if ( $rcType === RC_EDIT && (int)$params['rc_this_oldid'] > 0 && (int)$params['rc_last_oldid'] > 0 ) {
			return $this->getDiffUrl( $params );
		}
		// Otherwise: point to the page
		return $this->getPageUrl( $params );
	}

	private function buildLinks( array $params, int $rcType ): string {
		$userText = (string)$params['rc_user_text'];
		$pageTitle = $this->titleFactory->makeTitle(
			(int)$params['rc_namespace'],
			(string)$params['rc_title']
		);

		$links = [];

		// Diff (only for edits with a parent revision)
		if ( $this->display( 'diffLink' )
			&& $rcType === RC_EDIT
			&& (int)$params['rc_this_oldid'] > 0
			&& (int)$params['rc_last_oldid'] > 0
		) {
			$links[] = $this->makeMdLink( $this->msg( 'pasystem-link-diff' ), $this->getDiffUrl( $params ) );
		}

		// Page (except for account creations where the title = the user)
		if ( $rcType !== RC_LOG || $this->getActionKindFromParams( $params ) !== 'newuser' ) {
			$links[] = $this->makeMdLink( $this->msg( 'pasystem-link-page' ), $pageTitle->getFullURL() );
		}

		// History (for content pages only)
		if ( $rcType === RC_EDIT || $rcType === RC_NEW ) {
			$links[] = $this->makeMdLink(
				$this->msg( 'pasystem-link-history' ),
				$pageTitle->getFullURL( [ 'action' => 'history' ] )
			);
		}

		// User contributions (always relevant).
		// SpecialPage::getTitleFor gives the localized special page name.
		$contribsTitle = SpecialPage::getTitleFor( 'Contributions', $userText );
		$links[] = $this->makeMdLink( $this->msg( 'pasystem-link-contribs' ), $contribsTitle->getFullURL() );

		return implode( ' • ', $links );
	}

	private function buildFlags( array $params ): string {
		$flags = [];
		if ( (int)$params['rc_minor'] === 1 ) {
			$flags[] = '`' . $this->msg( 'pasystem-flag-minor' ) . '`';
		}
		if ( (int)$params['rc_bot'] === 1 ) {
			$flags[] = '`' . $this->msg( 'pasystem-flag-bot' ) . '`';
		}
		// Newness is already visible in the "created" verb, no redundant N flag.
		return implode( ' ', $flags );
	}

	private function formatDelta( int $delta ): string {
		$formatted = $this->contentLang->formatNum( abs( $delta ) );
		if ( $delta > 0 ) {
			return $this->msg( 'pasystem-bytes-added', $formatted );
		}
		if ( $delta < 0 ) {
			return $this->msg( 'pasystem-bytes-removed', $formatted );
		}
		return $this->msg( 'pasystem-bytes-neutral', $formatted );
	}

	private function getPrefixedTitle( array $params ): string {
		$ns = (int)$params['rc_namespace'];
		$title = (string)$params['rc_title'];
		// Special case for logs: the namespace may be -1, keep the title as-is
		if ( $ns === -1 ) {
			return $title;
		}

		try {
			$titleObj = $this->titleFactory->makeTitle( $ns, $title );
			return $titleObj->getPrefixedText();
		} catch ( \Throwable $e ) {
			$this->logger->warning( 'Cannot resolve title', [
				'ns'    => $ns,
				'title' => $title,
				'error' => $e->getMessage(),
			] );
			return $title;
		}
	}

	private function getActionKindFromParams( array $params ): string {
		return $this->resolveActionKind(
			(int)$params['rc_type'],
			(string)$params['rc_log_type'],
			(string)$params['rc_log_action']
		);
	}

	private function formatTimestamp( string $mwTimestamp ): string {
		// MW format: YYYYMMDDHHMMSS → ISO 8601 for Discord
		if ( strlen( $mwTimestamp ) === 14 && ctype_digit( $mwTimestamp ) ) {
			$dt = \DateTimeImmutable::createFromFormat(
				'YmdHis',
				$mwTimestamp,
				new \DateTimeZone( 'UTC' )
			);
			if ( $dt instanceof \DateTimeImmutable ) {
				return $dt->format( 'c' );
			}
		}
		return gmdate( 'c' );
	}

	private function truncate( string $text, int $maxLen ): string {
		if ( mb_strlen( $text ) <= $maxLen ) {
			return $text;
		}
		return mb_substr( $text, 0, $maxLen - 1 ) . '…';
	}

	private function getWikiBaseUrl(): string {
		$configured = $this->config->get( 'PASystemWikiBaseUrl' );
		if ( $configured ) {
			return rtrim( $configured, '/' );
		}
		// Fall back to $wgServer + the standard path
		$server = $this->config->get( 'Server' );
		$scriptPath = $this->config->get( 'ScriptPath' );
		return rtrim( $server . $scriptPath, '/' );
	}
}
