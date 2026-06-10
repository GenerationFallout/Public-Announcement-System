<?php
/**
 * ServiceWiring pour PASystem.
 *
 * Définit les services injectables nommés "PASystem.X" utilisés dans
 * extension.json via les attributs HookHandlers, JobClasses, SpecialPages.
 *
 * @file
 */

declare( strict_types = 1 );

use MediaWiki\Extension\PublicAnnouncementSystem\Filter\ChangeFilter;
use MediaWiki\Extension\PublicAnnouncementSystem\Formatter\DiscordEmbedFormatter;
use MediaWiki\Extension\PublicAnnouncementSystem\Notifier\DiscordNotifier;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [

	'PASystem.ChangeFilter' => static function ( MediaWikiServices $services ): ChangeFilter {
		return new ChangeFilter(
			$services->getMainConfig(),
			LoggerFactory::getInstance( 'PublicAnnouncementSystem' )
		);
	},

	'PASystem.DiscordEmbedFormatter' => static function ( MediaWikiServices $services ): DiscordEmbedFormatter {
		return new DiscordEmbedFormatter(
			$services->getMainConfig(),
			$services->getTitleFactory(),
			$services->getContentLanguage(),
			LoggerFactory::getInstance( 'PublicAnnouncementSystem' )
		);
	},

	'PASystem.DiscordNotifier' => static function ( MediaWikiServices $services ): DiscordNotifier {
		return new DiscordNotifier(
			$services->getMainConfig(),
			$services->getHttpRequestFactory(),
			LoggerFactory::getInstance( 'PublicAnnouncementSystem' )
		);
	},

];
