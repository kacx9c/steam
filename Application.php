<?php

namespace IPS\steam;

use IPS\Member;
use IPS\Application;
use IPS\Request;
use IPS\Output;
use IPS\Db;

/**
 * Blog Application Class
 */
class _Application extends Application
{
    /**
     * Init
     * @return    void
     */
    public function init(): void
    {
        /* If the viewing member cannot view the board (ex: guests must login first), then send a 404 Not Found header here, before the Login page shows in the dispatcher */
        if (!Member::loggedIn()->group['g_view_board'] && (Request::i()->module == 'steam' && Request::i()->controller == 'view' && Request::i()->do == 'rss')) {
            Output::i()->error('node_error', '2B221/1', 404, '');
        }
    }

    /**
     * Install 'other' items. Left blank here so that application classes can override for app
     *  specific installation needs. Always run as the last step.
     * @return void
     */
    public function installOther(): void
    {
        // Will need to get rid of this at some point and start managing everything from the login handler table
        // or profile fields.  Need to get out of core_members.
        if (!Db::i()->checkForColumn('core_members', 'steamid')) {
            Db::i()->addColumn('core_members', [
                'name'   => 'steamid',
                'type'   => 'VARCHAR',
                'length' => 17,
            ]);
        }

        // Will need to get rid of this at some point and start managing everything from the login handler table
        // or profile fields.  Need to get out of core_members.
        if (!Db::i()->checkForIndex('core_members', 'steamid')) {
            Db::i()->addIndex('core_members', array(
                'name'    => 'steamid',
                'type'    => 'key',
                'columns' => array('steamid'),
            ));
        }
    }
}