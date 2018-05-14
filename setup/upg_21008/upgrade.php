<?php


namespace IPS\steam\setup\upg_21008;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * 2.1.8 Upgrade Code
 */
class _Upgrade
{
	/**
	 * ...
	 * @copyright Lavoaster github.com/Lavoaster/
     * @license http://opensource.org/licenses/mit-license.php The MIT License
	 * @return	array|bool	If returns TRUE, upgrader will proceed to next step. If it returns any other value, it will set this as the value of the 'extra' GET parameter and rerun this step (useful for loops)
	 */
	public function step1()
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

        return TRUE;
	}


    /**
     * @return	array|bool	If returns TRUE, upgrader will proceed to next step. If it returns any other value, it will set this as the value of the 'extra' GET parameter and rerun this step (useful for loops)
     */
    public function step2()
    {
        $done = \IPS\Request::i()->extra ?: 0;
        $method = \IPS\Login\Handler::findMethod('IPS\steam\Login\Steam');

        // Convert all uses of core_members->steamid and put them into core_login_links.
        // Get the login_id from step1, and loop through core_members where steamid exists.
        // Do NOT use steam_profiles as duplicate steamID's may exists when using the custom profile field.

        $select = 'm.*';
        $where = 'm.steamid>0';

        $query = \IPS\Db::i()->select( $select, array('core_members', 'm'), $where, 'm.member_id ASC', array( $done, 10), NULL, NULL, '111');
        $insert = array();
        foreach($query as $row)
        {
            $member = \IPS\Member::constructFromData($row);
            $insert[] = array(
                'token_login_method'    => $method->id,
                'token_member'          => $member->member_id,
                'token_identifier'      => $member->steamid,
                'token_linked'          => 1,
            );
            ++$done;
        }

        \IPS\Db::i()->insert('core_login_links', $insert);
        $count = $query->count(TRUE);
        if($count <= $done) {
            $done = 0;
        }
        if(!$done) {
            return TRUE;
        }

        return $done;
    }
	
	// You can create as many additional methods (step2, step3, etc.) as is necessary.
	// Each step will be executed in a new HTTP request
}