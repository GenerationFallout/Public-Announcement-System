<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PublicAnnouncementSystem\Job;

use Job;
use MediaWiki\Extension\PublicAnnouncementSystem\Formatter\DiscordEmbedFormatter;
use MediaWiki\Extension\PublicAnnouncementSystem\Notifier\DiscordNotifier;
use MediaWiki\Extension\PublicAnnouncementSystem\Notifier\RateLimitException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * Background job sending a Discord notification from a captured
 * RecentChange.
 *
 * Architecture:
 *   - The RecentChange_save hook serializes the RecentChange attributes
 *     into the Job params (string|int|null only, to survive the JSON
 *     serialization used by some JobQueue backends).
 *   - The JobQueue runner pops the Job (`runJobs.php` cron, JobRunner, or
 *     post-send deferred updates when $wgJobRunRate > 0).
 *   - The Discord payload is rebuilt and the webhook is called.
 *
 * Why not inject "services" through JobClasses?
 *   It would impose a constructor of the form
 *     __construct( ...$services, Title $title, array $params )
 *   incompatible with the manual instantiation at push time
 *   (`new DiscordNotifyJob( $title, $params )`). Fetching the services in
 *   run() through MediaWikiServices is the most stable pattern and the most
 *   used one in MediaWiki core.
 *
 * Error strategy:
 *   - 429 (rate limit) → requeue an identical Job with a
 *     `jobReleaseTimestamp` delay matching Discord's Retry-After.
 *   - 5xx → return false + setLastError, MediaWiki retries automatically
 *     according to $wgJobBackoffThrottling.
 *   - 4xx → log and return false (permanent error, will not be requeued).
 */
class DiscordNotifyJob extends Job {

	public const COMMAND = 'PASystemDiscordNotify';

	public function __construct( Title $title, array $params ) {
		parent::__construct( self::COMMAND, $title, $params );
		// Lets the queue deduplicate identical notifications (same rc_id)
		// if the hook fires twice (rare but possible in some replication
		// scenarios).
		$this->removeDuplicates = true;
	}

	public function run(): bool {
		$logger = LoggerFactory::getInstance( 'PublicAnnouncementSystem' );

		$services = MediaWikiServices::getInstance();
		/** @var DiscordEmbedFormatter $formatter */
		$formatter = $services->getService( 'PASystem.DiscordEmbedFormatter' );
		/** @var DiscordNotifier $notifier */
		$notifier = $services->getService( 'PASystem.DiscordNotifier' );

		try {
			$payload = $formatter->build( $this->params );
			$notifier->send( $payload );
			return true;
		} catch ( RateLimitException $rl ) {
			$retryAfter = (int)ceil( $rl->getRetryAfter() );
			$logger->info( 'Discord rate limit, requeuing', [
				'rc_id'       => $this->params['rc_id'] ?? null,
				'retry_after' => $retryAfter,
			] );
			$this->requeueWithDelay( $retryAfter );
			// This job is considered "successful" from MediaWiki's point of
			// view since a new delayed job was already pushed.
			return true;
		} catch ( \Throwable $e ) {
			$logger->warning( 'Discord notify failed', [
				'rc_id' => $this->params['rc_id'] ?? null,
				'error' => $e->getMessage(),
			] );
			$this->setLastError( $e->getMessage() );
			return false;
		}
	}

	/**
	 * Reschedules an identical Job with a delay (rate limit case).
	 *
	 * Limited to 5 consecutive rate-limit retries to avoid an infinite loop
	 * when the webhook is durably saturated or broken.
	 */
	private function requeueWithDelay( int $delaySeconds ): void {
		$params = $this->params;
		$attempts = (int)( $params['_rl_attempts'] ?? 0 ) + 1;
		if ( $attempts > 5 ) {
			LoggerFactory::getInstance( 'PublicAnnouncementSystem' )->error(
				'Discord rate limit: too many retries, dropping notification',
				[ 'rc_id' => $params['rc_id'] ?? null ]
			);
			return;
		}

		$params['_rl_attempts'] = $attempts;

		$jobQueueGroup = MediaWikiServices::getInstance()->getJobQueueGroup();
		// Not all JobQueue backends support delayed jobs (the default DB
		// queue does not). Without the guard, push() would throw and the
		// notification would be lost; pushing without a delay just means the
		// next attempt may hit the rate limit again (capped at 5 attempts).
		if ( $jobQueueGroup->get( self::COMMAND )->delayedJobsEnabled() ) {
			$params['jobReleaseTimestamp'] = time() + max( 1, $delaySeconds );
		}

		$jobQueueGroup->push( new self( $this->title, $params ) );
	}
}
