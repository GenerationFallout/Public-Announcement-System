<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PublicAnnouncementSystem\Config;

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\JsonContent;
use MediaWiki\Content\TextContent;
use MediaWiki\Json\FormatJson;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\TitleFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Reads and writes the on-wiki configuration overrides stored as JSON in
 * [[MediaWiki:PASystemConfig.json]].
 *
 * Storing the overrides in a MediaWiki-namespace page gives versioning,
 * diffs, watchlisting and rollback for free, and the page is protected by
 * the editinterface right against direct edits.
 *
 * NOTE: pages are publicly readable — secrets (webhook URLs) must never be
 * stored here. OnWikiConfig enforces the list of allowed keys.
 */
class OnWikiConfigStore {

	public const CONFIG_PAGE = 'PASystemConfig.json';

	private RevisionLookup $revisionLookup;
	private WikiPageFactory $wikiPageFactory;
	private TitleFactory $titleFactory;
	private LoggerInterface $logger;

	/** @var array|null Request-local cache of the parsed overrides */
	private ?array $cache = null;

	public function __construct(
		RevisionLookup $revisionLookup,
		WikiPageFactory $wikiPageFactory,
		TitleFactory $titleFactory,
		LoggerInterface $logger
	) {
		$this->revisionLookup = $revisionLookup;
		$this->wikiPageFactory = $wikiPageFactory;
		$this->titleFactory = $titleFactory;
		$this->logger = $logger;
	}

	/**
	 * Returns the raw override map from the config page, or an empty array
	 * when the page does not exist or does not contain a JSON object.
	 */
	public function loadOverrides(): array {
		if ( $this->cache !== null ) {
			return $this->cache;
		}

		$this->cache = [];
		$title = $this->titleFactory->makeTitle( NS_MEDIAWIKI, self::CONFIG_PAGE );
		$revision = $this->revisionLookup->getRevisionByTitle( $title );
		if ( $revision === null ) {
			return $this->cache;
		}

		$content = $revision->getContent( SlotRecord::MAIN );
		if ( !( $content instanceof TextContent ) ) {
			return $this->cache;
		}

		$data = FormatJson::decode( $content->getText(), true );
		if ( is_array( $data ) ) {
			$this->cache = $data;
		} else {
			$this->logger->warning( 'Invalid JSON in MediaWiki:' . self::CONFIG_PAGE );
		}
		return $this->cache;
	}

	/**
	 * Saves the override map to the config page.
	 *
	 * @param array $overrides Key → value map (validated by the caller)
	 * @param Authority $performer User performing the edit
	 * @param string $summary Edit summary
	 * @throws RuntimeException When the page save fails
	 */
	public function saveOverrides( array $overrides, Authority $performer, string $summary ): void {
		$title = $this->titleFactory->makeTitle( NS_MEDIAWIKI, self::CONFIG_PAGE );
		$page = $this->wikiPageFactory->newFromTitle( $title );

		$updater = $page->newPageUpdater( $performer );
		$updater->setContent(
			SlotRecord::MAIN,
			new JsonContent( FormatJson::encode( $overrides, "\t" ) )
		);
		$updater->saveRevision( CommentStoreComment::newUnsavedComment( $summary ) );

		if ( !$updater->wasSuccessful() ) {
			throw new RuntimeException(
				'Failed to save ' . self::CONFIG_PAGE . ': '
				. $updater->getStatus()->getWikiText( false, false, 'en' )
			);
		}

		$this->cache = $overrides;
	}
}
