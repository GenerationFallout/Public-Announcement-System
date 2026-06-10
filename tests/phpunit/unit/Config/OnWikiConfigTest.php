<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PublicAnnouncementSystem\Tests\Unit\Config;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\PublicAnnouncementSystem\Config\OnWikiConfig;
use MediaWiki\Extension\PublicAnnouncementSystem\Config\OnWikiConfigStore;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\PublicAnnouncementSystem\Config\OnWikiConfig
 */
class OnWikiConfigTest extends MediaWikiUnitTestCase {

	private const FALLBACK = [
		'PASystemFormat'             => 'line',
		'PASystemBotName'            => 'FallbackBot',
		'PASystemNotifyMinor'        => true,
		'PASystemMinDiffSize'        => 0,
		'PASystemExcludedNamespaces' => [],
		'PASystemDisplay'            => [],
		'PASystemWebhookUrl'         => 'https://discord.com/api/webhooks/1/secret',
		'Sitename'                   => 'TestWiki',
	];

	private function makeConfig( array $overrides ): OnWikiConfig {
		$store = $this->createMock( OnWikiConfigStore::class );
		$store->method( 'loadOverrides' )->willReturn( $overrides );
		return new OnWikiConfig( new HashConfig( self::FALLBACK ), $store );
	}

	public function testFallsBackWhenNoOverride(): void {
		$config = $this->makeConfig( [] );
		$this->assertSame( 'line', $config->get( 'PASystemFormat' ) );
		$this->assertSame( 'FallbackBot', $config->get( 'PASystemBotName' ) );
	}

	public function testValidOverrideWins(): void {
		$config = $this->makeConfig( [
			'PASystemFormat'             => 'embed',
			'PASystemBotName'            => 'WikiBot',
			'PASystemNotifyMinor'        => false,
			'PASystemMinDiffSize'        => 25,
			'PASystemExcludedNamespaces' => [ 2, 3 ],
			'PASystemDisplay'            => [ 'icons' => false ],
		] );

		$this->assertSame( 'embed', $config->get( 'PASystemFormat' ) );
		$this->assertSame( 'WikiBot', $config->get( 'PASystemBotName' ) );
		$this->assertFalse( $config->get( 'PASystemNotifyMinor' ) );
		$this->assertSame( 25, $config->get( 'PASystemMinDiffSize' ) );
		$this->assertSame( [ 2, 3 ], $config->get( 'PASystemExcludedNamespaces' ) );
		$this->assertSame( [ 'icons' => false ], $config->get( 'PASystemDisplay' ) );
	}

	public function testInvalidOverrideFallsBack(): void {
		$config = $this->makeConfig( [
			'PASystemFormat'             => 'banana',
			'PASystemNotifyMinor'        => 'yes',
			'PASystemMinDiffSize'        => -5,
			'PASystemExcludedNamespaces' => [ 'two' ],
			'PASystemDisplay'            => [ 'icons' => 'off' ],
		] );

		$this->assertSame( 'line', $config->get( 'PASystemFormat' ) );
		$this->assertTrue( $config->get( 'PASystemNotifyMinor' ) );
		$this->assertSame( 0, $config->get( 'PASystemMinDiffSize' ) );
		$this->assertSame( [], $config->get( 'PASystemExcludedNamespaces' ) );
		$this->assertSame( [], $config->get( 'PASystemDisplay' ) );
	}

	public function testSecretsCannotBeOverriddenOnWiki(): void {
		$config = $this->makeConfig( [
			'PASystemWebhookUrl' => 'https://attacker.example/exfiltrate',
			'Sitename'           => 'Hacked',
		] );

		// Not in the editable allowlist → always from LocalSettings
		$this->assertSame(
			'https://discord.com/api/webhooks/1/secret',
			$config->get( 'PASystemWebhookUrl' )
		);
		$this->assertSame( 'TestWiki', $config->get( 'Sitename' ) );
	}

	public function testAvatarUrlMustBeHttpOrEmpty(): void {
		$config = $this->makeConfig( [ 'PASystemBotAvatarUrl' => 'javascript:alert(1)' ] );
		$fallbackless = new HashConfig( [ 'PASystemBotAvatarUrl' => '' ] );
		$store = $this->createMock( OnWikiConfigStore::class );
		$store->method( 'loadOverrides' )->willReturn( [ 'PASystemBotAvatarUrl' => 'javascript:alert(1)' ] );
		$config = new OnWikiConfig( $fallbackless, $store );

		$this->assertSame( '', $config->get( 'PASystemBotAvatarUrl' ) );
	}

	public function testEditableKeysContainNoSecrets(): void {
		$keys = OnWikiConfig::editableKeys();
		$this->assertNotContains( 'PASystemWebhookUrl', $keys );
		$this->assertNotContains( 'PASystemWebhookRoutes', $keys );
		$this->assertContains( 'PASystemFormat', $keys );
	}
}
