<?php

namespace dokuwiki\plugin\acknowledge\test;

use DokuWikiTest;

/**
 * Tests for the on-page manager/admin report (action_plugin_acknowledge_ajax::reportHtml)
 *
 * Drives the protected reportHtml() through the helper data layer to verify the
 * onpage_report config modes, the manager/admin visibility gate and the
 * current-vs-pending semantics.
 *
 * @group plugin_acknowledge
 * @group plugins
 */
class ReportTest extends DokuWikiTest
{
    /** @var array */
    protected $pluginsEnabled = ['acknowledge', 'sqlite'];

    /** @var \helper_plugin_acknowledge */
    protected $helper;

    /** @var \action_plugin_acknowledge_ajax */
    protected $action;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        /** @var \auth_plugin_authplain $auth */
        global $auth;
        $auth->createUser('max', 'none', 'max', 'max@example.com', ['super']);
        $auth->createUser('regular', 'none', 'regular', 'regular@example.com', ['user']);
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->helper = plugin_load('helper', 'acknowledge');
        $this->action = new \action_plugin_acknowledge_ajax();

        $db = $this->helper->getDB();

        // acktest1: max acknowledged current, regular never -> max current, regular pending
        // noassign: tracked page without any assignees
        $db->query(
            "REPLACE INTO pages(page,lastmod)
                VALUES ('dokuwiki:acktest1', 1560805365), ('dokuwiki:noassign', 1560805365)"
        );
        $db->query(
            "REPLACE INTO assignments(page,pageassignees)
                VALUES ('dokuwiki:acktest1', 'regular, @super')"
        );
        $db->query(
            "REPLACE INTO acks(page,user,ack)
                VALUES ('dokuwiki:acktest1', 'max', 1560805770)"
        );

        // default: a manager is viewing
        $this->loginAs('max', ['super']);
        global $conf;
        $conf['superuser'] = 'max';
    }

    /**
     * Set the current request user and groups.
     *
     * @param string $user
     * @param array $groups
     * @return void
     */
    protected function loginAs($user, array $groups)
    {
        global $INPUT, $USERINFO;
        $INPUT->server->set('REMOTE_USER', $user);
        $USERINFO['grps'] = $groups;
    }

    /**
     * Set the onpage_report config option.
     *
     * @param string $mode
     * @return void
     */
    protected function setMode($mode)
    {
        global $conf;
        $conf['plugin']['acknowledge']['onpage_report'] = $mode;
    }

    /**
     * Render the report box for a page.
     *
     * @param string $id
     * @return string
     */
    protected function report($id)
    {
        return self::callInaccessibleMethod($this->action, 'reportHtml', [$id, $this->helper]);
    }

    /**
     * The default "off" mode renders nothing.
     */
    public function testOffModeIsEmpty()
    {
        $this->setMode('off');
        self::assertSame('', $this->report('dokuwiki:acktest1'));
    }

    /**
     * Non-managers never see the report, even when enabled.
     */
    public function testNonManagerIsEmpty()
    {
        $this->setMode('both');
        $this->loginAs('regular', ['user']);
        self::assertSame('', $this->report('dokuwiki:acktest1'));
    }

    /**
     * Pages without assignees produce no report.
     */
    public function testPageWithoutAssigneesIsEmpty()
    {
        $this->setMode('both');
        self::assertSame('', $this->report('dokuwiki:noassign'));
    }

    /**
     * The "acknowledged" mode shows a clickable count of current acknowledgers only,
     * not their names.
     */
    public function testAcknowledgedMode()
    {
        $this->setMode('acknowledged');
        $html = $this->report('dokuwiki:acktest1');

        self::assertStringContainsString('plugin-acknowledge-box report', $html);
        self::assertStringContainsString($this->action->getLang('reportAcknowledgedTitle'), $html);
        self::assertStringNotContainsString($this->action->getLang('reportPendingTitle'), $html);

        // a single current acknowledger (max), shown as a count link, not by name
        self::assertStringContainsString('plugin-acknowledge-loadusers', $html);
        self::assertStringContainsString('data-status="current"', $html);
        self::assertStringContainsString(sprintf($this->action->getLang('reportUserCount'), 1), $html);
        self::assertStringNotContainsString('>max<', $html);
        self::assertStringNotContainsString('>regular<', $html);
    }

    /**
     * The "pending" mode shows a clickable count of users who still need to acknowledge.
     */
    public function testPendingMode()
    {
        $this->setMode('pending');
        $html = $this->report('dokuwiki:acktest1');

        self::assertStringContainsString($this->action->getLang('reportPendingTitle'), $html);
        self::assertStringNotContainsString($this->action->getLang('reportAcknowledgedTitle'), $html);

        // a single pending user (regular), shown as a count link, not by name
        self::assertStringContainsString('data-status="due"', $html);
        self::assertStringContainsString(sprintf($this->action->getLang('reportUserCount'), 1), $html);
        self::assertStringNotContainsString('>regular<', $html);
    }

    /**
     * The "both" mode shows acknowledged and pending counts in separate sections.
     */
    public function testBothMode()
    {
        $this->setMode('both');
        $html = $this->report('dokuwiki:acktest1');

        // headline and the (smaller) repeated banner icon
        self::assertStringContainsString($this->action->getLang('reportTitle'), $html);
        self::assertStringContainsString('ack-icon', $html);
        self::assertStringContainsString('<svg', $html);

        self::assertStringContainsString($this->action->getLang('reportAcknowledgedTitle'), $html);
        self::assertStringContainsString($this->action->getLang('reportPendingTitle'), $html);

        // both sections render an on-demand count link, no names up front
        self::assertStringContainsString('data-status="current"', $html);
        self::assertStringContainsString('data-status="due"', $html);
        self::assertStringNotContainsString('>max<', $html);
        self::assertStringNotContainsString('>regular<', $html);
    }

    /**
     * The count helper matches the row counts of the per-status getPageAcknowledgements()
     * it replaces, while resolving group membership only once.
     */
    public function testCountsMatchPerStatusLists()
    {
        $counts = $this->helper->getPageAcknowledgementCounts('dokuwiki:acktest1');

        $current = $this->helper->getPageAcknowledgements('dokuwiki:acktest1', '', 'current');
        $due = $this->helper->getPageAcknowledgements('dokuwiki:acktest1', '', 'due');

        self::assertSame(count($current), $counts['current']);
        self::assertSame(count($due), $counts['due']);
        self::assertSame($counts['current'] + $counts['due'], $counts['required']);

        // concretely: max acked current, regular pending
        self::assertSame(1, $counts['current']);
        self::assertSame(1, $counts['due']);
        self::assertSame(2, $counts['required']);
    }

    /**
     * Pages without assignees report zero counts.
     */
    public function testCountsWithoutAssignees()
    {
        self::assertSame(
            ['required' => 0, 'current' => 0, 'due' => 0],
            $this->helper->getPageAcknowledgementCounts('dokuwiki:noassign')
        );
    }

    /**
     * The on-demand user list (loaded when a count is clicked) renders the actual names.
     */
    public function testUserListLoadsNames()
    {
        $acked = $this->helper->getPageAcknowledgements('dokuwiki:acktest1', '', 'current');
        $html = self::callInaccessibleMethod($this->action, 'userListHtml', [$acked]);
        self::assertStringContainsString('max', $html);

        $pending = $this->helper->getPageAcknowledgements('dokuwiki:acktest1', '', 'due');
        $html = self::callInaccessibleMethod($this->action, 'userListHtml', [$pending]);
        self::assertStringContainsString('regular', $html);
    }
}
