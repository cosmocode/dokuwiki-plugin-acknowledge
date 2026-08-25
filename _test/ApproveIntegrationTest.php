<?php

namespace dokuwiki\plugin\acknowledge\test;

use DokuWikiTest;

/**
 * Tests for the approve plugin integration (helper->isBlockedByApprove and the binding revision)
 *
 * The test drives the approve plugin exclusively through its public helper API
 * (addMaintainer / handlePageEdit / setApprovedStatus) rather than touching its
 * database directly, so it exercises the real integration path.
 *
 * @group plugin_acknowledge
 * @group plugins
 */
class ApproveIntegrationTest extends DokuWikiTest
{
    /** @var array */
    protected $pluginsEnabled = ['acknowledge', 'sqlite', 'approve'];

    /** @var \helper_plugin_acknowledge */
    protected $helper;

    /** @var \helper_plugin_approve_db */
    protected $approve;

    public function setUp(): void
    {
        parent::setUp();

        // setApprovedStatus() records the approving user from $INFO
        global $INFO;
        $INFO['client'] = 'someapprover';

        $this->helper = plugin_load('helper', 'acknowledge');
        $this->approve = plugin_load('helper', 'approve_db');

        // track the whole "approved:" namespace
        $this->approve->addMaintainer('approved:**', 'someapprover');
    }

    /**
     * Create a page, approve it, then save a newer revision that stays a draft.
     *
     * Both revisions get an explicit mtime, because approve keys its revision table on the file
     * mtime and two saves within the same second would be indistinguishable.
     *
     * @param string $id page id
     * @param int $approved mtime of the approved revision
     * @param int $draft mtime of the later, unapproved revision
     * @return void
     */
    protected function createApprovedThenDraft($id, $approved, $draft)
    {
        saveWikiText($id, 'approved content', 'v1');
        touch(wikiFN($id), $approved);
        clearstatcache();
        $this->approve->handlePageEdit($id);
        $this->approve->setApprovedStatus($id);

        saveWikiText($id, 'draft content', 'v2');
        touch(wikiFN($id), $draft);
        clearstatcache();
        $this->approve->handlePageEdit($id);
    }

    /**
     * Create a wiki page and let approve record its current revision,
     * just as the COMMON_WIKIPAGE_SAVE hook would.
     *
     * @param string $id page id
     * @return void
     */
    protected function createPage($id)
    {
        saveWikiText($id, 'content', 'test');
        $this->approve->handlePageEdit($id);
    }

    /**
     * A page outside any approve-maintained namespace is never blocked.
     */
    public function testUntrackedPageNotBlocked()
    {
        $id = 'free:page';
        $this->createPage($id);
        self::assertFalse($this->helper->isBlockedByApprove($id));
    }

    /**
     * A maintained page that is still a draft is blocked.
     */
    public function testDraftPageBlocked()
    {
        $id = 'approved:draft';
        $this->createPage($id);
        self::assertTrue($this->helper->isBlockedByApprove($id));
    }

    /**
     * A maintained page whose current revision is approved is not blocked.
     */
    public function testApprovedPageNotBlocked()
    {
        $id = 'approved:done';
        $this->createPage($id);
        $this->approve->setApprovedStatus($id);
        self::assertFalse($this->helper->isBlockedByApprove($id));
    }

    /**
     * With the integration disabled, even a maintained draft is not blocked.
     */
    public function testDisabledIntegrationNeverBlocks()
    {
        global $conf;
        $conf['plugin']['acknowledge']['approve_integration'] = 0;

        $id = 'approved:ignored';
        $this->createPage($id);
        self::assertFalse($this->helper->isBlockedByApprove($id));
    }

    /**
     * While a draft awaits approval, the last approved revision is the one in force.
     */
    public function testBindingRevisionIsTheApprovedRevision()
    {
        $id = 'approved:pending';
        $this->createApprovedThenDraft($id, 1560805000, 1560809000);

        self::assertTrue($this->helper->isBlockedByApprove($id));
        self::assertSame(1560805000, $this->helper->getBindingRevision($id));
    }

    /**
     * Once the current revision is approved it is the one in force again.
     */
    public function testBindingRevisionIsCurrentWhenApproved()
    {
        $id = 'approved:current';
        $this->createPage($id);
        $this->approve->setApprovedStatus($id);

        clearstatcache();
        self::assertSame((int)filemtime(wikiFN($id)), $this->helper->getBindingRevision($id));
    }

    /**
     * A page still waiting for its very first approval has no revision in force at all,
     * so nothing about it can be displayed.
     */
    public function testBindingRevisionZeroWhenNeverApproved()
    {
        $id = 'approved:neverapproved';
        $this->createPage($id);

        self::assertTrue($this->helper->isBlockedByApprove($id));
        self::assertSame(0, $this->helper->getBindingRevision($id));
    }

    /**
     * While a draft is pending, only the approved revision on screen is the binding one - the
     * draft itself is not, even though it is the current revision the reader would get by default.
     */
    public function testIsBindingRevisionWhileDraftPending()
    {
        $id = 'approved:onscreen';
        $this->createApprovedThenDraft($id, 1560805000, 1560809000);

        self::assertTrue($this->helper->isBindingRevision($id, 1560805000), 'approved revision');
        self::assertFalse($this->helper->isBindingRevision($id, 1560809000), 'pending draft');
        // 0 means "the current revision", which is the draft
        self::assertFalse($this->helper->isBindingRevision($id), 'current revision is the draft');
    }

    /**
     * A page still waiting for its first approval has nothing in force, so no revision matches.
     */
    public function testIsBindingRevisionWhenNeverApproved()
    {
        $id = 'approved:nobinding';
        $this->createPage($id);

        clearstatcache();
        self::assertFalse($this->helper->isBindingRevision($id));
        self::assertFalse($this->helper->isBindingRevision($id, (int)filemtime(wikiFN($id))));
    }

    /**
     * The threshold follows the approved revision while a draft is pending, because the stored
     * page date has already moved on to that draft.
     */
    public function testThresholdFollowsTheApprovedRevision()
    {
        $id = 'approved:threshold';
        $this->createApprovedThenDraft($id, 1560805000, 1560809000);
        self::assertSame(1560805000, $this->helper->getAcknowledgementThreshold($id));

        $approved = 'approved:nothreshold';
        $this->createPage($approved);
        $this->approve->setApprovedStatus($approved);
        self::assertNull($this->helper->getAcknowledgementThreshold($approved));
    }

    /**
     * The counts describe the approved revision, not the pending draft: a user who acknowledged
     * the approved content is current, even though the stored page date is already the draft.
     */
    public function testCountsDescribeTheApprovedRevision()
    {
        $id = 'approved:counts';
        $this->createApprovedThenDraft($id, 1560805000, 1560809000);

        $db = $this->helper->getDB();
        // the page date has moved on to the draft, as the save hook would leave it
        $db->query("REPLACE INTO pages(page,lastmod) VALUES (?,?)", [$id, 1560809000]);
        $db->query("REPLACE INTO assignments(page,pageassignees) VALUES (?,?)", [$id, 'max, regular']);
        // max acknowledged the approved revision, regular never acknowledged anything
        $db->query("REPLACE INTO acks(page,user,ack) VALUES (?,?,?)", [$id, 'max', 1560806000]);

        $asOf = $this->helper->getAcknowledgementThreshold($id);
        $counts = $this->helper->getPageAcknowledgementCounts($id, $asOf);
        self::assertSame(1, $counts['current'], 'max acknowledged the revision in force');
        self::assertSame(1, $counts['due']);

        // measured against the draft instead, nobody would count as current
        self::assertSame(0, $this->helper->getPageAcknowledgementCounts($id)['current']);
    }
}
