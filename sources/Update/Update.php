<?php

namespace IPS\steam;

/* To prevent PHP errors (extending class does not exist) revealing path */

use IPS\Task\Queue\OutOfRangeException;
use IPS\Settings;
use IPS\Data\Store;
use IPS\Db;
use IPS\Member;
use IPS\Http\Request;
use IPS\Http\Request\Sockets;
use IPS\Http\Request\Curl;
use IPS\Http\Url;
use IPS\Http\Response;
use IPS\Lang;
use IPS\core\ProfileFields\Field;
use InvalidArgumentException;
use Exception;
use RuntimeException;
use LogicException;

if (!\defined('\IPS\SUITE_UNIQUE_KEY')) {
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

/**
 * Steam Update Class
 */
class _Update
{
    /**
     * @var array
     */
    static protected $badgesToKeep = array('1', '2', '13', '17', '21', '23');
    /**
     * @brief    [IPS\Member]    Member object
     */
    public $member;
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
     * @var
     */
    protected $err;

    /**
     * @var
     */
    protected $m;

    /**
     * _Update constructor.
     * @throws \InvalidArgumentException
     */
    public function __construct()
    {
        $this->api = Settings::i()->steam_api_key;
        if (!$this->api) {
//          If we don't have an API key, throw an exception to log an error message
            throw new InvalidArgumentException('steam_err_noapi');
        }
        $this->steamLogin = Db::i()->checkForColumn('core_members', 'steamid') ? 1 : 0;
        $this->profile = new Profile;
        $emptyCache = array(
            'offset'         => 0,
            'count'          => 0,
            'cleanup_offset' => 0,
            'profile_offset' => 0,
            'profile_count'  => 0,
            'pf_id'          => 0,
            'pf_group_id'    => 0,
        );

//      Load the cache data
        $this->cache = Store::i()->steamData ?? $emptyCache;

        /* Save some resources, only get the profile field ID once every cycle instead of every time. */
        if ($this->cache['offset'] === 0 || !isset($this->cache['pf_id'], $this->cache['pf_group_id'])) {
            $this->getFieldID();
        }
        if (!isset($this->cache['offset'])) {
            $this->cache['offset'] = 0;
        }
        if (!isset($this->cache['profile_offset'])) {
            $this->cache['profile_offset'] = 0;
        }
        Store::i()->steamData = $this->cache;
        $this->members = array();
    }

    /**
     * @return void
     */
    public function getFieldID(): void
    {
        try {
            $cfID = Db::i()->select('pf_id,pf_group_id', 'core_pfields_data',
                array('pf_type=?', 'Steamid'))->first();
            $this->cache['pf_id'] = $cfID['pf_id'];
            $this->cache['pf_group_id'] = $cfID['pf_group_id'];
        } catch (Exception $e) {
            /* If the custom field doesn't exist, we'll get an underflow exception.  Just set it to 0 and move on */
            $this->cache['pf_id'] = 0;
            $this->cache['pf_group_id'] = 0;
        }
    }

    /**
     * @param int $single
     * @return bool
     * @throws \Exception
     */
    public function update($single = 0): bool
    {
        $members = array();
        if ($single) {
            $members[] = Profile::load($single);

            if (!$members[0]->steamid) {
                $member = Member::load($single);
                $steamid = $this->getSteamID($member);

                /* If they set their steamID, lets put them in the cache */
                if ($steamid) {
                    $this->m = Profile::load($member->member_id);
                    if (!$this->m->steamid) {
                        $this->m->member_id = $member->member_id;
                        $this->m->steamid = $steamid;
                        $this->m->setDefaultValues();
                        $this->m->save();
                        $members[] = $this->m;
                    }
                } else {
                    /* We don't have a SteamID for this member, jump ship */
                    $this->diagnostics(Lang::load(Lang::defaultLanguage())->get('steam_id_invalid'));

                    return false;
                }
            }
        } else {
            $select = 's.*';
            $where = "s.st_steamid>0 AND s.st_restricted!='1'";
            $query = Db::i()->select($select, array('steam_profiles', 's'), $where, 's.st_member_id ASC',
                array($this->cache['offset'], Settings::i()->steam_batch_count), null, null, '011');

            foreach ($query as $row) {
                $members[] = Profile::constructFromData($row);
            }
            $this->cache['count'] = $query->count(true);
        }

        foreach ($members as $p) {
            $this->err = 0;

            // Load member so we can make changes.
            $this->m = Member::load($p->member_id);

            // Store general information that doesn't rely on an API.
            $p->addfriend = 'steam://friends/add/' . $p->steamid;
            $p->last_update = time();

            /*
             * GET PLAYER LEVEL AND BADGES
             */
            $p = $this->getBadges($p);

            /*
             * GET VAC BAN STATUS
             */
            $p = $this->getPlayerBans($p);

            /*
             * GET GAMES PLAYED IN THE LAST 2 WEEKS
             */
            $p = $this->getRecentlyPlayedGames($p);

            /*
             * GET LIST OF GAMES OWNED
             */
            $p = $this->getOwnedGames($p);

            /*
             * GET PLAYER GROUPS
             */
            $p = $this->getUserGroupList($p);

            if (!$this->err) {
                $p->error = ''; // Correctly set member, so clear any errors.
            }
            $this->err = 0;

            // Store the data
            $p->save();

            // Lets clear any errors before we start the next member
            $this->stError = '';
        }
        if (!$single) {
            $this->cache['offset'] += (int)Settings::i()->steam_batch_count;
            if ($this->cache['offset'] >= $this->cache['count']) {
                $this->cache['offset'] = 0;
            }
            Store::i()->steamData = $this->cache;
        }

        return true;
    }

    /**
     * @param $m
     * @return bool|float|int|mixed|null|string
     * @throws \Exception
     */
    public function getSteamID(Member $m)
    {
        $steamid = null;
        if (isset($m->steamid) && $m->steamid != '0') {
            return $m->steamid;
        }

        $group = "core_pfieldgroups_{$this->cache['pf_group_id']}";
        $field = "core_pfield_{$this->cache['pf_id']}";

        if ($this->cache['pf_id'] && !isset($m->profileFields[$group][$field])) {
            //$m = Member::load($m->member_id);
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

                if (PHP_INT_SIZE === 8) {
                    $steamid = $_steam[2] * 2 + 76561197960265728 + $_steam[1];
                } elseif (function_exists('bcadd') && function_exists('bcmul') && extension_loaded('bcmath')) {
                    $steamid = bcadd(bcadd(bcmul($_steam[2], 2), '76561197960265728'), $_steam[1]);
                } else {
                    /* If we've gotten here it's a 64 bit server with a limit on PHP_INT_SIZE or a 32 bit server w/o bcmath installed */
                    throw new LogicException('Missing extension: php-bcmath');
                }


            } elseif (!$m->steamid && !$steamid) {
                $url = 'http://api.steampowered.com/ISteamUser/ResolveVanityURL/v0001/?key=' . $this->api . '&vanityurl=' . $m->profileFields[$group][$field];
                try {

                    /**
                     * @var Response $req
                     */
                    $req = $this->request($url);

                    if ($req->httpResponseCode != 200) {

                        // API Call failed, go ahead and store them for updating later.
                        $this->failed($m, 'steam_err_getvanity');
                        $this->diagnostics($req->httpResponseCode . ': getVanity');

                        return false;
                    }
                    try {
                        $id = $req->decodeJson();
                    } catch (RuntimeException $e) {
                        // Couldn't decode API response, go ahead and store them for updating later.
                        $this->failed($m, 'steam_err_getvanity');
                        $this->diagnostics($e->getMessage());

                        return false;
                    }
                    /* If the steam name is valid, store the 64 bit version, if not, skip 'em. */
                    if (\is_array($id['response']) && \count($id['response']) && ($id['response']['success'] == 1) && $id['response']['steamid']) {
                        $steamid = $id['response']['steamid'];
                    } else {
                        // Valid API response, they just entered a something stupid... Don't store.
                        // $this->failed($m, 'steam_id_invalid');

                        $this->diagnostics('ID Invalid');

                        return false;
                    }
                } catch (\OutOfRangeException $e) {

                    $this->diagnostics($e->getMessage());
                }
            } else {
                // If they don't have a steamID, don't create an entry. AIWA-4
                // $this->failed( $m, 'steam_no_steamid');
                return false;
            }
        }

        return $steamid;
    }

    /**
     * @param $url
     * @return null
     */
    protected function request($url)
    {
        /**
         * @var Curl|Sockets $req
         */
        $req = null;
        $json = null;
        try {
            $req = Url::external($url)->request(\IPS\LONG_REQUEST_TIMEOUT);
            $json = $req->get();
        } catch (Request\CurlException $e) {
            //Try one more time in case we're hitting to many requests...
            // Wait 3 seconds
            sleep(2);
            try {
                $req = Url::external($url)->request(\IPS\LONG_REQUEST_TIMEOUT);
                $json = $req->get();
            } catch (Request\CurlException $e) {
                throw new \OutOfRangeException($e);
            }
        }

        return $json;
    }

    /**
     * @param      $m
     * @param null $lang
     */
    public function failed($m, $lang = null): void
    {
        if (isset($m->member_id)) {
            $mem = $this->profile::load($m->member_id);
        } else {
            return;
        }
        $this->err = 1;

        // Either we loaded an existing record, or are working with a new record... Either way, update and save it.
        $mem->member_id = $m->member_id;
        $mem->error = ($lang ?? '');
        $mem->last_update = time();
        $mem->save();
        $this->fail[] = $m->member_id;
    }

    /**
     * @param $message
     */
    protected function diagnostics($message): void
    {
        if (Settings::i()->steam_diagnostics) {
            throw new RuntimeException($message);
        }
    }

    /**
     * @param $p
     * @return mixed
     */
    protected function getBadges($p)
    {
        $url = 'http://api.steampowered.com/IPlayerService/GetBadges/v1/?key=' . $this->api . '&steamid=' . $p->steamid;
        try {
            /**
             * @var Response $req
             */
            $req = $this->request($url);

            if ($req->httpResponseCode != 200) {
                $this->failed($this->m, 'steam_err_getlevel');
                $this->diagnostics($req->httpResponseCode . ': getLevel');
            }
            try {
                $level = $req->decodeJson();
            } catch (RuntimeException $e) {
                $this->failed($this->m, 'steam_err_getlevel');
                $this->diagnostics($e->getMessage());
            }

            // Store the data and unset the variable to free up memory
            if (isset($level)) {
                if (\is_array($level['response']['badges']) && \count($level['response']['badges'])) {
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
            unset($req, $level);
        } catch (\OutOfRangeException $e) {
            $this->failed($this->m, 'steam_err_getlevel');
            $this->diagnostics($e->getMessage());
        }

        return $p;
    }

    /**
     * @param $p
     * @return mixed
     */
    protected function getPlayerBans($p)
    {
        $url = 'http://api.steampowered.com/ISteamUser/GetPlayerBans/v1/?key=' . $this->api . '&steamids=' . $p->steamid;
        $vacBans = null;
        try {
            /**
             * @var Response $req
             */
            $req = $this->request($url);

            if ($req->httpResponseCode != 200) {
                $this->failed($this->m, 'steam_err_vacbans');
                $this->diagnostics($req->httpResponseCode . ': getVACBans');
            } else {
                try {
                    $vacBans = $req->decodeJson();
                } catch (RuntimeException $e) {
                    $this->failed($this->m, 'steam_err_vacbans');
                    $this->diagnostics($e->getMessage());
                }
                if (\is_array($vacBans)) {
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
                unset($vacBans, $req);
            }
        } catch (\OutOfRangeException $e) {
            $this->failed($this->m, 'steam_err_vacbans');
            $this->diagnostics($e->getMessage());
        }

        return $p;
    }

    /**
     * @param $p
     * @return mixed
     */
    protected function getRecentlyPlayedGames($p)
    {
        $url = 'http://api.steampowered.com/IPlayerService/GetRecentlyPlayedGames/v0001/?key=' . $this->api . '&steamid=' . $p->steamid . '&format=json';
        $games = array();
        $_games = array();
        try {
            /**
             * @var Response $req
             */
            $req = $this->request($url);

            if ($req->httpResponseCode != 200) {
                $this->failed($this->m, 'steam_err_getrecent');
                $this->diagnostics($req->httpResponseCode . ': getRecentGames');
            } else {
                try {
                    $games = $req->decodeJson();
                } catch (RuntimeException $e) {
                    $this->failed($this->m, 'steam_err_getrecent');
                    $this->diagnostics($e->getMessage());
                }

                // Store recently played game data and free up memory
                if (isset($games['response']['total_count'], $games['response']['games'])) {
                    $p->playtime_2weeks = 0;
                    foreach ($games['response']['games'] as $id => $g) {
                        // If we don't have a logo for the game, don't bother storing it. Still tally time played.
                        if (isset($g['img_icon_url'], $g['img_logo_url']) && $g['img_icon_url'] && $g['img_logo_url']) {
                            $_games[$g['appid']] = $g;
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
                unset($req, $games, $_games);
            }
        } catch (\OutOfRangeException $e) {
            $this->failed($this->m, 'steam_err_getrecent');
            $this->diagnostics($e->getMessage());
        }

        return $p;
    }

    /**
     * @param $p
     * @return mixed
     */
    protected function getOwnedGames($p)
    {
        $owned = array();
        $_owned = array();
        if (Settings::i()->steam_get_owned) {
            $url = 'http://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/?key=' . $this->api . '&steamid=' . $p->steamid . '&include_appinfo=1&format=json';
            try {
                /**
                 * @var Response $req
                 */
                $req = $this->request($url);

                if ($req->httpResponseCode != 200) {
                    $this->failed($this->m, 'steam_err_getowned');
                    $this->diagnostics($req->httpResponseCode . ': getOwned');
                } else {
                    try {
                        $owned = $req->decodeJson();
                    } catch (RuntimeException $e) {
                        $this->failed($this->m, 'steam_err_getowned');
                        $this->diagnostics($e->getMessage());
                    }
                    if (isset($owned['response']['game_count'], $owned['response']['games']) && Settings::i()->steam_get_owned) {
                        foreach ($owned['response']['games'] as $id => $g) {
                            if ($g['img_icon_url'] && $g['img_logo_url']) {
                                $_owned[$g['appid']] = $g;
                                //	img_icon_url, img_logo_url - these are the filenames of various images for the game. To construct the URL to the image, use this format:
                                //	http://media.steampowered.com/steamcommunity/public/images/apps/{appid}/{hash}.jpg
                            }
                        }
                        $p->owned = json_encode($_owned);
                        $p->game_count = ($owned['response']['game_count'] ?? 0);        // Total # of owned games, if we are pulling that data
                    } else {
                        $p->owned = json_encode(array());
                        $p->game_count = 0;
                    }
                    unset($req, $owned, $_owned);
                }
            } catch (\OutOfRangeException $e) {
                $this->failed($this->m, 'steam_err_getowned');
                $this->diagnostics($e->getMessage());
            }
        } else {
            $p->owned = json_encode(array());
        }

        return $p;
    }

    /**
     * @param $p
     * @return mixed
     */
    protected function getUserGroupList($p)
    {
        $url = 'https://api.steampowered.com/ISteamUser/GetUserGroupList/v1/?key=' . $this->api . '&steamid=' . $p->steamid;
        $_groups = null;
        try {
            $base = '103582791429521408';
            /**
             * @var Response $req
             */
            $req = $this->request($url);

            if ($req->httpResponseCode != 200) {
                $content = array();
                if ($req->httpResponseCode == 403) {
                    $content = $req->decodeJson();
                }
                if (!isset($content['response']['success'])) {
                    $this->failed($this->m, 'steam_err_getGroupList');
                    $this->diagnostics($req->httpResponseCode . ': getGroupList');
                }
            } else {
                try {
                    $groupList = $req->decodeJson();
                } catch (RuntimeException $e) {
                    $this->failed($this->m, 'steam_err_getGroupList');
                    $this->diagnostics($e->getMessage());
                }

                // Store the data and unset the variable to free up memory
                if (isset($groupList) && $groupList['response']['success'] == true) {
                    if (\is_array($groupList['response']['groups']) && \count($groupList['response']['groups'])) {
                        $_groups = array();
                        foreach ($groupList['response']['groups'] as $g) {
                            if (PHP_INT_SIZE == 8) {
                                $_groups[$g['gid']] = (int)$base + $g['gid'];
                            } elseif (function_exists('bcadd') && extension_loaded('bcmath')) {
                                $_groups[] = bcadd($base, $g['gid']);
                            } else {
                                /* If we've gotten here it's a 64 bit server with a limit on PHP_INT_SIZE or a 32 bit server w/o bcmath installed */
                                throw new RuntimeException('Missing extension: php-bcmath');
                            }
                        }
                    }
                    $p->player_groups = json_encode($_groups);
                } else {
                    $p->player_groups = json_encode(array());
                }
                unset($req, $groupList);
            }
        } catch (\OutOfRangeException $e) {
            $this->failed($this->m, 'steam_err_getGroups');
            $this->diagnostics($e->getMessage());
        }

        return $p;
    }

    /**
     * @param int $single
     * @return bool
     * @throws \Exception
     */
    public function updateProfile($single = 0): bool
    {
        $req = null;
        $done = 0;
        try {
            if (!$single) {
                $profile_count = round(Settings::i()->steam_profile_count / 100);
            } else {
                $profile_count = 1;
            }
            for ($i = 0; $i < $profile_count; $i++) {
                $ids = array();
                $steamids = '';
                $select = 's.st_member_id,s.st_steamid,s.st_restricted';
                $where = "s.st_steamid>0 AND s.st_restricted!='1'";
                if ($single) {
                    $where .= " AND s.st_member_id='{$single}'";

                    /* Is the member already in the database ? */
                    $s = Profile::load($single);
                    if ($s->member_id != $single || $s->steamid < 1) {
                        $this->m = Member::load($single);
                        $steamid = null;
                        if ($this->m->member_id) {
                            $steamid = $this->getSteamID($this->m);
                        }

                        /* If they set their steamID, lets put them in the cache */
                        if ($steamid) {
                            if (!$s->steamid) {
                                $s->member_id = $this->m->member_id;
                                $s->steamid = $steamid;
                                $s->setDefaultValues();
                                $s->save();
                            }
                        } else {
                            /**
                             * @var Lang $message
                             */
                            $message = Lang::load(Lang::defaultLanguage());
                            $this->diagnostics($message->get('steam_id_invalid'));

                            return false;
                        }
                    }
                }
                $query = Db::i()->select($select, array('steam_profiles', 's'), $where, 's.st_member_id ASC',
                    array($this->cache['profile_offset'], 100), null, null, '011');

                foreach ($query as $id => $row) {
                    $ids[$id] = $row['st_steamid'];
                    $profiles[$id] = array(
                        'member_id' => $row['st_member_id'],
                        'steamid'   => $row['st_steamid'],
                    );
                }
                $this->cache['profile_count'] = $query->count(true);

                if (\is_array($ids) && \count($ids)) {
                    $steamids = implode(',', $ids);
                }

                /*
                 * GET PLAYER SUMMARIES
                 */
                $profiles[] = null;
                $url = 'http://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key=' . $this->api . '&steamids=' . $steamids . '&format=json';
                try {
                    /**
                     * @var Response $req
                     */
                    $req = $this->request($url);

                    if ($req->httpResponseCode != 200) {
                        if ($single) {
                            $this->failed(Member::load($single), 'steam_err_getplayer');
                        }

                        /* Throw an Exception */
                        $this->diagnostics($req->httpResponseCode);

                        continue;
                    }
                    try {
                        $players = $req->decodeJson();
                    } catch (RuntimeException $e) {
                        if ($single) {
                            $this->failed(Member::load($single), 'steam_err_getplayer');
                        }
                        $this->diagnostics($e->getMessage());

                        continue;
                    }
                } catch (\OutOfRangeException $e) {
                    if ($single) {
                        $this->failed(Member::load($single), 'steam_err_getplayer');
                    }
                    $this->diagnostics($e->getMessage());

                    continue;
                }
                if (\is_array($players)) {
                    foreach ($players['response']['players'] as $id => $p) {
                        /* Random bug here.  Every other run of the task only one of the duplicates is updated.  Next run, both are updated */
                        if ($profiles[$id]['steamid'] === $p['steamid']) {
                            $s = Profile::load($profiles[$id]['member_id'], 'st_member_id');
                        } else {
                            $s = Profile::load($p['steamid'], 'st_steamid');
                        }

                        $this->m = Member::load($s->member_id);

                        if ($this->m->member_id) {
                            $s->member_id = $this->m->member_id;
                            $s->steamid = $p['steamid'];
                            $s->last_update = time();
                            $s->timecreated = $p['timecreated'] ?? null;
                            $s->communityvisibilitystate = $p['communityvisibilitystate'];
                            $s->personaname = $p['personaname'];
                            $s->profileurl = $p['profileurl'];
                            $s->avatar = $p['avatar'];
                            $s->avatarmedium = $p['avatarmedium'];
                            $s->avatarfull = $p['avatarfull'];
                            $s->personastate = $p['personastate'];
                            $s->lastlogoff = $p['lastlogoff'];
                            $s->gameserverip = $p['gameserverip'] ?? '';
                            $s->gameid = $p['gameid'] ?? 0;

                            if (isset($p['gameextrainfo']) || isset($p['gameid'])) {
                                $s->gameextrainfo = $p['gameextrainfo'] ?? $p['gameid'];
                            } else {
                                $s->gameextrainfo = null;
                            }

                            $s->error = '';
                            $s->save();
                        } /* Improve data handling in rewrite. Log error or remove profile */

                        $done++;
                        $this->cache['profile_offset']++;
                        if ($this->cache['profile_offset'] >= $this->cache['profile_count']) {
                            $this->cache['profile_offset'] = 0;
                            break;
                        }
                    }
                }
                /* If we've run through everyone we have, no reason to continue */
                if ($done >= $this->cache['profile_count']) {
                    break;
                }
            }
            unset($profiles);
            Store::i()->steamData = $this->cache;

            return true;
        } catch (\OutOfRangeException $e) {
            $this->diagnostics($e->getMessage());

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
    public function cleanup($offset = -1): void
    {
        // AIWA-101 If cache does not initialize properly, cleanup_offset may not exist.
        if ($offset == -1) {
            $offset = $this->cache['cleanup_offset'] ?? 0;
            if ($offset) {
                $this->cache['cleanup_offset'] = 0;
            }
        }
        $this->load($offset);

        if (\is_array($this->members) && \count($this->members)) {
            foreach ($this->members as $this->m) {
                $steamid = ($this->m->steamid ?: $this->getSteamID($this->m));

                $s = Profile::load($this->m->member_id);

                /* If they don't have an entry, create one... If their entry doesn't match,
                   purge it and update the steamID */
                if (!$s->steamid || ($s->steamid != $steamid)) {
                    $s->setDefaultValues();
                    $s->steamid = $steamid;
                    $s->member_id = $this->m->member_id;
                    $s->last_update = time();
                    $s->save();
                }
            }
        }
        $this->cache['cleanup_offset'] += (int)Settings::i()->steam_batch_count;
        if ($this->cache['cleanup_offset'] >= $this->cache['count']) {
            $this->cache['cleanup_offset'] = 0;
        }
        /* Set the Extra data Cache */
        Store::i()->steamData = $this->cache;
    }

    /**
     * @param int $offset
     * @return void
     */
    public function load($offset = 0): void
    {
        /* We are loading new members, if there is anyone still there, dump 'em. */
        $this->members = array();
        $query = null;

        if ($offset > $this->cache['count']) {
            $offset = $this->cache['count'];
        }
        if ($this->steamLogin || ($this->cache['pf_id'] && $this->cache['pf_group_id'])) {
            // Build select and where clauses
            $select_member = 'm.*';
            //$select_pfields = "p.field_". $this->cache   ['pf_id'];
            $select_pfields = 'p.*';
            $where = 'p.member_id=m.member_id';

            // INNER join, INNER join, INNER join!!!!!

            if ($this->cache['pf_id']) {
                $select = $select_member . ',' . $select_pfields;
                $where .= ' AND (p.field_' . $this->cache['pf_id'] . ' IS NOT NULL';
                $where .= $this->steamLogin ? ' OR m.steamid>0)' : ')';

                $query = Db::i()->select($select, array('core_members', 'm'), null, 'm.member_id ASC',
                    array($offset, Settings::i()->steam_batch_count), null, null, '110')
                    ->join(array('core_pfields_content', 'p'), $where, 'INNER');

            } elseif ($this->steamLogin) {
                $select_member .= ',m.steamid';
                $select = $select_member;
                $where = 'm.steamid>0';

                $query = Db::i()->select($select, array('core_members', 'm'), $where, 'm.member_id ASC',
                    array($offset, Settings::i()->steam_batch_count), null, null, '110');
            }

            // Execute one of the queries built above
            foreach ($query as $row) {
                $this->m = new Member;
                if (isset($row['m'])) {
                    $member = $this->m::constructFromData($row['m']);
                } else {
                    $member = $this->m::constructFromData($row);
                }
                if (!$member->member_id) {
                    break;
                }
                if (\is_array($row['p']) && \count($row['p'])) {
                    // Set Location STAFF so task execution, running as guest, can still see the field data.
                    foreach (Field::values($row['p'], 'STAFF') as $group => $fields) {
                        $member->profileFields['core_pfieldgroups_' . $group] = $fields;
                    }
                } else {
                    $member->profileFields = array();
                }
                $this->members[] = $member;
            }
            // Count of all records found ignoring the limit
            $this->cache['count'] = $query->count(false);
        } else {
            $this->stError = Lang::load(Lang::defaultLanguage())->get('steam_field_invalid');
        }
    }

    /**
     * @param $member
     */
    public function remove($member): void
    {
        try {
            $r = Profile::load($member);
            $r->setDefaultValues();
            $r->save();
        } catch (Exception $e) {
//          Need to define what to do here...
        }
    }

    /**
     * @param $member
     * @return bool
     */
    public function restrict($member): bool
    {
        try {
            if ($member != null) {
                $p = Profile::load($member);
                $p->member_id = $member; // Make sure we set the member_id just in case the member doesn't actually exist.
                $p->restricted = 1;
                $p->save();
            } else {
                return false;
            }
        } catch (Exception $e) {
            $this->diagnostics($e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * @param $member
     * @return bool
     */
    public function unrestrict($member): bool
    {
        try {
            if ($member != null) {
                $p = Profile::load($member);
                if ($p->restricted == 1) {
                    $p->restricted = 0;
                    $p->save();
                }
            } else {
                return false;
            }
        } catch (Exception $e) {
            $this->diagnostics($e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * @param bool $raw
     * @return null|string
     */
    public function error($raw = true): string
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

    /**
     * @param $element
     * @return bool
     */
    protected function badges($element): bool
    {
        return \in_array($element['badgeid'], self::$badgesToKeep, false);
    }
}