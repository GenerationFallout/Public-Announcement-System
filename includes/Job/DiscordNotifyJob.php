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
 * Job exécuté en arrière-plan pour envoyer une notification Discord à partir
 * d'un RecentChange capturé.
 *
 * Architecture :
 *   - Le hook RecentChange_save sérialise les attributs du RecentChange dans
 *     les params du Job (string|int|null uniquement, pour passer la
 *     serialization JSON utilisée par certains backends de JobQueue).
 *   - Le runner JobQueue dépile le Job (cron `runJobs.php`, JobRunner, ou
 *     les post-send deferred updates avec $wgJobRunRate > 0).
 *   - On reconstruit le payload Discord et on appelle le webhook.
 *
 * Pourquoi pas l'injection via "services" dans JobClasses ?
 *   Cela imposerait un constructeur de la forme
 *     __construct( ...$services, Title $title, array $params )
 *   incompatible avec l'instanciation manuelle au moment du push
 *   (`new DiscordNotifyJob( $title, $params )`). Récupérer les services
 *   dans run() via MediaWikiServices est le pattern le plus stable et
 *   le plus utilisé dans le code Core de MW 1.45.
 *
 * Stratégie d'erreur :
 *   - 429 (rate limit) → on requeue un Job identique avec un délai
 *     `jobReleaseTimestamp` correspondant au Retry-After de Discord.
 *   - 5xx → return false + setLastError, MediaWiki retry automatiquement
 *     selon $wgJobBackoffThrottling.
 *   - 4xx → log et return false (erreur permanente, ne sera pas requeue).
 */
class DiscordNotifyJob extends Job {

	public const COMMAND = 'PASystemDiscordNotify';

	public function __construct( Title $title, array $params ) {
		parent::__construct( self::COMMAND, $title, $params );
		// Permet à la queue de dédupliquer des notifications identiques
		// (même rc_id) si le hook est appelé deux fois (rare mais possible
		// dans certains scénarios de réplication).
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
			// On considère ce job comme « réussi » du point de vue MW
			// puisqu'on a déjà repoussé un nouveau job avec délai.
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
	 * Reprogramme un Job identique avec un délai (cas du rate limit).
	 *
	 * Limité à 5 essais consécutifs sur rate limit pour éviter une boucle
	 * infinie si le webhook est durablement saturé ou cassé.
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
		$params['jobReleaseTimestamp'] = time() + max( 1, $delaySeconds );

		$newJob = new self( $this->title, $params );
		MediaWikiServices::getInstance()
			->getJobQueueGroup()
			->push( $newJob );
	}
}
