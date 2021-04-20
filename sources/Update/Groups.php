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
     * @brief    [IPS\Member]    Member object
     */
    public $groups = array();
    /**
     * @var string
     */
    public $api = '';
    /**
     * @var array
     */
    public $fail = array();
    /**
     * @var array|string
     */
    public $extras = array();
    /**
     * @var string
     */
    public $stError = '';
    /**
     * @var array
     */
    public $cacheData = array();
    /**
     * @var string
     */
    public $query = '';
    /**
     * @var array
     */
    public $cache = array();
    /**
     * @var int
     */
    public $count = 0;


    /**
     * _Groups constructor.
     */
    public function __construct()
    {
        /* Load the cache  data */
        $this->extras = Store::i()->steamGroupData ?? array('offset' => 0, 'count' => 0,);

        if (!isset($this->extras['offset'])) {
            $this->extras['offset'] = 0;
        }

        Store::i()->steamGroupData = $this->extras;
    }

// enhance to accept single groups to update.

    /**
     * @param array $data
     * @throws \Exception
     */
    public static function sync($data = array()) : void
    {
        $groups = array();
        $_delete = array();
        $_data = array();
        try {
            $select = 'g.*';
            $where = '';
            $query = Db::i()->select($select, array('steam_groups', 'g'), $where);

            foreach ($query as $row) {
                $groups[$row['stg_id']] = Profile\Groups::constructFromData($row);
            }
        } catch (\UnderflowException $e) {

        }

        if (\is_array($data) && \count($data)) {

            // Add groups that are missing
            foreach ($data as $d) {
                // If we have an ID, search for ID's.
                if (preg_match('/^\d{18}$/', $d)) {
                    if (!array_key_exists($d, $groups)) {
                        $_data[] = $d;
                        continue;
                    }
                } else {
                    $found = null;
                    if (\is_array($groups) && \count($groups)) {
                        foreach ($groups as $g) {
                            if (!strcasecmp($g->url, $d)) {
                                $found = $g->name;
                                break;
                            }
                        }
                    }
                    if (!$found) {
                        $_data[] = $d;
                        $found = null;
                    }
                }
            }
            // Check and see if groups were removed from the Setting
            if (\is_array($groups) && \count($groups)) {
                foreach ($groups as $g) {
                    $found = false;
                    if (\in_array($g->id, $data, false)) {
                        $found = true;
                    } else {
                        foreach ($data as $d) {
                            if (!strcasecmp($g->url, $d)) {
                                $found = true;
                                break;
                            }
                        }
                    }
                    if (!$found) {
                        $_delete[] = $g;
                    }
                }
            }
            // If we don't have anything in the setting, empty the table
        } else {
            foreach ($groups as $g) {
                $_delete[] = $g;
            }
        }

        // Delete removed entries
        if (\is_array($_delete) && \count($_delete)) {
            foreach ($_delete as $d) {
                $d->delete();
            }
        }

        // Create new entries
        if (\is_array($_data) && \count($_data)) {
            foreach ($_data as $g) {
                $new = new Profile\Groups;

                if (preg_match('/^\d{18}$/', $g)) {
                    $url = 'https://steamcommunity.com/gid/' . $g . '/memberslistxml/?xml=1';
                } else {
                    $url = 'https://steamcommunity.com/groups/' . $g . '/memberslistxml/?xml=1';
                }

                /**
                 * @var Response $req
                 */
                $req = static::request($url);

                if ($req->httpResponseCode != 200 || Settings::i()->steam_diagnostics) {
                    throw new \Exception($req->httpResponseCode . ': getGroup');
                }

                try {
                    $values = $req->decodeXml();
                    $new->storeXML($values);
                } catch (\RuntimeException $e) {
                    if (Settings::i()->steam_diagnostics) {
                        throw new \Exception($e->getMessage());
                    }
                    continue;
                }
                if (!array_key_exists($new->id, $groups)) {
                    $new->save();
                }
            }
        }
    }


    /**
     * @param $url
     * @return \IPS\Http\Url|null
     */
    public static function request($url): ?Url
    {
        /**
         * @var Curl|Sockets $req
         */
        $req = null;
        $return = null;
        try {
            $req = Url::external($url)->request(\IPS\LONG_REQUEST_TIMEOUT);
            $return = $req->get();
        } catch (Request\CurlException $e) {
            //Try one more time in case we're hitting to many requests...
            // Wait 3 seconds
            sleep(2);
            try {
                $req = Url::external($url)->request(\IPS\LONG_REQUEST_TIMEOUT);
                $return = $req->get();
            } catch (Request\CurlException $e) {
                throw new \OutOfRangeException($e);
            }
        }

        return $return;
    }

    /**
     * @throws \Exception
     */
    public function update(): void
    {
        $groups = array();
        $query = null;
        try {
            $select = 'g.*';
            $where = '';
            $query = Db::i()->select($select, array('steam_groups', 'g'), $where, 'g.stg_id ASC',
                array($this->extras['offset'], 5), null, null, '011');

            foreach ($query as $row) {
                $groups[] = Profile\Groups::constructFromData($row);
            }
        } catch (\UnderflowException $e) {

        }
        $this->extras['count'] = $query->count(true);

        foreach ($groups as $g) {

            if (preg_match('/^\d{18}$/', $g->id)) {
                $url = 'https://steamcommunity.com/gid/' . $g->id . '/memberslistxml/?xml=1';
            } else {
                $url = 'https://steamcommunity.com/groups/' . $g->url . '/memberslistxml/?xml=1';
            }
            /**
             * @var Response $req
             */
            $req = static::request($url);

            if ($req->httpResponseCode != 200) {
                $this->failed($g, 'group_err_request');
                if (Settings::i()->steam_diagnostics) {
                    throw new \Exception($req->httpResponseCode . ': getGroup');
                }
                continue;
            }
            try {
                $values = $req->decodeXml();
                $g->storeXML($values);
            } catch (\RuntimeException $e) {
                $this->failed($g, 'steam_err_getGroup');
                if (Settings::i()->steam_diagnostics) {
                    throw new \Exception($e->getMessage());
                }
                continue;
            }

            // If we got this far, there was no error.
            $g->error = '';
            $g->last_update = time();

            // Store the data
            $g->save();
        }

        $this->extras['offset'] += 5;

        // If offset is greater than count we've hit the end.  Reset Offset for the next query.
        if ($this->extras['offset'] >= $this->extras['count']) {
            $this->extras['offset'] = 0;
        }
        Store::i()->steamGroupData = $this->extras;
    }

    /**
     * @param      $g
     * @param null $lang
     */
    protected function failed($g, $lang = null): void
    {

        if (isset($g->id) || isset($g->name)) {
            $groupToLoad = $g->id ?? $g->name;
            $group = Profile\Groups::load($groupToLoad);
        } else {
            return;
        }

        $group->error = ($lang ?? '');
        $group->last_update = time();
        $group->save();
    }

    /**
     * @param $group
     */
    public function remove($group): void
    {
        try {
            $r = Profile\Groups::load($group);
            $r->setDefaultValues();
            $r->save();

        } catch (\Exception $e) {

        }
    }

    /**
     * @param bool $raw
     * @return string|null
     */
    public function error($raw = true): ?string
    {
        if ($raw) {
            return $this->stError;
        }

        if ($this->stError) {
            $return = $this->stError;
        } elseif (\is_array($this->failed) && \count($this->failed)) {
            $return = Lang::load(Lang::defaultLanguage())->get('task_steam_profile') . ' - ' . implode(',',
                    $this->fail);
        } else {
            $return = null;
        }

        return $return;
    }
}