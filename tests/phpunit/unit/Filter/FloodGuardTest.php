<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PublicAnnouncementSystem\Tests\Unit\Filter;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\PublicAnnouncementSystem\Filter\FloodGuard;
use MediaWikiUnitTestCase;
use Psr\Log\NullLogger;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * @covers \MediaWiki\Extension\PublicAnnouncementSystem\Filter\FloodGuard
 */
class FloodGuardTest extends MediaWikiUnitTestCase {

	private function makeGuard( int $limit ): FloodGuard {
		return new FloodGuard(
			new HashConfig( [ 'PASystemMaxPerMinute' => $limit ] ),
			new HashBagOStuff(),
			new NullLogger()
		);
	}

	public function testDisabledByDefault(): void {
		$guard = $this->makeGuard( 0 );
		for ( $i = 0; $i < 100; $i++ ) {
			$this->assertSame( FloodGuard::ALLOW, $guard->check() );
		}
	}

	public function testCapSequence(): void {
		$guard = $this->makeGuard( 2 );

		$this->assertSame( FloodGuard::ALLOW, $guard->check(), '1st announcement' );
		$this->assertSame( FloodGuard::ALLOW, $guard->check(), '2nd announcement' );
		$this->assertSame( FloodGuard::NOTIFY, $guard->check(), '3rd crosses the cap' );
		$this->assertSame( FloodGuard::DROP, $guard->check(), '4th is dropped' );
		$this->assertSame( FloodGuard::DROP, $guard->check(), '5th is dropped' );
	}
}
