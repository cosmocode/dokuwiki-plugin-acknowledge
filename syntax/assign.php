<?php
/**
 * DokuWiki Plugin acknowledge (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr, Anna Dabrowska <dokuwiki@cosmocode.de>
 */

class syntax_plugin_acknowledge_assign extends DokuWiki_Syntax_Plugin
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
        $this->Lexer->addSpecialPattern('~~ACK:.*?~~', $mode, 'plugin_acknowledge_assign');
    }


    /** @inheritDoc */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        $match = substr($match, 6, -2);
        return ['assignees' => $match];
    }

    /** @inheritDoc */
    public function render($mode, Doku_Renderer $renderer, $data)
    {
        global $ID;

        if ($mode === 'metadata') {
            /** @var helper_plugin_acknowledge $helper */
            $helper = plugin_load('helper', 'acknowledge');
            $helper->setAssignees($ID, $data['assignees']);
            return true;
        }

        if ($mode !== 'xhtml') {
            return false;
        }

        // a canvas to render the output to
        $renderer->doc .= '<div class="plugin-acknowledge-assign">…</div>';
        return true;
    }
}

