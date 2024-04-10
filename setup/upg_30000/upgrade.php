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
            // This will break LAVA's login handler.
            // Db::i()->dropColumn('core_members', array('steamid'));
        }

        if(Db::i()->checkForColumn('core_groups', 'steam_pull') && Db::i()->checkForColumn('core_groups', 'steam_index')){
            Db::i()->dropColumn('core_groups', array('steam_pull'));
            Db::i()->dropColumn('core_groups', array('steam_index'));
        }

        Db::i()->dropColumn('steam_profiles', array('avatarfull'));
        Db::i()->dropColumn('steam_profiles', array('avatarmedium'));
        Db::i()->dropColumn('steam_profiles', array('avatar'));

        Db::i()->addColumn('steam_profiles', array(
            'name' => 'avatarhash',
            'type' => 'VARCHAR',
            'length' => 255,
            'auto_increment' => false,
            'unique' => true,
            'default' => null,
            'allow_null' => true
        ));

        return TRUE;
    }
}