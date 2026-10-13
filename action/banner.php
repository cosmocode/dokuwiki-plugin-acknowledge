<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;

/**
 * DokuWiki Plugin acknowledge (Action Component)
 *
 * Provides the container the acknowledge banner is loaded into.
 */
class action_plugin_acknowledge_banner extends ActionPlugin
{
    /** @inheritDoc */
    public function register(EventHandler $controller)
    {
        $controller->register_hook('TPL_CONTENT_DISPLAY', 'BEFORE', $this, 'handleContentDisplay');
    }

    /**
     * Append the banner container to the rendered content of assigned pages
     * This replaces previous brittle client-side insertion, which made assumptions about markup.
     *
     * @param Event $event Event object, data is the rendered HTML
     * @param mixed $param optional parameter passed when event was registered
     * @return void
     */
    public function handleContentDisplay(Event $event, $param)
    {
        global $ACT, $ID;

        if ($ACT !== 'show') return;

        // check if the ~~ACK:...~~ syntax has already rendered the container
        if (str_contains($event->data, 'plugin-acknowledge-banner')) return;

        /** @var helper_plugin_acknowledge $helper */
        $helper = plugin_load('helper', 'acknowledge');

        if (!$helper || !$this->hasContentForUser($ID, $helper)) return;

        $event->data .= '<div class="plugin-acknowledge-banner"></div>';
    }

    /**
     * Could the current user see anything in the banner container on this page?
     * Same checks as in AJAX endpoint.
     *
     * @param string $id page id
     * @param helper_plugin_acknowledge $helper
     * @return bool
     */
    protected function hasContentForUser($id, helper_plugin_acknowledge $helper)
    {
        global $INPUT, $USERINFO;

        $user = $INPUT->server->str('REMOTE_USER');
        if ($user === '') return false;

        if ($helper->isUserAssigned($id, $user, $USERINFO['grps'] ?? [])) return true;

        return $this->getConf('onpage_report') !== 'off'
            && auth_ismanager()
            && $helper->hasPageAssignees($id);
    }
}
