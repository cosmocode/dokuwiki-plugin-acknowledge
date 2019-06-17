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
        $user = $INPUT->server->str('REMOTE_USER');
        if ($user === '') return '';

        /** @var helper_plugin_acknowledge $helper */
        $helper = plugin_load('helper', 'acknowledge');
        $all = $helper->getUserAssignments($user);
        $pending = $helper->filterAcknowledged($user, $all);

        $html =  $this->getLang('ackNotFound');

        if (!empty($pending)) {
            $html = '<ul>';
            foreach ($pending as $item) {
                $html .= sprintf('<li><a href="%s">%s</a></li>', wl($item['page']), $item['page']);
            }
            $html .= '</ul>';
        }

        return $html;
    }
}

