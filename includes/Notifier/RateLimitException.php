<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PublicAnnouncementSystem\Notifier;

use RuntimeException;

/**
 * Levée par le DiscordNotifier quand Discord retourne un HTTP 429.
 * Permet au Job de récupérer le délai d'attente recommandé.
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
