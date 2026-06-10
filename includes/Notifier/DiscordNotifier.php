<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PublicAnnouncementSystem\Notifier;

use MediaWiki\Config\Config;
use MediaWiki\Http\HttpRequestFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Sends a Discord payload through an HTTPS webhook.
 *
 * Handles:
 *   - The Discord rate limit (HTTP 429 → throws RateLimitException, the Job
 *     reschedules for later)
 *   - Server errors (5xx → throw, the Job retries)
 *   - Permanent client errors (4xx other than 429 → throw, but the Job is
 *     not meant to retry forever)
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
	 * Sends the payload to the webhook configured for an action kind.
	 *
	 * $wgPASystemWebhookRoutes maps action kinds ('edit', 'delete',
	 * 'block', …, plus 'flood' for flood notices) to dedicated webhook
	 * URLs; kinds without a route fall back to $wgPASystemWebhookUrl.
	 * This allows e.g. moderation actions to land in an admin channel
	 * while regular edits go to a public one.
	 *
	 * @param string $kind Action kind (see DiscordEmbedFormatter::getActionKind)
	 * @param array $payload Discord payload ready to be json_encoded.
	 * @return void
	 * @throws RuntimeException On permanent failure
	 * @throws RateLimitException On 429 (retry possible)
	 */
	public function sendForKind( string $kind, array $payload ): void {
		$routes = $this->config->get( 'PASystemWebhookRoutes' );
		$url = is_array( $routes ) && !empty( $routes[ $kind ] )
			? (string)$routes[ $kind ]
			: null;
		$this->send( $payload, $url );
	}

	/**
	 * Sends the payload to the configured webhook.
	 *
	 * @param array $payload Discord payload ready to be json_encoded.
	 * @param string|null $overrideUrl When provided, overrides the configured URL (route or test).
	 * @return void
	 * @throws RuntimeException On permanent failure
	 * @throws RateLimitException On 429 (retry possible)
	 */
	public function send( array $payload, ?string $overrideUrl = null ): void {
		$url = $overrideUrl ?: $this->config->get( 'PASystemWebhookUrl' );
		if ( !$url ) {
			throw new RuntimeException( 'PASystemWebhookUrl is not configured.' );
		}
		// Webhook URLs embed a secret token: never send it in clear text.
		if ( !str_starts_with( $url, 'https://' ) ) {
			throw new RuntimeException( 'PASystemWebhookUrl must be an HTTPS URL.' );
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

		$request->execute();
		$httpCode = (int)$request->getStatus();

		// 2xx → success
		if ( $httpCode >= 200 && $httpCode < 300 ) {
			if ( $this->config->get( 'PASystemDebug' ) ) {
				$this->logger->debug( 'Discord notify OK', [ 'http' => $httpCode ] );
			}
			return;
		}

		$responseBody = $request->getContent();

		// 429 → rate limit. Discord sends a Retry-After (seconds, sometimes float).
		if ( $httpCode === 429 ) {
			$retryAfter = $this->parseRetryAfter( $request, $responseBody );
			$this->logger->info( 'Discord rate limit hit', [
				'retry_after' => $retryAfter,
				'body'        => substr( $responseBody, 0, 200 ),
			] );
			throw new RateLimitException( $retryAfter );
		}

		// 5xx → server error, retry makes sense
		if ( $httpCode >= 500 ) {
			$this->logger->warning( 'Discord server error', [
				'http' => $httpCode,
				'body' => substr( $responseBody, 0, 200 ),
			] );
			throw new RuntimeException( "Discord HTTP $httpCode (server error)" );
		}

		// 4xx → permanent error, log and throw
		$this->logger->error( 'Discord client error', [
			'http'    => $httpCode,
			'body'    => substr( $responseBody, 0, 500 ),
			'payload' => substr( $body, 0, 500 ),
		] );
		throw new RuntimeException( "Discord HTTP $httpCode: " . substr( $responseBody, 0, 200 ) );
	}

	/**
	 * @param \MWHttpRequest $request
	 * @param string $responseBody
	 * @return float Recommended delay in seconds before retrying
	 */
	private function parseRetryAfter( $request, string $responseBody ): float {
		// Discord sends the delay both in the JSON body AND the Retry-After header
		$decoded = json_decode( $responseBody, true );
		if ( is_array( $decoded ) && isset( $decoded['retry_after'] ) ) {
			return (float)$decoded['retry_after'];
		}
		$header = $request->getResponseHeader( 'Retry-After' );
		if ( $header !== null ) {
			return (float)$header;
		}
		// Default to 5s
		return 5.0;
	}
}
