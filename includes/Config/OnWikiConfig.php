<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PublicAnnouncementSystem\Config;

use MediaWiki\Config\Config;

/**
 * Config wrapper that lets the on-wiki overrides (Special:PASystemConfig →
 * [[MediaWiki:PASystemConfig.json]]) take precedence over LocalSettings.php
 * for a fixed allowlist of non-sensitive settings.
 *
 * - Keys absent from the allowlist (notably the webhook URLs, which are
 *   secrets) always come from LocalSettings.php / extension defaults.
 * - Override values are validated against a per-key schema; invalid values
 *   are ignored (with fallback to the regular config), so a malformed JSON
 *   page can never break the wiki.
 *
 * Implements the Config interface so every collaborator of the extension
 * can receive it in place of the main config.
 */
class OnWikiConfig implements Config {

	/**
	 * Validation schema of the settings editable on-wiki.
	 * Types: bool, uint, string, url, enum (with values), int[], string[],
	 * map-bool, map-string, map-int.
	 */
	private const SCHEMA = [
		'PASystemFormat'               => [ 'enum', [ 'line', 'embed' ] ],
		'PASystemDeliveryMode'         => [ 'enum', [ 'immediate', 'job' ] ],
		'PASystemBotName'              => [ 'string' ],
		'PASystemBotAvatarUrl'         => [ 'url' ],
		'PASystemNotifyBots'           => [ 'bool' ],
		'PASystemNotifyMinor'          => [ 'bool' ],
		'PASystemNotifyCategorization' => [ 'bool' ],
		'PASystemNotifyExternal'       => [ 'bool' ],
		'PASystemStripAutoSummaries'   => [ 'bool' ],
		'PASystemDebug'                => [ 'bool' ],
		'PASystemIncludedNamespaces'   => [ 'int[]' ],
		'PASystemExcludedNamespaces'   => [ 'int[]' ],
		'PASystemExcludedUsers'        => [ 'string[]' ],
		'PASystemExcludedLogTypes'     => [ 'string[]' ],
		'PASystemMinDiffSize'          => [ 'uint' ],
		'PASystemMaxPerMinute'         => [ 'uint' ],
		'PASystemDisplay'              => [ 'map-bool' ],
		'PASystemActionIcons'          => [ 'map-string' ],
		'PASystemEmbedColors'          => [ 'map-int' ],
	];

	private Config $fallback;
	private OnWikiConfigStore $store;

	public function __construct( Config $fallback, OnWikiConfigStore $store ) {
		$this->fallback = $fallback;
		$this->store = $store;
	}

	/**
	 * List of the setting names editable through the on-wiki page.
	 *
	 * @return string[]
	 */
	public static function editableKeys(): array {
		return array_keys( self::SCHEMA );
	}

	/** @inheritDoc */
	public function get( $name ) {
		if ( isset( self::SCHEMA[ $name ] ) ) {
			$overrides = $this->store->loadOverrides();
			if ( array_key_exists( $name, $overrides ) ) {
				$value = $this->sanitize( $name, $overrides[ $name ] );
				if ( $value !== null ) {
					return $value;
				}
			}
		}
		return $this->fallback->get( $name );
	}

	/** @inheritDoc */
	public function has( $name ) {
		return isset( self::SCHEMA[ $name ] ) || $this->fallback->has( $name );
	}

	/**
	 * Validates and normalizes an override value against the schema.
	 *
	 * @param string $name Setting name
	 * @param mixed $value Raw value from the JSON page
	 * @return mixed Normalized value, or null when invalid (→ fallback)
	 */
	private function sanitize( string $name, $value ) {
		[ $type, $extra ] = self::SCHEMA[ $name ] + [ 1 => null ];

		switch ( $type ) {
			case 'bool':
				if ( is_bool( $value ) ) {
					return $value;
				}
				return ( $value === 0 || $value === 1 ) ? (bool)$value : null;

			case 'uint':
				return ( is_int( $value ) && $value >= 0 ) ? $value : null;

			case 'string':
				return is_string( $value ) ? $value : null;

			case 'url':
				if ( !is_string( $value ) ) {
					return null;
				}
				return ( $value === '' || preg_match( '!^https?://!', $value ) ) ? $value : null;

			case 'enum':
				return in_array( $value, $extra, true ) ? $value : null;

			case 'int[]':
				if ( !is_array( $value ) ) {
					return null;
				}
				$out = [];
				foreach ( $value as $item ) {
					if ( !is_int( $item ) ) {
						return null;
					}
					$out[] = $item;
				}
				return $out;

			case 'string[]':
				if ( !is_array( $value ) ) {
					return null;
				}
				$out = [];
				foreach ( $value as $item ) {
					if ( !is_string( $item ) ) {
						return null;
					}
					$out[] = $item;
				}
				return $out;

			case 'map-bool':
			case 'map-string':
			case 'map-int':
				if ( !is_array( $value ) ) {
					return null;
				}
				$check = [
					'map-bool'   => 'is_bool',
					'map-string' => 'is_string',
					'map-int'    => 'is_int',
				][ $type ];
				$out = [];
				foreach ( $value as $k => $item ) {
					if ( !is_string( $k ) || !$check( $item ) ) {
						return null;
					}
					$out[ $k ] = $item;
				}
				return $out;

			default:
				return null;
		}
	}
}
