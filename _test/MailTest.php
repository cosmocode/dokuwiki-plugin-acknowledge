<?php

namespace dokuwiki\plugin\acknowledge\test;

use DokuWikiTest;
use dokuwiki\Extension\Event;

/**
 * Tests for the acknowledgement email feature
 *
 * Mail sending is intercepted via the MAIL_MESSAGE_SEND event (the same extension point
 * DokuWiki itself exposes for this), so no actual mail is ever sent during these tests.
 * The event only carries the plain text body, so the HTML part (needed for the 'render'
 * content mode and the clickable link) is verified separately through the protected
 * buildAcknowledgementMail() that generates it.
 *
 * @group plugin_acknowledge
 * @group plugins
 */
class MailTest extends DokuWikiTest
{
    /** @var array */
    protected $pluginsEnabled = ['acknowledge', 'sqlite'];

    /** @var \helper_plugin_acknowledge */
    protected $helper;

    /** @var string page under test */
    protected $id = 'dokuwiki:mailtest';

    /** @var string content of the page under test */
    protected $content = "This is the page content.\n";

    /** @var array captured {to, subject, body} of every mail that would have been sent */
    protected $sent = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        /** @var \auth_plugin_authplain $auth */
        global $auth;
        $auth->createUser('max', 'none', 'Max Mustermann', 'max@example.com', ['user']);
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->helper = plugin_load('helper', 'acknowledge');
        $this->sent = [];

        saveWikiText($this->id, $this->content, 'test');

        global $INPUT, $USERINFO;
        $INPUT->server->set('REMOTE_USER', 'max');
        $USERINFO = ['name' => 'Max Mustermann', 'mail' => 'max@example.com', 'grps' => ['user']];

        global $conf;
        $conf['plugin']['acknowledge']['mail_user'] = 1;
        $conf['plugin']['acknowledge']['mail_address'] = '';
        $conf['plugin']['acknowledge']['mail_content'] = 'none';

        // core's wl() reads $conf['date_at_format'] for the 'at' parameter used in the
        // acknowledgement link without ever defining a default for it (pre-existing gap,
        // also hit by this plugin's own ackDiff link) - set it so the undefined-key warning
        // does not fail the test; '' preserves the default behaviour of a raw timestamp
        $conf['date_at_format'] = '';

        // intercept mail sending: capture what would have gone out, then cancel the actual send
        global $EVENT_HANDLER;
        $EVENT_HANDLER->register_hook('MAIL_MESSAGE_SEND', 'BEFORE', null, function (Event $event) {
            $this->sent[] = [
                'to' => $event->data['to'],
                'subject' => $event->data['subject'],
                'body' => $event->data['body'],
            ];
            $event->preventDefault();
        });
    }

    /**
     * Nothing is sent at all when both recipients are switched off.
     */
    public function testNoMailWhenBothRecipientsDisabled()
    {
        global $conf;
        $conf['plugin']['acknowledge']['mail_user'] = 0;
        $conf['plugin']['acknowledge']['mail_address'] = '';

        $this->helper->sendAcknowledgementMail($this->id, 'max');

        self::assertCount(0, $this->sent);
    }

    /**
     * With only mail_user enabled, exactly the acknowledging user is mailed.
     */
    public function testMailSentToUserOnly()
    {
        $this->helper->sendAcknowledgementMail($this->id, 'max');

        self::assertCount(1, $this->sent);
        self::assertSame('max@example.com', $this->sent[0]['to']);
        self::assertStringContainsString('Max Mustermann', $this->sent[0]['subject']);
    }

    /**
     * The central address is notified even while mail_user is switched off - it is an
     * independent audit log, not merely a copy of the user's mail.
     */
    public function testCentralAddressSendsEvenWhenUserMailDisabled()
    {
        global $conf;
        $conf['plugin']['acknowledge']['mail_user'] = 0;
        $conf['plugin']['acknowledge']['mail_address'] = 'audit@example.com';

        $this->helper->sendAcknowledgementMail($this->id, 'max');

        self::assertCount(1, $this->sent);
        self::assertSame('audit@example.com', $this->sent[0]['to']);
    }

    /**
     * Both recipients get their own mail, duplicates (e.g. the central address happening to
     * equal the user's own address) are not sent twice.
     */
    public function testMailSentToUserAndCentralAddressesDeduplicated()
    {
        global $conf;
        $conf['plugin']['acknowledge']['mail_address'] = 'max@example.com, audit@example.com';

        $this->helper->sendAcknowledgementMail($this->id, 'max');

        $recipients = array_column($this->sent, 'to');
        sort($recipients);
        self::assertSame(['audit@example.com', 'max@example.com'], $recipients);
    }

    /**
     * Nothing is sent to the user if they have no mail address on file, even with mail_user on.
     */
    public function testNoUserMailWithoutAnAddressOnFile()
    {
        global $USERINFO;
        $USERINFO = ['name' => 'Max Mustermann', 'grps' => ['user']]; // no 'mail' key

        $this->helper->sendAcknowledgementMail($this->id, 'max');

        self::assertCount(0, $this->sent);
    }

    /**
     * The display name falls back to the login name when the session has none - this is the
     * bug that made emails say "username (username)" instead of the real name.
     */
    public function testDisplayNameFallsBackToUsername()
    {
        global $USERINFO;
        $USERINFO = ['mail' => 'max@example.com', 'grps' => ['user']]; // no 'name' key

        $mail = self::callInaccessibleMethod($this->helper, 'buildAcknowledgementMail', [$this->id, 'max']);

        self::assertStringContainsString('max', $mail['subject']);
    }

    /**
     * mail_content 'none' (the default) does not leak the page content into either body part.
     */
    public function testMailContentNoneOmitsPageContent()
    {
        $mail = self::callInaccessibleMethod($this->helper, 'buildAcknowledgementMail', [$this->id, 'max']);

        self::assertStringNotContainsString('This is the page content.', $mail['text']);
        self::assertStringNotContainsString('This is the page content.', $mail['html']);
    }

    /**
     * mail_content 'source' appends the raw wiki markup to both body parts.
     */
    public function testMailContentSourceIncludesRawWikiText()
    {
        global $conf;
        $conf['plugin']['acknowledge']['mail_content'] = 'source';

        $mail = self::callInaccessibleMethod($this->helper, 'buildAcknowledgementMail', [$this->id, 'max']);

        self::assertStringContainsString('This is the page content.', $mail['text']);
        self::assertStringContainsString('This is the page content.', $mail['html']);
    }

    /**
     * mail_content 'render' embeds the rendered page (real HTML markup) in the HTML part only -
     * the plain text part stays the plain summary, since there is no plain text equivalent to add.
     */
    public function testMailContentRenderIncludesRenderedHtmlInHtmlPartOnly()
    {
        global $conf;
        $conf['plugin']['acknowledge']['mail_content'] = 'render';

        $mail = self::callInaccessibleMethod($this->helper, 'buildAcknowledgementMail', [$this->id, 'max']);

        self::assertStringContainsString('<p>', $mail['html']);
        self::assertStringContainsString('This is the page content.', $mail['html']);
        self::assertStringNotContainsString('This is the page content.', $mail['text']);
    }

    /**
     * The page link uses 'at', not 'rev', and points at the binding revision - see
     * BindingRevisionTest for why that distinction matters (rev requires an already-archived
     * attic file, which does not exist yet right after acknowledging).
     */
    public function testLinkPointsToBindingRevisionAndIsClickableInHtml()
    {
        $expectedLink = wl($this->id, ['at' => $this->helper->getBindingRevision($this->id)], true);

        $mail = self::callInaccessibleMethod($this->helper, 'buildAcknowledgementMail', [$this->id, 'max']);

        // wl() already returns its URL HTML-escaped (default '&amp;' separator), so the html
        // part must embed it as-is - re-escaping with hsc() here would mask the double-escaping
        // bug this test caught (a literal "&amp;amp;" in the link, which breaks it in a browser)
        self::assertStringContainsString($expectedLink, $mail['text']);
        self::assertStringContainsString('<a href="' . $expectedLink . '">', $mail['html']);
        self::assertStringNotContainsString('&amp;amp;', $mail['html']);
    }

    /**
     * The body names the acknowledging user once (display name), not as "Name (username)".
     */
    public function testBodyDoesNotRepeatUsernameInParens()
    {
        $mail = self::callInaccessibleMethod($this->helper, 'buildAcknowledgementMail', [$this->id, 'max']);

        self::assertStringContainsString('Max Mustermann', $mail['text']);
        self::assertStringNotContainsString('(max)', $mail['text']);
    }
}
