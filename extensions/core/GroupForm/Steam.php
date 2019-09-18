<?php
/**
 * @brief            Admin CP Group Form
 * @author           <a href='http://www.invisionpower.com'>Invision Power Services, Inc.</a>
 * @copyright    (c) 2001 - SVN_YYYY Invision Power Services, Inc.
 * @license          http://www.invisionpower.com/legal/standards/
 * @package          IPS Social Suite
 * @subpackage
 * @since            19 Nov 2013
 * @version          SVN_VERSION_NUMBER
 */

namespace IPS\steam\extensions\core\GroupForm;

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!defined('\IPS\SUITE_UNIQUE_KEY')) {
    header($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' . ' 403 Forbidden');
    exit;
}

/**
 * Admin CP Group Form
 */
class _Steam
{
    /**
     * Process Form
     * @param \IPS\Form\Tabbed  $form  The form
     * @param \IPS\Member\Group $group Existing Group
     * @return    void
     */
    public function process(&$form, $group): void
    {
        $form->add(new \IPS\Helpers\Form\YesNo('steam_pull', $group->steam_pull ?? 1));
        $form->add(new \IPS\Helpers\Form\YesNo('steam_index', $group->steam_index ?? 1));
    }

    /**
     * Save
     * @param array             $values Values from form
     * @param \IPS\Member\Group $group  The group
     * @return    void
     */
    public function save($values, &$group): void
    {
        $group->steam_pull = $values['steam_pull'];
        $group->steam_index = $values['steam_index'];
    }
}