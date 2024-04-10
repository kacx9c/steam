<?php

namespace IPS\steam\Update;

use IPS\Data\Store;
use IPS\Settings;
use IPS\Db;
use IPS\steam\Profile;
use IPS\Http\Url;
use IPS\Http\Response;
use IPS\Lang;
use IPS\Http\Request;
use IPS\Http\Request\Sockets;
use IPS\Http\Request\Curl;
use IPS\steam\Api;

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!defined('\IPS\SUITE_UNIQUE_KEY')) {
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

/**
 * Steam Update Class
 */
class _Groups
{
    /**
     * @var array
     */
    public $cache = array();
    protected static $instance = NULL;

    /**
     * _Groups constructor.
     */
    public function __construct()
    {
        /* Load the cache  data */
        $this->cache = Store::i()->steamGroupData ?? array('offset' => 0, 'count' => 0,);

        if (!isset($this->cache['offset'])) {
            $this->cache['offset'] = 0;
        }

        Store::i()->steamGroupData = $this->cache;
    }

    public static function i()
    {
        if( static::$instance === NULL )
        {
            $classname = \get_called_class();
            static::$instance = new $classname;
        }

        return self::$instance;
    }

    /**
     * Called when saving settings
     * @param array $groupSettings
     * @throws \Exception
     */
    public function sync($groupSettings = array()) : void
    {
        if (\count($groupSettings) === 0) {
            $this->deleteAllGroups();
            return;
        }

        $groupsToDelete = array();
        $groupsToAdd = array();
        try {
            $groups = $this->selectGroups();

            // $groupsById = ID64 of all groups in SQL
            // $groupsByUrl = text name ( url ) of groups.
            $groupsById = array_map([$this, 'groupIdMap'], $groups);
            $groupsByUrl = array_map([$this, 'groupsUrlMap'], $groups);
        } catch (\RuntimeException $e) {
            // TODO: Error handling
            return;
        }

        // GroupSettings could be a mix of ID64's and plain text
        foreach ($groupSettings as $groupSetting) {
            if (!array_key_exists($groupSetting, $groupsById) || !array_key_exists($groupSetting, $groupsByUrl)) {
                $groupsToAdd[] = $groupSetting;
            } else {
                $groupsToDelete[] = $groupSetting;
            }
        }

        $this->addGroups($groupsToAdd);
        $this->deleteGroups($groupsToDelete);
    }

    /**
     * Called by task execution, uses $limit when selecting groups.
     * @throws \Exception
     */
    public function update(): void
    {
        $limit = array($this->cache['offset'], 5);
        $query = null;
        try {
            $groups = $this->selectGroups($limit);
        } catch (\Exception $e) {
            // TODO: Error handling
            return;
        }

        foreach ($groups as $group) {
            try {
                $response = Api::i()->getGroup($group->id);
                $group->storeXML($response);
            } catch (\RuntimeException $e) {
                // TODO: Error handling
                continue;
            }
            $group->save();

            $this->cache['offset'] += 5;
        }
        // If offset is greater than count we've hit the end.  Reset Offset for the next query.
        if ($this->cache['offset'] >= $this->cache['count']) {
            $this->cache['offset'] = 0;
        }
        Store::i()->steamGroupData = $this->cache;
    }

    /**
     * Go to SQL and get from the steam_groups table
     * limit is an array required for \IPS\Db\Select clause
     * @param $limit
     * @return array
     */
    protected function selectGroups($limit = NULL): array
    {
        $groups = array();
        try {
            $select = 'g.*';
            $where = '';
            $query = Db::i()->select($select, array('steam_groups', 'g'), $where, 'g.stg_id ASC',
                $limit, null, null, '011');

            foreach ($query as $row) {
                $groups[$row['stg_id']] = Profile\Groups::constructFromData($row);
            }
        } catch (\UnderflowException $e) {
            throw new \RuntimeException($e->getMessage());
        }

        return $groups;
    }

    /**
     * Returns an array of the ID's for each group
     * @param $group
     * @return string
     */
    protected function groupIdMap($group): string {
        return $group->id;
    }

    /**
     * Returns an array of the plain text ( url ) for each group
     * @param $group
     * @return string
     */
    protected function groupsUrlMap($group): string {
        return $group->url;
    }

    /**
     * Delete the provided groups
     * @param $groups
     * @return void
     */
    protected function deleteGroups($groups): void
    {
        // Delete removed entries
        if (\count($groups)) {
            foreach ($groups as $group) {
                $deleteGroup = Profile\Groups::load($group);
                $deleteGroup->delete();
            }
        }
    }

    /**
     * Add the provided groups
     * @param $groups
     * @return void
     */
    protected function addGroups($groups): void {
        // Create new entries
        if (\count($groups)) {
            foreach ($groups as $group) {
                $addedGroups = new Profile\Groups;
                try {
                    $response = Api::i()->getGroup($group);
                    $addedGroups->storeXML($response);
                } catch (\RuntimeException $e) {
                    // TODO: Error handling
                    // throw new \Exception($e->getMessage());
                    continue;
                }
                $addedGroups->save();
            }
        }
    }

    /**
     * Go to SQL, get all groups, delete them all.
     * @return void
     */
    protected function deleteAllGroups(): void {
        $groups = $this->selectGroups();
        foreach($groups as $group)
        {
            $group->delete();
        }
    }
}