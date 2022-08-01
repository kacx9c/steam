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
abstract class _Api
{

    private const baseUrl = 'http://api.steampowered.com/';

    protected const badgesToKeep = array('1', '2', '13', '17', '21', '23');
    protected const base = '103582791429521408';
    protected const emptyCache = array(
        'offset' => 0,
        'count' => 0,
        'cleanup_offset' => 0,
        'profile_offset' => 0,
        'profile_count' => 0,
        'pf_id' => 0,
        'pf_group_id' => 0,
    );

    protected $members = array();
    protected $api = 0;
    protected $cache = array();
    protected $steamLogin = 0;

    /**
     * @param $uri
     * @return array
     */
    protected static function request($uri): array
    {
//        Profile::$databaseTable;
        /**
         * @var Curl|Sockets $request
         */
        $request = new Response;
        $tries = 0;

        do {
            try {
                $request = Url::external(self::baseUrl . $uri)
                    ->request(\IPS\LONG_REQUEST_TIMEOUT)
                    ->get();
            } catch (\Exception $e) {
                $tries++;
                sleep(2);
                continue;
            }
            break;
        } while ($tries <= 1);

        if ($request->httpResponseCode != 200) {
            // TODO: Add logic for diagnostics with 403 response

            return array();
        }

        try {
            $decodedJson = $request->decodeJson();
        } catch (\RuntimeException $e) {
            self::diagnostics($e->getMessage());
            throw $e;
        }

        return $decodedJson;
    }

    /**
     * @param $member
     * @return bool
     */
    protected static function restrict($member): bool
    {
        try {
            if ($member != null) {
                $profile = Profile::load($member);
                $profile->member_id = $member; // Make sure we set the member_id just in case the member doesn't actually exist.
                $profile->restricted = 1;
                $profile->save();
            } else {
                return false;
            }
        } catch (\Exception $e) {
            self::diagnostics($e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * @param $member
     * @return bool
     */
    protected static function unrestrict($member): bool
    {
        try {
            if ($member != null) {
                $profile = Profile::load($member);
                if ($profile->restricted == 1) {
                    $profile->restricted = 0;
                    $profile->save();
                }
            } else {
                return false;
            }
        } catch (\Exception $e) {
            self::diagnostics($e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * @param $member
     */
    protected static function remove($member): void
    {
        try {
            $profile = Profile::load($member);
            $profile->delete();
//            $r->setDefaultValues();
//            $r->save();
        } catch (\Exception $e) {
//          Need to define what to do here...
        }
    }
}