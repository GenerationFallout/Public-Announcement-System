<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PublicAnnouncementSystem\Special;

use MediaWiki\Config\Config;
use MediaWiki\Extension\PublicAnnouncementSystem\Notifier\DiscordNotifier;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;

/**
 * Special:PASystemTest — sends a test message to the webhook.
 *
 * Admin page restricted to the `pasystem-admin` right (sysop by default).
 * Convenient to validate the install and the Discord rendering without
 * waiting for a real wiki change.
 */
class SpecialPASystemTest extends SpecialPage {

	private Config $config;
	private DiscordNotifier $notifier;

	public function __construct(
		Config $config,
		DiscordNotifier $notifier
	) {
		parent::__construct( 'PASystemTest', 'pasystem-admin' );
		$this->config = $config;
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
		$botName = (string)$this->config->get( 'PASystemBotName' );
		if ( $botName === '' ) {
			$botName = (string)$this->config->get( 'Sitename' );
		}

		// Content language: the Discord channel audience is the wiki
		// community, not the individual admin running the test.
		$content = $this->msg(
			'pasystem-test-message',
			$this->getUser()->getName(),
			$this->config->get( 'Sitename' )
		)->inContentLanguage()->text();

		$payload = [
			'username'         => $botName,
			'content'          => $content,
			// 4 = SUPPRESS_EMBEDS
			'flags'            => 4,
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
