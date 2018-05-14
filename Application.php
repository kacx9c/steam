<?php

namespace IPS\steam;

/**
 * Blog Application Class
 */
class _Application extends \IPS\Application
{
	/**
	 * Init
	 *
	 * @return	void
	 */
	public function init()
	{
		/* If the viewing member cannot view the board (ex: guests must login first), then send a 404 Not Found header here, before the Login page shows in the dispatcher */
		if ( !\IPS\Member::loggedIn()->group['g_view_board'] and ( \IPS\Request::i()->module == 'steam' and \IPS\Request::i()->controller == 'view' and \IPS\Request::i()->do == 'rss' ) )
		{
			\IPS\Output::i()->error( 'node_error', '2B221/1', 404, '' );
		}
	}

    /**
     * Install 'other' items. Left blank here so that application classes can override for app
     *  specific installation needs. Always run as the last step.
     *
     * @return void
     */
    public function installOther()
    {
        if (!\IPS\Db::i()->checkForColumn('core_members', 'steamid')) {
            \IPS\Db::i()->addColumn('core_members', [
                'name' => 'steamid',
                'type' => 'VARCHAR',
                'length' => 17
            ]);
        }

        if (!\IPS\Db::i()->checkForIndex('core_members', 'steamid')) {
            \IPS\Db::i()->addIndex('core_members', array(
                'name' => 'steamid',
                'type' => 'key',
                'columns' => array('steamid')
            ));
        }

        try {
            \IPS\Db::i()->select('login_id', 'core_login_methods', array('login_classname=?', 'IPS\Login\Steam'))->first();
            \IPS\Db::i()->delete( 'core_login_methods', array('login_classname=?', 'IPS\Login\Steam'));
        } catch (\UnderflowException $e) {
            // Do nothing, we're creating a new login handler no matter what, and removing the old Sign in.
        }

        $maxLoginOrder = \IPS\Db::i()->select('MAX(login_order)', 'core_login_methods')->first();

        \IPS\Db::i()->insert('core_login_methods', array(
            'login_settings' => json_encode(array()),
            'login_classname' => 'IPS\steam\Login\Steam',
            'login_enabled' => 1,
            'login_order' => $maxLoginOrder + 1,
            'login_register' => 1,
            'login_acp' => 0
        ));
    }
}