<?php

use dokuwiki\Extension\AdminPlugin;
use dokuwiki\Form\Form;
use dokuwiki\Extension\AuthPlugin;

/**
 * DokuWiki Plugin acknowledge (Admin Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr, Anna Dabrowska <dokuwiki@cosmocode.de>
 */
class admin_plugin_acknowledge_report extends AdminPlugin
{
    /** @inheritdoc */
    public function forAdminOnly()
    {
        return false;
    }

    /** @inheritdoc */
    public function handle()
    {
    }

    /** @inheritdoc */
    public function html()
    {
        global $INPUT;

        echo '<div class="plugin_acknowledgement_admin">';
        echo '<h1>' . $this->getLang('menu') . '</h1>';
        $this->htmlForms();
        $user = $INPUT->str('user');
        $pg = $INPUT->str('pg');
        if ($pg) {
            $this->htmlPageStatus($pg, $user);
        } elseif ($user) {
            $this->htmlUserStatus($user);
        } else {
            $this->htmlLatest();
        }
        echo '</div>';
    }

    /**
     * Show which users have or need ot acknowledge a specific page
     *
     * @param string $pattern A page assignment pattern
     * @param string $user Optional user
     */
    protected function htmlPageStatus($pattern, $user = '')
    {
        global $lang;
        global $INPUT;

        /** @var helper_plugin_acknowledge $helper */
        $helper = plugin_load('helper', 'acknowledge');

        $status = $INPUT->str('status');
        $pages = $helper->getPagesMatchingPattern($pattern);
        $acknowledgements = [];

        foreach ($pages as $pattern) {
            $acknowledgements = array_merge($acknowledgements, $helper->getPageAcknowledgements($pattern, $user, $status, 1000));
            if (count($acknowledgements) > 1000) {
                // don't show too many
                msg($this->getLang('toomanyresults'), 0);
                break;
            }
        }

        if (!$acknowledgements) {
            echo '<p>' . $lang['nothingfound'] . '</p>';
            return;
        }

        $this->htmlTable($acknowledgements);
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
        global $INPUT;

        $user = $auth->cleanUser($user);
        $userinfo = $auth->getUserData($user, true);
        if (!$userinfo) {
            echo '<p>' . $lang['nothingfound'] . '</p>';
            return;
        }

        /** @var helper_plugin_acknowledge $helper */
        $helper = plugin_load('helper', 'acknowledge');

        $status = $INPUT->str('status');

        if ($status === 'current') {
            $assignments = $helper->getUserAcknowledgements($user, $userinfo['grps'], 'current');
        } elseif ($status === 'due') {
            $assignments = $helper->getUserAcknowledgements($user, $userinfo['grps'], 'due');
        } else {
            $assignments = $helper->getUserAcknowledgements($user, $userinfo['grps'], 'all');
        }
        $count = $this->htmlTable($assignments);
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
        $this->htmlTable($acks);
        echo '<p>' . $this->getLang('overviewHistory') . '</p>';
    }

    /**
     * @return void
     */
    protected function htmlForms()
    {
        echo '<nav>';
        echo $this->homeLink();

        $form = new Form(['method' => 'GET']);
        $form->id('acknowledge__user-autocomplete');
        $form->setHiddenField('do', 'admin');
        $form->setHiddenField('page', 'acknowledge_report');
        $form->addTextInput('pg', $this->getLang('pattern'));
        $form->addTextInput('user', $this->getLang('overviewUser'))
            ->attr('type', 'search');
        $form->addDropdown(
            'status',
            [
                'all' => $this->getLang('all'),
                'current' => $this->getLang('current'),
                'due' => $this->getLang('due'),
            ],
            $this->getLang('status')
        );
        $form->addButton('', '>');
        echo $form->toHTML();
        echo '</nav>';
    }

    /**
     * Print the given acknowledge data
     *
     * @param array $data
     * @return int number of acknowledged entries
     */
    protected function htmlTable($data)
    {
        echo '<table>';
        echo '<tr>';
        echo '<th>#</th>';
        echo '<th>' . $this->getLang('overviewPage') . '</th>';
        echo '<th>' . $this->getLang('overviewUser') . '</th>';
        echo '<th>' . $this->getLang('overviewMod') . '</th>';
        echo '<th>' . $this->getLang('overviewTime') . '</th>';
        echo '<th>' . $this->getLang('overviewCurrent') . '</th>';
        echo '</tr>';

        $count = 0;
        $i = 0;
        foreach ($data as $item) {
            $current = $item['ack'] >= $item['lastmod'];
            if ($current) $count++;
            $i++;
            echo '<tr>';
            echo "<td>$i</td>";
            echo '<td>' . $this->pageLink($item['page']) . '</td>';
            echo '<td>' . $this->userLink($item['user']) . '</td>';
            echo '<td>' . html_wikilink(
                ':' . $item['page'],
                ($item['lastmod'] ? dformat($item['lastmod']) : '?')
            ) . '</td>';
            echo '<td>' . ($item['ack'] ? dformat($item['ack']) : '') . '</td>';
            echo '<td>' . ($current ? $this->getLang('yes') : '') . '</td>';
            echo '</tr>';
        }
        echo '</table>';

        return $count;
    }

    protected function homeLink()
    {
        global $ID;

        $url = wl(
            $ID,
            [
                'do' => 'admin',
                'page' => 'acknowledge_report',
            ]
        );

        return '<a href="' . $url . '">' . $this->getLang('home') . '</a>';
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
                'page' => 'acknowledge_report',
                'user' => $user,
            ]
        );

        return '<a href="' . $url . '">' . hsc($user) . '</a>';
    }

    /**
     * Link to the page overview
     *
     * @param string $page
     * @return string
     */
    protected function pageLink($page)
    {
        global $ID;

        $url = wl(
            $ID,
            [
                'do' => 'admin',
                'page' => 'acknowledge_report',
                'pg' => $page,
            ]
        );

        return '<a href="' . $url . '">' . hsc($page) . '</a>';
    }

    /** @inheritdoc */
    public function getTOC()
    {
        global $ID;
        return [
            html_mktocitem(
                wl($ID, ['do' => 'admin', 'page' => 'acknowledge_report']),
                $this->getLang('menu'),
                0,
                ''
            ),
            html_mktocitem(
                wl($ID, ['do' => 'admin', 'page' => 'acknowledge_assign']),
                $this->getLang('menu_assign'),
                0,
                ''
            ),
        ];
    }
}
