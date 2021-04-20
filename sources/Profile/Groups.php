<?php

namespace IPS\steam\Profile;

use IPS\Patterns\ActiveRecord;
use IPS\Http\Url;
use IPS\Settings;


/* To prevent PHP errors (extending class does not exist) revealing path */
if (!defined('\IPS\SUITE_UNIQUE_KEY')) {
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

/**
 * Steam Update Class
 */
class _Groups extends ActiveRecord
{
    /**
     * @brief    [ActiveRecord] Database Prefix
     */
    public static $databasePrefix = 'stg_';

    /**
     * @brief    [ActiveRecord] ID Database Column
     */
    public static $databaseColumnId = 'id';

    /**
     * @brief    [ActiveRecord] Database table
     * @note    This MUST be over-ridden
     */
    public static $databaseTable = 'steam_groups';

    /**
     * @brief    [ActiveRecord] Database ID Fields
     */
    protected static $databaseIdFields = array('stg_id', 'stg_name', 'stg_url');

    /**
     * @brief    Bitwise keys
     */
    protected static $bitOptions = array();

    /**
     * @var array
     */
    public $ownedGames = array();

    /**
     * @var array
     */
    public $recentGames = array();

    /**
     * @var array
     */
    public $playerLevel = array();

    /**
     * @brief    [ActiveRecord] Multiton Store
     * @note    This needs to be declared in any child classes as well, only declaring here for editor
     *          code-complete/error-check functionality
     */
    protected static $multitons = array();

    /**
     * @param int|string $id
     * @param null       $idField
     * @param null       $extraWhereClause
     * @return \IPS\steam\Profile\Groups
     */
    public static function load($id, $idField = null, $extraWhereClause = null) : ?Groups
    {
        try {
            if ($id === null || $id === 0 || $id === '') {
                $classname = static::class;

                return new $classname;
            }
            $member = parent::load($id, $idField, $extraWhereClause);
        } catch (\OutOfRangeException $e) {
            $classname = static::class;

            return new $classname;
        }

        return $member;
    }


    // TODO: Do I even need this since it's inherited ?
    /**
     * Construct ActiveRecord from database row
     * @param array $data                        Row from database table
     * @param bool  $updateMultitonStoreIfExists Replace current object in multiton store if it already exists there?
     * @return    static
     */
    public static function constructFromData($data, $updateMultitonStoreIfExists = true)
    {
        return parent::constructFromData($data, $updateMultitonStoreIfExists);
    }

    /**
     *
     */
    public function setDefaultValues() : void
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

    /**
     * @param $data
     */
    public function storeXML($data) : void
    {
        $this->id = (string)$data->groupID64;
        $this->name = (string)$data->groupDetails->groupName;
        $this->summary = (string)$data->groupDetails->summary;
        $this->members = $data->members->steamID64;
        $this->avatarIcon = (string)$data->groupDetails->avatarIcon;
        $this->avatarMedium = (string)$data->groupDetails->avatarMedium;
        $this->avatarFull = (string)$data->groupDetails->avatarFull;
        $this->memberCount = (int)$data->memberCount;
        $this->membersInGame = (int)$data->groupDetails->membersInGame;
        $this->membersOnline = (int)$data->groupDetails->membersOnline;
        $this->membersInChat = (int)$data->groupDetails->membersInChat;
        $this->headline = (string)$data->groupDetails->headline;
        $this->url = (string)$data->groupDetails->groupURL;
        $this->last_update = time();
        $this->error = '';
    }

    /**
     * @param array $values
     */
    public function set_members($values = array()): void
    {
        $this->_data['members'] = json_encode($values);
    }

    /**
     * @param $value
     */
    public function set_avatarIcon($value): void
    {
        $this->avatarProxy('avatarIcon', $value);
    }

    /**
     * @param $key
     * @param $val
     */
    protected function avatarProxy($key, $val): void
    {
        $proxyUrl = null;
        if ($val && Settings::i()->remote_image_proxy) {
            $proxyUrl = Url::createFromString(Settings::i()->base_url . 'applications/core/interface/imageproxy/imageproxy.php');
            $proxyUrl = $proxyUrl->setQueryString(array(
                'img' => $val,
                'key' => hash_hmac('sha256', $val, Settings::i()->site_secret_key),
            ));

            $this->_data[$key] = (string)$proxyUrl;
        } else {
            $this->_data[$key] = $val;
        }
    }

    /**
     * @param $value
     */
    public function set_avatarMedium($value): void
    {
        $this->avatarProxy('avatarMedium', $value);
    }

    /**
     * @param $value
     */
    public function set_avatarFull($value): void
    {
        $this->avatarProxy('avatarFull', $value);
    }

    /**
     * @return string
     */
    public function url(): string
    {
        return 'https://steamcommunity.com/groups/' . $this->url;
    }

    /**
     * @return string
     */
    public function chat(): string
    {
        return 'steam://friends/joinchat/' . $this->id;
    }

}