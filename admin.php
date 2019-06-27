<?php
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
        /** @var helper_plugin_acknowledge $helper */
        $helper = plugin_load('helper', 'acknowledge');

        $acks = $helper->getAcknowledgements();

        ptln('<h1>' . $this->getLang('menu') . '</h1>');

        echo '<table>';
        echo '<tr>';
        echo '<th>' . $this->getLang('overviewPage') . '</th>';
        echo '<th>' . $this->getLang('overviewUser') . '</th>';
        echo '<th>' . $this->getLang('overviewTime') . '</th>';
        echo '</tr>';

        foreach ($acks as $ack) {
            echo '<tr>';
            echo '<td>' . html_wikilink($ack['page']) . '</td>' .
                '<td>' . hsc($ack['user']) . '</td><td>' . dformat($ack['ack']) . '</td>';
            echo '</tr>';
        }

        echo '</table>';

        echo '<p>' . $this->getLang('overviewHistory') . '</p>';

    }
}

