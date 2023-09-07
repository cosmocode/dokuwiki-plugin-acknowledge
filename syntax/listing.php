<?php
/**
 * DokuWiki Plugin acknowledge (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr, Anna Dabrowska <dokuwiki@cosmocode.de>
 */

class syntax_plugin_acknowledge_listing extends DokuWiki_Syntax_Plugin
{
    /** @inheritDoc */
    public function getType()
    {
        return 'substition';
    }

    /** @inheritDoc */
    public function getPType()
    {
        return 'block';
    }

    /** @inheritDoc */
    public function getSort()
    {
        return 155;
    }

    /** @inheritDoc */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('~~ACKNOWLEDGE.*?~~', $mode, 'plugin_acknowledge_listing');
    }

    /** @inheritDoc */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        // check for 'all' parameter
        $includeDone = strtolower(substr($match, strlen('~~ACKNOWLEDGE '), -2)) === 'all';
        return ['includeDone' => $includeDone];
    }

    /** @inheritDoc */
    public function render($mode, Doku_Renderer $renderer, $data)
    {
        if ($mode !== 'xhtml') {
            return false;
        }

        $renderer->info['cache'] = false;

        $renderer->doc .= '<div class="plugin-acknowledge-listing">';
        $renderer->doc .= $this->getListing($data['includeDone']);
        $renderer->doc .= '</div>';
        return true;
    }

    /**
     * Returns the list of pages to be acknowledged by the user,
     * optionally including past acknowledgments.
     *
     * @param bool $includeDone
     *
     * @return string
     */
    protected function getListing($includeDone)
    {
        global $INPUT;
        global $USERINFO;

        $user = $INPUT->server->str('REMOTE_USER');
        if ($user === '') return '';

        $groups = $USERINFO['grps'];

        /** @var helper_plugin_acknowledge $helper */
        $helper = plugin_load('helper', 'acknowledge');
        $items = $helper->getUserAssignments($user, $groups, $includeDone);

        $html =  $this->getLang('ackNotFound');

        if (!empty($items)) {
            $html = '<ul>';
            foreach ($items as $item) {
                $done = $item['ack'] ?
                    ' <span title="' . sprintf($this->getLang('ackGranted'), dformat($item['ack'])) . '">&#x2714;</span>'
                    : '';
                $html .= '<li>' . html_wikilink(':' . $item['page']) . $done . '</li>';
            }
            $html .= '</ul>';
        }

        return $html;
    }
}

