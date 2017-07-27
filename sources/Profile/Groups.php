<?php

namespace IPS\steam\Profile;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Steam Update Class
 */
class _Groups extends \IPS\Patterns\ActiveRecord
{
	/**
	 * @brief	[ActiveRecord] Database Prefix
	 */
	public static $databasePrefix = 'stg_';

	/**
	 * @brief	[ActiveRecord] ID Database Column
	 */
	public static $databaseColumnId = 'id';

	/**
	 * @brief	[ActiveRecord] Database table
	 * @note	This MUST be over-ridden
	 */
	public static $databaseTable	= 'steam_groups';

	/**
	 * @brief	[ActiveRecord] Database ID Fields
	 */
	protected static $databaseIdFields = array( 'stg_id, stg_name');

	/**
	 * @brief	Bitwise keys
	 */
	protected static $bitOptions = array();

	public $ownedGames = array();

	public $recentGames = array();

	public $playerLevel = array();

	/**
	 * @brief	[ActiveRecord] Multiton Store
	 * @note	This needs to be declared in any child classes as well, only declaring here for editor code-complete/error-check functionality
	 */
	protected static $multitons	= array();

	public static function load( $id, $idField=NULL, $extraWhereClause=NULL )
	{
		try
		{
			if( $id === NULL OR $id === 0 OR $id === '' )
			{
				$classname = get_called_class();
				return new $classname;
			}
			$member = parent::load( $id, $idField, $extraWhereClause );
		}catch ( \OutOfRangeException $e )
		{
			$classname = get_called_class();
			return new $classname;
		}
		return $member;
	}
	/**
	 * Construct ActiveRecord from database row
	 *
	 * @param	array	$data							Row from database table
	 * @param	bool	$updateMultitonStoreIfExists	Replace current object in multiton store if it already exists there?
	 * @return	static
	 */
	public static function constructFromData( $data, $updateMultitonStoreIfExists = TRUE )
	{
		return parent::constructFromData($data, $updateMultitonStoreIfExists);

	}

	public function setDefaultValues()
	{
		$this->name = '';
		$this->summary = '';
		$this->members = array();
		$this->avatarIcon = '';
		$this->avatarMedium = '';
		$this->avatarFull = '';
		$this->memberCount = 0;
		$this->membersInGame = 0;
		$this->membersOnline = 0;
		$this->membersInChat = 0;
		$this->headline = '';
		$this->url = '';
	}

	public function set_members($values = array())
	{
		$this->_data['members'] = json_encode($values);
	}
}