<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PublicAnnouncementSystem\Filter;

use MediaWiki\Config\Config;
use Psr\Log\LoggerInterface;
use RecentChange;

/**
 * Decides whether a given RecentChange should trigger a Discord
 * notification, according to the options set in LocalSettings.php.
 *
 * By default everything goes through (edits + logs + new accounts +
 * uploads). The exclusion options allow fine-grained filtering.
 */
class ChangeFilter {

	private Config $config;
	private LoggerInterface $logger;

	public function __construct( Config $config, LoggerInterface $logger ) {
		$this->config = $config;
		$this->logger = $logger;
	}

	public function shouldNotify( RecentChange $rc ): FilterDecision {
		$rcType = (int)$rc->getAttribute( 'rc_type' );

		// 0. Technical change types, disabled by default.
		// RC_CATEGORIZE rows are emitted for every category membership
		// change (so a single edit can produce several extra entries) and
		// RC_EXTERNAL rows come from external feeds (e.g. Wikidata).
		if ( $rcType === RC_CATEGORIZE && !$this->config->get( 'PASystemNotifyCategorization' ) ) {
			return FilterDecision::reject( 'categorization change' );
		}
		if ( $rcType === RC_EXTERNAL && !$this->config->get( 'PASystemNotifyExternal' ) ) {
			return FilterDecision::reject( 'external change' );
		}

		// 1. Bots
		if ( !$this->config->get( 'PASystemNotifyBots' )
			&& (int)$rc->getAttribute( 'rc_bot' ) === 1
		) {
			return FilterDecision::reject( 'bot edit' );
		}

		// 2. Minor edits
		if ( !$this->config->get( 'PASystemNotifyMinor' )
			&& (int)$rc->getAttribute( 'rc_minor' ) === 1
		) {
			return FilterDecision::reject( 'minor edit' );
		}

		// 3. Namespace allowlist (when non-empty, only these are announced)
		$includedNs = $this->config->get( 'PASystemIncludedNamespaces' );
		$rcNamespace = (int)$rc->getAttribute( 'rc_namespace' );
		if ( is_array( $includedNs ) && $includedNs !== []
			&& !in_array( $rcNamespace, $includedNs, true )
		) {
			return FilterDecision::reject( "namespace not in allowlist ($rcNamespace)" );
		}

		// 4. Excluded namespaces
		$excludedNs = $this->config->get( 'PASystemExcludedNamespaces' );
		if ( is_array( $excludedNs ) && in_array( $rcNamespace, $excludedNs, true ) ) {
			return FilterDecision::reject( "excluded namespace ($rcNamespace)" );
		}

		// 5. Excluded users
		$excludedUsers = $this->config->get( 'PASystemExcludedUsers' );
		$userText = (string)$rc->getAttribute( 'rc_user_text' );
		if ( is_array( $excludedUsers ) && in_array( $userText, $excludedUsers, true ) ) {
			return FilterDecision::reject( "excluded user ($userText)" );
		}

		// 6. Excluded log types
		$logType = (string)( $rc->getAttribute( 'rc_log_type' ) ?? '' );
		if ( $logType !== '' ) {
			$excludedLogTypes = $this->config->get( 'PASystemExcludedLogTypes' );
			if ( is_array( $excludedLogTypes )
				&& in_array( $logType, $excludedLogTypes, true )
			) {
				return FilterDecision::reject( "excluded log type ($logType)" );
			}
		}

		// 7. Minimum diff size (edits only)
		if ( $rcType === RC_EDIT || $rcType === RC_NEW ) {
			$minDiff = (int)$this->config->get( 'PASystemMinDiffSize' );
			if ( $minDiff > 0 ) {
				$delta = abs(
					(int)$rc->getAttribute( 'rc_new_len' )
					- (int)$rc->getAttribute( 'rc_old_len' )
				);
				if ( $delta < $minDiff ) {
					return FilterDecision::reject( "diff too small ($delta < $minDiff)" );
				}
			}
		}

		return FilterDecision::accept();
	}
}
