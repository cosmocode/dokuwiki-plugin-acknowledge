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
     * Delete a page
     *
     * Cascades to delete all assigned data, etc.
     *
     * @param string $page Page ID
     */
    public function removePage($page)
    {
        $sqlite = $this->getDB();
        if (!$sqlite) return;

        $sql = "DELETE FROM pages WHERE page = ?";
        $sqlite->query($sql, $page);
    }

    /**
     * Update last modified date of page
     *
     * @param string $page Page ID
     * @param int $lastmod timestamp of last non-minor change
     */
    public function storePageDate($page, $lastmod)
    {
        $sqlite = $this->getDB();
        if (!$sqlite) return;

        $sql = "REPLACE INTO pages (page, lastmod) VALUES (?,?)";
        $sqlite->query($sql, $page, $lastmod);
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

    /**
     * Clears assignments for a page
     *
     * @param string $page Page ID
     */
    public function clearAssignments($page)
    {
        $sqlite = $this->getDB();
        if (!$sqlite) return;

        $sql = "DELETE FROM assignments WHERE page = ?";
        $sqlite->query($sql, $page);
    }


    /**
     * Is the given user one of the assignees for this page
     *
     * @param string $page Page ID
     * @param string $user user name to check
     * @param string[] $groups groups this user is in
     * @return bool
     */
    public function isUserAssigned($page, $user, $groups)
    {
        $sqlite = $this->getDB();
        if (!$sqlite) return false;


        $sql = "SELECT assignee FROM assignments WHERE page = ?";
        $result = $sqlite->query($sql, $page);
        $assignees = (string)$sqlite->res2single($result);
        $sqlite->res_close($result);

        return auth_isMember($assignees, $user, $groups);
    }

    /**
     * Has the given user acknowledged the given page?
     *
     * @param string $page
     * @param string $user
     * @return bool|int timestamp of acknowledgement or false
     */
    public function hasUserAcknowledged($page, $user)
    {
        $sqlite = $this->getDB();
        if (!$sqlite) return false;

        $sql = "SELECT ack 
                  FROM acks A, pages B
                 WHERE A.page = B.page
                   AND A.page = ?
                   AND A.user = ?
                   AND A.ack >= B.lastmod";

        $result = $sqlite->query($sql, $page, $user);
        $acktime = $sqlite->res2single($result);
        $sqlite->res_close($result);

        return $acktime ? (int)$acktime : false;
    }

    /**
     * Save user's acknowledgement for a given page
     *
     * @param string $page
     * @param string $user
     * @return bool
     */
    public function saveAcknowledgement($page, $user)
    {
        $sqlite = $this->getDB();
        if (!$sqlite) return false;

        $sql = "REPLACE INTO acks (page, user, ack) VALUES (?,?, strftime('%s','now'))";

        $result = $sqlite->query($sql, $page, $user);
        $sqlite->res_close($result);
        return true;

    }
}

