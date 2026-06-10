<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PublicAnnouncementSystem\Special;

use MediaWiki\Config\Config;
use MediaWiki\Extension\PublicAnnouncementSystem\Config\OnWikiConfigStore;
use MediaWiki\Extension\PublicAnnouncementSystem\Formatter\DiscordEmbedFormatter;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Json\FormatJson;
use MediaWiki\SpecialPage\FormSpecialPage;

/**
 * Special:PASystemConfig — graphical configuration of the extension.
 *
 * Every non-sensitive setting can be edited here without touching
 * LocalSettings.php. Values are stored as JSON in
 * [[MediaWiki:PASystemConfig.json]] (versioned, watchable, revertable) and
 * take precedence over LocalSettings.php — see OnWikiConfig.
 *
 * The webhook URLs ($wgPASystemWebhookUrl / $wgPASystemWebhookRoutes) are
 * deliberately NOT configurable here: wiki pages are publicly readable and
 * webhook URLs embed a secret token.
 */
class SpecialPASystemConfig extends FormSpecialPage {

	/** Form field name → configuration setting name */
	private const FIELD_MAP = [
		'format'               => 'PASystemFormat',
		'deliverymode'         => 'PASystemDeliveryMode',
		'botname'              => 'PASystemBotName',
		'botavatar'            => 'PASystemBotAvatarUrl',
		'notifybots'           => 'PASystemNotifyBots',
		'notifyminor'          => 'PASystemNotifyMinor',
		'notifycategorization' => 'PASystemNotifyCategorization',
		'notifyexternal'       => 'PASystemNotifyExternal',
		'stripautosummaries'   => 'PASystemStripAutoSummaries',
		'mindiffsize'          => 'PASystemMinDiffSize',
		'maxperminute'         => 'PASystemMaxPerMinute',
		'debug'                => 'PASystemDebug',
	];

	private Config $effectiveConfig;
	private OnWikiConfigStore $store;

	public function __construct(
		Config $effectiveConfig,
		OnWikiConfigStore $store
	) {
		parent::__construct( 'PASystemConfig', 'pasystem-admin' );
		$this->effectiveConfig = $effectiveConfig;
		$this->store = $store;
	}

	/** @inheritDoc */
	protected function preHtml() {
		return $this->msg( 'pasystem-config-intro' )->parseAsBlock();
	}

	/** @inheritDoc */
	protected function getDisplayFormat() {
		return 'ooui';
	}

	/** @inheritDoc */
	protected function getFormFields() {
		$config = $this->effectiveConfig;

		$displayDefaults = [];
		$configuredDisplay = $config->get( 'PASystemDisplay' );
		$display = ( is_array( $configuredDisplay ) ? $configuredDisplay : [] )
			+ DiscordEmbedFormatter::DEFAULT_DISPLAY;
		foreach ( DiscordEmbedFormatter::DEFAULT_DISPLAY as $key => $unused ) {
			if ( $display[ $key ] ) {
				$displayDefaults[] = $key;
			}
		}

		$configuredIcons = $config->get( 'PASystemActionIcons' );
		$icons = ( is_array( $configuredIcons ) ? $configuredIcons : [] )
			+ DiscordEmbedFormatter::DEFAULT_ICONS;

		return [
			// ===== General =====
			'format' => [
				'type'             => 'select',
				'label-message'    => 'pasystem-config-format',
				'options-messages' => [
					'pasystem-config-format-line'  => 'line',
					'pasystem-config-format-embed' => 'embed',
				],
				'default'          => (string)$config->get( 'PASystemFormat' ),
				'section'          => 'pasystem-config-section-general',
			],
			'deliverymode' => [
				'type'             => 'select',
				'label-message'    => 'pasystem-config-deliverymode',
				'options-messages' => [
					'pasystem-config-deliverymode-immediate' => 'immediate',
					'pasystem-config-deliverymode-job'       => 'job',
				],
				'default'          => (string)$config->get( 'PASystemDeliveryMode' ),
				'section'          => 'pasystem-config-section-general',
			],
			'botname' => [
				'type'          => 'text',
				'label-message' => 'pasystem-config-botname',
				'help-message'  => 'pasystem-config-botname-help',
				'default'       => (string)$config->get( 'PASystemBotName' ),
				'section'       => 'pasystem-config-section-general',
			],
			'botavatar' => [
				'type'          => 'text',
				'label-message' => 'pasystem-config-botavatar',
				'default'       => (string)$config->get( 'PASystemBotAvatarUrl' ),
				'section'       => 'pasystem-config-section-general',
			],

			// ===== Filtering =====
			'notifybots' => [
				'type'          => 'check',
				'label-message' => 'pasystem-config-notifybots',
				'default'       => (bool)$config->get( 'PASystemNotifyBots' ),
				'section'       => 'pasystem-config-section-filters',
			],
			'notifyminor' => [
				'type'          => 'check',
				'label-message' => 'pasystem-config-notifyminor',
				'default'       => (bool)$config->get( 'PASystemNotifyMinor' ),
				'section'       => 'pasystem-config-section-filters',
			],
			'notifycategorization' => [
				'type'          => 'check',
				'label-message' => 'pasystem-config-notifycategorization',
				'default'       => (bool)$config->get( 'PASystemNotifyCategorization' ),
				'section'       => 'pasystem-config-section-filters',
			],
			'notifyexternal' => [
				'type'          => 'check',
				'label-message' => 'pasystem-config-notifyexternal',
				'default'       => (bool)$config->get( 'PASystemNotifyExternal' ),
				'section'       => 'pasystem-config-section-filters',
			],
			'includedns' => [
				'type'          => 'namespacesmultiselect',
				'label-message' => 'pasystem-config-includedns',
				'help-message'  => 'pasystem-config-includedns-help',
				'default'       => implode( "\n", (array)$config->get( 'PASystemIncludedNamespaces' ) ),
				'section'       => 'pasystem-config-section-filters',
			],
			'excludedns' => [
				'type'          => 'namespacesmultiselect',
				'label-message' => 'pasystem-config-excludedns',
				'default'       => implode( "\n", (array)$config->get( 'PASystemExcludedNamespaces' ) ),
				'section'       => 'pasystem-config-section-filters',
			],
			'excludedusers' => [
				'type'          => 'usersmultiselect',
				'label-message' => 'pasystem-config-excludedusers',
				'default'       => implode( "\n", (array)$config->get( 'PASystemExcludedUsers' ) ),
				'section'       => 'pasystem-config-section-filters',
			],
			'excludedlogtypes' => [
				'type'          => 'text',
				'label-message' => 'pasystem-config-excludedlogtypes',
				'help-message'  => 'pasystem-config-excludedlogtypes-help',
				'default'       => implode( ', ', (array)$config->get( 'PASystemExcludedLogTypes' ) ),
				'section'       => 'pasystem-config-section-filters',
			],
			'stripautosummaries' => [
				'type'          => 'check',
				'label-message' => 'pasystem-config-stripautosummaries',
				'default'       => (bool)$config->get( 'PASystemStripAutoSummaries' ),
				'section'       => 'pasystem-config-section-filters',
			],
			'mindiffsize' => [
				'type'          => 'int',
				'min'           => 0,
				'label-message' => 'pasystem-config-mindiffsize',
				'help-message'  => 'pasystem-config-mindiffsize-help',
				'default'       => (int)$config->get( 'PASystemMinDiffSize' ),
				'section'       => 'pasystem-config-section-filters',
			],
			'maxperminute' => [
				'type'          => 'int',
				'min'           => 0,
				'label-message' => 'pasystem-config-maxperminute',
				'help-message'  => 'pasystem-config-maxperminute-help',
				'default'       => (int)$config->get( 'PASystemMaxPerMinute' ),
				'section'       => 'pasystem-config-section-filters',
			],

			// ===== Appearance =====
			'display' => [
				'type'             => 'multiselect',
				'label-message'    => 'pasystem-config-display',
				'options-messages' => [
					'pasystem-config-display-icons'     => 'icons',
					'pasystem-config-display-delta'     => 'delta',
					'pasystem-config-display-summary'   => 'summary',
					'pasystem-config-display-difflink'  => 'diffLink',
					'pasystem-config-display-links'     => 'links',
					'pasystem-config-display-flags'     => 'flags',
					'pasystem-config-display-footer'    => 'footer',
					'pasystem-config-display-timestamp' => 'timestamp',
				],
				'default'          => $displayDefaults,
				'section'          => 'pasystem-config-section-appearance',
			],
			'actionicons' => [
				'type'                => 'textarea',
				'rows'                => 4,
				'label-message'       => 'pasystem-config-actionicons',
				'help-message'        => 'pasystem-config-actionicons-help',
				'default'             => FormatJson::encode( $icons, "\t" ),
				'validation-callback' => [ $this, 'validateJsonMap' ],
				'section'             => 'pasystem-config-section-appearance',
			],
			'embedcolors' => [
				'type'                => 'textarea',
				'rows'                => 4,
				'label-message'       => 'pasystem-config-embedcolors',
				'help-message'        => 'pasystem-config-embedcolors-help',
				'default'             => FormatJson::encode( (array)$config->get( 'PASystemEmbedColors' ), "\t" ),
				'validation-callback' => [ $this, 'validateJsonMap' ],
				'section'             => 'pasystem-config-section-appearance',
			],

			// ===== Advanced =====
			'debug' => [
				'type'          => 'check',
				'label-message' => 'pasystem-config-debug',
				'default'       => (bool)$config->get( 'PASystemDebug' ),
				'section'       => 'pasystem-config-section-advanced',
			],
		];
	}

	/**
	 * HTMLForm validation callback for the JSON textareas.
	 *
	 * @param string $value
	 * @return bool|\MediaWiki\Message\Message
	 */
	public function validateJsonMap( $value ) {
		$decoded = FormatJson::decode( (string)$value, true );
		if ( !is_array( $decoded ) ) {
			return $this->msg( 'pasystem-config-invalid-json' );
		}
		return true;
	}

	/** @inheritDoc */
	public function onSubmit( array $data ) {
		$overrides = [];
		foreach ( self::FIELD_MAP as $field => $setting ) {
			$overrides[ $setting ] = $data[ $field ];
		}

		// int fields come back as strings from HTMLForm
		$overrides['PASystemMinDiffSize'] = (int)$data['mindiffsize'];
		$overrides['PASystemMaxPerMinute'] = (int)$data['maxperminute'];

		$overrides['PASystemIncludedNamespaces'] = $this->parseIntLines( $data['includedns'] );
		$overrides['PASystemExcludedNamespaces'] = $this->parseIntLines( $data['excludedns'] );
		$overrides['PASystemExcludedUsers'] = $this->parseLines( $data['excludedusers'] );
		$overrides['PASystemExcludedLogTypes'] = $this->parseCsv( $data['excludedlogtypes'] );

		$displaySelected = (array)$data['display'];
		$displayMap = [];
		foreach ( DiscordEmbedFormatter::DEFAULT_DISPLAY as $key => $unused ) {
			$displayMap[ $key ] = in_array( $key, $displaySelected, true );
		}
		$overrides['PASystemDisplay'] = $displayMap;

		$overrides['PASystemActionIcons'] = array_map(
			'strval',
			FormatJson::decode( (string)$data['actionicons'], true ) ?: []
		);
		$overrides['PASystemEmbedColors'] = array_map(
			'intval',
			FormatJson::decode( (string)$data['embedcolors'], true ) ?: []
		);

		$this->store->saveOverrides(
			$overrides,
			$this->getUser(),
			$this->msg( 'pasystem-config-summary' )->inContentLanguage()->text()
		);

		return true;
	}

	/** @inheritDoc */
	public function onSuccess() {
		$this->getOutput()->addWikiMsg( 'pasystem-config-saved', OnWikiConfigStore::CONFIG_PAGE );
	}

	/**
	 * @param string|null $raw Newline-separated integers (namespacesmultiselect value)
	 * @return int[]
	 */
	private function parseIntLines( ?string $raw ): array {
		return array_values( array_map( 'intval', $this->parseLines( $raw ) ) );
	}

	/**
	 * @param string|null $raw Newline-separated values (multiselect widget value)
	 * @return string[]
	 */
	private function parseLines( ?string $raw ): array {
		$lines = array_map( 'trim', explode( "\n", (string)$raw ) );
		return array_values( array_filter( $lines, static fn ( $l ) => $l !== '' ) );
	}

	/**
	 * @param string|null $raw Comma-separated values
	 * @return string[]
	 */
	private function parseCsv( ?string $raw ): array {
		$items = array_map( 'trim', explode( ',', (string)$raw ) );
		return array_values( array_filter( $items, static fn ( $i ) => $i !== '' ) );
	}

	/** @inheritDoc */
	protected function alterForm( HTMLForm $form ) {
		$form->setSubmitTextMsg( 'pasystem-config-save' );
	}

	/** @inheritDoc */
	public function getDescription() {
		return $this->msg( 'pasystem-config-title' );
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'wiki';
	}

	/** @inheritDoc */
	public function doesWrites() {
		return true;
	}
}
