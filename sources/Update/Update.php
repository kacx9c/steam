<?php

namespace IPS\steam;

use IPS\core\ProfileFields\Field;
use IPS\Data\Store;
use IPS\Db;
use IPS\Http\Response;
use IPS\Lang;
use IPS\Member;
use IPS\Settings;
use InvalidArgumentException;
use Exception;
use RuntimeException;
use LogicException;

if (!\defined('\IPS\SUITE_UNIQUE_KEY')) {
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

/**
 * Class _Update
 * @package IPS\steam
 */
class _Update extends AbstractSteam
{
    /**
     * @var array
     */
    public $fail = array();
    /**
     * @var string
     */
    public $stError = '';
    /**
     * @var int
     */
    public $count = 0;
    /**
     * @var int
     */
    protected $error;

    /**
     * _Update constructor.
     * @throws \InvalidArgumentException
     * @throws \JsonException
     */
    public function __construct()
    {
        $this->api = Settings::i()->steam_api_key;
        if (!$this->api) {
            throw new InvalidArgumentException('steam_err_noapi');
        }
        $this->cache = parent::buildStore();
        $this->steamLogin = Db::i()->checkForColumn('core_members', 'steamid') ? 1 : 0;

        // TODO: Users may not want profile cache, only login handler
    }

    /**
     * @param int $single
     * @return bool
     * @throws \Exception
     */
    public function update($single = 0): bool
    {
        if ($single) {
            $steamProfiles = $this->getSingleUser($single);
        } else {
            $steamProfiles = $this->getAllProfiles();
            $this->updateCache();
        }
        foreach ($steamProfiles as $steamProfile) {
//            $member = Member::load($steamProfile->member_id);

//  Keep the error scope local
            $this->error = 0;
            $steamProfile->addfriend = 'steam://friends/add/' . $steamProfile->steamid;
            $steamProfile->last_update = time();
            try {
                $steamProfile = $this->getBadges($steamProfile);
                $steamProfile = $this->getPlayerBans($steamProfile);
                $steamProfile = $this->getRecentlyPlayedGames($steamProfile);
                $steamProfile = $this->getOwnedGames($steamProfile);
                $steamProfile = $this->getUserGroupList($steamProfile);
            } catch (\Exception $e) {

            }
            if (!$this->error) {
                $steamProfile->error = '';
            }
            $steamProfile->save();

            // Keeping these local will mean they don't have to get reset
            $this->error = 0;
            $this->stError = '';
        }

        return true;
    }

    /**
     * @param $profile
     * @return array
     * @throws \Exception
     */
    public function getSingleUser($profile): array
    {
        $members[] = Profile::load($profile);

        if (!$members[0]->steamid) {
            $member = Member::load($profile);
            $steamid = $this->getSteamID($member);

            /* If they set their steamID, lets put them in the cache */
            if ($steamid) {
                $this->m = Profile::load($member->member_id);
                if (!$this->m->steamid) {
//                        $this->m->setDefaultValues();
                    $this->m = $this->updateProfile($profile);
//                        $this->m->member_id = $member->member_id;
//                        $this->m->steamid = $steamid;
//                        $this->m->save();
                    $members[] = $this->m;
                }
            } else {
                /* We don't have a SteamID for this member, jump ship */
                self::diagnostics(Lang::load(Lang::defaultLanguage())->get('steam_id_invalid'));

                return array();
            }
        }

        return $members;
    }

    // TODO Maybe move this to helper

    /**
     * @param \IPS\Member $m
     * @return mixed
     */
    public function getSteamID(Member $m): mixed
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
                    $req = self::request($url);

                    if ($req->httpResponseCode != 200) {

                        // API Call failed, go ahead and store them for updating later.
                        $this->failed($m, 'steam_err_getvanity');
                        self::diagnostics($req->httpResponseCode . ': getVanity');

                        return false;
                    }
                    try {
                        $id = $req->decodeJson();
                    } catch (RuntimeException $e) {
                        // Couldn't decode API response, go ahead and store them for updating later.
                        $this->failed($m, 'steam_err_getvanity');
                        self::diagnostics($e->getMessage());

                        return false;
                    }
                    /* If the steam name is valid, store the 64 bit version, if not, skip 'em. */
                    if (\is_array($id['response']) && \count($id['response']) && ($id['response']['success'] == 1) && $id['response']['steamid']) {
                        $steamid = $id['response']['steamid'];
                    } else {
                        // Valid API response, they just entered a something stupid... Don't store.
                        // $this->failed($m, 'steam_id_invalid');

                        self::diagnostics('ID Invalid');

                        return false;
                    }
                } catch (\OutOfRangeException $e) {

                    self::diagnostics($e->getMessage());
                }
            } else {
                // If they don't have a steamID, don't create an entry. AIWA-4
                // $this->failed( $m, 'steam_no_steamid');
                return false;
            }
        }

        return $steamid;
    }

    // TODO Maybe move this to helper

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
        // TODO: What am I doing here?
        $this->error = 1;

        // Either we loaded an existing record, or are working with a new record... Either way, update and save it.
        $mem->member_id = $m->member_id;
        $mem->error = ($lang ?? '');
        $mem->last_update = time();
        $mem->save();
        $this->fail[] = $m->member_id;
    }

    // TODO LOTS OF WORK TO DO

    /**
     * @param int $single
     * @return bool|null
     * @throws \Exception
     */
    public function updateProfile($single = 0): ?bool
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
//                                $s->setDefaultValues();
                                $s->member_id = $this->m->member_id;
                                $s->steamid = $steamid;
                                $s->save();
                            }
                        } else {
                            /**
                             * @var Lang $message
                             */
                            $message = Lang::load(Lang::defaultLanguage());
                            self::diagnostics($message->get('steam_id_invalid'));

                            return false;
                        }
                    }
                }
                $query = Db::i()->select($select, array('steam_profiles', 's'), $where, 's.st_member_id ASC',
                    array($this->cache['profile_offset'], 100), null, null);

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
                    $req = self::request($url);

                    if ($req->httpResponseCode != 200) {
                        if ($single) {
                            $this->failed(Member::load($single), 'steam_err_getplayer');
                        }

                        /* Throw an Exception */
                        self::diagnostics($req->httpResponseCode);

                        continue;
                    }
                    try {
                        $players = $req->decodeJson();
                    } catch (RuntimeException $e) {
                        if ($single) {
                            $this->failed(Member::load($single), 'steam_err_getplayer');
                        }
                        self::diagnostics($e->getMessage());

                        continue;
                    }
                } catch (\OutOfRangeException $e) {
                    if ($single) {
                        $this->failed(Member::load($single), 'steam_err_getplayer');
                    }
                    self::diagnostics($e->getMessage());

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
                            if ($single) {
                                return $s;
                            }
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
            self::diagnostics($e->getMessage());

            return false;
        }
    }

    // TODO

    /**
     * @return array
     */
    public function getAllProfiles(): array
    {
        $members = array();
        $select = 's.*';
        $where = "s.st_steamid <> '' AND s.st_steamid IS NOT NULL AND s.st_restricted!='1'";
        $query = Db::i()->select($select, array('steam_profiles', 's'), $where, 's.st_member_id ASC',
            array($this->cache['offset'], Settings::i()->steam_batch_count), null, null);

        foreach ($query as $row) {
            $members[] = Profile::constructFromData($row);
        }
        $profileCount = Db::i()->select('COUNT(s.st_steamid)', array('steam_profiles', 's'));
        $this->cache['count'] = $profileCount->count();

        return $members;
    }

    /**
     * Update the cache offset for the next query
     */
    public function updateCache(): void
    {
        $this->cache['offset'] += (int)Settings::i()->steam_batch_count;
        if ($this->cache['offset'] >= $this->cache['count']) {
            $this->cache['offset'] = 0;
        }
        Store::i()->steamData = $this->cache;
    }

    /**
     * @param $profile
     * @return string
     * @throws \JsonException
     */
    public function getBadges($profile): string
    {
        $uri = 'IPlayerService/GetBadges/v1/?key=' . $this->api .
            '&steamid=' . $profile->steamid;
        $response = array();
        try {
            $response = self::request($uri);
        } catch (\Exception $e) {
            $this->failed($this->m, 'steam_err_getlevel');
        }

        return $this->storeGetBadges($profile, $response['response']);
    }

    /**
     * @param $profile
     * @param $response
     * @return string
     * @throws \JsonException
     */
    public function storeGetBadges($profile, $response): string
    {
        // Store the data and unset the variable to free up memory
        if (!isset($response)) {
            return json_encode(array());
        }
        if (\is_array($response['badges']) && \count($response['badges'])) {
            $player_badges = array_filter($response['badges'], array($this, 'badges'));
            // Clear the response of all badges and only keep what we want
            unset($response['badges']);
            $response['badges'] = $player_badges;
        }

        return json_encode($response);
    }

    // Done

    /***
     * @param $profile
     * @return string
     * @throws \JsonException
     */
    public function getPlayerBans($profile): string
    {
        $uri = 'ISteamUser/GetPlayerBans/v1/?key=' .
            $this->api . '&steamids=' .
            $profile->steamid;
        $response = array();
        try {
            $response = self::request($uri);
        } catch (\Exception $e) {
            $this->failed($this->m, 'steam_err_vacbans');
        }

        return $this->storePlayerBans($profile, $response);
    }

    /***
     * @param $profile
     * @param $response
     * @return string
     * @throws \JsonException
     */
    public function storePlayerBans($profile, $response): string
    {
        if (!\is_array($response)) {
            $profile->vac_status = '0';

            return json_encode(array());;
        }
        foreach ($response['players'] as $player) {
            if ($player['CommunityBanned'] || $player['VACBanned']) {
                $profile->vac_status = '1';

                return json_encode($player);
            }
            $profile->vac_status = '0';

            return json_encode(array());
        }
    }

    /**
     * @param $profile
     * @return mixed
     * @throws \JsonException
     */
    public function getRecentlyPlayedGames($profile): Profile
    {
        $uri = 'IPlayerService/GetRecentlyPlayedGames/v0001/?key=' . $this->api .
            '&steamid=' . $profile->steamid .
            '&format=json';
        $response = array();
        try {
            $response = self::request($uri);
        } catch (\OutOfRangeException $e) {
            $this->failed($this->m, 'steam_err_getrecent');
        }

        return $this->storeRecentlyPlayedGames($profile, $response['response']);
    }

    /**
     * @param $profile
     * @param $response
     * @return \IPS\steam\Profile
     * @throws \JsonException
     */
    public function storeRecentlyPlayedGames($profile, $response): Profile
    {
        if (!isset($response['total_count'], $response['games'])) {
            $profile->playtime_2weeks = 0;
            $profile->total_count = 0;
            $profile->games = json_encode(array());

            return $profile;
        }

        $profile->playtime_2weeks = 0;
        foreach ($response['games'] as $id => $g) {
            // If we don't have a logo for the game, don't bother storing it. Still tally time played.
            if (isset($g['img_icon_url'], $g['img_logo_url']) && $g['img_icon_url'] && $g['img_logo_url']) {
                $_games[$g['appid']] = $g;
            }
            $profile->playtime_2weeks += $g['playtime_2weeks'];
            //	img_icon_url, img_logo_url - these are the filenames of various images for the game. To construct the URL to the image, use this format:
            //	http://media.steampowered.com/steamcommunity/public/images/apps/{appid}/{hash}.jpg
        }
        $profile->games = json_encode($_games);
        $profile->total_count = $response['total_count']; // Total counts of games played in last 2 weeks
    }

    /**
     * @param $profile
     * @return \IPS\steam\Profile
     * @throws \JsonException
     */
    public function getOwnedGames($profile): Profile
    {
        if (!Settings::i()->steam_get_owned) {
            $profile->owned = json_encode(array());

            return $profile;
        }

        $uri = 'IPlayerService/GetOwnedGames/v0001/?key=' . $this->api .
            '&steamid=' . $profile->steamid .
            '&include_appinfo=1&format=json';
        $response = array();
        try {
            $response = self::request($uri);
        } catch (\RuntimeException $e) {
            // TODO: Come up with error codes
            $this->failed($this->m, 'steam_err_getowned');
        }

        return $this->storeOwnedGames($profile, $response['response']);
    }

    // TODO Try / Catch

    /***
     * @param $profile
     * @param $response
     * @return \IPS\steam\Profile
     * @throws \JsonException
     */
    public function storeOwnedGames($profile, $response): Profile
    {
        if (!isset($response['game_count'], $response['games'])) {
            $profile->owned = json_encode(array());
            $profile->game_count = 0;

            return $profile;
        }

        $_game = array();
        foreach ($response['games'] as $id => $game) {
            if ($game['img_icon_url'] && $game['img_logo_url']) {
                $_game[$game['appid']] = $game;
                //	img_icon_url, img_logo_url - these are the filenames of various images for the game. To construct the URL to the image, use this format:
                //	http://media.steampowered.com/steamcommunity/public/images/apps/{appid}/{hash}.jpg
            }
        }
        $profile->owned = json_encode($_game);
        $profile->game_count = ($response['game_count'] ?? 0);

        return $profile;
    }

    // TODO

    /**
     * @param $p
     * @return \IPS\steam\Profile
     */
    public function getUserGroupList($p): Profile
    {
        $uri = 'ISteamUser/GetUserGroupList/v1/?key=' . $this->api .
            '&steamid=' . $p->steamid;
        $_groups = null;

        try {
            $response = self::request($uri);

            if ($response->httpResponseCode != 200) {
                $content = array();
                if ($response->httpResponseCode == 403) {
                    $content = $response->decodeJson();
                }
                if (!isset($content['response']['success'])) {
                    // TODO: Use method name as error message?
                    $this->failed($this->m, 'steam_err_getGroupList');
                    self::diagnostics($response->httpResponseCode . ': getGroupList');
                }
            } else {
                try {
                    $groupList = $response->decodeJson();
                } catch (RuntimeException $e) {
                    $this->failed($this->m, 'steam_err_getGroupList');
                    self::diagnostics($e->getMessage());
                }

                // Store the data and unset the variable to free up memory
                if (isset($groupList) && $groupList['response']['success'] == true) {
                    if (\is_array($groupList['response']['groups']) && \count($groupList['response']['groups'])) {
                        $_groups = array();
                        foreach ($groupList['response']['groups'] as $g) {
                            if (PHP_INT_SIZE == 8) {
                                $_groups[$g['gid']] = (int)self::base + $g['gid'];
                            } elseif (function_exists('bcadd') && extension_loaded('bcmath')) {
                                $_groups[] = bcadd(self::base, $g['gid']);
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
            self::diagnostics($e->getMessage());
        }

        return $p;
    }

    // TODO

    /**
     * @param int $offset
     * @throws \Exception
     */
//    protected function cleanup($offset = -1): void
//    {
//        // AIWA-101 If cache does not initialize properly, cleanup_offset may not exist.
//        if ($offset == -1) {
//            $offset = $this->cache['cleanup_offset'] ?? 0;
//            if ($offset) {
//                $this->cache['cleanup_offset'] = 0;
//            }
//        }
//        $this->load($offset);
//
//        if (\is_array($this->members) && \count($this->members)) {
//            foreach ($this->members as $this->m) {
//                $steamid = ($this->m->steamid ?: $this->getSteamID($this->m));
//
//                $s = Profile::load($this->m->member_id);
//
//                /* If they don't have an entry, create one... If their entry doesn't match,
//                   purge it and update the steamID */
//                if (!$s->steamid || ($s->steamid != $steamid)) {
////                    $s->setDefaultValues();
//                    $s->steamid = $steamid;
//                    $s->member_id = $this->m->member_id;
//                    $s->last_update = time();
//                    $s->save();
//                }
//            }
//        }
//        $this->cache['cleanup_offset'] += (int)Settings::i()->steam_batch_count;
//        if ($this->cache['cleanup_offset'] >= $this->cache['count']) {
//            $this->cache['cleanup_offset'] = 0;
//        }
//        /* Set the Extra data Cache */
//        Store::i()->steamData = $this->cache;
//    }

    // TODO

    /**
     * @param int $offset
     * @return void
     */
//    public function load($offset = 0): void
//    {
//        /* We are loading new members, if there is anyone still there, dump 'em. */
//        $this->members = array();
//        $query = null;
//
//        if ($offset > $this->cache['count']) {
//            $offset = $this->cache['count'];
//        }
//        if ($this->steamLogin || ($this->cache['pf_id'] && $this->cache['pf_group_id'])) {
//            // Build select and where clauses
//            $select_member = 'm.*';
//            //$select_pfields = "p.field_". $this->cache   ['pf_id'];
//            $select_pfields = 'p.*';
//            $where = 'p.member_id=m.member_id';
//
//            // INNER join, INNER join, INNER join!!!!!
//            if ($this->cache['pf_id']) {
//                $select = $select_member . ',' . $select_pfields;
//                $where .= ' AND (p.field_' . $this->cache['pf_id'] . ' IS NOT NULL';
//                $where .= $this->steamLogin ? ' OR m.steamid>0)' : ')';
//
//                $query = Db::i()->select($select, array('core_members', 'm'), null, 'm.member_id ASC',
//                    array($offset, Settings::i()->steam_batch_count), null, null)
//                    ->join(array('core_pfields_content', 'p'), $where, 'INNER');
//
//            } elseif ($this->steamLogin) {
//                $select_member .= ',m.steamid';
//                $select = $select_member;
//                $where = 'm.steamid>0';
//
//                $query = Db::i()->select($select, array('core_members', 'm'), $where, 'm.member_id ASC',
//                    array($offset, Settings::i()->steam_batch_count), null, null);
//            }
//
//            // Execute one of the queries built above
//            foreach ($query as $row) {
//                $this->m = new Member;
//                if (isset($row['m'])) {
//                    $member = $this->m::constructFromData($row['m']);
//                } else {
//                    $member = $this->m::constructFromData($row);
//                }
//                if (!$member->member_id) {
//                    break;
//                }
//                if (\is_array($row['p']) && \count($row['p'])) {
//                    // Set Location STAFF so task execution, running as guest, can still see the field data.
//                    foreach (Field::values($row['p'], 'STAFF') as $group => $fields) {
//                        $member->profileFields['core_pfieldgroups_' . $group] = $fields;
//                    }
//                } else {
//                    $member->profileFields = array();
//                }
//                $this->members[] = $member;
//            }
//            // TODO: Need to do this another way. 2nd count query.
//            // put the count query in the helper.
//            // Count of all records found ignoring the limit
//            $this->cache['count'] = $query->count(false);
//        } else {
//            $this->stError = Lang::load(Lang::defaultLanguage())->get('steam_field_invalid');
//        }
//    }

    // TODO

    /**
     * @param bool $raw
     * @return null|string
     */
//    public function error($raw = true): ?string
//    {
//        if ($raw || $this->stError) {
//            return $this->stError;
//        }
//        if (\is_array($this->failed) && \count($this->failed)) {
//            return Lang::load(Lang::defaultLanguage())->get('task_steam_profile') . ' - ' . implode(',',
//                    $this->fail);
//        }
//
//        return null;
//    }


}