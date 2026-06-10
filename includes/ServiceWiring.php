<?php
/**
 * Service wiring for PublicAnnouncementSystem.
 *
 * Defines the injectable "PASystem.X" services referenced in extension.json
 * through the HookHandlers, JobClasses and SpecialPages attributes.
 *
 * Every collaborator receives 'PASystem.Config' — the merged view of the
 * on-wiki overrides ([[MediaWiki:PASystemConfig.json]], editable through
 * Special:PASystemConfig) over LocalSettings.php.
 *
 * @file
 */

declare( strict_types = 1 );

use MediaWiki\Config\Config;
use MediaWiki\Extension\PublicAnnouncementSystem\Config\OnWikiConfig;
use MediaWiki\Extension\PublicAnnouncementSystem\Config\OnWikiConfigStore;
use MediaWiki\Extension\PublicAnnouncementSystem\Filter\ChangeFilter;
use MediaWiki\Extension\PublicAnnouncementSystem\Filter\FloodGuard;
use MediaWiki\Extension\PublicAnnouncementSystem\Formatter\DiscordEmbedFormatter;
use MediaWiki\Extension\PublicAnnouncementSystem\Notifier\DiscordNotifier;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [

	'PASystem.OnWikiConfigStore' => static function ( MediaWikiServices $services ): OnWikiConfigStore {
		return new OnWikiConfigStore(
			$services->getRevisionLookup(),
			$services->getWikiPageFactory(),
			$services->getTitleFactory(),
			LoggerFactory::getInstance( 'PublicAnnouncementSystem' )
		);
	},

	'PASystem.Config' => static function ( MediaWikiServices $services ): Config {
		return new OnWikiConfig(
			$services->getMainConfig(),
			$services->getService( 'PASystem.OnWikiConfigStore' )
		);
	},

	'PASystem.ChangeFilter' => static function ( MediaWikiServices $services ): ChangeFilter {
		return new ChangeFilter(
			$services->getService( 'PASystem.Config' ),
			LoggerFactory::getInstance( 'PublicAnnouncementSystem' )
		);
	},

	'PASystem.FloodGuard' => static function ( MediaWikiServices $services ): FloodGuard {
		return new FloodGuard(
			$services->getService( 'PASystem.Config' ),
			$services->getMainObjectStash(),
			LoggerFactory::getInstance( 'PublicAnnouncementSystem' )
		);
	},

	'PASystem.DiscordEmbedFormatter' => static function ( MediaWikiServices $services ): DiscordEmbedFormatter {
		$contentLang = $services->getContentLanguage();
		return new DiscordEmbedFormatter(
			$services->getService( 'PASystem.Config' ),
			$services->getTitleFactory(),
			$contentLang,
			$services->getMessageFormatterFactory()->getTextFormatter( $contentLang->getCode() ),
			LoggerFactory::getInstance( 'PublicAnnouncementSystem' )
		);
	},

	'PASystem.DiscordNotifier' => static function ( MediaWikiServices $services ): DiscordNotifier {
		return new DiscordNotifier(
			$services->getService( 'PASystem.Config' ),
			$services->getHttpRequestFactory(),
			LoggerFactory::getInstance( 'PublicAnnouncementSystem' )
		);
	},

];
