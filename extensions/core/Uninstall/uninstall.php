<?php
/**
 * @brief		Uninstall callback
 * @author		<a href='http://www.invisionpower.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) 2001 - SVN_YYYY Invision Power Services, Inc.
 * @license		http://www.invisionpower.com/legal/standards/
 * @package		IPS Social Suite
 * @subpackage	Steam Integration
 * @since		06 May 2016
 * @version		SVN_VERSION_NUMBER
 */

namespace IPS\steam\extensions\core\Uninstall;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Uninstall callback
 */
class _uninstall
{
	/**
	 * Code to execute before the application has been uninstalled
	 *
	 * @param	string	$application	Application directory
	 * @return	array
	 */
	public function preUninstall( $application )
	{
		try
		{
			\IPS\Db::i()->update('core_pfields_data', array( 'pf_type' => 'Text'), array('pf_type=?', 'Steamid'));
			// \IPS\Db::i()->dropColumn( 'core_groups', array( 'steam_index', 'steam_pull' ));
		}
		catch( \IPS\Db\Exception $e )
		{
			/* Ignore "Cannot drop because it does not exist" */
			if( $e->getCode() <> 1091 )
			{
				throw $e;
			}
		}
	}

	/**
	 * Code to execute after the application has been uninstalled
	 *
	 * @param	string	$application	Application directory
	 * @return	array
	 */
	public function postUninstall( $application )
	{	}
}