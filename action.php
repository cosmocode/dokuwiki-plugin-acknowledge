<?php
/**
 * DokuWiki Plugin acknowledge (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr, Anna Dabrowska <dokuwiki@cosmocode.de>
 */

use dokuwiki\Form\Form;

class action_plugin_acknowledge extends DokuWiki_Action_Plugin
{

    /** @inheritDoc */
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('COMMON_WIKIPAGE_SAVE', 'AFTER', $this, 'handlePageSave');
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjax');
    }

    /**
     * Manage page meta data
     *
     * Store page last modified date
     * Handle page deletions
     * Remove assignments on page save, they get readded on rendering if needed
     *
     * @param Doku_Event $event
     * @param $param
     */
    public function handlePageSave(Doku_Event $event, $param)
    {
        /** @var helper_plugin_acknowledge $helper */
        $helper = plugin_load('helper', 'acknowledge');

        if ($event->data['changeType'] === DOKU_CHANGE_TYPE_DELETE) {
            $helper->removePage($event->data['id']);
        } elseif ($event->data['changeType'] !== DOKU_CHANGE_TYPE_MINOR_EDIT) {
            $helper->storePageDate($event->data['id'], $event->data['newRevision'], $event->data['newContent']);
        }

        $helper->clearAssignments($event->data['id']);
    }

    /**
     * @param Doku_Event $event
     * @param $param
     */
    public function handleAjax(Doku_Event $event, $param)
    {
        if ($event->data === 'plugin_acknowledge_assign') {
            echo $this->html();
            $event->stopPropagation();
            $event->preventDefault();
        }
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

        if ($ackSubmitted) {
            $helper->saveAcknowledgement($id, $user);
        }

        $html = '';

        $ack = $helper->hasUserAcknowledged($id, $user);
        if ($ack) {

            $html .= '<div>';
            $html .= $this->getLang('ackGranted') . sprintf('%s', dformat($ack));
            $html .= '</div>';
        } elseif ($helper->isUserAssigned($id, $user, $USERINFO['grps'])) {
            $form = new Form(['id' => 'ackForm']);
            $form->addCheckbox('ack', $this->getLang('ackText'))->attr('required', 'required');
            $form->addHTML('<br><button type="submit" name="acksubmit" id="ack-submit">' . $this->getLang('ackButton') . '</button>');
            $html .= '<div>';

            $html .= $this->getLang('ackRequired') . '<br>';

            $latest = $helper->getLatestUserAcknowledgement($id, $user);
            if ($latest) {
                $html .= '<a href="'
                    . wl($id, ['do' => 'diff', 'at' => $latest], false, '&') . '">'
                    . sprintf($this->getLang('ackDiff'), dformat($latest))
                    . '</a><br>';
            }

            $html .= $form->toHTML();
            $html .= '</div>';
        }

        return $html;
    }
}
