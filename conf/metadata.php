<?php

/**
 * Configuration metadata for the acknowledge plugin
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Anna Dabrowska <dokuwiki@cosmocode.de>
 */

$meta['approve_integration'] = ['onoff'];
$meta['notification_integration'] = ['onoff'];
$meta['onpage_report'] = ['multichoice', '_choices' => ['off', 'acknowledged', 'pending', 'both']];
$meta['mail_user'] = ['onoff'];
$meta['mail_address'] = ['string'];
$meta['mail_content'] = ['multichoice', '_choices' => ['none', 'source', 'render']];
