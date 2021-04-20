<?php


namespace IPS\steam\setup\upg_30000;

use \IPS\Db;

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!\defined('\IPS\SUITE_UNIQUE_KEY')) {
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

/**
 * 3.0 Upgrade Code
 */
class _Upgrade
{
    /**
     * ...
     * @return    array    If returns TRUE, upgrader will proceed to next step. If it returns any other value, it will
     *                     set this as the value of the 'extra' GET parameter and rerun this step (useful for loops)
     */
    public function step1()
    {
        // Cleanup column(s) added to core tables.
        if (Db::i()->checkForColumn('core_members', 'steamid')) {
            Db::i()->dropColumn('core_members', array('steamid'));
        }

        if(Db::i()->checkForColumn('core_groups', 'steam_pull') && Db::i()->checkForColumn('core_groups', 'steam_index')){
            // Migrate data first
            $groups = Db::i()->select(array('g_id','steam_index','steam_pull'), 'core_groups');
            foreach($groups as $group){
                Db::i()->insert('steam_core_groups', array(
                    'group_id'          => $group->g_id,
                    'group_pull'        => $group->steam_pull,
                    'group_board_block' => $group->steam_index,
                ));
            }
            Db::i()->dropColumn('core_groups', array('steam_pull'));
            Db::i()->dropColumn('core_groups', array('steam_index'));
        }

        return TRUE;
    }
}