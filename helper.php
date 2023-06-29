<?php

use dokuwiki\ErrorHandler;
use dokuwiki\Extension\AuthPlugin;
use dokuwiki\plugin\sqlite\SQLiteDB;

/**
 * DokuWiki Plugin acknowledge (Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr, Anna Dabrowska <dokuwiki@cosmocode.de>
 */
class helper_plugin_acknowledge extends DokuWiki_Plugin
{

    protected $db;

    // region Database Management

    /**
     * Get SQLiteDB instance
     *
     * @return SQLiteDB|null
     */
    public function getDB()
    {
        if ($this->db === null) {
            try {
                $this->db = new SQLiteDB('acknowledgement', __DIR__ . '/db');

                // register our custom functions
                $this->db->getPdo()->sqliteCreateFunction('AUTH_ISMEMBER', [$this, 'auth_isMember'], -1);
                $this->db->getPdo()->sqliteCreateFunction('MATCHES_PAGE_PATTERN', [$this, 'matchPagePattern'], 2);
            } catch (\Exception $exception) {
                if (defined('DOKU_UNITTEST')) throw new \RuntimeException('Could not load SQLite', 0, $exception);
                ErrorHandler::logException($exception);
                msg($this->getLang('error sqlite plugin missing'), -1);
                return null;
            }
        }
        return $this->db;
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
     * Fills the page index with all unknown pages from the fulltext index
     * @return void
     */
    public function updatePageIndex()
    {
        $sqlite = $this->getDB();
        if (!$sqlite) return;

        $pages = idx_getIndex('page', '');
        $sql = "INSERT OR IGNORE INTO pages (page, lastmod) VALUES (?,?)";

        $sqlite->getPdo()->beginTransaction();
        foreach ($pages as $page) {
            $page = trim($page);
            $lastmod = @filemtime(wikiFN($page));
            if ($lastmod) {
                try {
                    $sqlite->exec($sql, [$page, $lastmod]);
                } catch (\Exception $exception) {
                    $sqlite->getPdo()->rollBack();
                    throw $exception;
                }
            }
        }
        $sqlite->getPdo()->commit();
    }

    /**
     * Check if the given pattern matches the given page
     *
     * @param string $pattern the pattern to check against
     * @param string $page the cleaned pageid to check
     * @return bool
     */
    public function matchPagePattern($pattern, $page)
    {
        if (trim($pattern, ':') == '**') return true; // match all

        // regex patterns
        if ($pattern[0] == '/') {
            return (bool)preg_match($pattern, ":$page");
        }

        $pns = ':' . getNS($page) . ':';

        $ans = ':' . cleanID($pattern) . ':';
        if (substr($pattern, -2) == '**') {
            // upper namespaces match
            if (strpos($pns, $ans) === 0) {
                return true;
            }
        } elseif (substr($pattern, -1) == '*') {
            // namespaces match exact
            if ($ans == $pns) {
                return true;
            }
        } else {
            // exact match
            if (cleanID($pattern) == $page) {
                return true;
            }
        }

        return false;
    }

    // endregion
    // region Page Data

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
        $sqlite->exec($sql, $page);
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
        $sqlite->exec($sql, [$page, $lastmod]);
    }

    // endregion
    // region Assignments

    /**
     * Clears direct assignments for a page
     *
     * @param string $page Page ID
     */
    public function clearPageAssignments($page)
    {
        $sqlite = $this->getDB();
        if (!$sqlite) return;

        $sql = "UPDATE assignments SET pageassignees = '' WHERE page = ?";
        $sqlite->exec($sql, $page);
    }

    /**
     * Set assignees for a given page as manually specified
     *
     * @param string $page Page ID
     * @param string $assignees
     * @return void
     */
    public function setPageAssignees($page, $assignees)
    {
        $sqlite = $this->getDB();
        if (!$sqlite) return;

        $assignees = join(',', array_unique(array_filter(array_map('trim', explode(',', $assignees)))));

        $sql = "REPLACE INTO assignments ('page', 'pageassignees') VALUES (?,?)";
        $sqlite->exec($sql, [$page, $assignees]);
    }

    /**
     * Set assignees for a given page from the patterns
     * @param string $page Page ID
     */
    public function setAutoAssignees($page)
    {
        $sqlite = $this->getDB();
        if (!$sqlite) return;

        $patterns = $this->getAssignmentPatterns();

        // given assignees
        $assignees = '';

        // find all patterns that match the page and add the configured assignees
        foreach ($patterns as $pattern => $assignees) {
            if ($this->matchPagePattern($pattern, $page)) {
                $assignees .= ',' . $assignees;
            }
        }

        // remove duplicates and empty entries
        $assignees = join(',', array_unique(array_filter(array_map('trim', explode(',', $assignees)))));

        // store the assignees
        $sql = "REPLACE INTO assignments ('page', 'autoassignees') VALUES (?,?)";
        $sqlite->exec($sql, [$page, $assignees]);
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

        $sql = "SELECT pageassignees,autoassignees FROM assignments WHERE page = ?";
        $record = $sqlite->queryRecord($sql, $page);
        if (!$record) return false;
        $assignees = $record['pageassignees'] . ',' . $record['autoassignees'];
        return auth_isMember($assignees, $user, $groups);
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

        $sql = "SELECT A.page, A.pageassignees, A.autoassignees, B.lastmod, C.user, C.ack FROM assignments A
                JOIN pages B
                ON A.page = B.page
                LEFT JOIN acks C
                ON A.page = C.page AND ( (C.user = ? AND C.ack > B.lastmod) )
                WHERE AUTH_ISMEMBER(A.pageassignees || ',' || A.autoassignees , ? , ?)
                AND ack IS NULL";

        return $sqlite->queryAll($sql, $user, $user, implode('///', $groups));
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

        $sql = "SELECT pageassignees || ',' || autoassignees AS 'assignments'
                  FROM assignments
                 WHERE page = ?";
        $assignments = $sqlite->queryValue($sql, $page);

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

    // endregion
    // region Assignment Patterns

    /**
     * Get all the assignment patterns
     * @return array (pattern => assignees)
     */
    public function getAssignmentPatterns()
    {
        $sqlite = $this->getDB();
        if (!$sqlite) return [];

        $sql = "SELECT pattern, assignees FROM assignments_patterns";
        return $sqlite->queryKeyValueList($sql);
    }

    /**
     * Save new assignment patterns
     *
     * This resaves all patterns and reapplies them
     *
     * @param array $patterns (pattern => assignees)
     */
    public function saveAssignmentPatterns($patterns)
    {
        $sqlite = $this->getDB();
        if (!$sqlite) return;

        $sqlite->getPdo()->beginTransaction();
        try {

            /** @noinspection SqlWithoutWhere Remove all assignments */
            $sql = "UPDATE assignments SET autoassignees = ''";
            $sqlite->exec($sql);

            /** @noinspection SqlWithoutWhere Remove all patterns */
            $sql = "DELETE FROM assignments_patterns";
            $sqlite->exec($sql);

            // insert new patterns and gather affected pages
            $pages = [];

            $sql = "REPLACE INTO assignments_patterns (pattern, assignees) VALUES (?,?)";
            foreach ($patterns as $pattern => $assignees) {
                $pattern = trim($pattern);
                $assignees = trim($assignees);
                if (!$pattern || !$assignees) continue;
                $sqlite->exec($sql, [$pattern, $assignees]);

                // patterns may overlap, so we need to gather all affected pages first
                $affectedPages = $this->getPagesMatchingPattern($pattern);
                foreach ($affectedPages as $page) {
                    if (isset($pages[$page])) {
                        $pages[$page] .= ',' . $assignees;
                    } else {
                        $pages[$page] = $assignees;
                    }
                }
            }

            $sql = "INSERT INTO assignments (page, autoassignees) VALUES (?, ?)
                ON CONFLICT(page)
                DO UPDATE SET autoassignees = ?";
            foreach ($pages as $page => $assignees) {
                // remove duplicates and empty entries
                $assignees = join(',', array_unique(array_filter(array_map('trim', explode(',', $assignees)))));
                $sqlite->exec($sql, [$page, $assignees, $assignees]);
            }
        } catch (Exception $e) {
            $sqlite->getPdo()->rollBack();
            throw $e;
        }
        $sqlite->getPdo()->commit();
    }

    /**
     * Get all known pages that match the given pattern
     *
     * @param $pattern
     * @return string[]
     */
    public function getPagesMatchingPattern($pattern)
    {
        $sqlite = $this->getDB();
        if (!$sqlite) return [];

        $sql = "SELECT page FROM pages WHERE MATCHES_PAGE_PATTERN(?, page)";
        $pages = $sqlite->queryAll($sql, $pattern);

        return array_column($pages, 'page');
    }

    // endregion
    // region Acknowledgements

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

        $acktime = $sqlite->queryValue($sql, $page, $user);

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

        return $sqlite->queryValue($sql, [$page, $user]);
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

        $sqlite->exec($sql, $page, $user);
        return true;

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

        $sql = "SELECT A.page, A.pageassignees, A.autoassignees, B.lastmod, C.user, MAX(C.ack) AS ack
                  FROM assignments A
                  JOIN pages B
                    ON A.page = B.page
             LEFT JOIN acks C
                    ON A.page = C.page AND C.user = ?
                 WHERE AUTH_ISMEMBER(A.pageassignees || ',' || A.autoassignees, ? , ?)
            GROUP BY A.page
            ORDER BY A.page
            ";

        return $sqlite->queryAll($sql, [$user, $user, implode('///', $groups)]);
    }

    /**
     * Get ack status for all assigned users of a given page
     *
     * This can be slow!
     *
     * @param string $page
     * @return array|false
     */
    public function getPageAcknowledgements($page, $max=0)
    {
        $users = $this->getPageAssignees($page);
        if ($users === false) return false;
        $sqlite = $this->getDB();
        if (!$sqlite) return false;

        $ulist = join(',', array_map([$sqlite->getPdo(), 'quote'], $users));
        $sql = "SELECT A.page, A.lastmod, B.user, MAX(B.ack) AS ack
                  FROM pages A
             LEFT JOIN acks B
                    ON A.page = B.page
                   AND B.user IN ($ulist)
                WHERE  A.page = ?
              GROUP BY A.page, B.user
                 ";
        if($max) $sql .= " LIMIT $max";
        $acknowledgements = $sqlite->queryAll($sql, $page);

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
        $acknowledgements = $sqlite->queryAll($sql, $limit);

        return $acknowledgements;
    }

    // endregion
}

