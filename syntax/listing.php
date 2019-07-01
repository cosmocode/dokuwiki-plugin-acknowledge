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
        $this->Lexer->addSpecialPattern('~~ACKNOWLEDGE~~', $mode, 'plugin_acknowledge_listing');
    }

    /** @inheritDoc */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
    }

    /** @inheritDoc */
    public function render($mode, Doku_Renderer $renderer, $data)
    {
        if ($mode !== 'xhtml') {
            return false;
        }

        $renderer->info['cache'] = false;

        $renderer->doc .= '<div class="plugin-acknowledge-listing">';
        $renderer->doc .= $this->getListing();
        $renderer->doc .= '</div>';
        return true;
    }

    /**
     * Returns the list of pages to be acknowledged by the user
     *
     * @return string
     */
    protected function getListing()
    {
        global $INPUT;
        global $USERINFO;

        $user = $INPUT->server->str('REMOTE_USER');
        if ($user === '') return '';

        $groups = $USERINFO['grps'];

        /** @var helper_plugin_acknowledge $helper */
        $helper = plugin_load('helper', 'acknowledge');
        $pending = $helper->getUserAssignments($user, $groups);

        $html =  $this->getLang('ackNotFound');

        if (!empty($pending)) {
            $html = '<ul>';
            foreach ($pending as $item) {
                $html .= '<li>' . html_wikilink(':' . $item['page']) . '</li>';
            }
            $html .= '</ul>';
        }

        return $html;
    }
}

