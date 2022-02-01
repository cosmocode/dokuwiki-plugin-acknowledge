<?php

use dokuwiki\Extension\AuthPlugin;

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
        $changelog = new \dokuwiki\ChangeLog\PageChangeLog($page);
        $revs = $changelog->getRevisions(0, 1);

        // compare content
        $oldContent = str_replace(NL, '', io_readFile(wikiFN($page, $revs[0])));
        $newContent = str_replace(NL, '', $newContent);
        if ($oldContent === $newContent) return;

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
     * Timestamp of the latest acknowledgment of the given page
     * by the given user
     *
     * @param string $page
     * @param string $user
     * @return bool|string
     */
    public function getLatestUserAcknowledgement($page, $user)
    {
        $sqlite = $this->getDB();
        if (!$sqlite) return false;

        $sql = "SELECT MAX(ack)
                  FROM acks
                 WHERE page = ?
                   AND user = ?";

        $result = $sqlite->query($sql, $page, $user);
        $latestAck = $sqlite->res2single($result);
        $sqlite->res_close($result);

        return $latestAck;
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
     * Get all pages a user needs to acknowledge and the last acknowledge date
     *
     * @param string $user
     * @param array $groups
     * @return array|bool
     */
    public function getUserAcknowledgements($user, $groups)
    {
        $sqlite = $this->getDB();
        if (!$sqlite) return false;

        $sql = "SELECT A.page, A.assignee, B.lastmod, C.user, MAX(C.ack) AS ack
                  FROM assignments A
                  JOIN pages B
                    ON A.page = B.page
             LEFT JOIN acks C
                    ON A.page = C.page AND C.user = ?
                 WHERE AUTH_ISMEMBER(A.assignee, ? , ?)
            GROUP BY A.page
            ORDER BY A.page
            ";

        $result = $sqlite->query($sql, $user, $user, implode('///', $groups));
        $assignments = $sqlite->res2arr($result);
        $sqlite->res_close($result);

        return $assignments;
    }

    /**
     * Resolve names of users assigned to a given page
     *
     * This can be slow on huge user bases!
     *
     * @param string $page
     * @return array|false
     */
    public function getPageAssignees($page)
    {
        $sqlite = $this->getDB();
        if (!$sqlite) return false;
        /** @var AuthPlugin $auth */
        global $auth;

        $sql = "SELECT assignee
                  FROM assignments
                 WHERE page = ?";
        $result = $sqlite->query($sql, $page);
        $assignments = $sqlite->res2single($result);
        $sqlite->res_close($result);

        $users = [];
        foreach (explode(',', $assignments) as $item) {
            $item = trim($item);
            if ($item === '') continue;
            if ($item[0] == '@') {
                $users = array_merge(
                    $users,
                    array_keys($auth->retrieveUsers(0, 0, ['grps' => substr($item, 1)]))
                );
            } else {
                $users[] = $item;
            }
        }

        return array_unique($users);
    }

    /**
     * Get ack status for all assigned users of a given page
     *
     * This can be slow!
     *
     * @param string $page
     * @return array|false
     */
    public function getPageAcknowledgements($page)
    {
        $users = $this->getPageAssignees($page);
        if ($users === false) return false;
        $sqlite = $this->getDB();
        if (!$sqlite) return false;

        $ulist = $sqlite->quote_and_join($users);
        $sql = "SELECT A.page, A.lastmod, B.user, MAX(B.ack) AS ack
                  FROM pages A
             LEFT JOIN acks B
                    ON A.page = B.page
                   AND B.user IN ($ulist)
                WHERE  A.page = ?
              GROUP BY A.page, B.user
                 ";
        $result = $sqlite->query($sql, $page);
        $acknowledgements = $sqlite->res2arr($result);
        $sqlite->res_close($result);

        // there should be at least one result, unless the page is unknown
        if (!count($acknowledgements)) return false;

        $baseinfo = [
            'page' => $acknowledgements[0]['page'],
            'lastmod' => $acknowledgements[0]['lastmod'],
            'user' => null,
            'ack' => null,
        ];

        // fill up the result with all users that never acknowledged the page
        $combined = [];
        foreach ($acknowledgements as $ack) {
            if ($ack['user'] !== null) {
                $combined[$ack['user']] = $ack;
            }
        }
        foreach ($users as $user) {
            if (!isset($combined[$user])) {
                $combined[$user] = array_merge($baseinfo, ['user' => $user]);
            }
        }

        ksort($combined);
        return array_values($combined);
    }

    /**
     * Returns all acknowledgements
     *
     * @param int $limit maximum number of results
     * @return array|bool
     */
    public function getAcknowledgements($limit = 100)
    {
        $sqlite = $this->getDB();
        if (!$sqlite) return false;

        $sql = '
            SELECT A.page, A.user, B.lastmod, max(A.ack) AS ack
              FROM acks A, pages B
             WHERE A.page = B.page
          GROUP BY A.user, A.page
          ORDER BY ack DESC
             LIMIT ?
              ';
        $result = $sqlite->query($sql, $limit);
        $acknowledgements = $sqlite->res2arr($result);
        $sqlite->res_close($result);

        return $acknowledgements;
    }
}

