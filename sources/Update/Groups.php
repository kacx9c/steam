<?php

namespace IPS\steam\Update;

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!defined('\IPS\SUITE_UNIQUE_KEY')) {
    header((isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0') . ' 403 Forbidden');
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
    public $api = '';
    public $fail = array();
    public $extras = array();
    public $stError = '';
    public $cacheData = array();
    public $query = '';
    public $cache = array();
    public $count = 0;


    public function __construct()
    {
        /* Load the cache  data */
        if (isset(\IPS\Data\Store::i()->steamGroupData)) {
            $this->extras = \IPS\Data\Store::i()->steamGroupData;
        } else {
            $this->extras = array(
                'offset' => 0,
                'count'  => 0,
            );
        }
        if (!isset($this->extras['offset'])) {
            $this->extras['offset'] = 0;
        }

        \IPS\Data\Store::i()->steamGroupData = $this->extras;
    }

    // enhance to accept single groups to update.

    public static function sync($data = array())
    {
        $groups = array();
        $_delete = array();
        $_data = array();
        try {
            $select = 'g.*';
            $where = '';
            $query = \IPS\Db::i()->select($select, array('steam_groups', 'g'), $where);

            foreach ($query as $row) {
                $groups[$row['stg_id']] = \IPS\steam\Profile\Groups::constructFromData($row);
            }
        } catch (\UnderflowException $e) {

        }

        if (is_array($data) && count($data)) {

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
                    if (is_array($groups) && count($groups)) {
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
            if (is_array($groups) && count($groups)) {
                foreach ($groups as $g) {
                    $found = false;
                    if (in_array($g->id, $data)) {
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
        } elseif (!count($data)) {
            foreach ($groups as $g) {
                $_delete[] = $g;
            }
        }

        // Delete removed entries
        if (is_array($_delete) && count($_delete)) {
            foreach ($_delete as $d) {
                $d->delete();
            }
        }

        // Create new entries
        if (is_array($_data) && count($_data)) {
            foreach ($_data as $g) {
                $new = new \IPS\steam\Profile\Groups;

                if (preg_match('/^\d{18}$/', $g)) {
                    $url = "https://steamcommunity.com/gid/" . $g . "/memberslistxml/?xml=1";
                } else {
                    $url = "https://steamcommunity.com/groups/" . $g . "/memberslistxml/?xml=1";
                }

                $req = static::request($url);

                if ($req->httpResponseCode != 200) {

                    if (\IPS\Settings::i()->steam_diagnostics) {
                        throw new \Exception($req->httpResponseCode . ": getGroup");
                    }
                }
                try {
                    $values = $req->decodeXml();
                    $new->storeXML($values);
                } catch (\RuntimeException $e) {
                    $err = 1;
                    if (\IPS\Settings::i()->steam_diagnostics) {
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

    public static function request($url)
    {
        if ($url) {
            return \IPS\Http\Url::external($url)->request(\IPS\LONG_REQUEST_TIMEOUT)->get();
        } else {
            return;
        }
    }

    public function update()
    {
        try {
            $select = "g.*";
            $where = '';
            $query = \IPS\Db::i()->select($select, array('steam_groups', 'g'), $where, 'g.stg_id ASC',
                array($this->extras['offset'], 5), null, null, '011');

            foreach ($query as $row) {
                $groups[] = \IPS\steam\Profile\Groups::constructFromData($row);
            }
        } catch (\UnderflowException $e) {

        }
        $this->extras['count'] = $query->count(true);

        foreach ($groups as $g) {

            if (preg_match('/^\d{18}$/', $g->id)) {
                $url = "https://steamcommunity.com/gid/" . $g->id . "/memberslistxml/?xml=1";
            } else {
                $url = "https://steamcommunity.com/groups/" . $g->url . "/memberslistxml/?xml=1";
            }

            $req = static::request($url);

            if ($req->httpResponseCode != 200) {
                $this->failed($g, 'group_err_request');
                if (\IPS\Settings::i()->steam_diagnostics) {
                    throw new \Exception($req->httpResponseCode . ": getGroup");
                }
                continue;
            }
            try {
                $values = $req->decodeXml();
                $g->storeXML($values);
            } catch (\RuntimeException $e) {
                $this->failed($g, 'steam_err_getGroup');
                if (\IPS\Settings::i()->steam_diagnostics) {
                    throw new \Exception($e->getMessage());
                }
                continue;
            }

            unset($data);

            // If we got this far, there was no error.
            $g->error = '';
            $g->last_update = time();

            // Store the data
            $g->save();
        }

        $this->extras['offset'] = $this->extras['offset'] + 5;

        // If offset is greater than count we've hit the end.  Reset Offset for the next query.
        if ($this->extras['offset'] >= $this->extras['count']) {
            $this->extras['offset'] = 0;
        }
        \IPS\Data\Store::i()->steamGroupData = $this->extras;
    }

    protected function failed($g, $lang = null)
    {

        if (isset($g->id) || isset($g->name)) {
            $groupToLoad = isset($g->id) ? $g->id : $g->name;
            $group = \IPS\steam\Profile\Groups::load($groupToLoad);
        } else {
            return;
        }

        $group->error = ($lang ? $lang : '');
        $group->last_update = time();
        $group->save();

        return;
    }

    public function remove($group)
    {
        try {
            $r = \IPS\steam\Profile\Groups::load($group);
            $r->setDefaultValues();
            $r->save();

        } catch (\Exception $e) {
            return;
        }

        return;
    }

    public function error($raw = true)
    {
        if ($raw) {
            return $this->stError;
        }

        if ($this->stError) {
            $return = $this->stError;
        } elseif (is_array($this->failed) && count($this->failed)) {
            $return = \IPS\Lang::load(\IPS\Lang::defaultLanguage())->get('task_steam_profile') . " - " . implode(',',
                    $this->fail);
        } else {
            $return = null;
        }

        return $return;
    }


}