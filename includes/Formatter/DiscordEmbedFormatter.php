<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PublicAnnouncementSystem\Formatter;

use Config;
use Language;
use MediaWiki\Title\TitleFactory;
use Psr\Log\LoggerInterface;

/**
 * Construit le payload Discord (embed) à partir des données sérialisées
 * d'un RecentChange.
 *
 * On reçoit un array de paramètres (pas un RecentChange) car la classe est
 * appelée depuis un Job, et MediaWiki ne sérialise pas bien les RecentChange
 * en BDD de jobs.
 */
class DiscordEmbedFormatter {

	private Config $config;
	private TitleFactory $titleFactory;
	private Language $contentLang;
	private LoggerInterface $logger;

	public function __construct(
		Config $config,
		TitleFactory $titleFactory,
		Language $contentLang,
		LoggerInterface $logger
	) {
		$this->config = $config;
		$this->titleFactory = $titleFactory;
		$this->contentLang = $contentLang;
		$this->logger = $logger;
	}

	/**
	 * Flag Discord SUPPRESS_EMBEDS (1 << 2).
	 *
	 * Quand on l'inclut dans le payload, Discord ne génère pas d'aperçus
	 * automatiques pour les URLs présentes dans `content`. Sans ce flag,
	 * un message comme "Kims a modifié Canigou" génère 2-3 cartes d'aperçu
	 * inutiles sous le message, qui polluent le canal.
	 *
	 * Note : ce flag n'affecte PAS les embeds explicitement envoyés dans le
	 * tableau `embeds` du payload — seulement ceux générés automatiquement
	 * à partir du `content`.
	 */
	private const FLAG_SUPPRESS_EMBEDS = 4;

	/**
	 * Construit le payload Discord complet à partir des params du Job.
	 *
	 * Selon `$wgPASystemFormat` :
	 *   - 'line'  : message texte compact (1 ligne), markdown — défaut
	 *   - 'embed' : embed riche détaillé
	 *
	 * @param array $params Données du RecentChange (cf. RecentChangeHooks)
	 * @return array Payload prêt pour json_encode
	 */
	public function build( array $params ): array {
		$format = (string)$this->config->get( 'PASystemFormat' );

		if ( $format === 'embed' ) {
			$payload = $this->buildEmbedPayload( $params );
		} else {
			$payload = $this->buildLinePayload( $params );
		}

		// Anti-aperçus : on ne veut pas que Discord génère des cartes
		// automatiques pour les liens wiki présents dans le content.
		$payload['flags'] = self::FLAG_SUPPRESS_EMBEDS;

		$avatar = $this->config->get( 'PASystemBotAvatarUrl' );
		if ( $avatar ) {
			$payload['avatar_url'] = $avatar;
		}

		return $payload;
	}

	/* ============================================================
	 * Format LIGNE (défaut) — message texte compact sur une ligne
	 * ============================================================ */

	private function buildLinePayload( array $params ): array {
		$content = $this->buildLineContent( $params );
		// Discord rejette les messages > 2000 chars dans content. On tronque
		// à 1990 pour garder une marge (et ajouter ellipse si besoin).
		if ( mb_strlen( $content ) > 1990 ) {
			$content = mb_substr( $content, 0, 1989 ) . '…';
		}
		return [
			'username'         => $this->config->get( 'PASystemBotName' ),
			'content'          => $content,
			'allowed_mentions' => [ 'parse' => [] ],
		];
	}

	private function buildLineContent( array $params ): string {
		$rcType = (int)$params['rc_type'];
		$logType = (string)$params['rc_log_type'];
		$logAction = (string)$params['rc_log_action'];

		$kind = $this->resolveActionKind( $rcType, $logType, $logAction );
		$icon = $this->getActionIcon( $kind );
		$userText = (string)$params['rc_user_text'];
		$userLink = $this->makeMdLink( $userText, $this->getUserUrl( $userText ) );

		switch ( $kind ) {
			case 'edit':
				return $this->lineForEdit( $params, $icon, $userLink, true );

			case 'new':
				return $this->lineForEdit( $params, $icon, $userLink, false );

			case 'upload':
				return sprintf(
					'%s %s a téléversé %s%s',
					$icon,
					$userLink,
					$this->makeMdLink(
						$this->getPrefixedTitle( $params ),
						$this->getPageUrl( $params )
					),
					$this->summarySuffix( $params )
				);

			case 'delete':
				return sprintf(
					'%s %s a supprimé %s%s',
					$icon,
					$userLink,
					$this->makeMdLink(
						'« ' . $this->getPrefixedTitle( $params ) . ' »',
						$this->getPageUrl( $params )
					),
					$this->summarySuffix( $params )
				);

			case 'restore':
				return sprintf(
					'%s %s a restauré %s%s',
					$icon,
					$userLink,
					$this->makeMdLink(
						$this->getPrefixedTitle( $params ),
						$this->getPageUrl( $params )
					),
					$this->summarySuffix( $params )
				);

			case 'move':
				return $this->lineForMove( $params, $icon, $userLink );

			case 'protect':
				return sprintf(
					'%s %s a protégé %s%s',
					$icon,
					$userLink,
					$this->makeMdLink(
						$this->getPrefixedTitle( $params ),
						$this->getPageUrl( $params )
					),
					$this->summarySuffix( $params )
				);

			case 'unprotect':
				return sprintf(
					'%s %s a déprotégé %s%s',
					$icon,
					$userLink,
					$this->makeMdLink(
						$this->getPrefixedTitle( $params ),
						$this->getPageUrl( $params )
					),
					$this->summarySuffix( $params )
				);

			case 'block':
				return sprintf(
					'%s %s a bloqué `%s`%s',
					$icon,
					$userLink,
					(string)$params['rc_title'],
					$this->summarySuffix( $params )
				);

			case 'unblock':
				return sprintf(
					'%s %s a débloqué `%s`%s',
					$icon,
					$userLink,
					(string)$params['rc_title'],
					$this->summarySuffix( $params )
				);

			case 'newuser':
				// Le user créé EST le user qui s'inscrit (rc_user_text)
				return sprintf(
					'%s %s a rejoint le wiki — bienvenue !',
					$icon,
					$userLink
				);

			case 'rights':
				return sprintf(
					'%s %s a modifié les droits de %s%s',
					$icon,
					$userLink,
					$this->makeMdLink(
						(string)$params['rc_title'],
						$this->getUserUrl( (string)$params['rc_title'] )
					),
					$this->summarySuffix( $params )
				);

			default:
				return sprintf(
					'%s %s a effectué une action%s',
					$icon,
					$userLink,
					$this->summarySuffix( $params )
				);
		}
	}

	/**
	 * Construit la ligne pour une édition normale ou une nouvelle page.
	 * Format : ICON **User** {verbe} [Page](url) `±X octets` — Résumé tronqué ([diff](url))
	 */
	private function lineForEdit( array $params, string $icon, string $userLink, bool $withDiff ): string {
		$verb = $withDiff ? 'a modifié' : 'a créé';
		$pageLink = $this->makeMdLink(
			$this->getPrefixedTitle( $params ),
			$this->getPageUrl( $params )
		);

		$delta = (int)$params['rc_new_len'] - (int)$params['rc_old_len'];
		$deltaStr = $this->formatDeltaCompact( $delta );

		$diffLink = '';
		if ( $withDiff && (int)$params['rc_this_oldid'] > 0 && (int)$params['rc_last_oldid'] > 0 ) {
			$diffLink = ' (' . $this->makeMdLink( 'diff', $this->getDiffUrl( $params ) ) . ')';
		}

		return sprintf(
			'%s %s %s %s %s%s%s',
			$icon,
			$userLink,
			$verb,
			$pageLink,
			$deltaStr,
			$this->summarySuffix( $params ),
			$diffLink
		);
	}

	/**
	 * Pour les renommages, le titre nouveau est dans rc_params (sérialisé PHP/JSON).
	 * On essaie de le récupérer, sinon on tombe sur un format dégradé.
	 */
	private function lineForMove( array $params, string $icon, string $userLink ): string {
		$oldTitle = $this->getPrefixedTitle( $params );
		$newTitle = $this->extractMoveTarget( (string)$params['rc_params'] );

		if ( $newTitle ) {
			$arrow = sprintf(
				'%s → %s',
				$this->makeMdLink( $oldTitle, $this->getPageUrl( $params ) ),
				$this->makeMdLink( $newTitle, $this->getPageUrlFromText( $newTitle ) )
			);
		} else {
			$arrow = $this->makeMdLink( $oldTitle, $this->getPageUrl( $params ) );
		}

		return sprintf(
			'%s %s a renommé %s%s',
			$icon,
			$userLink,
			$arrow,
			$this->summarySuffix( $params )
		);
	}

	private function extractMoveTarget( string $rcParams ): ?string {
		if ( $rcParams === '' ) {
			return null;
		}
		// rc_params peut être en PHP serialize ou JSON selon l'âge de MW.
		// allowed_classes => false : sécurité contre les POP chains, on ne veut
		// que des arrays/scalaires de toute façon.
		$decoded = @unserialize( $rcParams, [ 'allowed_classes' => false ] );
		if ( !is_array( $decoded ) ) {
			$decoded = json_decode( $rcParams, true );
		}
		if ( !is_array( $decoded ) ) {
			return null;
		}
		// Plusieurs clés possibles selon la version : 'target', '4::target', 'new_title'
		foreach ( [ 'target', '4::target', 'new_title', 'newTitle' ] as $k ) {
			if ( isset( $decoded[ $k ] ) && is_string( $decoded[ $k ] ) ) {
				return $decoded[ $k ];
			}
		}
		return null;
	}

	private function formatDeltaCompact( int $delta ): string {
		if ( $delta > 0 ) {
			return '`+' . number_format( $delta, 0, ',', "\u{202F}" ) . '`';
		}
		if ( $delta < 0 ) {
			return '`−' . number_format( abs( $delta ), 0, ',', "\u{202F}" ) . '`';
		}
		return '`±0`';
	}

	/**
	 * Suffixe " — Résumé tronqué". Vide si :
	 *   - aucun résumé, ou
	 *   - résumé auto-généré par MediaWiki ET StripAutoSummaries activé
	 *
	 * Les résumés auto-générés sont ceux que MW pré-remplit quand l'utilisateur
	 * n'a rien saisi (typiquement « Page créée avec « ‹début du contenu› » »
	 * pour une création). On veut les filtrer pour ne montrer que les vrais
	 * commentaires humains.
	 *
	 * Tronqué à ~80 chars pour rester sur une ligne dans Discord.
	 */
	private function summarySuffix( array $params ): string {
		$comment = trim( (string)$params['rc_comment'] );
		if ( $comment === '' ) {
			return '';
		}

		// Filtrage des résumés auto-MW (uniquement si l'option est activée)
		if ( (bool)$this->config->get( 'PASystemStripAutoSummaries' ) ) {
			$actionKind = $this->resolveActionKind(
				(int)$params['rc_type'],
				(string)$params['rc_log_type'],
				(string)$params['rc_log_action']
			);
			if ( $this->isAutoGeneratedSummary( $comment, $actionKind ) ) {
				return '';
			}
		}

		$comment = $this->truncate( $comment, 80 );
		// Échapper le markdown du résumé pour éviter qu'un résumé contenant
		// des * ou _ ne casse le rendu.
		$comment = $this->escapeMarkdown( $comment );
		return ' — ' . $comment;
	}

	/**
	 * Détecte les résumés auto-générés par MediaWiki.
	 *
	 * Pour les créations : MW préfixe avec « Page créée avec » (fr) ou
	 * « Created page with » (en), suivi d'un extrait du contenu. C'est rarement
	 * utile à afficher dans une annonce Discord (verbeux, peu lisible).
	 *
	 * On gère aussi le préfixe de section « → » et la flèche gauche « ← ».
	 */
	private function isAutoGeneratedSummary( string $comment, string $actionKind ): bool {
		if ( $actionKind !== 'new' ) {
			return false;
		}
		// Retirer les caractères de préfixe optionnels (flèches MW, espaces)
		$normalized = ltrim( $comment, "←→\xE2\x86\x90\xE2\x86\x92 \t" );
		$patterns = [
			'Page créée avec',     // français
			'Created page with',   // anglais
			'Page blanchie',       // page blanchie
			'Blanked the page',
		];
		foreach ( $patterns as $prefix ) {
			if ( str_starts_with( $normalized, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	private function makeMdLink( string $text, string $url ): string {
		// Échapper les chars qui cassent le texte d'un lien markdown Discord
		$safeText = str_replace( [ '[', ']', '(', ')' ], [ '⟦', '⟧', '⟨', '⟩' ], $text );
		// Encadrer l'URL avec < > : nécessaire pour les URLs contenant des
		// parenthèses (ex. « Vulpes Inculta (Fallout: New Vegas) »), sinon le
		// markdown Discord casse le lien au premier ')'. Bonus : désactive
		// aussi l'aperçu auto pour ce lien spécifique.
		return '[' . $safeText . '](<' . $url . '>)';
	}

	private function escapeMarkdown( string $text ): string {
		// Échapper * _ ` ~ et > (citation) sans casser la lisibilité
		return preg_replace( '/([\\\\*_`~>|])/', '\\\\$1', $text );
	}

	private function getUserUrl( string $userText ): string {
		// NS_USER = 2, hardcoded pour éviter de dépendre de defines.php
		// dans un contexte de namespace
		$title = $this->titleFactory->makeTitle( 2, $userText );
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
	 * URL d'un diff entre deux révisions de la page courante.
	 * Utilise Title::getFullURL avec query pour générer l'URL selon
	 * la config du wiki ($wgArticlePath etc.).
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
	 * URL d'une page nommée par sa version texte (typiquement la cible
	 * d'un renommage, qu'on récupère via rc_params).
	 */
	private function getPageUrlFromText( string $prefixedText ): string {
		$title = $this->titleFactory->newFromText( $prefixedText );
		if ( $title === null ) {
			// Fallback : URL approximative si le titre est invalide
			return $this->getWikiBaseUrl()
				. '/index.php?title=' . rawurlencode( str_replace( ' ', '_', $prefixedText ) );
		}
		return $title->getFullURL();
	}

	/* ============================================================
	 * Format EMBED — version riche, conservée pour compatibilité
	 * ============================================================ */

	private function buildEmbedPayload( array $params ): array {
		$embed = $this->buildEmbed( $params );
		return [
			'username'         => $this->config->get( 'PASystemBotName' ),
			'embeds'           => [ $embed ],
			'allowed_mentions' => [ 'parse' => [] ],
		];
	}

	private function buildEmbed( array $params ): array {
		$rcType = (int)$params['rc_type'];
		$logType = (string)$params['rc_log_type'];
		$logAction = (string)$params['rc_log_action'];

		$actionKind = $this->resolveActionKind( $rcType, $logType, $logAction );
		$color = $this->resolveColor( $actionKind );
		$title = $this->buildTitle( $params, $actionKind );
		$url = $this->buildPrimaryUrl( $params, $rcType );

		$embed = [
			'title'     => $title,
			'color'     => $color,
			'timestamp' => $this->formatTimestamp( (string)$params['rc_timestamp'] ),
			'author'    => $this->buildAuthor( $params ),
		];

		if ( $url ) {
			$embed['url'] = $url;
		}

		// Champs : résumé, taille, flags
		$fields = [];

		// Pour les éditions : delta d'octets
		if ( $rcType === RC_EDIT || $rcType === RC_NEW ) {
			$delta = (int)$params['rc_new_len'] - (int)$params['rc_old_len'];
			$fields[] = [
				'name'   => '📐 Taille',
				'value'  => $this->formatDelta( $delta ),
				'inline' => true,
			];

			$flags = $this->buildFlags( $params );
			if ( $flags !== '' ) {
				$fields[] = [
					'name'   => '🏷️ Drapeaux',
					'value'  => $flags,
					'inline' => true,
				];
			}
		}

		// Résumé d'édition / commentaire de log
		$comment = trim( (string)$params['rc_comment'] );
		if ( $comment !== '' ) {
			$fields[] = [
				'name'   => '💬 Résumé',
				'value'  => $this->truncate( $comment, 1024 ),
				'inline' => false,
			];
		}

		// Liens utiles
		$links = $this->buildLinks( $params, $rcType );
		if ( $links !== '' ) {
			$fields[] = [
				'name'   => '🔗 Liens',
				'value'  => $links,
				'inline' => false,
			];
		}

		if ( $fields ) {
			$embed['fields'] = $fields;
		}

		$embed['footer'] = [
			'text' => $this->config->get( 'PASystemBotName' ) . ' • Système d\'annonces publiques',
		];

		return $embed;
	}

	/**
	 * Détermine la « catégorie » d'action — utilisée pour la couleur et le
	 * verbe d'action.
	 */
	private function resolveActionKind( int $rcType, string $logType, string $logAction ): string {
		// RC_LOG = 3
		if ( $rcType === 3 ) {
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

		// RC_NEW = 1
		if ( $rcType === 1 ) {
			return 'new';
		}

		// RC_EDIT = 0 (default)
		return 'edit';
	}

	private function resolveColor( string $kind ): int {
		$colors = $this->config->get( 'PASystemEmbedColors' );
		// Map quelques alias
		$key = match ( $kind ) {
			'restore', 'unprotect', 'unblock' => 'edit',
			'rights'                          => 'log',
			default                           => $kind,
		};
		return (int)( $colors[ $key ] ?? $colors['edit'] ?? 3447003 );
	}

	private function buildTitle( array $params, string $actionKind ): string {
		$userText = (string)$params['rc_user_text'];
		$pageTitle = $this->getPrefixedTitle( $params );

		$verb = $this->getActionVerb( $actionKind );

		// Cas spécial : création de compte → le « pageTitle » est en fait le nom de l'utilisateur
		if ( $actionKind === 'newuser' ) {
			return sprintf( '👤 %s %s %s', $userText, $verb, $userText );
		}

		$icon = $this->getActionIcon( $actionKind );
		return sprintf( '%s %s %s « %s »', $icon, $userText, $verb, $pageTitle );
	}

	private function getActionVerb( string $kind ): string {
		return match ( $kind ) {
			'edit'      => 'a modifié',
			'new'       => 'a créé',
			'upload'    => 'a téléversé',
			'delete'    => 'a supprimé',
			'restore'   => 'a restauré',
			'move'      => 'a déplacé',
			'protect'   => 'a protégé',
			'unprotect' => 'a déprotégé',
			'block'     => 'a bloqué',
			'unblock'   => 'a débloqué',
			'newuser'   => 'a créé le compte',
			'rights'    => 'a modifié les droits de',
			default     => 'a effectué une action sur',
		};
	}

	private function getActionIcon( string $kind ): string {
		return match ( $kind ) {
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
			default     => '📋',
		};
	}

	private function buildAuthor( array $params ): array {
		$userText = (string)$params['rc_user_text'];
		return [
			'name' => $userText,
			'url'  => $this->getUserUrl( $userText ),
		];
	}

	private function buildPrimaryUrl( array $params, int $rcType ): ?string {
		// Pour les édits avec parent : pointer vers le diff
		if ( $rcType === 0 && (int)$params['rc_this_oldid'] > 0 && (int)$params['rc_last_oldid'] > 0 ) {
			return $this->getDiffUrl( $params );
		}
		// Sinon : pointer vers la page
		return $this->getPageUrl( $params );
	}

	private function buildLinks( array $params, int $rcType ): string {
		$userText = (string)$params['rc_user_text'];
		$pageTitle = $this->titleFactory->makeTitle(
			(int)$params['rc_namespace'],
			(string)$params['rc_title']
		);

		$links = [];

		// Diff (uniquement pour les édits avec parent)
		if ( $rcType === 0 && (int)$params['rc_this_oldid'] > 0 && (int)$params['rc_last_oldid'] > 0 ) {
			$links[] = '[diff](' . $this->getDiffUrl( $params ) . ')';
		}

		// Page (sauf pour les créations de compte où le titre = le user)
		if ( $rcType !== 3 || $this->getActionKindFromParams( $params ) !== 'newuser' ) {
			$links[] = '[page](' . $pageTitle->getFullURL() . ')';
		}

		// Historique (pour les pages d'articles uniquement)
		if ( $rcType === 0 || $rcType === 1 ) {
			$links[] = '[historique](' . $pageTitle->getFullURL( [ 'action' => 'history' ] ) . ')';
		}

		// Contributions de l'utilisateur (toujours pertinent)
		// On utilise SpecialPage::getTitleFor pour avoir la traduction locale
		$contribsTitle = \SpecialPage::getTitleFor( 'Contributions', $userText );
		$links[] = '[contribs](' . $contribsTitle->getFullURL() . ')';

		return implode( ' • ', $links );
	}

	private function buildFlags( array $params ): string {
		$flags = [];
		if ( (int)$params['rc_minor'] === 1 ) {
			$flags[] = '`m`';
		}
		if ( (int)$params['rc_bot'] === 1 ) {
			$flags[] = '`b`';
		}
		// La nouveauté est déjà visible dans le verbe « a créé », on n'ajoute pas un flag N redondant.
		return implode( ' ', $flags );
	}

	private function formatDelta( int $delta ): string {
		if ( $delta > 0 ) {
			return sprintf( '🟢 **+%s** octets', number_format( $delta, 0, ',', "\u{00A0}" ) );
		}
		if ( $delta < 0 ) {
			return sprintf( '🔴 **−%s** octets', number_format( abs( $delta ), 0, ',', "\u{00A0}" ) );
		}
		return '⚪ 0 octet';
	}

	private function getPrefixedTitle( array $params ): string {
		$ns = (int)$params['rc_namespace'];
		$title = (string)$params['rc_title'];
		// Cas particulier des logs : le namespace peut être -1, on garde le titre tel quel
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
		// Format MW : YYYYMMDDHHMMSS → ISO 8601 pour Discord
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
		// Fallback sur $wgServer + le path standard
		$server = $this->config->get( 'Server' );
		$scriptPath = $this->config->get( 'ScriptPath' );
		return rtrim( $server . $scriptPath, '/' );
	}
}
