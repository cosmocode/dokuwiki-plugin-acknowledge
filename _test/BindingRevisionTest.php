<?php

namespace dokuwiki\plugin\acknowledge\test;

use DokuWikiTest;
use dokuwiki\Extension\Event;

/**
 * Tests for the binding revision gate and the acknowledgement threshold
 *
 * Without the approve plugin the binding revision is simply the current revision, so neither the
 * banner nor the report is rendered while an older revision is on screen. The optional $asOf
 * threshold of the query helpers is covered here too, because it is the mechanism the approve
 * integration builds upon (see ApproveIntegrationTest).
 *
 * @group plugin_acknowledge
 * @group plugins
 */
class BindingRevisionTest extends DokuWikiTest
{
    /** @var array */
    protected $pluginsEnabled = ['acknowledge', 'sqlite'];

    /** @var \helper_plugin_acknowledge */
    protected $helper;

    /** @var \action_plugin_acknowledge_ajax */
    protected $action;

    /** @var string page under test */
    protected $id = 'dokuwiki:bindingtest';

    /** @var int the page date stored by the plugin */
    protected $lastmod = 1560805365;

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

        saveWikiText($this->id, 'content', 'test');

        $db = $this->helper->getDB();
        // the stored page date is deliberately older than the file, the two are not interchangeable
        $db->query("REPLACE INTO pages(page,lastmod) VALUES (?,?)", [$this->id, $this->lastmod]);
        $db->query(
            "REPLACE INTO assignments(page,pageassignees) VALUES (?,?)",
            [$this->id, 'max, regular']
        );
        // max acknowledged after the page date, regular shortly before it
        $db->query(
            "REPLACE INTO acks(page,user,ack) VALUES (?,?,?), (?,?,?)",
            [
                $this->id, 'max', $this->lastmod + 100,
                $this->id, 'regular', $this->lastmod - 100,
            ]
        );

        // a manager is viewing
        global $conf, $INPUT, $USERINFO;
        $conf['superuser'] = 'max';
        $conf['plugin']['acknowledge']['onpage_report'] = 'both';
        $INPUT->server->set('REMOTE_USER', 'max');
        $USERINFO['grps'] = ['super'];
    }

    /**
     * Current modification time of the page under test.
     *
     * @return int
     */
    protected function currentRev()
    {
        clearstatcache();
        return (int)filemtime(wikiFN($this->id));
    }

    /**
     * Render the AJAX response for a given revision.
     *
     * @param int|null $rev revision being viewed, null to send none at all
     * @param string|null $id page to request, defaults to the page under test
     * @return string
     */
    protected function render($rev = null, $id = null)
    {
        global $INPUT;
        $INPUT->set('id', $id ?? $this->id);
        if ($rev === null) {
            $INPUT->remove('rev');
        } else {
            $INPUT->set('rev', $rev);
        }

        return self::callInaccessibleMethod($this->action, 'html', []);
    }

    /**
     * Without the approve plugin the current revision is the one in force.
     */
    public function testBindingRevisionIsCurrentRevision()
    {
        self::assertSame($this->currentRev(), $this->helper->getBindingRevision($this->id));
    }

    /**
     * A page that does not exist has no revision in force.
     */
    public function testBindingRevisionOfUnknownPage()
    {
        self::assertSame(0, $this->helper->getBindingRevision('dokuwiki:nosuchpage'));
    }

    /**
     * Without the approve plugin acknowledgements are measured against the stored page date,
     * which is signalled by a null threshold.
     */
    public function testThresholdIsNullWithoutApprove()
    {
        self::assertNull($this->helper->getAcknowledgementThreshold($this->id));
    }

    /**
     * The current revision is binding, whether it is named explicitly or left at 0.
     */
    public function testIsBindingRevisionAcceptsTheCurrentRevision()
    {
        self::assertTrue($this->helper->isBindingRevision($this->id, $this->currentRev()));
        self::assertTrue($this->helper->isBindingRevision($this->id, 0));
        self::assertTrue($this->helper->isBindingRevision($this->id));
    }

    /**
     * Any other revision is not the one in force.
     */
    public function testIsBindingRevisionRejectsOtherRevisions()
    {
        self::assertFalse($this->helper->isBindingRevision($this->id, $this->currentRev() - 3600));
        self::assertFalse($this->helper->isBindingRevision($this->id, $this->currentRev() + 3600));
    }

    /**
     * A page without a revision in force never matches, not even for its own file time.
     */
    public function testIsBindingRevisionOfUnknownPage()
    {
        $unknown = 'dokuwiki:nosuchpage';
        self::assertFalse($this->helper->isBindingRevision($unknown));
        self::assertFalse($this->helper->isBindingRevision($unknown, 0));
    }

    /**
     * A request without a rev parameter means the current revision, as sent by a script.js
     * from before the feature existed.
     */
    public function testRendersWithoutRevParameter()
    {
        self::assertStringContainsString('plugin-acknowledge-box report', $this->render());
    }

    /**
     * The report is rendered when the viewed revision is the current one.
     */
    public function testRendersOnCurrentRevision()
    {
        self::assertStringContainsString(
            'plugin-acknowledge-box report',
            $this->render($this->currentRev())
        );
    }

    /**
     * Nothing at all is rendered while an older revision is on screen, not even the banner.
     */
    public function testRendersNothingOnOldRevision()
    {
        self::assertSame('', $this->render($this->currentRev() - 3600));
    }

    /**
     * A page that does not exist renders nothing.
     */
    public function testRendersNothingForUnknownPage()
    {
        self::assertSame('', $this->render(null, 'dokuwiki:nosuchpage'));
    }

    /**
     * By default the counts are measured against the stored page date.
     */
    public function testCountsUseStoredPageDateByDefault()
    {
        $counts = $this->helper->getPageAcknowledgementCounts($this->id);

        self::assertSame(2, $counts['required']);
        self::assertSame(1, $counts['current']); // max
        self::assertSame(1, $counts['due']);     // regular
    }

    /**
     * A threshold replaces the stored page date, moving users between current and due.
     *
     * Also guards the CAST in the query: parameters are bound as strings and sqlite sorts text
     * above every integer, so without it the HAVING comparison silently matches nobody.
     */
    public function testCountsUseThresholdWhenGiven()
    {
        $counts = $this->helper->getPageAcknowledgementCounts($this->id, $this->lastmod - 200);
        self::assertSame(2, $counts['current'], 'both acks are newer than the threshold');
        self::assertSame(0, $counts['due']);

        $counts = $this->helper->getPageAcknowledgementCounts($this->id, $this->lastmod + 200);
        self::assertSame(0, $counts['current'], 'both acks are older than the threshold');
        self::assertSame(2, $counts['due']);
    }

    /**
     * The user lists honour the same threshold, so they cannot disagree with the counts.
     */
    public function testAcknowledgementsUseThreshold()
    {
        $current = $this->helper->getPageAcknowledgements($this->id, '', 'current', 0, $this->lastmod - 200);
        self::assertSame(['max', 'regular'], $this->users($current));

        $due = $this->helper->getPageAcknowledgements($this->id, '', 'due', 0, $this->lastmod + 200);
        self::assertSame(['max', 'regular'], $this->users($due));

        // unchanged without a threshold
        $current = $this->helper->getPageAcknowledgements($this->id, '', 'current');
        self::assertSame(['max'], $this->users($current));
    }

    /**
     * Rows report the threshold as their lastmod, which is what the due filter compares against.
     */
    public function testThresholdIsReportedAsLastmod()
    {
        $rows = $this->helper->getPageAcknowledgements($this->id, '', 'current', 0, $this->lastmod - 200);

        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertEquals($this->lastmod - 200, $row['lastmod']);
        }
    }

    /**
     * The viewed revision is published to JavaScript for the AJAX call to send back.
     */
    public function testJsInfoCarriesTheViewedRevision()
    {
        global $JSINFO, $REV;
        $JSINFO = [];
        $REV = 1560805365;

        $data = [];
        $this->action->handleJsInfo(new Event('DOKUWIKI_STARTED', $data));

        self::assertSame(1560805365, $JSINFO['plugins']['acknowledge']['rev']);
    }

    /**
     * Viewing the current revision publishes a zero, which the gate reads as "current".
     */
    public function testJsInfoCarriesZeroForCurrentRevision()
    {
        global $JSINFO, $REV;
        $JSINFO = [];
        $REV = 0;

        $data = [];
        $this->action->handleJsInfo(new Event('DOKUWIKI_STARTED', $data));

        self::assertSame(0, $JSINFO['plugins']['acknowledge']['rev']);
    }

    /**
     * Sorted user names of acknowledgement rows.
     *
     * @param array $rows
     * @return string[]
     */
    protected function users($rows)
    {
        $users = array_column($rows, 'user');
        sort($users);
        return $users;
    }
}
