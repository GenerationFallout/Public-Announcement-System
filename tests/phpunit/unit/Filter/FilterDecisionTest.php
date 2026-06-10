<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PublicAnnouncementSystem\Tests\Unit\Filter;

use MediaWiki\Extension\PublicAnnouncementSystem\Filter\FilterDecision;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\PublicAnnouncementSystem\Filter\FilterDecision
 */
class FilterDecisionTest extends MediaWikiUnitTestCase {

	public function testAccept(): void {
		$decision = FilterDecision::accept();
		$this->assertTrue( $decision->isAllowed() );
		$this->assertSame( '', $decision->getReason() );
	}

	public function testReject(): void {
		$decision = FilterDecision::reject( 'bot edit' );
		$this->assertFalse( $decision->isAllowed() );
		$this->assertSame( 'bot edit', $decision->getReason() );
	}
}
