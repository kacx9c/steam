<?php

namespace IPS\steam;

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!\defined('\IPS\SUITE_UNIQUE_KEY')) {
    header((isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

/**
 * Steam Update Class
 */
class _Update
{
    /**
     * @brief    [IPS\Member]    Member object
     */
    public $member = null;
    /**
     * @var array
     */
    public $cfID = array();
    /**
     * @var int
     */
    public $steamLogin = 0;
    /**
     * @var array
     */
    public $members = array();
    /**
     * @var string
     */
    public $api = '';
    /**
     * @var array
     */
    public $fail = array();
    /**
     * @var int
     */
    public $unrestrict = 0;
    /**
     * @var array
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
     * @var array
     */
    protected $badgesToKeep = array('1', '2', '13', '17', '21', '23');

    /**
     * _Update constructor.
     * @throws \Exception
     */
    public function __construct()
    {
        $this->api = \IPS\Settings::i()->steam_api_key;

        if (!$this->api) {
            /* // If we don't have an API key, throw an exception to log an error message // */
            throw new \Exception('steam_err_noapi');
        }

        $this->steamLogin = \IPS\DB::i()->checkForColumn('core_members', 'steamid') ? 1 : 0;

        $this->profile = new \IPS\steam\Profile;
        /* Load the cache  data */
        if (isset(\IPS\Data\Store::i()->steamData)) {
            $this->extras = \IPS\Data\Store::i()->steamData;
        } else {
            $this->extras = array(
                'offset'         => 0,
                'count'          => 0,
                'cleanup_offset' => 0,
                'profile_offset' => 0,
                'profile_count'  => 0,
                'pf_id'          => 0,
                'pf_group_id'    => 0,
            );
        }
        /* Save some resources, only get the profile field ID once every cycle instead of every time. */
        if ($this->extras['offset'] == 0 || !isset($this->extras['pf_id']) || !isset($this->extras['pf_group_id'])) {
            $this->cfID = $this->getFieldID();
            $this->extras['pf_id'] = $this->cfID['pf_id'];
            $this->extras['pf_group_id'] = $this->cfID['pf_group_id'];
        } else {
            $this->cfID['pf_id'] = $this->extras['pf_id'];
            $this->cfID['pf_group_id'] = $this->extras['pf_group_id'];
        }
        if (!isset($this->extras['offset'])) {
            $this->extras['offset'] = 0;
        }
        if (!isset($this->extras['profile_offset'])) {
            $this->extras['profile_offset'] = 0;
        }

        \IPS\Data\Store::i()->steamData = $this->extras;
        $this->members = array();
    }

    /**
     * @return array|mixed
     */
    public function getFieldID()
    {
        try {
            $this->cfID = \IPS\DB::i()->select('pf_id,pf_group_id', 'core_pfields_data',
                array('pf_type=?', 'Steamid'))->first();
        } catch (\Exception $e) {
            /* If the custom field doesn't exist, we'll get an underflow exception.  Just store null and move on */

            $this->cfID['pf_id'] = null;
            $this->cfID['pf_group_id'] = null;
        }

        return $this->cfID;
    }

    /**
     * @param int $single
     * @return bool
     * @throws \Exception
     */
    public function update($single = 0)
    {
        if ($single) {
            $members[] = \IPS\steam\Profile::load($single);

            if (!$members[0]->steamid) {
                $member = \IPS\Member::load($single);
                $steamid = $this->getSteamID($member);

                /* If they set their steamID, lets put them in the cache */
                if ($steamid) {
                    $m = \IPS\steam\Profile::load($member->member_id);
                    if (!$m->steamid) {
                        $m->member_id = $member->member_id;
                        $m->steamid = $steamid;
                        $m->setDefaultValues();
                        $m->save();

                        $members[] = $m;
                    }

                } else {
                    /* We don't have a SteamID for this member, jump ship */
                    if (\IPS\Settings::i()->steam_diagnostics) {
                        throw new \Exception(\IPS\Lang::load(\IPS\Lang::defaultLanguage())->get('steam_id_invalid'));
                    }

                    return false;
                }
            }
        } else {
            $select = "s.*";
            $where = "s.st_steamid>0 AND s.st_restricted!='1'";
            $query = \IPS\Db::i()->select($select, array('steam_profiles', 's'), $where, 's.st_member_id ASC',
                array($this->extras['offset'], \IPS\Settings::i()->steam_batch_count), null, null, '011');

            foreach ($query as $row) {
                $members[] = \IPS\steam\Profile::constructFromData($row);
            }

            $this->extras['count'] = $query->count(true);
        }

        foreach ($members as $p) {
            $err = 0;
            // Load member so we can make changes.
            $m = \IPS\Member::load($p->member_id);

            // Store general information that doesn't rely on an API.
            $p->addfriend = "steam://friends/add/" . $p->steamid;
            $p->last_update = time();

            /*
             * GET PLAYER LEVEL AND BADGES
             */

            $url = "http://api.steampowered.com/IPlayerService/GetBadges/v1/?key=" . $this->api . "&steamid=" . $p->steamid;
            try {
                $req = $this->request($url);
                if ($req->httpResponseCode != 200) {
                    $this->failed($m, 'steam_err_getlevel');
                    $err = 1;

                    if (\IPS\Settings::i()->steam_diagnostics) {
                        throw new \Exception($req->httpResponseCode . ": getLevel");
                    }

                }
                try {
                    $level = $req->decodeJson();
                } catch (\RuntimeException $e) {
                    $this->failed($m, 'steam_err_getlevel');
                    $err = 1;
                    if (\IPS\Settings::i()->steam_diagnostics) {
                        throw new \Exception($e->getMessage());
                    }
                }

                // Store the data and unset the variable to free up memory
                if (isset($level)) {
                    if (is_array($level['response']['badges']) && count($level['response']['badges'])) {
                        // Prune data and only keep what's needed.
                        $player_badges = array_filter($level['response']['badges'], array($this, 'badges'));
                        unset($level['response']['badges']);
                        $level['response']['badges'] = $player_badges;
                        unset($player_badges);

                    }
                    $p->player_level = json_encode($level['response']);
                } else {
                    $p->player_level = json_encode(array());
                }
                unset($req);
                unset($level);

            } catch (\OutOfRangeException $e) {
                $this->failed($m, 'steam_err_getlevel');
                $err = 1;

                if (\IPS\Settings::i()->steam_diagnostics) {
                    throw new \Exception($e->getMessage());
                }
            }

            /*
             * GET VAC BAN STATUS
             */

            $url = "http://api.steampowered.com/ISteamUser/GetPlayerBans/v1/?key=" . $this->api . "&steamids=" . $p->steamid;
            try {
                $req = $this->request($url);

                if ($req->httpResponseCode != 200) {
                    $this->failed($m, 'steam_err_vacbans');
                    $err = 1;

                    if (\IPS\Settings::i()->steam_diagnostics) {
                        throw new \Exception($req->httpResponseCode . ": getVACBans");
                    }
                } else {
                    try {
                        $vacBans = $req->decodeJson();
                    } catch (\RuntimeException $e) {
                        $this->failed($m, 'steam_err_vacbans');
                        $err = 1;

                        if (\IPS\Settings::i()->steam_diagnostics) {
                            throw new \Exception($e->getMessage());
                        }
                    }
                    if (is_array($vacBans)) {
                        foreach ($vacBans['players'] as $v) {
                            if ($v['CommunityBanned'] || $v['VACBanned']) {
                                $p->vac_status = '1';
                                $p->vac_bans = json_encode($v);
                            } else {
                                $p->vac_status = '0';
                                $p->vac_bans = json_encode(array());
                            }
                        }
                    } else {
                        $p->vac_status = '0';
                        $p->vac_bans = json_encode(array());
                    }
                    unset($vacBans);
                    unset($req);
                }
            } catch (\OutOfRangeException $e) {
                if (\IPS\Settings::i()->steam_diagnostics) {
                    throw new \Exception($e->getMessage());
                }
                $this->failed($m, 'steam_err_vacbans');
                $err = 1;
            }

            /*
             * GET GAMES PLAYED IN THE LAST 2 WEEKS
             */

            $url = "http://api.steampowered.com/IPlayerService/GetRecentlyPlayedGames/v0001/?key=" . $this->api . "&steamid=" . $p->steamid . "&format=json";

            try {
                $req = $this->request($url);

                if ($req->httpResponseCode != 200) {
                    $this->failed($m, 'steam_err_getrecent');
                    $err = 1;

                    if (\IPS\Settings::i()->steam_diagnostics) {
                        throw new \Exception($req->httpResponseCode . ": getRecent");
                    }
                } else {
                    try {
                        $games = $req->decodeJson();
                    } catch (\RuntimeException $e) {
                        $this->failed($m, 'steam_err_getrecent');
                        $err = 1;

                        if (\IPS\Settings::i()->steam_diagnostics) {
                            throw new \Exception($e->getMessage());
                        }
                    }

                    // Store recently played game data and free up memory
                    if (isset($games['response']['total_count']) AND isset($games['response']['games'])) {
                        $p->playtime_2weeks = 0;
                        foreach ($games['response']['games'] as $id => $g) {
                            // If we don't have a logo for the game, don't bother storing it. Still tally time played.
                            if (isset($g['img_icon_url']) && isset($g['img_logo_url'])) {
                                if ($g['img_icon_url'] && $g['img_logo_url']) {
                                    $_games[$g['appid']] = $g;
                                }
                            }
                            $p->playtime_2weeks += $g['playtime_2weeks'];
                            //	img_icon_url, img_logo_url - these are the filenames of various images for the game. To construct the URL to the image, use this format:
                            //	http://media.steampowered.com/steamcommunity/public/images/apps/{appid}/{hash}.jpg
                        }
                        $p->games = json_encode($_games);
                        $p->total_count = $games['response']['total_count']; // Total counts of games played in last 2 weeks
                    } else {
                        $p->playtime_2weeks = 0;
                        $p->total_count = 0;
                        $p->games = json_encode(array());
                    }
                    unset($req);
                    unset($games);
                    unset($_games);
                }
            } catch (\OutOfRangeException $e) {
                $this->failed($m, 'steam_err_getrecent');
                $err = 1;
                if (\IPS\Settings::i()->steam_diagnostics) {
                    throw new \Exception($e->getMessage());
                }
            }

            /*
             * GET LIST OF GAMES OWNED
             */

            if (\IPS\Settings::i()->steam_get_owned) {
                $url = "http://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/?key=" . $this->api . "&steamid=" . $p->steamid . "&include_appinfo=1&format=json";

                try {
                    $req = $this->request($url);

                    if ($req->httpResponseCode != 200) {
                        $this->failed($m, 'steam_err_getowned');
                        $err = 1;
                        if (\IPS\Settings::i()->steam_diagnostics) {
                            throw new \Exception($req->httpResponseCode . ": getOwned");
                        }
                    } else {
                        try {
                            $owned = $req->decodeJson();
                        } catch (\RuntimeException $e) {
                            $this->failed($m, 'steam_err_getowned');
                            $err = 1;
                            if (\IPS\Settings::i()->steam_diagnostics) {
                                throw new \Exception($e->getMessage());
                            }
                        }

                        if (isset($owned['response']['game_count']) && \IPS\Settings::i()->steam_get_owned && isset($owned['response']['games'])) {
                            foreach ($owned['response']['games'] as $id => $g) {
                                if ($g['img_icon_url'] && $g['img_logo_url']) {
                                    $_owned[$g['appid']] = $g;
                                    //	img_icon_url, img_logo_url - these are the filenames of various images for the game. To construct the URL to the image, use this format:
                                    //	http://media.steampowered.com/steamcommunity/public/images/apps/{appid}/{hash}.jpg
                                }
                            }
                            $p->owned = json_encode($_owned);
                            $p->game_count = (isset($owned['response']['game_count']) ? $owned['response']['game_count'] : 0);        // Total # of owned games, if we are pulling that data
                        } else {
                            $p->owned = json_encode(array());
                            $p->game_count = 0;
                        }
                        unset($req);
                        unset($owned);
                        unset($_owned);
                    }
                } catch (\OutOfRangeException $e) {
                    $this->failed($m, 'steam_err_getowned');
                    $err = 1;
                    if (\IPS\Settings::i()->steam_diagnostics) {
                        throw new \Exception($e->getMessage());
                    }
                }

            } else {
                $p->owned = json_encode(array());
            }

            /*
             * GET PLAYER GROUPS
             */

            $url = "https://api.steampowered.com/ISteamUser/GetUserGroupList/v1/?key=" . $this->api . "&steamid=" . $p->steamid;
            try {
                $base = "103582791429521408";
                $req = $this->request($url);
                if ($req->httpResponseCode != 200) {
                    $content = array();
                    if ($req->httpResponseCode == 403) {
                        $content = $req->decodeJson();
                    }
                    if (!isset($content['response']['success'])) {
                        $this->failed($m, 'steam_err_getGroupList');
                        $err = 1;

                        if (\IPS\Settings::i()->steam_diagnostics) {
                            throw new \Exception($req->httpResponseCode . ": getGroupList");
                        }
                    }
                } else {
                    try {
                        $groupList = $req->decodeJson();
                    } catch (\RuntimeException $e) {
                        $this->failed($m, 'steam_err_getGroupList');
                        $err = 1;
                        if (\IPS\Settings::i()->steam_diagnostics) {
                            throw new \Exception($e->getMessage());
                        }
                    }

                    // Store the data and unset the variable to free up memory
                    if (isset($groupList) && $groupList['response']['success'] == true) {
                        if (is_array($groupList['response']['groups']) && count($groupList['response']['groups'])) {
                            $_groups = array();
                            foreach ($groupList['response']['groups'] as $g) {
                                if (PHP_INT_SIZE == 8) {
                                    $_groups[$g['gid']] = $base + $g['gid'];
                                } elseif (extension_loaded('bcmath') && function_exists('bcadd')) {
                                    $_groups[] = bcadd($base, $g['gid'], 0);
                                } else {
                                    /* If we've gotten here it's a 64 bit server with a limit on PHP_INT_SIZE or a 32 bit server w/o bcmath installed */
                                    throw new \Exception('Missing extension: php-bcmath');
                                }
                            }

                        }
                        $p->player_groups = json_encode($_groups);
                    } else {
                        $p->player_groups = json_encode(array());
                    }
                    unset($req);
                    unset($groupList);
                }
            } catch (\OutOfRangeException $e) {
                $this->failed($m, 'steam_err_getGroups');
                $err = 1;

                if (\IPS\Settings::i()->steam_diagnostics) {
                    throw new \Exception($e->getMessage());
                }
            }

            if (!$err) {
                $p->error = ''; // Correctly set member, so clear any errors.
            }
            $err = 0;

            // Store the data
            $p->save();

            // Lets clear any errors before we start the next member
            $this->stError = '';
        }
        if (!$single) {
            $this->extras['offset'] = $this->extras['offset'] + \IPS\Settings::i()->steam_batch_count;
            if ($this->extras['offset'] >= $this->extras['count']) {
                $this->extras['offset'] = 0;
            }
            \IPS\Data\Store::i()->steamData = $this->extras;
        }

        return true;
    }

    /**
     * @param $m
     * @return bool|float|int|mixed|null|string
     * @throws \Exception
     */
    public function getSteamID($m)
    {
        $steamid = null;

        if (isset($m->steamid)) {
            if ($m->steamid && $m->steamid != '0') {
                return $m->steamid;
            }
        }

        $group = "core_pfieldgroups_{$this->cfID['pf_group_id']}";
        $field = "core_pfield_{$this->cfID['pf_id']}";

        if (!isset($m->profileFields[$group][$field]) && $this->cfID['pf_id']) {
            //$m = \IPS\Member::load($m->member_id);
            $m->profileFields = $m->profileFields('PROFILE');
        }
        // Don't just check if the var exists / isset.  Check if it has something in it.
        if (!empty($m->profileFields[$group][$field])) {
            if (!$m->steamid && preg_match('/^\d{17}$/', $m->profileFields[$group][$field])) {
                // We have a 17 digit number, lets just assume it's a 64bit Steam ID
                $steamid = $m->profileFields[$group][$field];
            } elseif (!$m->steamid && preg_match('/STEAM_(\d+?):(\d+?):(\d+?)$/', $m->profileFields[$group][$field])) {
                // Format STEAM_X:Y:Z
                // ID64 = (Z*2) + 76561197960265728 + Y
                $_steam = explode(':', $m->profileFields[$group][$field]);

                if (PHP_INT_SIZE == 8) {
                    $steamid = $_steam[2] * 2 + 76561197960265728 + $_steam[1];
                } elseif (extension_loaded('bcmath') && function_exists('bcadd') && function_exists('bcmul')) {
                    $steamid = bcadd(bcadd(bcmul($_steam[2], 2, 0), '76561197960265728', 0), $_steam[1], 0);
                } else {
                    /* If we've gotten here it's a 64 bit server with a limit on PHP_INT_SIZE or a 32 bit server w/o bcmath installed */
                    throw new \Exception('Missing extension: php-bcmath');
                }


            } elseif (!$m->steamid && !$steamid) {
                $url = "http://api.steampowered.com/ISteamUser/ResolveVanityURL/v0001/?key=" . $this->api . "&vanityurl=" . $m->profileFields[$group][$field];
                $req = $this->request($url);

                if ($req->httpResponseCode != 200) {
                    $this->failed($m, 'steam_err_getvanity');
                    if (\IPS\Settings::i()->steam_diagnostics) {
                        throw new \Exception($req->httpResponseCode . ': getVanity');
                    }

                    return false;
                }
                try {
                    $id = $req->decodeJson();
                } catch (\RuntimeException $e) {
                    $this->failed($m, 'steam_err_getvanity');
                    if (\IPS\Settings::i()->steam_diagnostics) {
                        throw new \Exception($e->getMessage);
                    }

                    return false;
                }
                /* If the steam name is valid, store the 64 bit version, if not, skip 'em. */
                if (is_array($id['response']) && count($id['response']) && ($id['response']['success'] == 1) && $id['response']['steamid']) {
                    $steamid = $id['response']['steamid'];
                } else {
                    $this->failed($m, 'steam_id_invalid');

                    if (\IPS\Settings::i()->steam_diagnostics) {
                        throw new \Exception('ID Invalid');
                    }

                    return false;
                }
            }
        } else {
            // If they don't have a steamID, don't create an entry. AIWA-4
            // $this->failed( $m, 'steam_no_steamid');
            return false;
        }

        return $steamid;
    }

    /**
     * @param $url
     * @return null
     */
    protected function request($url)
    {
        if ($url) {
            return \IPS\Http\Url::external($url)->request(\IPS\LONG_REQUEST_TIMEOUT)->get();
        }

        return null;
    }

    /**
     * @param      $m
     * @param null $lang
     */
    protected function failed($m, $lang = null)
    {
        if (isset($m->member_id)) {
            $mem = $this->profile->load($m->member_id);
        } else {
            return;
        }

        // Either we loaded an existing record, or are working with a new record... Either way, update and save it.
        $mem->member_id = $m->member_id;
        $mem->error = ($lang ? $lang : '');
        $mem->last_update = time();
        $mem->save();
        $this->fail[] = $m->member_id;
    }

    /**
     * @param int $single
     * @return bool
     * @throws \Exception
     */
    public function updateProfile($single = 0)
    {
        try {
            $done = 0;
            if (!$single) {
                $profile_count = round((\IPS\Settings::i()->steam_profile_count / 100), 0);
            } else {
                $profile_count = 1;
            }
            for ($i = 0; $i < $profile_count; $i++) {
                $ids = array();
                $steamids = '';
                $select = "s.st_member_id,s.st_steamid,s.st_restricted";
                $where = "s.st_steamid>0 AND s.st_restricted!='1'";
                if ($single) {
                    $where .= " AND s.st_member_id='{$single}'";

                    /* Is the member already in the database ? */
                    $s = \IPS\steam\Profile::load($single);
                    if ($s->member_id != $single || $s->steamid < 1) {
                        $m = \IPS\Member::load($single);

                        if ($m->member_id) {
                            $steamid = $this->getSteamID($m);
                        }

                        /* If they set their steamID, lets put them in the cache */
                        if ($steamid) {
                            if (!$s->steamid) {
                                $s->member_id = $m->member_id;
                                $s->steamid = $steamid;
                                $s->setDefaultValues();
                                $s->save();
                            }

                        } else {
                            if (\IPS\Settings::i()->steam_diagnostics) {
                                throw new \Exception(\IPS\Lang::load(\IPS\Lang::defaultLanguage())->get('steam_id_invalid'));
                            }

                            return false;
                        }
                    }
                }
                $query = \IPS\Db::i()->select($select, array('steam_profiles', 's'), $where, 's.st_member_id ASC',
                    array($this->extras['profile_offset'], 100), null, null, '011');

                foreach ($query as $id => $row) {
                    $ids[$id] = $row['st_steamid'];
                    $profiles[$id] = array(
                        'member_id' => $row['st_member_id'],
                        'steamid'   => $row['st_steamid'],
                    );
                }

                $this->extras['profile_count'] = $query->count(true);

                if (is_array($ids) && count($ids)) {
                    $steamids = implode(",", $ids);
                }

                /*
                 * GET PLAYER SUMMARIES
                 */

                $url = "http://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key=" . $this->api . "&steamids=" . $steamids . "&format=json";
                try {
                    $req = $this->request($url);

                    if ($req->httpResponseCode != 200) {
                        if ($single) {
                            $this->failed(\IPS\Member::load($single), 'steam_err_getplayer');
                        }

                        /* Throw an Exception */
                        if (\IPS\Settings::i()->steam_diagnostics) {
                            throw new \Exception($req->httpResponseCode);
                        }
                        continue;
                    }
                    try {
                        $players = $req->decodeJson();
                    } catch (\RuntimeException $e) {
                        if ($single) {
                            $this->failed(\IPS\Member::load($single), 'steam_err_getplayer');
                        }
                        if (\IPS\Settings::i()->steam_diagnostics) {
                            throw new \Exception($e->getMessage());
                        }
                        continue;
                    }

                } catch (\OutOfRangeException $e) {
                    if ($single) {
                        $this->failed(\IPS\Member::load($single), 'steam_err_getplayer');
                    }
                    if (\IPS\Settings::i()->steam_diagnostics) {
                        throw new \Exception($e->getMessage());
                    }
                    continue;
                }
                if (is_array($players)) {
                    foreach ($players['response']['players'] as $id => $p) {
                        /* Random bug here.  Every other run of the task only one of the duplicates is updated.  Next run, both are updated */
                        if ($profiles[$id]['steamid'] === $p['steamid']) {
                            $s = Profile::load($profiles[$id]['member_id'], 'st_member_id');
                        } else {
                            $s = Profile::load($p['steamid'], 'st_steamid');
                        }

                        $m = \IPS\Member::load($s->member_id);

                        if ($m->member_id) {
                            $s->member_id = $m->member_id;
                            $s->steamid = $p['steamid'];
                            $s->last_update = time();
                            $s->timecreated = (isset($p['timecreated']) ? $p['timecreated'] : null);
                            $s->communityvisibilitystate = $p['communityvisibilitystate'];
                            $s->personaname = $p['personaname'];
                            $s->profileurl = $p['profileurl'];
                            $s->avatar = $p['avatar'];
                            $s->avatarmedium = $p['avatarmedium'];
                            $s->avatarfull = $p['avatarfull'];
                            $s->personastate = $p['personastate'];
                            $s->lastlogoff = $p['lastlogoff'];
                            $s->gameserverip = (isset($p['gameserverip']) ? $p['gameserverip'] : '');
                            $s->gameid = (isset($p['gameid']) ? $p['gameid'] : 0);


                            if (isset($p['gameextrainfo']) || isset($p['gameid'])) {
                                $s->gameextrainfo = (isset($p['gameextrainfo']) ? $p['gameextrainfo'] : $p['gameid']);
                            } else {
                                $s->gameextrainfo = null;
                            }

                            $s->error = '';
                            $s->save();
                        } /* Improve data handling in rewrite. Log error or remove profile */
                        
                        $done++;
                        $this->extras['profile_offset']++;
                        if ($this->extras['profile_offset'] >= $this->extras['profile_count']) {
                            $this->extras['profile_offset'] = 0;
                            break;
                        }
                    }
                }
                /* If we've run through everyone we have, no reason to continue */
                if ($done >= $this->extras['profile_count']) {
                    break;
                }

            }
            unset($profiles);
            \IPS\Data\Store::i()->steamData = $this->extras;

            return true;
        } catch (\OutOfRangeException $e) {
            if (\IPS\Settings::i()->steam_diagnostics) {
                throw new \OutOfRangeException;
            }

            return false;
        }
    }

    /* 	Just in case a profile isn't caught with memberSync
        this will make sure everyone with a valid steamid in their account
        gets put into the steam_profile table for update.
    */
    /**
     * @param int $offset
     * @throws \Exception
     */
    public function cleanup($offset = -1)
    {
        // AIWA-101 If cache does not initialize properly, cleanup_offset may not exist.
        if ($offset == -1) {
            if (isset($this->extras['cleanup_offset'])) {
                $offset = $this->extras['cleanup_offset'];
            } else {
                $offset = 0;
                $this->extras['cleanup_offset'] = 0;
            }
        }
        $cleanup = $this->load($offset);

        if (\is_array($cleanup) && \count($cleanup)) {
            foreach ($cleanup as $m) {
                $steamid = ($m->steamid ?: $this->getSteamID($m));

                $s = Profile::load($m->member_id);

                /* If they don't have an entry, create one... If their entry doesn't match,
                   purge it and update the steamID */
                if (!$s->steamid || ($s->steamid != $steamid)) {
                    $s->setDefaultValues();
                    $s->steamid = $steamid;
                    $s->member_id = $m->member_id;
                    $s->last_update = time();
                    $s->save();
                }
            }
        }

        $this->extras['cleanup_offset'] = $this->extras['cleanup_offset'] + \IPS\Settings::i()->steam_batch_count;
        if ($this->extras['cleanup_offset'] >= $this->extras['count']) {
            $this->extras['cleanup_offset'] = 0;
        }

        /* Set the Extra data Cache */
        \IPS\Data\Store::i()->steamData = $this->extras;
    }

    /**
     * @param int $offset
     * @return array
     */
    public function load($offset = 0)
    {
        /* We are loading new members, if there is anyone still there, dump 'em. */
        unset($this->members);

        if (($this->cfID['pf_id'] && $this->cfID['pf_group_id']) || $this->steamLogin) {
            // Build select and where clauses
            $select_member = "m.*";
            //$select_pfields = "p.field_". $this->cfID['pf_id'];
            $select_pfields = "p.*";

            $where = "p.member_id=m.member_id";

            // INNER join, INNER join, INNER join!!!!!

            if ($this->cfID['pf_id'] && $this->steamLogin) {
                $select_member .= ",m.steamid";
                $select = $select_member . "," . $select_pfields;
                $where .= " AND (p.field_" . $this->cfID['pf_id'] . "<>'' OR m.steamid>0)";

                $query = \IPS\Db::i()->select($select, array('core_members', 'm'), null, 'm.member_id ASC',
                    array($offset, \IPS\Settings::i()->steam_batch_count), null, null, '111')
                    ->join(array('core_pfields_content', 'p'), $where, 'INNER');

            } elseif ($this->cfID['pf_id']) {
                $select = $select_member . "," . $select_pfields;
                $where .= " AND (p.field_" . $this->cfID['pf_id'] . "<>'')";

                $query = \IPS\Db::i()->select($select, array('core_members', 'm'), null, 'm.member_id ASC',
                    array($offset, \IPS\Settings::i()->steam_batch_count), null, null, '111')
                    ->join(array('core_pfields_content', 'p'), $where, 'INNER');

            } elseif ($this->steamLogin) {
                $select_member .= ",m.steamid";
                $select = $select_member;
                $where = "m.steamid>0";

                $query = \IPS\Db::i()->select($select, array('core_members', 'm'), $where, 'm.member_id ASC',
                    array($offset, \IPS\Settings::i()->steam_batch_count), null, null, '111');
            }

            // Execute one of the queries built above
            foreach ($query as $row) {
                $m = new \IPS\Member;
                if (isset($row['m'])) {
                    $member = $m->constructFromData($row['m']);
                } else {
                    $member = $m->constructFromData($row);
                }
                if (!$member->real_name || !$member->member_id) {
                    break;
                }
                if (\is_array($row['p']) && \count($row['p'])) {
                    foreach (\IPS\core\ProfileFields\Field::values($row['p'], 'PROFILE') as $group => $fields) {
                        $member->profileFields['core_pfieldgroups_' . $group] = $fields;
                    }
                } else {
                    $member->profileFields = array();
                }
                $this->members[] = $member;
            }
            // Count of all records found ignoring the limit
            $this->extras['count'] = $query->count(true);

        } else {
            $this->stError = \IPS\Lang::load(\IPS\Lang::defaultLanguage())->get('steam_field_invalid');
        }

        return $this->members;
    }

    /**
     * @param $member
     */
    public function remove($member)
    {
        try {
            $r = \IPS\steam\Profile::load($member);
            $r->setDefaultValues();
            $r->save();

        } catch (\Exception $e) {
            return;
        }

        return;
    }

    /**
     * @param $member
     * @return bool
     */
    public function restrict($member)
    {
        try {
            if ($member != null) {
                $p = \IPS\steam\Profile::load($member);
                $p->member_id = $member; // Make sure we set the member_id just in case the member doesn't actually exist.
                $p->restricted = 1;
                $p->save();
            } else {
                return false;
            }
        } catch (\Exception $e) {
            if (\IPS\Settings::i()->steam_diagnostics) {
                throw new \OutOfRangeException;
            }

            return false;
        }

        return true;
    }

    /**
     * @param $member
     * @return bool
     */
    public function unrestrict($member)
    {
        try {
            if ($member != null) {
                $p = \IPS\steam\Profile::load($member);
                if ($p->restricted == 1) {
                    $p->restricted = 0;
                    $p->save();
                }
            } else {
                return false;
            }
        } catch (\Exception $e) {
            if (\IPS\Settings::i()->steam_diagnostics) {
                throw new \OutOfRangeException;
            }

            return false;
        }

        return true;
    }

    /**
     * @param bool $raw
     * @return null|string
     */
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

    /**
     * @param $element
     * @return bool
     */
    protected function badges($element)
    {

        if (in_array($element['badgeid'], $this->badgesToKeep) && !array_key_exists('appid', $element)) {
            return true;
        }

        return false;
    }

}