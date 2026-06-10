<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PublicAnnouncementSystem\Notifier;

use Config;
use MediaWiki\Http\HttpRequestFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Envoie un payload Discord via webhook HTTPS.
 *
 * Gère :
 *   - Le rate limit Discord (HTTP 429 → throw RateLimitException, le Job
 *     reprogramme à plus tard)
 *   - Les erreurs serveur (5xx → throw, le Job retry)
 *   - Les erreurs client définitives (4xx hors 429 → throw, mais le Job
 *     n'a pas vocation à retry indéfiniment)
 */
class DiscordNotifier {

	private const HTTP_TIMEOUT = 10;

	private Config $config;
	private HttpRequestFactory $httpRequestFactory;
	private LoggerInterface $logger;

	public function __construct(
		Config $config,
		HttpRequestFactory $httpRequestFactory,
		LoggerInterface $logger
	) {
		$this->config = $config;
		$this->httpRequestFactory = $httpRequestFactory;
		$this->logger = $logger;
	}

	/**
	 * Envoie le payload au webhook configuré.
	 *
	 * @param array $payload Payload Discord prêt à json_encoder.
	 * @param string|null $overrideUrl Si fourni, écrase l'URL de config (pour les tests).
	 * @return void
	 * @throws RuntimeException En cas d'échec définitif
	 * @throws RateLimitException En cas de 429 (retry possible)
	 */
	public function send( array $payload, ?string $overrideUrl = null ): void {
		$url = $overrideUrl ?: $this->config->get( 'PASystemWebhookUrl' );
		if ( !$url ) {
			throw new RuntimeException( 'PASystemWebhookUrl is not configured.' );
		}

		$body = json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( $body === false ) {
			throw new RuntimeException( 'Failed to encode Discord payload: ' . json_last_error_msg() );
		}

		$request = $this->httpRequestFactory->create( $url, [
			'method'   => 'POST',
			'timeout'  => self::HTTP_TIMEOUT,
			'postData' => $body,
		], __METHOD__ );

		$request->setHeader( 'Content-Type', 'application/json' );

		$status = $request->execute();
		$httpCode = (int)$request->getStatus();

		// 2xx → succès
		if ( $httpCode >= 200 && $httpCode < 300 ) {
			if ( $this->config->get( 'PASystemDebug' ) ) {
				$this->logger->debug( 'Discord notify OK', [ 'http' => $httpCode ] );
			}
			return;
		}

		$responseBody = $request->getContent();

		// 429 → rate limit. Discord renvoie un Retry-After (en secondes, parfois flottant).
		if ( $httpCode === 429 ) {
			$retryAfter = $this->parseRetryAfter( $request, $responseBody );
			$this->logger->info( 'Discord rate limit hit', [
				'retry_after' => $retryAfter,
				'body'        => substr( $responseBody, 0, 200 ),
			] );
			throw new RateLimitException( $retryAfter );
		}

		// 5xx → erreur serveur, retry pertinent
		if ( $httpCode >= 500 ) {
			$this->logger->warning( 'Discord server error', [
				'http' => $httpCode,
				'body' => substr( $responseBody, 0, 200 ),
			] );
			throw new RuntimeException( "Discord HTTP $httpCode (server error)" );
		}

		// 4xx → erreur permanente, on log et on lève
		$this->logger->error( 'Discord client error', [
			'http'    => $httpCode,
			'body'    => substr( $responseBody, 0, 500 ),
			'payload' => substr( $body, 0, 500 ),
		] );
		throw new RuntimeException( "Discord HTTP $httpCode: " . substr( $responseBody, 0, 200 ) );
	}

	private function parseRetryAfter( $request, string $responseBody ): float {
		// Discord renvoie le délai dans le body JSON ET dans le header Retry-After
		$decoded = json_decode( $responseBody, true );
		if ( is_array( $decoded ) && isset( $decoded['retry_after'] ) ) {
			return (float)$decoded['retry_after'];
		}
		$header = $request->getResponseHeader( 'Retry-After' );
		if ( $header !== null ) {
			return (float)$header;
		}
		// Par défaut, 5s
		return 5.0;
	}
}
