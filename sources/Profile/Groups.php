<?php

namespace IPS\steam\Profile;

use IPS\Patterns\ActiveRecord;
use IPS\Http\Url;
use IPS\Settings;
use JsonException;


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
    public array $ownedGames = array();

    /**
     * @var array
     */
    public array $recentGames = array();

    /**
     * @var array
     */
    public array $playerLevel = array();

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
     * @return Groups
     */
    public static function load($id, $idField = null, $extraWhereClause = null): ?Groups
    {
        try {
            if ($id === 0 || $id === '') {
                $className = static::class;

                return new $className;
            }
            $member = parent::load($id, $idField, $extraWhereClause);
        } catch (\OutOfRangeException $e) {
            $className = static::class;

            return new $className;
        }

        return $member;
    }


    // TODO: Do I even need this since it's inherited ?
    /**
     * Construct ActiveRecord from database row
     * @param array $data                        Row from database table
     * @param bool $updateMultitonStoreIfExists Replace current object in multiton store if it already exists there?
     * @return    static
     */
    public static function constructFromData($data, $updateMultitonStoreIfExists = true): static
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
     * @param $group
     */
    public function storeXML($group) : void
    {
        $this->id = (string)$group->groupID64;
        $this->name = (string)$group->groupDetails->groupName;
        $this->summary = (string)$group->groupDetails->summary;
        $this->members = $group->members->steamID64;
        $this->avatarIcon = (string)$group->groupDetails->avatarIcon;
        $this->avatarMedium = (string)$group->groupDetails->avatarMedium;
        $this->avatarFull = (string)$group->groupDetails->avatarFull;
        $this->memberCount = (int)$group->memberCount;
        $this->membersInGame = (int)$group->groupDetails->membersInGame;
        $this->membersOnline = (int)$group->groupDetails->membersOnline;
        $this->membersInChat = (int)$group->groupDetails->membersInChat;
        $this->headline = (string)$group->groupDetails->headline;
        $this->url = (string)$group->groupDetails->groupURL;
        $this->last_update = time();
        $this->error = '';
    }

    /**
     * @param array $values
     * @throws JsonException
     */
    public function set_members($values = array()): void
    {
        $this->_data['members'] = json_encode($values, JSON_THROW_ON_ERROR);
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