<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PublicAnnouncementSystem\Filter;

/**
 * Binary decision of the filter: notify or not, with a textual reason for
 * debugging.
 */
class FilterDecision {

	private bool $allowed;
	private string $reason;

	private function __construct( bool $allowed, string $reason ) {
		$this->allowed = $allowed;
		$this->reason = $reason;
	}

	public static function accept(): self {
		return new self( true, '' );
	}

	public static function reject( string $reason ): self {
		return new self( false, $reason );
	}

	public function isAllowed(): bool {
		return $this->allowed;
	}

	public function getReason(): string {
		return $this->reason;
	}
}
