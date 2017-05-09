<?php


namespace IPS\steam\setup\upg_20013;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * 2.0.13 Upgrade Code
 */
class _Upgrade
{
	/**
	 * ...
	 *
	 * @return	array	If returns TRUE, upgrader will proceed to next step. If it returns any other value, it will set this as the value of the 'extra' GET parameter and rerun this step (useful for loops)
	 */
	public function step1()
	{
		try
		{
			\IPS\Db::i()->delete('steam_profiles', array( '(st_error=? OR st_steamid=?) AND st_restricted=0', 'steam_no_steamid', '0'));

		}catch( \IPS\Db\Exception $e)
		{
			// Do nothing
		}


		return TRUE;
	}
}