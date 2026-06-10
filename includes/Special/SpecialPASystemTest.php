<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PublicAnnouncementSystem\Special;

use Config;
use HTMLForm;
use MediaWiki\Extension\PublicAnnouncementSystem\Notifier\DiscordNotifier;
use MediaWiki\Http\HttpRequestFactory;
use SpecialPage;

/**
 * Special:PASystemTest — envoie un message de test vers le webhook.
 *
 * Page d'admin réservée au droit `pasystem-admin` (sysop par défaut).
 * Pratique pour valider l'install et le rendu Discord sans attendre une
 * vraie modification du wiki.
 */
class SpecialPASystemTest extends SpecialPage {

	private Config $config;
	private HttpRequestFactory $httpRequestFactory;
	private DiscordNotifier $notifier;

	public function __construct(
		Config $config,
		HttpRequestFactory $httpRequestFactory,
		DiscordNotifier $notifier
	) {
		parent::__construct( 'PASystemTest', 'pasystem-admin' );
		$this->config = $config;
		$this->httpRequestFactory = $httpRequestFactory;
		$this->notifier = $notifier;
	}

	public function execute( $subPage ): void {
		$this->setHeaders();
		$this->checkPermissions();
		$out = $this->getOutput();

		$webhookUrl = $this->config->get( 'PASystemWebhookUrl' );
		if ( !$webhookUrl ) {
			$out->addWikiMsg( 'pasystem-test-no-webhook' );
			return;
		}

		$out->addWikiMsg( 'pasystem-test-intro' );

		$form = HTMLForm::factory( 'ooui', [], $this->getContext() );
		$form->setSubmitTextMsg( 'pasystem-test-button' );
		$form->setSubmitCallback( [ $this, 'onSubmit' ] );
		$form->show();
	}

	public function onSubmit( array $data ) {
		$payload = [
			'username'         => $this->config->get( 'PASystemBotName' ),
			'content'          => sprintf(
				'🧪 **%s** a déclenché un test du Système d\'annonces publiques sur %s',
				$this->getUser()->getName(),
				$this->config->get( 'Sitename' )
			),
			'flags'            => 4, // SUPPRESS_EMBEDS
			'allowed_mentions' => [ 'parse' => [] ],
		];

		$avatar = $this->config->get( 'PASystemBotAvatarUrl' );
		if ( $avatar ) {
			$payload['avatar_url'] = $avatar;
		}

		try {
			$this->notifier->send( $payload );
			$this->getOutput()->addWikiMsg( 'pasystem-test-success' );
			return true;
		} catch ( \Throwable $e ) {
			return $this->msg( 'pasystem-test-error', $e->getMessage() )->text();
		}
	}

	public function getDescription() {
		return $this->msg( 'pasystem-test-title' );
	}

	protected function getGroupName(): string {
		return 'changes';
	}
}
