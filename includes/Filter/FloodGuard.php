<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PublicAnnouncementSystem\Filter;

use MediaWiki\Config\Config;
use Psr\Log\LoggerInterface;
use Wikimedia\ObjectCache\BagOStuff;

/**
 * Caps the number of announcements per minute to protect the Discord
 * channel from floods (mass imports, bot bursts, vandalism sprees…).
 *
 * Uses a fixed one-minute window counter stored in the main object stash,
 * so the cap is shared across web requests and job runners.
 *
 * Disabled when $wgPASystemMaxPerMinute is 0 (the default).
 */
class FloodGuard {

	/** The announcement may be sent. */
	public const ALLOW = 'allow';
	/** The cap was just crossed: send a single flood notice instead. */
	public const NOTIFY = 'notify';
	/** Over the cap: drop silently until the window resets. */
	public const DROP = 'drop';

	private const WINDOW_SECONDS = 60;

	private Config $config;
	private BagOStuff $cache;
	private LoggerInterface $logger;

	public function __construct( Config $config, BagOStuff $cache, LoggerInterface $logger ) {
		$this->config = $config;
		$this->cache = $cache;
		$this->logger = $logger;
	}

	/**
	 * Registers one announcement attempt and returns the verdict for it.
	 *
	 * @return string One of self::ALLOW, self::NOTIFY, self::DROP
	 */
	public function check(): string {
		$limit = (int)$this->config->get( 'PASystemMaxPerMinute' );
		if ( $limit <= 0 ) {
			return self::ALLOW;
		}

		$key = $this->cache->makeKey( 'PASystem', 'flood-window' );
		$count = $this->cache->incrWithInit( $key, self::WINDOW_SECONDS );

		if ( $count === false || $count <= $limit ) {
			return self::ALLOW;
		}

		if ( $count === $limit + 1 ) {
			$this->logger->info( 'Announcement cap reached, sending flood notice', [
				'limit' => $limit,
			] );
			return self::NOTIFY;
		}

		return self::DROP;
	}
}
