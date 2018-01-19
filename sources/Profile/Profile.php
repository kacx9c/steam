<?php

namespace IPS\steam;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Steam Update Class
 */
class _Profile extends \IPS\Patterns\ActiveRecord
{
	/**
	 * @brief	[ActiveRecord] Database Prefix
	 */
	public static $databasePrefix = 'st_';

	/**
	 * @brief	[ActiveRecord] ID Database Column
	 */
	public static $databaseColumnId = 'member_id';

	/**
	 * @brief	[ActiveRecord] Database table
	 * @note	This MUST be over-ridden
	 */
	public static $databaseTable	= 'steam_profiles';

	/**
	 * @brief	[ActiveRecord] Database ID Fields
	 */
	protected static $databaseIdFields = array( 'st_member_id', 'st_steamid');

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
		$this->error 		= '';
		$this->personaname	= '';
		$this->profileurl	= '';
		$this->avatar		= '';
		$this->avatarmedium	= '';
		$this->avatarfull	= '';
		$this->personastate	= 0;
		$this->timecreated 	= NULL;
		$this->lastlogoff	= NULL;
		$this->last_update	= NULL;
		$this->gameextrainfo	= NULL;
		$this->gameserverip		= NULL;
		$this->playtime_2weeks	= NULL;
		$this->communityvisibilitystate	= NULL;
		$this->gameid		= NULL;
		$this->addfriend	= '';
		$this->total_count	= 0;
		$this->game_count	= 0;
		$this->games 		= json_encode(array());
		$this->owned		= json_encode(array());
		$this->restricted	= 0;
		$this->player_level = json_encode(array());
	}

	public function getRecent($count = 0)
	{
		if(isset($this->games))
		{
			if(!is_array($this->recentGames) || !count($this->recentGames))
			{
				$this->recentGames = json_decode($this->games, TRUE);
			}

			$temp = $this->recentGames;

			if(is_array($temp) && count($temp) && $count)
			{
				/* Limit to $count recent games, otherwise we may break a layout */
				while(count($temp) > $count)
				{
					$yoink = array_pop($temp);
					unset($yoink);
				}
			}

			return $temp;
		}else
		{
			return array();
		}
	}

	public function getOwned()
	{
		if(isset($this->owned))
		{
			if(!is_array($this->ownedGames) || !count($this->ownedGames))
			{
				$this->ownedGames = json_decode($this->owned, TRUE);
			}
			return $this->ownedGames;
		}else
		{
			return array();
		}

	}

	public function getLevel()
	{
		if(isset($this->player_level))
		{
			if(!is_array($this->playerLevel) || !count($this->playerLevel))
			{
				$this->playerLevel = json_decode($this->player_level, TRUE);
			}
			return $this->playerLevel;
		}else
		{
			return array();
		}

	}

	public function set_personaname( $value )
	{
		// If their database isn't set up for mb4, strip 4 byte characters and replace with Diamond ?.
		if ( \IPS\Settings::i()->getFromConfGlobal('sql_utf8mb4') !== TRUE)
		{
			$value = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $value);
		}
		$this->_data['personaname'] = $value;
	}

	public function set_gameextrainfo( $value )
	{
		$name = '';
		if($value) {
			$this->ownedGames = $this->getOwned();
			// If we're playing the game, we own it. Check the cache for the game name.
			if (is_array($this->ownedGames[$value])) {
				$name = $this->ownedGames[$value]['name'];
			}
		}

		$this->_data['gameextrainfo'] = $name;
	}

	public function author()
	{
		return \IPS\Member::load($this->member_id);
	}

}