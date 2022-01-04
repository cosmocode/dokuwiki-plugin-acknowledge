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

        $this->registerUDF($sqlite);

        return $sqlite;
    }

    /**
     * Register user defined functions
     *
     * @param helper_plugin_sqlite $sqlite
     */
    protected function registerUDF($sqlite)
    {
        $sqlite->create_function('AUTH_ISMEMBER', [$this, 'auth_isMember'], -1);
    }

    /**
     * Wrapper function for auth_isMember which accepts groups as string
     *
     * @param string $memberList
     * @param string $user
     * @param string $groups
     * @return bool
     */
    public function auth_isMember($memberList, $user, $groups)
    {
        return auth_isMember($memberList, $user, explode('///', $groups));
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
     * Update last modified date of page if content has changed
     *
     * @param string $page Page ID
     * @param int $lastmod timestamp of last non-minor change
     */
    public function storePageDate($page, $lastmod, $newContent)
    {
        $sqlite = $this->getDB();
        if (!$sqlite) return;

        $identical = true;
        $changelog = new \dokuwiki\ChangeLog\PageChangeLog($page);

        $revs = $changelog->getRevisions(1, 20);

        foreach ($revs as $rev) {
            $info = $changelog->getRevisionInfo($rev);
            if ($info['type'] !== DOKU_CHANGE_TYPE_MINOR_EDIT) {
                // compare content
                $oldContent = str_replace(NL, '', io_readFile(wikiFN($page, $rev)));
                $newContent = str_replace(NL, '', $newContent);
                if ($oldContent !== $newContent) $identical = false;
                break;
            }
        }

        if ($identical) return;

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

        $sql = "INSERT INTO acks (page, user, ack) VALUES (?,?, strftime('%s','now'))";

        $result = $sqlite->query($sql, $page, $user);
        $sqlite->res_close($result);
        return true;

    }

    /**
     * Fetch all assignments for a given user, with additional page information,
     * filtering already granted acknowledgements.
     *
     * @param string $user
     * @param array $groups
     * @return array|bool
     */
    public function getUserAssignments($user, $groups)
    {
        $sqlite = $this->getDB();
        if (!$sqlite) return false;

        $sql = "SELECT A.page, A.assignee, B.lastmod, C.user, C.ack FROM assignments A
                JOIN pages B
                ON A.page = B.page
                LEFT JOIN acks C
                ON A.page = C.page AND ( (C.user = ? AND C.ack > B.lastmod) )
                WHERE AUTH_ISMEMBER(A.assignee, ? , ?)
                AND ack IS NULL";

        $result = $sqlite->query($sql, $user, $user, implode('///', $groups));
        $assignments = $sqlite->res2arr($result);
        $sqlite->res_close($result);

        return $assignments;
    }

    /**
     * Returns all acknowledgements
     *
     * @return array|bool
     */
    public function getAcknowledgements()
    {
        $sqlite = $this->getDB();
        if (!$sqlite) return false;

        $sql = 'SELECT page, user, max(ack) AS ack FROM acks GROUP BY user,page ORDER BY ack DESC';
        $result = $sqlite->query($sql);
        $acknowledgements = $sqlite->res2arr($result);
        $sqlite->res_close($result);

        return $acknowledgements;
    }
}

