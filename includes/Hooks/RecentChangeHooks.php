<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PublicAnnouncementSystem\Hooks;

use Config;
use JobQueueGroup;
use MediaWiki\Extension\PublicAnnouncementSystem\Filter\ChangeFilter;
use MediaWiki\Hook\RecentChange_saveHook;
use MediaWiki\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use RecentChange;

/**
 * Handler du hook RecentChange_save.
 *
 * Appelé par MediaWiki à chaque fois qu'une ligne est ajoutée dans la table
 * recentchanges. Ce hook est void — on observe sans pouvoir annuler.
 *
 * Stratégie :
 *   1. Sortie rapide si l'extension n'est pas configurée (webhook URL vide).
 *   2. Filtrage configurable (namespaces, users, log types, bots, mineures).
 *   3. Dispatch selon $wgPASystemDeliveryMode :
 *      - 'immediate' (défaut) : DeferredUpdate POSTSEND, l'envoi se fait
 *        après la réponse HTTP à l'utilisateur, dans le même process. Latence
 *        typique : 50ms à 1s. Sans retry sur erreur.
 *      - 'job' : JobQueue, retry natif sur 5xx et 429, mais latence dépend
 *        du runner (`runJobs.php` en cron).
 *
 * **Aucun appel réseau dans le hook lui-même** : ça allongerait la durée de
 * sauvegarde des pages, ce que l'utilisateur subirait directement.
 */
class RecentChangeHooks implements RecentChange_saveHook {

	private Config $config;
	private JobQueueGroup $jobQueueGroup;
	private ChangeFilter $filter;
	private LoggerInterface $logger;

	public function __construct(
		Config $config,
		JobQueueGroup $jobQueueGroup,
		ChangeFilter $filter
	) {
		$this->config = $config;
		$this->jobQueueGroup = $jobQueueGroup;
		$this->filter = $filter;
		$this->logger = LoggerFactory::getInstance( 'PublicAnnouncementSystem' );
	}

	/**
	 * @param RecentChange $recentChange
	 * @return void
	 */
	public function onRecentChange_save( $recentChange ): void {
		// Extension désactivée si aucun webhook
		$webhookUrl = $this->config->get( 'PASystemWebhookUrl' );
		if ( !$webhookUrl ) {
			return;
		}

		// Filtrage configurable (namespaces, users, log types, bots, minor, diff size)
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

		// Sérialisation minimale du RecentChange pour passer au formatter / job.
		// On embarque uniquement les attributs nécessaires.
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
	 * Mode "immediate" : DeferredUpdate POSTSEND. L'envoi a lieu juste après
	 * que MediaWiki ait renvoyé la réponse HTTP à l'utilisateur, dans le même
	 * process PHP. Latence : 50ms à 1s typiquement. Inconvénient : si le
	 * process est tué (timeout PHP, crash) ou si Discord répond mal, on perd
	 * la notification (pas de retry).
	 *
	 * On wrap dans un try/catch défensif pour ne JAMAIS faire planter
	 * MediaWiki post-send même si Discord est cassé.
	 */
	private function dispatchImmediate( $title, array $params ): void {
		\MediaWiki\Deferred\DeferredUpdates::addCallableUpdate(
			static function () use ( $params ): void {
				$logger = LoggerFactory::getInstance( 'PublicAnnouncementSystem' );
				try {
					$services = \MediaWiki\MediaWikiServices::getInstance();
					$formatter = $services->getService( 'PASystem.DiscordEmbedFormatter' );
					$notifier  = $services->getService( 'PASystem.DiscordNotifier' );
					$payload   = $formatter->build( $params );
					$notifier->send( $payload );
				} catch ( \MediaWiki\Extension\PublicAnnouncementSystem\Notifier\RateLimitException $rl ) {
					// Rate limit en mode immédiat → on dégrade vers Job pour retry
					$logger->info( 'Immediate mode hit rate limit, falling back to job queue', [
						'rc_id'       => $params['rc_id'] ?? null,
						'retry_after' => $rl->getRetryAfter(),
					] );
					$job = new \MediaWiki\Extension\PublicAnnouncementSystem\Job\DiscordNotifyJob(
						\Title::newMainPage(),
						$params + [ 'jobReleaseTimestamp' => time() + (int)ceil( $rl->getRetryAfter() ) ]
					);
					\MediaWiki\MediaWikiServices::getInstance()
						->getJobQueueGroup()
						->push( $job );
				} catch ( \Throwable $e ) {
					$logger->warning( 'Immediate Discord notify failed (no retry)', [
						'rc_id' => $params['rc_id'] ?? null,
						'error' => $e->getMessage(),
					] );
				}
			},
			\MediaWiki\Deferred\DeferredUpdates::POSTSEND
		);
	}

	/**
	 * Mode "job" : passe par la JobQueue, retry automatique sur 5xx ou 429
	 * grâce au mécanisme natif de MediaWiki. Latence dépend du JobRunner.
	 */
	private function dispatchJob( $title, array $params ): void {
		$job = new \MediaWiki\Extension\PublicAnnouncementSystem\Job\DiscordNotifyJob(
			$title,
			$params
		);
		$this->jobQueueGroup->lazyPush( $job );
	}
}
