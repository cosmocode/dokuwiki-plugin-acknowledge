<?php
/**
 * DokuWiki Plugin acknowledge (Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr, Anna Dabrowska <dokuwiki@cosmocode.de>
 */


class helper_plugin_acknowledge extends DokuWiki_Plugin
{

    /**
     * @return helper_plugin_sqlite|null
     */
    public function getDB()
    {
        /** @var \helper_plugin_sqlite $sqlite */
        $sqlite = plugin_load('helper', 'sqlite');
        if ($sqlite === null) {
            msg($this->getLang('error sqlite plugin missing'), -1);
            return null;
        }
        if (!$sqlite->init('acknowledgement', __DIR__ . '/db')) {
            return null;
        }

        return $sqlite;
    }

    /**
     * @param string $page Page ID
     * @param string $assignees comma separated list of users and groups
     */
    public function setAssignees($page, $assignees)
    {
        $sqlite = $this->getDB();
        if (!$sqlite) return;

        $sql = "REPLACE INTO assignments ('page', 'assignee') VALUES (?,?)";
        $sqlite->query($sql, $page, $assignees);
    }


}

