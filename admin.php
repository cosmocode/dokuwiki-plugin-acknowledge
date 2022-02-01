<?php

use dokuwiki\Extension\AuthPlugin;

/**
 * DokuWiki Plugin acknowledge (Admin Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr, Anna Dabrowska <dokuwiki@cosmocode.de>
 */
class admin_plugin_acknowledge extends DokuWiki_Admin_Plugin
{

    /**
     * @return int sort number in admin menu
     */
    public function getMenuSort()
    {
        return 100;
    }

    /**
     * @return bool true if only access for superuser, false is for superusers and moderators
     */
    public function forAdminOnly()
    {
        return false;
    }

    /**
     * Should carry out any processing required by the plugin.
     */
    public function handle()
    {
    }

    /**
     * Render HTML output, e.g. helpful text and a form
     */
    public function html()
    {
        global $INPUT;

        echo '<h1>' . $this->getLang('menu') . '</h1>';

        if ($INPUT->has('user')) {
            $this->htmlUserStatus($INPUT->str('user'));
        } else {
            $this->htmlLatest();
        }

    }

    /**
     * Show what a given user should sign and has
     *
     * @param string $user
     */
    protected function htmlUserStatus($user)
    {
        /** @var AuthPlugin $auth */
        global $auth;
        global $lang;

        $user = $auth->cleanUser($user);
        $userinfo = $auth->getUserData($user, true);
        if (!$userinfo) {
            echo '<p>' . $lang['nothingfound'] . '</p>';
            return;
        }

        /** @var helper_plugin_acknowledge $helper */
        $helper = plugin_load('helper', 'acknowledge');

        $assignments = $helper->getUserAcknowledgements($user, $userinfo['grps']);

        echo '<table>';
        echo '<tr>';
        echo '<th>' . $this->getLang('overviewPage') . '</th>';
        echo '<th>' . $this->getLang('overviewMod') . '</th>';
        echo '<th>' . $this->getLang('overviewTime') . '</th>';
        echo '<th>' . $this->getLang('overviewCurrent') . '</th>';
        echo '</tr>';

        $count = 0;
        foreach ($assignments as $ass) {
            $current = $ass['ack'] >= $ass['lastmod'];
            if ($current) $count++;

            echo '<tr>';
            echo '<td>' . html_wikilink(':' . $ass['page']) . '</td>';
            echo '<td>' . ($ass['lastmod'] ? dformat($ass['lastmod']) : '') . '</td>';
            echo '<td>' . ($ass['ack'] ? dformat($ass['ack']) : '') . '</td>';
            echo '<td>' . ($current ? $this->getLang('yes') : '') . '</td>';
            echo '</tr>';
        }
        echo '</table>';

        echo '<p>' . sprintf($this->getLang('count'), hsc($user), $count, count($assignments)) . '</p>';
    }

    /**
     * Show the latest 100 acknowledgements
     */
    protected function htmlLatest()
    {
        /** @var helper_plugin_acknowledge $helper */
        $helper = plugin_load('helper', 'acknowledge');
        $acks = $helper->getAcknowledgements();

        echo '<table>';
        echo '<tr>';
        echo '<th>' . $this->getLang('overviewPage') . '</th>';
        echo '<th>' . $this->getLang('overviewUser') . '</th>';
        echo '<th>' . $this->getLang('overviewTime') . '</th>';
        echo '</tr>';

        foreach ($acks as $ack) {
            echo '<tr>';
            echo '<td>' . html_wikilink(':' . $ack['page']) . '</td>';
            echo '<td>' . $this->userLink($ack['user']) . '</td>';
            echo '<td>' . dformat($ack['ack']) . '</td>';
            echo '</tr>';
        }

        echo '</table>';

        echo '<p>' . $this->getLang('overviewHistory') . '</p>';
    }

    /**
     * Link to the user overview
     *
     * @param string $user
     * @return string
     */
    protected function userLink($user)
    {
        global $ID;

        $url = wl(
            $ID,
            [
                'do' => 'admin',
                'page' => 'acknowledge',
                'user' => $user,
            ]
        );

        return '<a href="' . $url . '">' . hsc($user) . '</a>';
    }
}

