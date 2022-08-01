<?php

namespace IPS\steam;

use Exception;
use IPS\Data\Store;
use IPS\Http\Request\CurlException;
use IPS\steam\Update;
use IPS\Request;
use IPS\Http\Response;
use IPS\Http\Url;
use IPS\Log;
use IPS\Http\Request\Curl;
use IPS\Http\Request\Sockets;
use IPS\Db;
use IPS\Member;
use IPS\Dispatcher;
use IPS\Session;
use IPS\Login;
use IPS\steam\Profile;
use IPS\Settings;
use IPS\Output;
use RuntimeException;

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!\defined('\IPS\SUITE_UNIQUE_KEY')) {
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

/**
 * Class _SteamHelper
 * @package IPS\steam
 */
abstract class _AbstractSteam
{
    protected const badgesToKeep = array('1', '2', '13', '17', '21', '23');
    protected const base = '103582791429521408';
    protected const emptyCache = array(
        'offset'         => 0,
        'count'          => 0,
        'cleanup_offset' => 0,
        'profile_offset' => 0,
        'profile_count'  => 0,
        'pf_id'          => 0,
        'pf_group_id'    => 0,
    );
    protected $api = 0;
    protected $cache = array();
    protected $steamLogin = 0;

    private const baseUrl = 'http://api.steampowered.com/';

    protected function initSteam(): void
    {
        $this->steamLogin = Settings::i()->steam_api_key;
        if (!$this->steamLogin) {
            throw new InvalidArgumentException('steam_err_noapi');
        }
        $this->cache = static::buildStore();
    }

    /**
     * @param $message
     */
    protected static function diagnostics($message): void
    {
        if (Settings::i()->steam_diagnostics) {
            throw new RuntimeException($message);
        }
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

    /**
     * @param $element
     * @return bool
     */
    protected static function badges($element): bool
    {
        return \in_array($element['badgeid'], self::badgesToKeep, false);
    }

    /**
     * @return array
     */
    protected static function buildStore(): array
    {
        try {
            $cache = json_decode(Store::i()->steamData, true);
        } catch (\Exception $e) {
            $cache = static::emptyCache;
        }

        /* Save some resources, only get the profile field ID once every cycle instead of every time. */
        if ($cache['offset'] === 0 || !isset($cache['pf_id'], $cache['pf_group_id'])) {
            $cache = array_merge($cache, static::getFieldID($cache));
        }
        if (!isset($cache['offset'])) {
            $cache['offset'] = 0;
        }
        if (!isset($cache['profile_offset'])) {
            $cache['profile_offset'] = 0;
        }
        return $cache;
    }

    /**
     * @param $cache
     * @return array
     */
    protected static function getFieldID($cache): array
    {
        try {
            $customFieldId = Db::i()->select('pf_id,pf_group_id', 'core_pfields_data',
                array('pf_type=?', 'Steamid'))->first();
            $cache['pf_id'] = $customFieldId['pf_id'];
            $cache['pf_group_id'] = $customFieldId['pf_group_id'];
        } catch (Exception $e) {
            /* If the custom field doesn't exist, we'll get an underflow exception.  Just set it to 0 and move on */
            $cache['pf_id'] = 0;
            $cache['pf_group_id'] = 0;
        }

        return $cache;
    }
}