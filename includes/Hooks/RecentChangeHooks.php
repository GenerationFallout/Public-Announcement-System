<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PublicAnnouncementSystem\Hooks;

use JobQueueGroup;
use MediaWiki\Config\Config;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\PublicAnnouncementSystem\Filter\ChangeFilter;
use MediaWiki\Extension\PublicAnnouncementSystem\Filter\FloodGuard;
use MediaWiki\Extension\PublicAnnouncementSystem\Job\DiscordNotifyJob;
use MediaWiki\Extension\PublicAnnouncementSystem\Notifier\RateLimitException;
use MediaWiki\Hook\RecentChange_saveHook;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Psr\Log\LoggerInterface;
use RecentChange;

/**
 * Handler for the RecentChange_save hook.
 *
 * Called by MediaWiki each time a row is added to the recentchanges table.
 * This hook is void — we observe without being able to abort.
 *
 * Strategy:
 *   1. Fast exit when the extension is not configured (empty webhook URL).
 *   2. Configurable filtering (namespaces, users, log types, bots, minor).
 *   3. Dispatch according to $wgPASystemDeliveryMode:
 *      - 'immediate' (default): POSTSEND DeferredUpdate, the call happens
 *        after the HTTP response was sent to the user, in the same process.
 *        Typical latency: 50ms to 1s. No retry on failure.
 *      - 'job': JobQueue, native retries on 5xx and 429, but latency
 *        depends on the runner (`runJobs.php` cron).
 *
 * **No network call inside the hook itself**: it would lengthen the page
 * save duration, which the user would directly experience.
 */
class RecentChangeHooks implements RecentChange_saveHook {

	private Config $config;
	private JobQueueGroup $jobQueueGroup;
	private ChangeFilter $filter;
	private FloodGuard $floodGuard;
	private LoggerInterface $logger;

	public function __construct(
		Config $config,
		JobQueueGroup $jobQueueGroup,
		ChangeFilter $filter,
		FloodGuard $floodGuard
	) {
		$this->config = $config;
		$this->jobQueueGroup = $jobQueueGroup;
		$this->filter = $filter;
		$this->floodGuard = $floodGuard;
		$this->logger = LoggerFactory::getInstance( 'PublicAnnouncementSystem' );
	}

	/**
	 * @param RecentChange $recentChange
	 * @return void
	 */
	public function onRecentChange_save( $recentChange ): void {
		// Extension disabled when no webhook is configured
		$webhookUrl = $this->config->get( 'PASystemWebhookUrl' );
		if ( !$webhookUrl ) {
			return;
		}

		// Configurable filtering (namespaces, users, log types, bots, minor, diff size)
		$decision = $this->filter->shouldNotify( $recentChange );
		if ( !$decision->isAllowed() ) {
			if ( $this->config->get( 'PASystemDebug' ) ) {
				$this->logger->debug( 'RC filtered out', [
					'rc_id'     => $recentChange->getAttribute( 'rc_id' ),
					'rc_title'  => $recentChange->getAttribute( 'rc_title' ),
					'rc_user'   => $recentChange->getAttribute( 'rc_user_text' ),
					'reason'    => $decision->getReason(),
				] );
			}
			return;
		}

		// Per-minute cap ($wgPASystemMaxPerMinute): when crossed, a single
		// flood notice replaces the announcement; further changes in the
		// window are dropped silently.
		$verdict = $this->floodGuard->check();
		if ( $verdict === FloodGuard::DROP ) {
			if ( $this->config->get( 'PASystemDebug' ) ) {
				$this->logger->debug( 'RC dropped by flood guard', [
					'rc_id' => $recentChange->getAttribute( 'rc_id' ),
				] );
			}
			return;
		}
		if ( $verdict === FloodGuard::NOTIFY ) {
			$params = [
				'_flood_notice' => 1,
				'rc_id'         => (int)$recentChange->getAttribute( 'rc_id' ),
			];
			$title = $recentChange->getTitle();
			if ( (string)$this->config->get( 'PASystemDeliveryMode' ) === 'immediate' ) {
				$this->dispatchImmediate( $title, $params );
			} else {
				$this->dispatchJob( $title, $params );
			}
			return;
		}

		// Minimal serialization of the RecentChange for the formatter / job.
		// Only the needed attributes are embedded.
		$params = [
			'rc_id'           => (int)$recentChange->getAttribute( 'rc_id' ),
			'rc_type'         => (int)$recentChange->getAttribute( 'rc_type' ),
			'rc_timestamp'    => (string)$recentChange->getAttribute( 'rc_timestamp' ),
			'rc_namespace'    => (int)$recentChange->getAttribute( 'rc_namespace' ),
			'rc_title'        => (string)$recentChange->getAttribute( 'rc_title' ),
			'rc_user'         => (int)$recentChange->getAttribute( 'rc_user' ),
			'rc_user_text'    => (string)$recentChange->getAttribute( 'rc_user_text' ),
			'rc_comment'      => (string)( $recentChange->getAttribute( 'rc_comment_text' )
											?? $recentChange->getAttribute( 'rc_comment' )
											?? '' ),
			'rc_minor'        => (int)$recentChange->getAttribute( 'rc_minor' ),
			'rc_bot'          => (int)$recentChange->getAttribute( 'rc_bot' ),
			'rc_this_oldid'   => (int)$recentChange->getAttribute( 'rc_this_oldid' ),
			'rc_last_oldid'   => (int)$recentChange->getAttribute( 'rc_last_oldid' ),
			'rc_old_len'      => (int)$recentChange->getAttribute( 'rc_old_len' ),
			'rc_new_len'      => (int)$recentChange->getAttribute( 'rc_new_len' ),
			'rc_log_type'     => (string)( $recentChange->getAttribute( 'rc_log_type' ) ?? '' ),
			'rc_log_action'   => (string)( $recentChange->getAttribute( 'rc_log_action' ) ?? '' ),
			'rc_logid'        => (int)$recentChange->getAttribute( 'rc_logid' ),
			'rc_patrolled'    => (int)$recentChange->getAttribute( 'rc_patrolled' ),
			'rc_params'       => (string)( $recentChange->getAttribute( 'rc_params' ) ?? '' ),
		];

		$mode = (string)$this->config->get( 'PASystemDeliveryMode' );
		$title = $recentChange->getTitle();

		if ( $mode === 'immediate' ) {
			$this->dispatchImmediate( $title, $params );
		} else {
			$this->dispatchJob( $title, $params );
		}

		if ( $this->config->get( 'PASystemDebug' ) ) {
			$this->logger->debug( 'RC dispatched for Discord', [
				'rc_id'    => $params['rc_id'],
				'rc_type'  => $params['rc_type'],
				'rc_title' => $params['rc_title'],
				'mode'     => $mode,
			] );
		}
	}

	/**
	 * "immediate" mode: POSTSEND DeferredUpdate. The call happens right
	 * after MediaWiki sent the HTTP response to the user, in the same PHP
	 * process. Latency: typically 50ms to 1s. Downside: if the process is
	 * killed (PHP timeout, crash) or Discord misbehaves, the notification
	 * is lost (no retry).
	 *
	 * Wrapped in a defensive try/catch so MediaWiki NEVER crashes post-send
	 * even when Discord is broken.
	 *
	 * @param Title $title
	 * @param array $params
	 */
	private function dispatchImmediate( Title $title, array $params ): void {
		DeferredUpdates::addCallableUpdate(
			static function () use ( $title, $params ): void {
				$logger = LoggerFactory::getInstance( 'PublicAnnouncementSystem' );
				try {
					$services = MediaWikiServices::getInstance();
					$formatter = $services->getService( 'PASystem.DiscordEmbedFormatter' );
					$notifier  = $services->getService( 'PASystem.DiscordNotifier' );
					$payload   = $formatter->build( $params );
					$notifier->sendForKind( $formatter->getActionKind( $params ), $payload );
				} catch ( RateLimitException $rl ) {
					// Rate limit in immediate mode → degrade to a Job for retry
					$logger->info( 'Immediate mode hit rate limit, falling back to job queue', [
						'rc_id'       => $params['rc_id'] ?? null,
						'retry_after' => $rl->getRetryAfter(),
					] );
					$jobQueueGroup = MediaWikiServices::getInstance()->getJobQueueGroup();
					// Delayed jobs are not supported by every backend (the
					// default DB queue rejects jobReleaseTimestamp).
					if ( $jobQueueGroup->get( DiscordNotifyJob::COMMAND )->delayedJobsEnabled() ) {
						$params['jobReleaseTimestamp'] = time() + (int)ceil( $rl->getRetryAfter() );
					}
					$jobQueueGroup->push( new DiscordNotifyJob( $title, $params ) );
				} catch ( \Throwable $e ) {
					$logger->warning( 'Immediate Discord notify failed (no retry)', [
						'rc_id' => $params['rc_id'] ?? null,
						'error' => $e->getMessage(),
					] );
				}
			},
			DeferredUpdates::POSTSEND
		);
	}

	/**
	 * "job" mode: goes through the JobQueue, automatic retries on 5xx or
	 * 429 thanks to MediaWiki's native mechanism. Latency depends on the
	 * JobRunner.
	 *
	 * @param Title $title
	 * @param array $params
	 */
	private function dispatchJob( Title $title, array $params ): void {
		$job = new DiscordNotifyJob( $title, $params );
		$this->jobQueueGroup->lazyPush( $job );
	}
}
