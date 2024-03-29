<?php

/**
 * DokuWiki Plugin acknowledge (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr, Anna Dabrowska <dokuwiki@cosmocode.de>
 */

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;
use dokuwiki\Form\Form;

class action_plugin_acknowledge extends ActionPlugin
{
    /** @inheritDoc */
    public function register(EventHandler $controller)
    {
        $controller->register_hook('COMMON_WIKIPAGE_SAVE', 'AFTER', $this, 'handlePageSave');
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjaxAcknowledge');
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjaxAutocomplete');
        $controller->register_hook('PLUGIN_SQLITE_DATABASE_UPGRADE', 'AFTER', $this, 'handleUpgrade');
    }

    /**
     * Manage page meta data
     *
     * Store page last modified date
     * Handle page deletions
     * Handle page creations
     *
     * @param Event $event
     * @param $param
     */
    public function handlePageSave(Event $event, $param)
    {
        /** @var helper_plugin_acknowledge $helper */
        $helper = plugin_load('helper', 'acknowledge');

        if ($event->data['changeType'] === DOKU_CHANGE_TYPE_DELETE) {
            $helper->removePage($event->data['id']); // this cascades to assignments
        } elseif ($event->data['changeType'] !== DOKU_CHANGE_TYPE_MINOR_EDIT) {
            $helper->storePageDate($event->data['id'], $event->data['newRevision'], $event->data['newContent']);
        }

        // Remove page assignees here because the syntax might have been removed
        // they are readded on metadata rendering if still there
        $helper->clearPageAssignments($event->data['id']);

        if ($event->data['changeType'] === DOKU_CHANGE_TYPE_CREATE) {
            // new pages need to have their auto assignments updated based on the existing patterns
            $helper->setAutoAssignees($event->data['id']);
        }
    }

    /**
     * @param Event $event
     * @param $param
     */
    public function handleAjaxAcknowledge(Event $event, $param)
    {
        if ($event->data === 'plugin_acknowledge_acknowledge') {
            $event->stopPropagation();
            $event->preventDefault();

            global $INPUT;
            $id = $INPUT->str('id');

            if (page_exists($id)) {
                echo $this->html();
            }
        }
    }

    /**
     * @param Event $event
     * @return void
     */
    public function handleAjaxAutocomplete(Event $event)
    {
        if ($event->data === 'plugin_acknowledge_autocomplete') {
            if (!checkSecurityToken()) return;

            global $INPUT;

            $event->stopPropagation();
            $event->preventDefault();

            /** @var helper_plugin_acknowledge $hlp */
            $hlp = $this->loadHelper('acknowledge');

            $found = [];

            if ($INPUT->has('user')) {
                $search = $INPUT->str('user');
                $knownUsers = $hlp->getUsers();
                $found = array_filter($knownUsers, function ($user) use ($search) {
                    return (strstr(strtolower($user['label']), strtolower($search))) !== false ? $user : null;
                });
            }

            if ($INPUT->has('pg')) {
                $search = $INPUT->str('pg');
                $pages = ft_pageLookup($search, true);
                $found = array_map(function ($id, $title) {
                    return ['value' => $id, 'label' => $title ?? $id];
                }, array_keys($pages), array_values($pages));
            }

            header('Content-Type: application/json');

            echo json_encode($found);
        }
    }

    /**
     * Handle Migration events
     *
     * @param Event $event
     * @param $param
     * @return void
     */
    public function handleUpgrade(Event $event, $param)
    {
        if ($event->data['sqlite']->getAdapter()->getDbname() !== 'acknowledgement') {
            return;
        }
        $to = $event->data['to'];
        if ($to !== 3) return; // only handle upgrade to version 3

        /** @var helper_plugin_acknowledge $helper */
        $helper = plugin_load('helper', 'acknowledge');
        $helper->updatePageIndex();
    }

    /**
     * Returns the acknowledgment form/confirmation
     *
     * @return string The HTML to display
     */
    protected function html()
    {
        global $INPUT;
        global $USERINFO;
        $id = $INPUT->str('id');
        $ackSubmitted = $INPUT->bool('ack');
        $user = $INPUT->server->str('REMOTE_USER');
        if ($id === '' || $user === '') return '';

        /** @var helper_plugin_acknowledge $helper */
        $helper = plugin_load('helper', 'acknowledge');

        // only display for users assigned to the page
        if (!$helper->isUserAssigned($id, $user, $USERINFO['grps'])) {
            return '';
        }

        if ($ackSubmitted) {
            $helper->saveAcknowledgement($id, $user);
        }

        $ack = $helper->hasUserAcknowledged($id, $user);

        $html = '<div class="' . ($ack ? 'ack' : 'noack') . '">';
        $html .= inlineSVG(__DIR__ . '/admin.svg');
        $html .= '</div>';

        if ($ack) {
            $html .= '<div>';
            $html .= '<h4>';
            $html .= $this->getLang('ackOk');
            $html .= '</h4>';
            $html .= sprintf($this->getLang('ackGranted'), dformat($ack));
            $html .= '</div>';
        } else {
            $html .= '<div>';
            $html .= '<h4>' . $this->getLang('ackRequired') . '</h4>';
            $latest = $helper->getLatestUserAcknowledgement($id, $user);
            if ($latest) {
                $html .= '<a href="'
                    . wl($id, ['do' => 'diff', 'at' => $latest], false, '&') . '">'
                    . sprintf($this->getLang('ackDiff'), dformat($latest))
                    . '</a><br>';
            }

            $form = new Form(['id' => 'ackForm']);
            $form->addCheckbox('ack', $this->getLang('ackText'))->attr('required', 'required');
            $form->addHTML(
                '<br><button type="submit" name="acksubmit" id="ack-submit">'
                . $this->getLang('ackButton')
                . '</button>'
            );

            $html .= $form->toHTML();
            $html .= '</div>';
        }

        return $html;
    }
}
