<?php

namespace IPS\steam;

use IPS\core\ProfileFields\Field;
use IPS\Data\Store;
use IPS\Db;
use IPS\Http\Response;
use IPS\Lang;
use IPS\Member;
use IPS\Settings;
use IPS\Login;
use InvalidArgumentException;
use Exception;
use JsonException;
use RuntimeException;

if (!\defined('\IPS\SUITE_UNIQUE_KEY')) {
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

/**
 * Class _Update
 * @package IPS\steam
 */
class _Update
{
    /**
     * @var array
     */
    protected const badgesToKeep = array('1', '2', '13', '17', '21', '23');
    /**
     * @var string
     */
    protected const baseGroupId = '103582791429521408';
    /**
     * @var array
     */
    protected const emptyCache = array(
        'offset'         => 0,
        'count'          => 0,
        'cleanup_offset' => 0,
        'cleanup_count'  => 0,
        'pf_id'          => 0,
        'pf_group_id'    => 0,
        'login_count'    => 0,
        'login_offset'   => 0,
    );
    /**
     * @var array
     */
    protected array $cache = array();
    /**
     * @var bool
     */
    protected bool $isRunningAsTask = true;
    protected static $instance = NULL;

    /**
     * _Update constructor.
     * @throws InvalidArgumentException
     */
    public function __construct()
    {
        $this->initSteam();
    }

    /**
     * Get Instance
     * @return mixed|null
     */
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
     * Empty profile and do not allow a profile to be re-created
     * @param int $memberId
     * @return void
     */
    public static function restrict(int $memberId): void
    {
        $profile = new Profile;
        try {
            $profile = Profile::load($memberId, 'st_member_id');
            $profile->setDefaultValues();
            $profile->restricted = 1;
        } catch (\Exception $e) {
            $profile->error = $e->getMessage();
        }
        $profile->save();
    }

    /**
     * Re-enable a profile. It will be cached via a task.
     * @param int $memberId
     * @return void
     */
    public static function unrestrict(int $memberId): void
    {
        $profile = new Profile;
        try {
            $profile = Profile::load($memberId, 'st_member_id');
            $profile->restricted = 0;
        } catch (\Exception $e) {
            $profile->error = $e->getMessage();
        }
        $profile->save();
    }

    /**
     * Query all API endpoints to update a members profile
     * @param int $memberId
     * @return string
     */
    public function updateFullProfile(int $memberId = -1): string
    {
        if ($memberId !== -1) {
            $steamProfiles = $this->updateSingleProfileSummary($memberId);
        } else {
            $steamProfiles = $this->updateBatchProfilesSummaries();
            $this->updateCache();
        }
        foreach ($steamProfiles as $steamProfile) {
            $steamProfile->addfriend = 'steam://friends/add/' . $steamProfile->steamid;
            $steamProfile->last_update = time();

            // TODO: Start breaking this out into individual methods to update the profile
            try {
                $this->getBadges($steamProfile);
                self::getRecentlyPlayedGames($steamProfile);
                self::getOwnedGames($steamProfile);
                self::getUserGroupList($steamProfile);
            } catch(JsonException|\RuntimeException $e) {
                if($memberId !== -1)
                {
                    throw new RuntimeException($e->getMessage());
                }
                $steamProfile->error = $e->getMessage();
            }
            $steamProfile->save();
        }

        return Member::loggedIn()->language()->addToStack('steam_updated');
    }

    /**
     * If a member id is provided, only update the individual members profile.
     * @param int $memberId
     * @return array
     */
    public function updateSingleProfileSummary(int $memberId = -1): array
    {
        $this->isRunningAsTask = false;
        if($memberId === -1) {
            return array();
        }
        $member = Member::load($memberId);
        $steamId = $this->getSteamId($member);
        $steamProfile = Profile::load($member->member_id, 'st_member_id');

        /* If they set their steamId, but they aren't in the DB, lets put them in the DB */
        if (!isset($steamProfile->steam_id)) {
            $steamProfile->setDefaultValues();
            $steamProfile->member_id = $member->member_id;
            $steamProfile->steamid = $steamId;
            $steamProfile->save();
        }
        try {
            return $this->getPlayerSummaries(array($steamProfile));
        } catch(\RuntimeException $e) {
            $steamProfile->error = $e->getMessage();
            $steamProfile->save();
            return array();
        }
    }

    /**
     * Update up to 100 profiles.
     * If more than 100 profiles are needed, will need to call this in a for loop.
     * @return array
     */
    public function updateBatchProfilesSummaries(): array
    {
        $this->isRunningAsTask = true;
        $profiles = array();
        $query = Db::i()->select(
            '*',
            'steam_profiles',
            "st_steamid <> '' AND st_steamid IS NOT NULL AND st_restricted!='1'",
            'st_member_id ASC',
            array($this->cache['offset'], 100),
        );
        $this->cache['count'] = Db::i()->select( 'COUNT(*)', 'steam_profiles')->first();

        foreach ($query as $id => $row) {
            $profiles[$id] = Profile::constructFromData($row);
        }

        return $this->getPlayerSummaries($profiles);
    }

    /**
     * @param Profile $steamProfile
     * @return void
     * @throws JsonException
     */
    protected static function getRecentlyPlayedGames(Profile $steamProfile): void
    {
        $playedGames = Api::i()->getRecentlyPlayedGames($steamProfile->steamid);
        // Store recently played game data and free up memory
        if (isset($playedGames['total_count'], $playedGames['games'])) {
            $steamProfile->playtime_2weeks = 0;
            $_games = array();
            foreach ($playedGames['games'] as $id => $game) {
                // If we don't have a logo for the game, don't bother storing it. Still tally time played.
                if (isset($game['img_icon_url']) && $game['img_icon_url']) {
                    $_games[$game['appid']] = $game;
                }
                $steamProfile->playtime_2weeks += $game['playtime_2weeks'];
                //	img_icon_url - these are the filenames of various images for the game. To construct the URL to the image, use this format:
                //	http://media.steampowered.com/steamcommunity/public/images/apps/{appid}/{hash}.jpg
            }
            $steamProfile->games = json_encode($_games, JSON_THROW_ON_ERROR);
            $steamProfile->total_count = $playedGames['total_count']; // Total counts of games played in last 2 weeks
        } else {
            $steamProfile->playtime_2weeks = 0;
            $steamProfile->total_count = 0;
            $steamProfile->games = json_encode(array(), JSON_THROW_ON_ERROR);
        }
    }

    /**
     * @param Profile $steamProfile
     * @return void
     * @throws JsonException
     */
    protected static function getOwnedGames(Profile $steamProfile): void {
        $ownedGames = Api::i()->getOwnedGames($steamProfile->steamid);

        if (isset($ownedGames['game_count'], $ownedGames['games']) && Settings::i()->steam_get_owned) {
            $_owned = array();
            foreach ($ownedGames['games'] as $id => $game) {
                if ($game['img_icon_url']) {
                    $_owned[$game['appid']] = $game;
                    //	img_icon_url -
                    // these are the filenames of various images for the game.
                    // To construct the URL to the image, use this format:
                    //	http://media.steampowered.com/steamcommunity/public/images/apps/{appid}/{hash}.jpg
                }
            }
            $steamProfile->owned = json_encode($_owned, JSON_THROW_ON_ERROR);
            // Total # of owned games, if we are pulling that data
            $steamProfile->game_count = $ownedGames['game_count'];
        } else {
            $steamProfile->owned = json_encode(array(), JSON_THROW_ON_ERROR);
            $steamProfile->game_count = 0;
        }
    }

    /**
     * @param Profile $steamProfile
     * @return void
     * @throws JsonException
     */
    protected static function getUserGroupList(Profile $steamProfile): void
    {
        $groupList = Api::i()->getUserGroupList($steamProfile->steamid);

        if (isset($groupList) && $groupList['success']) {
            $_groups = array();
            if (\is_array($groupList['groups']) && \count($groupList['groups'])) {
                foreach ($groupList['groups'] as $group) {
                    if (PHP_INT_SIZE === 8) {
                        $_groups[$group['gid']] = (int)static::baseGroupId + $group['gid'];
                    } elseif (function_exists('bcadd') && extension_loaded('bcmath')) {
                        $_groups[] = bcadd(static::baseGroupId, $group['gid']);
                    } else {
                        /* If we've gotten here it's a 64 bit server with a limit on PHP_INT_SIZE or a 32 bit server w/o bcmath installed */
                        Output::i()->error( 'steam_err_updated', '3ST001/1', 503, 'steam_err_bcmath');
                    }
                }
            }
            $steamProfile->player_groups = json_encode($_groups, JSON_THROW_ON_ERROR);
        } else {
            $steamProfile->player_groups = json_encode(array(), JSON_THROW_ON_ERROR);
        }
    }

    /**
     * @return array
     */
    protected static function buildStore(): array
    {
        try {
            $cache = Store::i()->steamData;
        } catch (\Exception $e) {
            $cache = static::emptyCache;
        }

        /* Save some resources, only get the profile field ID once every cycle instead of every time. */
        if ($cache['offset'] === 0 || !isset($cache['pf_id'], $cache['pf_group_id'])) {
            $cache = array_merge($cache, static::getFieldId($cache));
        }
        if (!isset($cache['offset'])) {
            $cache['offset'] = 0;
        }
        if(!isset($cache['cleanup_offset'])) {
            $cache['cleanup_offset'] = 0;
        }

        return $cache;
    }

    /**
     * Findd the custom profile field, if it exists.
     * @param array $cache
     * @return array
     */
    protected static function getFieldId(array $cache): array
    {
        try {
            $customFieldId = Db::i()->select(
                'pf_id,pf_group_id',
                'core_pfields_data',
                array('pf_type=?', 'Steamid')
            )->first();
            $cache['pf_id'] = $customFieldId['pf_id'];
            $cache['pf_group_id'] = $customFieldId['pf_group_id'];
        } catch (Exception $e) {
            /* If the custom field doesn't exist, we'll get an underflow exception.  Just set it to 0 and move on */
            $cache['pf_id'] = 0;
            $cache['pf_group_id'] = 0;
        }

        return $cache;
    }

    /**
     * Not storing all badges, only a select few.
     * @param array $element
     * @return bool
     */
    protected static function badges(array $element): bool
    {
        return \in_array($element['badgeid'], static::badgesToKeep, false);
    }

    /**
     * @param Member $member
     * @return string
     */
    public function getSteamId(Member $member): string
    {
        $steamProfile = Profile::load($member->membe_id);

        if (isset($steamProfile->steamid) && $steamProfile->steamid) {
            return $steamProfile->steamid;
        }

        $group = "core_pfieldgroups_{$this->cache['pf_group_id']}";
        $field = "core_pfield_{$this->cache['pf_id']}";

        if ($this->cache['pf_id'] && !isset($member->profileFields[$group][$field])) {
            $member->profileFields = $member->profileFields('PROFILE');
        }
        // Don't just check if the var exists / isset.  Check if it has something in it.
        $steamId = '';
        if (!empty($member->profileFields[$group][$field])) {
            $steamId = Api::i()->getSteamId($member->profileFields[$group][$field]);
        }
        return $steamId;
    }

    /**
     * @param $profile
     * @return string
     */
    function steamIdMap($profile): string {
        return $profile->steamid;
    }

    /**
     * @param array $profiles
     * @return array
     */
    protected function getPlayerSummaries(array $profiles): array
    {
        $steam_ids = array_map([$this, 'steamIdMap'], $profiles);
        if (\count($steam_ids)) {
            $implodedSteamIds = implode(',', $steam_ids);
        } else {
            return array();
        }
        $players = Api::i()->getPlayerSummaries($implodedSteamIds);
        return $this->savePlayerSummaries($players, $profiles);
    }

    /**
     * @param array $players
     * @param array $profiles
     * @return array
     */
    protected function savePlayerSummaries(array $players, array $profiles): array
    {
        $returnProfiles = array();
        foreach ($players['players'] as $id => $player) {
            // Load by member_id if we can, it's unique, there can be duplicate steamids.
            if ($profiles[$id]->steamid === $player['steamid']) {
                $steamProfile = Profile::load($profiles[$id]->member_id, 'st_member_id');
            } else {
                $steamProfile = Profile::load($player['steamid'], 'st_steamid');
            }

            $member = Member::load($steamProfile->member_id);
            $steamProfile->member_id = $member->member_id;
            $steamProfile->steamid = $player['steamid'];
            $steamProfile->last_update = time();
            $steamProfile->timecreated = $player['timecreated'] ?? null;
            $steamProfile->communityvisibilitystate = $player['communityvisibilitystate'] ?? null;
            $steamProfile->personaname = $player['personaname'] ?? '';
            $steamProfile->profileurl = $player['profileurl'] ?? '';
            $steamProfile->avatarhash = $player['avatarhash'] ?? '';
            $steamProfile->personastate = $player['personastate'];
            $steamProfile->lastlogoff = $player['lastlogoff'] ?? null;
            $steamProfile->gameserverip = $player['gameserverip'] ?? '';
            $steamProfile->gameid = $player['gameid'] ?? null;

            if (isset($player['gameextrainfo']) || isset($player['gameid'])) {
                $steamProfile->gameextrainfo = $player['gameextrainfo'] ?? $player['gameid'];
            } else {
                $steamProfile->gameextrainfo = null;
            }

            $steamProfile->error = '';
            $steamProfile->save();

            if($this->isRunningAsTask) {
                $this->cache['offset']++;
            }
            $returnProfiles[$id] = $steamProfile;
        }
        return $returnProfiles;
    }

    /**
     * @param Profile $steamProfile
     * @return void
     * @throws JsonException
     */
    protected function getBadges(Profile $steamProfile): void
    {
        $badges = Api::i()->getBadges($steamProfile->steamid);
        $badges['badges'] = array_filter($badges['badges'], array($this, 'badges'));
        $steamProfile->player_level = json_encode(
            $badges,
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * Check for API key and initialize the store.
     * @return void
     */
    protected function initSteam(): void
    {

        if (!Settings::i()->steam_api_key) {
            throw new InvalidArgumentException('steam_err_noapi');
        }
        $this->cache = static::buildStore();
    }

    /**
     * Used for custom profile field and login handler tasks
     * Create any new profiles and update them.
     * @param array $members
     */
    public function cleanup(array $members): void
    {
        if (\count($members)) {
            foreach ($members as $index => $member) {
                // If the members array was built with Steam64 as the index
                // we don't need to go get it again
                if(preg_match('/^\d{17}$/', $index)) {
                    $steamid = $index;
                } else {
                    $steamid = $this->getSteamID($member);
                }

                $steamProfile = Profile::load($member->member_id, 'st_member_id');

                /* If they don't have an entry, create one... If their entry doesn't match,
                   purge it and update the steamID */
                if (!$steamProfile->steamid || ($steamProfile->steamid !== $steamid)) {
                    $steamProfile->setDefaultValues();
                    $steamProfile->steamid = $steamid;
                    $steamProfile->member_id = $member->member_id;
                    $steamProfile->last_update = time();
                    $steamProfile->save();
                }
                try{
                    $this->updateFullProfile($member->member_id);
                } catch(\RuntimeException $e)
                {
                    $steamProfile->error = $e->getMessage();
                }
            }
        }
    }

    /**
     * Get members based on Custom Profile field, that aren't in steam_profiles
     * @return array
     */
    public function getBatchMembers(): array
    {
//         SELECT m.* FROM 'core_members' as 'm'
//         INNER JOIN 'core_pfields_content' as 'p'
//         ON m.member_id = p.member_id
//         LEFT JOIN 'steam_profiles as 's'
//         ON s.st_steamid IS NULL AND p.field_# IS NOT NULL
//         ORDER BY m.member_id ASC
//         LIMIT #,##
//         (limit is not used when getting the count )

        $members = array();
        $offset = $this->cache['cleanup_offset'];

        if ($this->cache['pf_id'] && $this->cache['pf_group_id']) {
            $on_core_pfields = 'p.member_id=m.member_id';
            $on_steam_profiles = 'm.member_id = s.st_member_id';
            $where = 's.st_member_id IS NULL AND p.field_' . $this->cache['pf_id'] . ' IS NOT NULL';

            $query =
                Db::i()->select('m.*', array('core_members', 'm'), $where, 'm.member_id ASC',
                array($offset, Settings::i()->steam_batch_count))
                ->join(array('core_pfields_content', 'p'), $on_core_pfields, 'INNER')
                ->join(array('steam_profiles', 's' ), $on_steam_profiles, 'LEFT');

            $queryCount =
                Db::i()->select('COUNT(*)', array('core_members', 'm'), $where, 'm.member_id ASC')
                ->join(array('core_pfields_content', 'p'), $on_core_pfields, 'INNER')
                ->join(array('steam_profiles', 's' ), $on_steam_profiles, 'LEFT');
            foreach ($query as $id => $row) {
                $members[$id] = Member::constructFromData($row);
            }
            // Must use ->first() to get the VALUE of the COUNT(*).
            // COUNT(*) query only returns 1 row, ->count() returns 1.
            $this->cache['cleanup_count'] = $queryCount->first();
            $this->updateCleanupCache();
            return $members;
        }
        return $members;
    }

    /**
     * Update profile based on steamid used for login, that aren't in steam_profiles
     * @return array
     */
    public function getLoginMembers(): array
    {
        $members = array();
        if (Login\Handler::findMethod('IPS\steamlogin\sources\Login\Steam') === null) {
            return $members;
        }

//        SELECT m.member_id, cll.token_identifier FROM core_members as m
//        INNER JOIN core_login_links as cll
//        ON m.member_id = cll.token_member
//        LEFT JOIN core_login_methods as clm
//        ON cll.token_login_method = clm.login_id AND clm.login_classname LIKE '%Steam%'
//        LEFT JOIN steam_profiles as s
//        ON m.member_id = s.st_member_id
//        WHERE s.st_member_id IS NULL;
//        ORDER BY m.member_id ASC
//        LIMIT #,##
//        (limit is not used when getting the count )

        $offset = $this->cache['login_offset'];
        $on_login_method = "cll.token_login_method = clm.login_id AND clm.login_classname LIKE '%Steam%'";
        $on_core_members = 'm.member_id = cll.token_member';
        $on_steam_profiles = 's.st_member_id = m.member_id';
        $where = "s.st_member_id IS NULL";
        $query =
            Db::i()->select('m.*, cll.token_identifier', array('core_members', 'm'), $where, 'm.member_id ASC',
                array($offset, Settings::i()->steam_batch_count))
                ->join(array('core_login_links', 'cll'), $on_core_members, 'INNER')
                ->join(array('core_login_methods', 'clm'), $on_login_method, 'LEFT')
                ->join(array('steam_profiles', 's' ), $on_steam_profiles, 'LEFT');

        $queryCount =
            Db::i()->select('COUNT(*)', array('core_members', 'm'), $where, 'm.member_id ASC')
                ->join(array('core_login_links', 'cll'), $on_core_members, 'INNER')
                ->join(array('core_login_methods', 'clm'), $on_login_method, 'LEFT')
                ->join(array('steam_profiles', 's' ), $on_steam_profiles, 'LEFT');
        foreach ($query as $id => $row) {
            $members[$row['token_identifier']] = Member::constructFromData($row);
        }

        // Must use ->first() to get the VALUE of the COUNT(*).
        // COUNT(*) query only returns 1 row, ->count() returns 1.
        $this->cache['login_count'] = $queryCount->first();
        $this->updateLoginCache();
        return $members;
    }

    /**
     * Update the cache offset for the next query
     */
    protected function updateCleanupCache(): void
    {
        $this->cache['cleanup_offset'] += (int)Settings::i()->steam_batch_count;
        if ($this->cache['cleanup_offset'] >= $this->cache['cleanup_count']) {
            $this->cache['cleanup_offset'] = 0;
        }
        Store::i()->steamData = $this->cache;
    }

    /**
     * Update the cache offset for the next query
     */
    protected function updateLoginCache(): void
    {
        $this->cache['login_offset'] += (int)Settings::i()->steam_batch_count;
        if ($this->cache['login_offset'] >= $this->cache['login_count']) {
            $this->cache['login_offset'] = 0;
        }
        Store::i()->steamData = $this->cache;
    }

    /**
     * Update the cache offset for the next query
     */
    protected function updateCache(): void
    {
        $this->cache['offset'] += (int)Settings::i()->steam_batch_count;
        if ($this->cache['offset'] >= $this->cache['count']) {
            $this->cache['offset'] = 0;
        }
        Store::i()->steamData = $this->cache;
    }
}