<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\PublicAnnouncementSystem\Filter;

use Config;
use Psr\Log\LoggerInterface;
use RecentChange;

/**
 * Décide si un RecentChange donné doit déclencher une notification Discord
 * selon les options de configuration définies dans LocalSettings.php.
 *
 * Par défaut tout passe (édits + logs + nouveaux comptes + uploads).
 * Les options d'exclusion permettent un filtrage fin.
 */
class ChangeFilter {

	private Config $config;
	private LoggerInterface $logger;

	public function __construct( Config $config, LoggerInterface $logger ) {
		$this->config = $config;
		$this->logger = $logger;
	}

	public function shouldNotify( RecentChange $rc ): FilterDecision {
		// 1. Bots
		if ( !$this->config->get( 'PASystemNotifyBots' )
			&& (int)$rc->getAttribute( 'rc_bot' ) === 1
		) {
			return FilterDecision::reject( 'bot edit' );
		}

		// 2. Modifications mineures
		if ( !$this->config->get( 'PASystemNotifyMinor' )
			&& (int)$rc->getAttribute( 'rc_minor' ) === 1
		) {
			return FilterDecision::reject( 'minor edit' );
		}

		// 3. Namespaces exclus
		$excludedNs = $this->config->get( 'PASystemExcludedNamespaces' );
		$rcNamespace = (int)$rc->getAttribute( 'rc_namespace' );
		if ( is_array( $excludedNs ) && in_array( $rcNamespace, $excludedNs, true ) ) {
			return FilterDecision::reject( "excluded namespace ($rcNamespace)" );
		}

		// 4. Utilisateurs exclus
		$excludedUsers = $this->config->get( 'PASystemExcludedUsers' );
		$userText = (string)$rc->getAttribute( 'rc_user_text' );
		if ( is_array( $excludedUsers ) && in_array( $userText, $excludedUsers, true ) ) {
			return FilterDecision::reject( "excluded user ($userText)" );
		}

		// 5. Types de log exclus
		$logType = (string)( $rc->getAttribute( 'rc_log_type' ) ?? '' );
		if ( $logType !== '' ) {
			$excludedLogTypes = $this->config->get( 'PASystemExcludedLogTypes' );
			if ( is_array( $excludedLogTypes )
				&& in_array( $logType, $excludedLogTypes, true )
			) {
				return FilterDecision::reject( "excluded log type ($logType)" );
			}
		}

		// 6. Taille minimale du diff (uniquement pour les édits)
		$rcType = (int)$rc->getAttribute( 'rc_type' );
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
