<?php

use dokuwiki\Extension\AdminPlugin;
use dokuwiki\Form\Form;

/**
 * DokuWiki Plugin acknowledge (Admin Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr, Anna Dabrowska <dokuwiki@cosmocode.de>
 */
class admin_plugin_acknowledge_assign extends AdminPlugin
{
    /** @inheritdoc */
    public function forAdminOnly()
    {
        return false;
    }

    /** @inheritDoc */
    public function getMenuText($language)
    {
        return $this->getLang('menu_assign');
    }


    /** @inheritDoc */
    public function handle()
    {
        global $INPUT;

        /** @var helper_plugin_acknowledge $helper */
        $helper = plugin_load('helper', 'acknowledge');

        $pattern = $INPUT->arr('pattern');
        $assignees = $INPUT->arr('assignees');
        $patterns = array_combine($pattern, $assignees);

        if ($patterns && checkSecurityToken()) {
            $helper->saveAssignmentPatterns($patterns);
        }
    }

    /** @inheritDoc */
    public function html()
    {
        echo $this->locale_xhtml('assign');

        /** @var helper_plugin_acknowledge $helper */
        $helper = plugin_load('helper', 'acknowledge');

        $assignments = $helper->getAssignmentPatterns();

        $form = new Form(['method' => 'post']);
        $form->setHiddenField('do', 'admin');
        $form->setHiddenField('page', 'acknowledge_assign');
        $form->addTagOpen('table');
        $form->addTagOpen('tr');
        $form->addTagOpen('th');
        $form->addHTML($this->getLang('pattern'));
        $form->addTagClose('th');
        $form->addTagOpen('th');
        $form->addHTML($this->getLang('assignees'));
        $form->addTagClose('th');
        $form->addTagClose('tr');
        foreach ($assignments as $pattern => $assignees) {
            $this->addRow($form, $pattern, $assignees);
        }
        $this->addRow($form, '', '');
        $form->addTagClose('table');

        $form->addButton('save', $this->getLang('save'));
        echo $form->toHTML();
    }

    /**
     * @param Form $form
     * @param string $pattern
     * @param string $assignee
     * @return void
     */
    public function addRow($form, $pattern, $assignee)
    {
        static $row = 0;

        $form->addTagOpen('tr');
        $form->addTagOpen('td');
        $form->addTextInput("pattern[$row]")->val($pattern);
        $form->addTagClose('td');
        $form->addTagOpen('td');
        $form->addTextInput("assignees[$row]")->val($assignee);
        $form->addTagClose('td');
        $form->addTagClose('tr');
        $row++;
    }

    /** @inheritDoc */
    public function getTOC()
    {
        return (new admin_plugin_acknowledge_report())->getTOC();
    }
}
