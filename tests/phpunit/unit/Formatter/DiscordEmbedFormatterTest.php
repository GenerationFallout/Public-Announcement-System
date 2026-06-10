<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PublicAnnouncementSystem\Tests\Unit\Formatter;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\PublicAnnouncementSystem\Formatter\DiscordEmbedFormatter;
use MediaWiki\Language\Language;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWikiUnitTestCase;
use Psr\Log\NullLogger;
use Wikimedia\Message\ITextFormatter;
use Wikimedia\Message\MessageSpecifier;

/**
 * @covers \MediaWiki\Extension\PublicAnnouncementSystem\Formatter\DiscordEmbedFormatter
 */
class DiscordEmbedFormatterTest extends MediaWikiUnitTestCase {

	private const DEFAULT_CONFIG = [
		'PASystemFormat'              => 'line',
		'PASystemBotName'             => 'TestBot',
		'PASystemBotAvatarUrl'        => '',
		'PASystemWikiBaseUrl'         => '',
		'PASystemDisplay'             => [],
		'PASystemActionIcons'         => [],
		'PASystemEmbedColors'         => [ 'edit' => 3447003, 'new' => 5763719 ],
		'PASystemStripAutoSummaries'  => true,
		'Sitename'                    => 'TestWiki',
		'Server'                      => 'https://wiki.example.org',
		'ScriptPath'                  => '/w',
	];

	/**
	 * Text formatter backed by the extension's real English templates from
	 * i18n/en.json, plus the core autosumm-* messages used for auto-summary
	 * detection. This way the tests exercise the actual shipped templates.
	 */
	private function makeTextFormatter(): ITextFormatter {
		$messages = json_decode(
			file_get_contents( dirname( __DIR__, 4 ) . '/i18n/en.json' ),
			true
		);
		$messages += [
			'autosumm-new'      => 'Created page with "$1"',
			'autosumm-newblank' => 'Created blank page',
			'autosumm-blank'    => 'Blanked the page',
			'autosumm-replace'  => 'Replaced content with "$1"',
			'autosumm-removed-redirect' => 'Removed redirect to [[$1]]',
			'autosumm-changed-redirect-target' => 'Changed redirect target from [[$1]] to [[$2]]',
		];

		return new class ( $messages ) implements ITextFormatter {
			public function __construct( private readonly array $messages ) {
			}

			public function getLangCode(): string {
				return 'en';
			}

			public function format( MessageSpecifier $mv ): string {
				$text = $this->messages[ $mv->getKey() ] ?? '⧼' . $mv->getKey() . '⧽';
				$i = 0;
				foreach ( $mv->getParams() as $param ) {
					$i++;
					$text = str_replace( '$' . $i, (string)$param->getValue(), $text );
				}
				return $text;
			}
		};
	}

	private function makeTitleFactory(): TitleFactory {
		$makeTitle = function ( int $ns, string $text ): Title {
			$prefixed = ( $ns === 2 ? 'User:' : '' ) . str_replace( '_', ' ', $text );
			$title = $this->createMock( Title::class );
			$title->method( 'getPrefixedText' )->willReturn( $prefixed );
			$title->method( 'getFullURL' )->willReturnCallback(
				static function ( $query = '' ) use ( $prefixed ): string {
					$url = 'https://wiki.example.org/wiki/' . rawurlencode( str_replace( ' ', '_', $prefixed ) );
					if ( is_array( $query ) && $query ) {
						$url .= '?' . http_build_query( $query );
					}
					return $url;
				}
			);
			return $title;
		};

		$factory = $this->createMock( TitleFactory::class );
		$factory->method( 'makeTitle' )->willReturnCallback( $makeTitle );
		$factory->method( 'newFromText' )->willReturnCallback(
			static fn ( string $text ) => $makeTitle( 0, $text )
		);
		return $factory;
	}

	private function makeFormatter( array $configOverrides = [] ): DiscordEmbedFormatter {
		$contentLang = $this->createMock( Language::class );
		$contentLang->method( 'formatNum' )->willReturnCallback(
			static fn ( $number ) => (string)$number
		);

		return new DiscordEmbedFormatter(
			new HashConfig( $configOverrides + self::DEFAULT_CONFIG ),
			$this->makeTitleFactory(),
			$contentLang,
			$this->makeTextFormatter(),
			new NullLogger()
		);
	}

	private function makeEditParams( array $overrides = [] ): array {
		return $overrides + [
			'rc_id'         => 42,
			'rc_type'       => RC_EDIT,
			'rc_timestamp'  => '20260610120000',
			'rc_namespace'  => 0,
			'rc_title'      => 'Vault_City',
			'rc_user'       => 1,
			'rc_user_text'  => 'Alice',
			'rc_comment'    => 'Fixed a typo',
			'rc_minor'      => 0,
			'rc_bot'        => 0,
			'rc_this_oldid' => 200,
			'rc_last_oldid' => 100,
			'rc_old_len'    => 1000,
			'rc_new_len'    => 1050,
			'rc_log_type'   => '',
			'rc_log_action' => '',
			'rc_logid'      => 0,
			'rc_patrolled'  => 0,
			'rc_params'     => '',
		];
	}

	public function testLineEdit(): void {
		$payload = $this->makeFormatter()->build( $this->makeEditParams() );

		$this->assertSame( 'TestBot', $payload['username'] );
		$this->assertSame( [ 'parse' => [] ], $payload['allowed_mentions'] );
		$this->assertSame( 4, $payload['flags'], 'SUPPRESS_EMBEDS flag must be set' );
		$this->assertArrayNotHasKey( 'avatar_url', $payload );

		$content = $payload['content'];
		$this->assertStringContainsString( '📝', $content );
		$this->assertStringContainsString( '[Alice](<https://wiki.example.org/wiki/User%3AAlice>)', $content );
		$this->assertStringContainsString( 'edited', $content );
		$this->assertStringContainsString( '[Vault City]', $content );
		$this->assertStringContainsString( '`+50`', $content );
		$this->assertStringContainsString( '— Fixed a typo', $content );
		$this->assertStringContainsString(
			'[diff](<https://wiki.example.org/wiki/Vault_City?diff=200&oldid=100>)',
			$content
		);
	}

	public function testLineNewPageStripsAutoSummary(): void {
		$payload = $this->makeFormatter()->build( $this->makeEditParams( [
			'rc_type'    => RC_NEW,
			'rc_comment' => 'Created page with "Some content excerpt"',
		] ) );

		$this->assertStringContainsString( 'created', $payload['content'] );
		$this->assertStringNotContainsString( 'Created page with', $payload['content'] );
	}

	public function testAutoSummaryKeptWhenStrippingDisabled(): void {
		$payload = $this->makeFormatter( [ 'PASystemStripAutoSummaries' => false ] )
			->build( $this->makeEditParams( [
				'rc_type'    => RC_NEW,
				'rc_comment' => 'Created page with "Some content excerpt"',
			] ) );

		$this->assertStringContainsString( 'Created page with', $payload['content'] );
	}

	public function testAutoSummaryStrippedOnEditsToo(): void {
		$payload = $this->makeFormatter()->build( $this->makeEditParams( [
			'rc_comment' => 'Blanked the page',
		] ) );

		$this->assertStringNotContainsString( 'Blanked the page', $payload['content'] );
	}

	public function testHumanSummaryIsKept(): void {
		$payload = $this->makeFormatter()->build( $this->makeEditParams( [
			'rc_comment' => 'Rewrote the intro section',
		] ) );

		$this->assertStringContainsString( '— Rewrote the intro section', $payload['content'] );
	}

	public function testSummaryMarkdownIsEscaped(): void {
		$payload = $this->makeFormatter()->build( $this->makeEditParams( [
			'rc_comment' => 'Added *emphasis* and `code`',
		] ) );

		$this->assertStringContainsString( '\\*emphasis\\*', $payload['content'] );
		$this->assertStringContainsString( '\\`code\\`', $payload['content'] );
	}

	public function testMaskedLinkInSummaryIsNeutralized(): void {
		$payload = $this->makeFormatter()->build( $this->makeEditParams( [
			'rc_comment' => '[click here](https://evil.example/phish)',
		] ) );

		// Discord renders masked links in webhook content: the brackets must
		// be escaped so no clickable link can be smuggled in.
		$this->assertStringNotContainsString( '[click here](', $payload['content'] );
		$this->assertStringContainsString( '\\[click here\\]', $payload['content'] );
	}

	public function testMaskedLinkInEmbedSummaryIsNeutralized(): void {
		$payload = $this->makeFormatter( [
			'PASystemFormat'  => 'embed',
			'PASystemDisplay' => [ 'links' => false ],
		] )->build( $this->makeEditParams( [
			'rc_comment' => '[click here](https://evil.example/phish)',
		] ) );

		$summaryField = $payload['embeds'][0]['fields'][1];
		$this->assertStringNotContainsString( '[click here](', $summaryField['value'] );
	}

	public function testIconsCanBeDisabled(): void {
		$payload = $this->makeFormatter( [ 'PASystemDisplay' => [ 'icons' => false ] ] )
			->build( $this->makeEditParams() );

		$this->assertStringNotContainsString( '📝', $payload['content'] );
		$this->assertStringStartsWith( '[Alice]', $payload['content'] );
	}

	public function testDeltaAndDiffLinkCanBeDisabled(): void {
		$payload = $this->makeFormatter( [
			'PASystemDisplay' => [ 'delta' => false, 'diffLink' => false ],
		] )->build( $this->makeEditParams() );

		$this->assertStringNotContainsString( '`+50`', $payload['content'] );
		$this->assertStringNotContainsString( '[diff]', $payload['content'] );
	}

	public function testActionIconsAreConfigurable(): void {
		$payload = $this->makeFormatter( [ 'PASystemActionIcons' => [ 'edit' => '✏️' ] ] )
			->build( $this->makeEditParams() );

		$this->assertStringStartsWith( '✏️', $payload['content'] );
		$this->assertStringNotContainsString( '📝', $payload['content'] );
	}

	public function testLineNewUser(): void {
		$payload = $this->makeFormatter()->build( $this->makeEditParams( [
			'rc_type'       => RC_LOG,
			'rc_log_type'   => 'newusers',
			'rc_log_action' => 'create',
			'rc_title'      => 'Alice',
			'rc_namespace'  => 2,
			'rc_comment'    => '',
		] ) );

		$this->assertStringContainsString( 'joined the wiki — welcome!', $payload['content'] );
	}

	public function testLineMoveWithTarget(): void {
		$payload = $this->makeFormatter()->build( $this->makeEditParams( [
			'rc_type'       => RC_LOG,
			'rc_log_type'   => 'move',
			'rc_log_action' => 'move',
			'rc_params'     => serialize( [ '4::target' => 'New Vault City' ] ),
			'rc_comment'    => '',
		] ) );

		$content = $payload['content'];
		$this->assertStringContainsString( 'moved', $content );
		$this->assertStringContainsString( '→', $content );
		$this->assertStringContainsString( '[New Vault City]', $content );
	}

	public function testLineBlock(): void {
		$payload = $this->makeFormatter()->build( $this->makeEditParams( [
			'rc_type'       => RC_LOG,
			'rc_log_type'   => 'block',
			'rc_log_action' => 'block',
			'rc_title'      => 'Vandal',
			'rc_namespace'  => 2,
			'rc_comment'    => 'spam',
		] ) );

		$this->assertStringContainsString( 'blocked `Vandal`', $payload['content'] );
	}

	public function testPageTitleWithParenthesesKeepsLinkIntact(): void {
		$payload = $this->makeFormatter()->build( $this->makeEditParams( [
			'rc_title' => 'Vulpes_Inculta_(Fallout:_New_Vegas)',
		] ) );

		// Parentheses must be substituted in the link text and the URL must
		// be wrapped in <>, otherwise Discord breaks the link at the ')'.
		$this->assertStringContainsString( '[Vulpes Inculta ⟨Fallout: New Vegas⟩](<', $payload['content'] );
	}

	public function testContentIsTruncatedAt2000Chars(): void {
		$payload = $this->makeFormatter( [ 'PASystemStripAutoSummaries' => false ] )
			->build( $this->makeEditParams( [
				'rc_title' => str_repeat( 'A', 3000 ),
			] ) );

		$this->assertLessThanOrEqual( 1990, mb_strlen( $payload['content'] ) );
		$this->assertStringEndsWith( '…', $payload['content'] );
	}

	public function testBotNameFallsBackToSitename(): void {
		$payload = $this->makeFormatter( [ 'PASystemBotName' => '' ] )
			->build( $this->makeEditParams() );

		$this->assertSame( 'TestWiki', $payload['username'] );
	}

	public function testAvatarUrlIsIncludedWhenConfigured(): void {
		$payload = $this->makeFormatter( [ 'PASystemBotAvatarUrl' => 'https://img.example/bot.png' ] )
			->build( $this->makeEditParams() );

		$this->assertSame( 'https://img.example/bot.png', $payload['avatar_url'] );
	}

	public function testEmbedFormat(): void {
		// 'links' is disabled because building them requires the service
		// container (SpecialPage::getTitleFor), unavailable in unit tests.
		$payload = $this->makeFormatter( [
			'PASystemFormat'  => 'embed',
			'PASystemDisplay' => [ 'links' => false ],
		] )->build( $this->makeEditParams() );

		$this->assertSame( 'TestBot', $payload['username'] );
		$this->assertCount( 1, $payload['embeds'] );

		$embed = $payload['embeds'][0];
		$this->assertSame( '📝 Alice edited "Vault City"', $embed['title'] );
		$this->assertSame( 3447003, $embed['color'] );
		$this->assertSame( '2026-06-10T12:00:00+00:00', $embed['timestamp'] );
		$this->assertSame( 'Alice', $embed['author']['name'] );
		$this->assertStringContainsString( 'diff=200', $embed['url'] );
		$this->assertSame( 'TestBot • Public announcement system', $embed['footer']['text'] );

		$sizeField = $embed['fields'][0];
		$this->assertSame( '📐 Size', $sizeField['name'] );
		$this->assertSame( '🟢 **+50** bytes', $sizeField['value'] );

		$summaryField = $embed['fields'][1];
		$this->assertSame( '💬 Summary', $summaryField['name'] );
		$this->assertSame( 'Fixed a typo', $summaryField['value'] );
	}

	public function testEmbedFooterAndTimestampCanBeDisabled(): void {
		$payload = $this->makeFormatter( [
			'PASystemFormat'  => 'embed',
			'PASystemDisplay' => [ 'links' => false, 'footer' => false, 'timestamp' => false ],
		] )->build( $this->makeEditParams() );

		$this->assertArrayNotHasKey( 'footer', $payload['embeds'][0] );
		$this->assertArrayNotHasKey( 'timestamp', $payload['embeds'][0] );
	}

	public function testEmbedFlagsField(): void {
		$payload = $this->makeFormatter( [
			'PASystemFormat'  => 'embed',
			'PASystemDisplay' => [ 'links' => false ],
		] )->build( $this->makeEditParams( [ 'rc_minor' => 1, 'rc_bot' => 1 ] ) );

		$flagsField = $payload['embeds'][0]['fields'][1];
		$this->assertSame( '🏷️ Flags', $flagsField['name'] );
		$this->assertSame( '`m` `b`', $flagsField['value'] );
	}
}
