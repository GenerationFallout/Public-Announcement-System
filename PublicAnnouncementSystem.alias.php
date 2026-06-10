<?php
/**
 * Aliases for the special pages of the PublicAnnouncementSystem extension.
 *
 * @file
 * @license GPL-2.0-or-later
 */

$specialPageAliases = [];

/** English (English) */
$specialPageAliases['en'] = [
	'PASystemTest' => [ 'PASystemTest', 'PublicAnnouncementSystemTest' ],
	'PASystemConfig' => [ 'PASystemConfig', 'PublicAnnouncementSystemConfig' ],
];

/** French (français) */
$specialPageAliases['fr'] = [
	'PASystemTest' => [ 'Test_des_annonces_publiques' ],
	'PASystemConfig' => [ 'Configuration_des_annonces_publiques' ],
];
