<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PublicAnnouncementSystem\Tests\Unit\Notifier;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\PublicAnnouncementSystem\Notifier\DiscordNotifier;
use MediaWiki\Extension\PublicAnnouncementSystem\Notifier\RateLimitException;
use MediaWiki\Http\HttpRequestFactory;
use MediaWikiUnitTestCase;
use MWHttpRequest;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \MediaWiki\Extension\PublicAnnouncementSystem\Notifier\DiscordNotifier
 */
class DiscordNotifierTest extends MediaWikiUnitTestCase {

	private const WEBHOOK = 'https://discord.com/api/webhooks/123/abc';

	private function makeNotifier(
		int $httpCode,
		string $responseBody = '',
		?string $retryAfterHeader = null,
		?array &$capturedOptions = null
	): DiscordNotifier {
		$request = $this->createMock( MWHttpRequest::class );
		$request->method( 'getStatus' )->willReturn( $httpCode );
		$request->method( 'getContent' )->willReturn( $responseBody );
		$request->method( 'getResponseHeader' )->willReturnCallback(
			static fn ( string $name ) => $name === 'Retry-After' ? $retryAfterHeader : null
		);

		$factory = $this->createMock( HttpRequestFactory::class );
		$factory->method( 'create' )->willReturnCallback(
			static function ( $url, $options ) use ( $request, &$capturedOptions ) {
				$capturedOptions = $options + [ 'url' => $url ];
				return $request;
			}
		);

		return new DiscordNotifier(
			new HashConfig( [
				'PASystemWebhookUrl' => self::WEBHOOK,
				'PASystemDebug'      => false,
			] ),
			$factory,
			new NullLogger()
		);
	}

	public function testSuccessfulSend(): void {
		$captured = null;
		$notifier = $this->makeNotifier( 204, '', null, $captured );

		$notifier->send( [ 'content' => 'héllo', 'username' => 'Bot' ] );

		$this->assertSame( self::WEBHOOK, $captured['url'] );
		$this->assertSame( 'POST', $captured['method'] );
		// JSON_UNESCAPED_UNICODE: accents must not be \u-escaped
		$this->assertStringContainsString( '"content":"héllo"', $captured['postData'] );
	}

	public function testThrowsWhenNoWebhookConfigured(): void {
		$notifier = new DiscordNotifier(
			new HashConfig( [ 'PASystemWebhookUrl' => '', 'PASystemDebug' => false ] ),
			$this->createMock( HttpRequestFactory::class ),
			new NullLogger()
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'PASystemWebhookUrl is not configured' );
		$notifier->send( [ 'content' => 'x' ] );
	}

	public function testRejectsNonHttpsWebhookUrl(): void {
		$notifier = new DiscordNotifier(
			new HashConfig( [
				'PASystemWebhookUrl' => 'http://discord.com/api/webhooks/123/abc',
				'PASystemDebug'      => false,
			] ),
			$this->createMock( HttpRequestFactory::class ),
			new NullLogger()
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'must be an HTTPS URL' );
		$notifier->send( [ 'content' => 'x' ] );
	}

	public function testRateLimitUsesRetryAfterFromBody(): void {
		$notifier = $this->makeNotifier( 429, '{"retry_after": 2.5}' );

		try {
			$notifier->send( [ 'content' => 'x' ] );
			$this->fail( 'RateLimitException expected' );
		} catch ( RateLimitException $e ) {
			$this->assertSame( 2.5, $e->getRetryAfter() );
		}
	}

	public function testRateLimitFallsBackToHeader(): void {
		$notifier = $this->makeNotifier( 429, 'not json', '7' );

		try {
			$notifier->send( [ 'content' => 'x' ] );
			$this->fail( 'RateLimitException expected' );
		} catch ( RateLimitException $e ) {
			$this->assertSame( 7.0, $e->getRetryAfter() );
		}
	}

	public function testServerErrorThrows(): void {
		$notifier = $this->makeNotifier( 502, 'bad gateway' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Discord HTTP 502 (server error)' );
		$notifier->send( [ 'content' => 'x' ] );
	}

	public function testClientErrorThrows(): void {
		$notifier = $this->makeNotifier( 400, '{"message": "Invalid payload"}' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Discord HTTP 400' );
		$notifier->send( [ 'content' => 'x' ] );
	}

	public function testOverrideUrlTakesPrecedence(): void {
		$captured = null;
		$notifier = $this->makeNotifier( 200, '', null, $captured );

		$notifier->send( [ 'content' => 'x' ], 'https://discord.com/api/webhooks/999/zzz' );

		$this->assertSame( 'https://discord.com/api/webhooks/999/zzz', $captured['url'] );
	}
}
