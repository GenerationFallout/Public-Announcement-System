<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PublicAnnouncementSystem\Tests\Unit\Filter;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\PublicAnnouncementSystem\Filter\ChangeFilter;
use MediaWikiUnitTestCase;
use Psr\Log\NullLogger;
use RecentChange;

/**
 * @covers \MediaWiki\Extension\PublicAnnouncementSystem\Filter\ChangeFilter
 */
class ChangeFilterTest extends MediaWikiUnitTestCase {

	private const DEFAULT_CONFIG = [
		'PASystemNotifyBots'            => false,
		'PASystemNotifyMinor'           => true,
		'PASystemNotifyCategorization'  => false,
		'PASystemNotifyExternal'        => false,
		'PASystemIncludedNamespaces'    => [],
		'PASystemExcludedNamespaces'    => [],
		'PASystemExcludedUsers'         => [],
		'PASystemExcludedLogTypes'      => [],
		'PASystemMinDiffSize'           => 0,
	];

	private function makeFilter( array $configOverrides = [] ): ChangeFilter {
		return new ChangeFilter(
			new HashConfig( $configOverrides + self::DEFAULT_CONFIG ),
			new NullLogger()
		);
	}

	private function makeRecentChange( array $attributes ): RecentChange {
		$attributes += [
			'rc_bot'       => 0,
			'rc_minor'     => 0,
			'rc_namespace' => 0,
			'rc_user_text' => 'Alice',
			'rc_log_type'  => null,
			'rc_type'      => RC_EDIT,
			'rc_old_len'   => 0,
			'rc_new_len'   => 0,
		];
		$rc = $this->createMock( RecentChange::class );
		$rc->method( 'getAttribute' )->willReturnCallback(
			static fn ( $name ) => $attributes[ $name ] ?? null
		);
		return $rc;
	}

	public function testAcceptsRegularEdit(): void {
		$decision = $this->makeFilter()->shouldNotify( $this->makeRecentChange( [] ) );
		$this->assertTrue( $decision->isAllowed() );
	}

	public function testRejectsBotEditByDefault(): void {
		$decision = $this->makeFilter()->shouldNotify(
			$this->makeRecentChange( [ 'rc_bot' => 1 ] )
		);
		$this->assertFalse( $decision->isAllowed() );
		$this->assertSame( 'bot edit', $decision->getReason() );
	}

	public function testAcceptsBotEditWhenEnabled(): void {
		$decision = $this->makeFilter( [ 'PASystemNotifyBots' => true ] )->shouldNotify(
			$this->makeRecentChange( [ 'rc_bot' => 1 ] )
		);
		$this->assertTrue( $decision->isAllowed() );
	}

	public function testRejectsMinorEditWhenDisabled(): void {
		$decision = $this->makeFilter( [ 'PASystemNotifyMinor' => false ] )->shouldNotify(
			$this->makeRecentChange( [ 'rc_minor' => 1 ] )
		);
		$this->assertFalse( $decision->isAllowed() );
		$this->assertSame( 'minor edit', $decision->getReason() );
	}

	public function testRejectsCategorizationByDefault(): void {
		$decision = $this->makeFilter()->shouldNotify(
			$this->makeRecentChange( [ 'rc_type' => RC_CATEGORIZE ] )
		);
		$this->assertFalse( $decision->isAllowed() );
		$this->assertSame( 'categorization change', $decision->getReason() );
	}

	public function testAcceptsCategorizationWhenEnabled(): void {
		$decision = $this->makeFilter( [ 'PASystemNotifyCategorization' => true ] )->shouldNotify(
			$this->makeRecentChange( [ 'rc_type' => RC_CATEGORIZE ] )
		);
		$this->assertTrue( $decision->isAllowed() );
	}

	public function testRejectsExternalChangeByDefault(): void {
		$decision = $this->makeFilter()->shouldNotify(
			$this->makeRecentChange( [ 'rc_type' => RC_EXTERNAL ] )
		);
		$this->assertFalse( $decision->isAllowed() );
		$this->assertSame( 'external change', $decision->getReason() );
	}

	public function testNamespaceAllowlist(): void {
		$filter = $this->makeFilter( [ 'PASystemIncludedNamespaces' => [ 0, 6 ] ] );

		$this->assertTrue(
			$filter->shouldNotify( $this->makeRecentChange( [ 'rc_namespace' => 0 ] ) )->isAllowed()
		);
		$decision = $filter->shouldNotify( $this->makeRecentChange( [ 'rc_namespace' => 3 ] ) );
		$this->assertFalse( $decision->isAllowed() );
		$this->assertSame( 'namespace not in allowlist (3)', $decision->getReason() );
	}

	public function testRejectsExcludedNamespace(): void {
		$decision = $this->makeFilter( [ 'PASystemExcludedNamespaces' => [ 2, 3 ] ] )->shouldNotify(
			$this->makeRecentChange( [ 'rc_namespace' => 3 ] )
		);
		$this->assertFalse( $decision->isAllowed() );
		$this->assertSame( 'excluded namespace (3)', $decision->getReason() );
	}

	public function testRejectsExcludedUser(): void {
		$decision = $this->makeFilter( [ 'PASystemExcludedUsers' => [ 'Alice' ] ] )->shouldNotify(
			$this->makeRecentChange( [] )
		);
		$this->assertFalse( $decision->isAllowed() );
		$this->assertSame( 'excluded user (Alice)', $decision->getReason() );
	}

	public function testRejectsExcludedLogType(): void {
		$decision = $this->makeFilter( [ 'PASystemExcludedLogTypes' => [ 'patrol' ] ] )->shouldNotify(
			$this->makeRecentChange( [ 'rc_type' => RC_LOG, 'rc_log_type' => 'patrol' ] )
		);
		$this->assertFalse( $decision->isAllowed() );
		$this->assertSame( 'excluded log type (patrol)', $decision->getReason() );
	}

	public function testRejectsTooSmallDiff(): void {
		$decision = $this->makeFilter( [ 'PASystemMinDiffSize' => 10 ] )->shouldNotify(
			$this->makeRecentChange( [ 'rc_old_len' => 100, 'rc_new_len' => 105 ] )
		);
		$this->assertFalse( $decision->isAllowed() );
		$this->assertSame( 'diff too small (5 < 10)', $decision->getReason() );
	}

	public function testAcceptsLargeEnoughDiff(): void {
		$decision = $this->makeFilter( [ 'PASystemMinDiffSize' => 10 ] )->shouldNotify(
			$this->makeRecentChange( [ 'rc_old_len' => 100, 'rc_new_len' => 90 ] )
		);
		$this->assertTrue( $decision->isAllowed() );
	}

	public function testMinDiffSizeDoesNotApplyToLogs(): void {
		$decision = $this->makeFilter( [ 'PASystemMinDiffSize' => 1000 ] )->shouldNotify(
			$this->makeRecentChange( [ 'rc_type' => RC_LOG, 'rc_log_type' => 'delete' ] )
		);
		$this->assertTrue( $decision->isAllowed() );
	}
}
