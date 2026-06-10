<?php
/**
 * Service wiring for PublicAnnouncementSystem.
 *
 * Defines the injectable "PASystem.X" services referenced in extension.json
 * through the HookHandlers, JobClasses and SpecialPages attributes.
 *
 * @file
 */

declare( strict_types = 1 );

use MediaWiki\Extension\PublicAnnouncementSystem\Filter\ChangeFilter;
use MediaWiki\Extension\PublicAnnouncementSystem\Filter\FloodGuard;
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

	'PASystem.FloodGuard' => static function ( MediaWikiServices $services ): FloodGuard {
		return new FloodGuard(
			$services->getMainConfig(),
			$services->getMainObjectStash(),
			LoggerFactory::getInstance( 'PublicAnnouncementSystem' )
		);
	},

	'PASystem.DiscordEmbedFormatter' => static function ( MediaWikiServices $services ): DiscordEmbedFormatter {
		$contentLang = $services->getContentLanguage();
		return new DiscordEmbedFormatter(
			$services->getMainConfig(),
			$services->getTitleFactory(),
			$contentLang,
			$services->getMessageFormatterFactory()->getTextFormatter( $contentLang->getCode() ),
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
