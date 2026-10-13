<?php
/**
 * English language file for acknowledge plugin
 *
 * @author Andreas Gohr, Anna Dabrowska <dokuwiki@cosmocode.de>
 */

$lang['approve_integration'] = 'If the approve plugin is installed, hide the acknowledgement ' .
    'button on pages that are under approve\'s control but not in "Approved" state.';
$lang['notification_integration'] = 'If the notification plugin is installed, email assigned ' .
    'users about pages that require their acknowledgement.';
$lang['onpage_report'] = 'Show an on-page report of who has acknowledged the page and/or who ' .
    'still needs to. The report is only visible to managers and admins.';
$lang['onpage_report_o_off'] = 'Off';
$lang['onpage_report_o_acknowledged'] = 'Users who have acknowledged';
$lang['onpage_report_o_pending'] = 'Users who still need to acknowledge';
$lang['onpage_report_o_both'] = 'Both acknowledged and pending users';
$lang['mail_user'] = 'Send a confirmation email to the user after acknowledging.';
$lang['mail_address'] = 'Send a confirmation email to the following address(es). Comma-separate multiple addresses.';
$lang['mail_content'] = 'Include the acknowledged page\'s content in the email.';
$lang['mail_content_o_none'] = 'Do not include the page content, just a link';
$lang['mail_content_o_source'] = 'Include the raw wiki source';
$lang['mail_content_o_render'] = 'Include the rendered page (as HTML)';
