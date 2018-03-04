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
	protected static $databaseIdFields = array( 'stg_id', 'stg_name', 'stg_url');

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

	public function storeXML($data)
	{
		$this->id = (string) $data->groupID64;
		$this->name = (string) $data->groupDetails->groupName;
		$this->summary = (string) $data->groupDetails->summary;
		$this->members = $data->members->steamID64;
		$this->avatarIcon = (string) $data->groupDetails->avatarIcon;
		$this->avatarMedium = (string) $data->groupDetails->avatarMedium;
		$this->avatarFull = (string) $data->groupDetails->avatarFull;
		$this->memberCount = (int) $data->memberCount;
		$this->membersInGame = (int) $data->groupDetails->membersInGame;
		$this->membersOnline = (int) $data->groupDetails->membersOnline;
		$this->membersInChat = (int) $data->groupDetails->membersInChat;
		$this->headline = (string) $data->groupDetails->headline;
		$this->url = (string) $data->groupDetails->groupURL;
		$this->last_update = time();
		$this->error = '';
	}

	public function set_members($values = array())
	{
		$this->_data['members'] = json_encode($values);
	}

	protected function avatarProxy( $key, $val )
	{
		$proxyUrl = NULL;
		if( $val && \IPS\Settings::i()->remote_image_proxy) {
			$proxyUrl = \IPS\Http\Url::createFromString(\IPS\Settings::i()->base_url . "applications/core/interface/imageproxy/imageproxy.php");
			$proxyUrl = $proxyUrl->setQueryString(array('img' => $val,
			                                            'key' => hash_hmac('sha256', $val, \IPS\Settings::i()->site_secret_key)
			));

			$this->_data[$key] = (string) $proxyUrl;
		}else
		{
			$this->_data[$key] = $val;
		}
	}

	public function set_avatarIcon( $value )
	{
		$this->avatarProxy( 'avatarIcon', $value);
	}

	public function set_avatarMedium( $value )
	{
		$this->avatarProxy( 'avatarMedium', $value);
	}

	public function set_avatarFull( $value )
	{
		$this->avatarProxy( 'avatarFull', $value);
	}

	public function url()
	{
		return "https://steamcommunity.com/groups/" . $this->url;
	}

	public function chat()
	{
		return "steam://friends/joinchat/" . $this->id;
	}

}