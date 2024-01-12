<?php

namespace IPS\steam;

use IPS\Member;
use IPS\Settings;
use IPS\Patterns\ActiveRecord;
use JsonException;

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!defined('\IPS\SUITE_UNIQUE_KEY')) {
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

/**
 * Steam Update Class
 */
class _Profile extends ActiveRecord
{
    /**
     * @brief    [ActiveRecord] Database Prefix
     */
    public static $databasePrefix = 'st_';

    /**
     * @brief    [ActiveRecord] ID Database Column
     */
    public static $databaseColumnId = 'member_id';

    /**
     * @brief    [ActiveRecord] Database table
     * @note    This MUST be over-ridden
     */
    public static $databaseTable = 'steam_profiles';

    /**
     * @brief    [ActiveRecord] Database ID Fields
     */
    protected static $databaseIdFields = array('st_member_id', 'st_steamid');

    /**
     * @brief    Bitwise keys
     */
    protected static $bitOptions = array();
    /**
     * @brief    [ActiveRecord] Multiton Store
     * @note    This needs to be declared in any child classes as well, only declaring here for editor
     *          code-complete/error-check functionality
     */
    protected static $multitons = array();
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
     * @param null $id
     * @param null       $idField
     * @param null       $extraWhereClause
     * @return \IPS\Patterns\ActiveRecord
     */
    public static function load($id, $idField = null, $extraWhereClause = null) : ActiveRecord
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
        $this->error = '';
        $this->personaname = '';
        $this->profileurl = '';
        $this->avatarhash = '';
        $this->personastate = 0;
        $this->timecreated = null;
        $this->lastlogoff = null;
        $this->last_update = null;
        $this->gameextrainfo = null;
        $this->gameserverip = null;
        $this->playtime_2weeks = null;
        $this->communityvisibilitystate = null;
        $this->gameid = null;
        $this->addfriend = '';
        $this->total_count = 0;
        $this->game_count = 0;
        $this->games = json_encode(array());
        $this->owned = json_encode(array());
        $this->restricted = 0;
        $this->player_level = json_encode(array());
        $this->player_groups = json_encode(array());
        $this->steamid = null;
        $this->steamid_hex = null;
    }

    /**
     * @return array
     * @throws JsonException
     */
    public function getLevel(): array
    {
        $playerLevel = array();
        if (isset($this->player_level)) {
            if(!\is_array($this->player_level) || !\count($this->player_level)) {
                $playerLevel = json_decode($this->player_level, true, 512, JSON_THROW_ON_ERROR);
            }
        }
        return $playerLevel;
    }

    /**
     * @param $value
     */
    public function set_personaname($value): void
    {
        // If their database isn't set up for mb4, strip 4 byte characters and replace with Diamond ?.
        if (Settings::i()->getFromConfGlobal('sql_utf8mb4') !== true) {
            $value = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $value);
        }
        $this->_data['personaname'] = $value;
    }

    /**
     * @param string $value
     * @throws JsonException
     */
    public function set_gameextrainfo($value): void
    {
        // If the value we're saving is NULL, save it and return.
        if (!$value) {
            $this->_data['gameextrainfo'] = null;

            return;
        }
        // Otherwise, let's see what we have... If we have a numeric value, it's a gameid, search owned / recent
        // If it's not numeric, set the string passed and move on.
        $name = null;
        if (\is_numeric($value) && Settings::i()->steam_get_owned) {
            $this->ownedGames = $this->getOwned();
            // If we're playing the game, we own it. Check the cache for the game name.
            if (isset($this->ownedGames[$value])) {
                $name = $this->ownedGames[$value]['name'];
            }
        } elseif (\is_numeric($value)) {
            $this->recentGames = $this->getRecent();
            if (isset($this->recentGames[$value])) {
                $name = $this->recentGames[$value]['name'];
            }
        } else {
            $name = $value;
        }
        $this->_data['gameextrainfo'] = $name;
    }

    /**
     * @return array|mixed
     * @throws JsonException
     */
    public function getOwned() : ?array
    {
        if (isset($this->owned)) {
            if (!\is_array($this->ownedGames) || !\count($this->ownedGames)) {
                $this->ownedGames = json_decode($this->owned, true, 512, JSON_THROW_ON_ERROR);
            }
            return $this->ownedGames;
        }

        return array();
    }

    /**
     * @param int $count
     * @return array
     */
    public function getRecent($count = 0): array
    {
        if (isset($this->games)) {
            if (!\is_array($this->recentGames) || !\count($this->recentGames)) {
                $this->recentGames = json_decode($this->games, true);
            }

            $temp = $this->recentGames;

            if ($count && \is_array($temp) && \count($temp)) {
                /* Limit to $count recent games, otherwise we may break a layout */
                while (\count($temp) > $count) {
                    $yoink = \array_pop($temp);
                    unset($yoink);
                }
            }

            return $temp;
        }

        return array();
    }

    /**
     * @return \IPS\Member
     */
    public function author(): Member
    {
        return Member::load($this->member_id);
    }
}