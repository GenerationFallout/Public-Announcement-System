<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PublicAnnouncementSystem\Notifier;

use RuntimeException;

/**
 * Thrown by DiscordNotifier when Discord returns an HTTP 429.
 * Lets the Job recover the recommended waiting delay.
 */
class RateLimitException extends RuntimeException {

	private float $retryAfter;

	public function __construct( float $retryAfter ) {
		parent::__construct(
			sprintf( 'Discord rate limit hit, retry after %.2fs', $retryAfter )
		);
		$this->retryAfter = $retryAfter;
	}

	public function getRetryAfter(): float {
		return $this->retryAfter;
	}
}
