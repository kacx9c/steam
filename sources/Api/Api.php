<?php

namespace IPS\steam;

use IPS\Data\Store;
use IPS\Http\Request\CurlException;
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
use IPS\Settings;
use IPS\Output;
use http\Exception\InvalidArgumentException;
use RuntimeException;
use LogicException;

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!\defined('\IPS\SUITE_UNIQUE_KEY')) {
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

/**
 * Class _SteamApi
 * @package IPS\steam
 */
class _Api
{
    protected const baseUrl = 'https://api.steampowered.com/';
    protected const baseGroupVanityUrl = 'https://steamcommunity.com/groups/%s/memberslistxml/?xml=1';
    protected const baseGroupId64Url = 'https://steamcommunity.com/gid/%d/memberslistxml/?xml=1';
    protected static $instance = NULL;

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
     * @param string $uri
     * @param array $queryParams
     * @return array
     * @throws RuntimeException
     */
    protected function requestJson(string $uri, array $queryParams): array
    {
        $urlClass = new Url;
        $request = null;
        $queryParams["key"] = Settings::i()->steam_api_key;
        $queryString = $urlClass->convertQueryAsArrayToString($queryParams);
        // Set this up to try a couple of times if the queries hit a rate limit per second
        for ($i = 0; $i < 3; $i++) {
            try {
                $request = Url::external(self::baseUrl . $uri . "?" . $queryString)
                    ->request(\IPS\LONG_REQUEST_TIMEOUT)
                    ->get();
                break;
            } catch (InvalidArgumentException $e) {
                if ($i === 2) {
                    throw $e;
                }
                sleep(2);
            }
        }
        if($request->httpResponseCode !== '200') {
            throw new RuntimeException(self::baseUrl . $uri . "?" . $queryString);
        }

        // Your code must catch a RuntimeException, BAD_JSON
        $decodedJson = $request->decodeJson();
        if (isset($decodedJson['response'])) {
            return $decodedJson['response'];
        }
        return $decodedJson;
    }

    protected function requestXml($url): mixed
    {
        $request = null;
        // Set this up to try a couple of times if the queries hit a rate limit per second
        for ($i = 0; $i < 3; $i++) {
            try {
                $request = Url::external($url)
                    ->request(\IPS\LONG_REQUEST_TIMEOUT)
                    ->get();
                break;
            } catch (InvalidArgumentException $e) {
                if ($i === 2) {
                    throw $e;
                }
                sleep(2);
            }
        }
        if($request->httpResponseCode !== '200') {
            throw new RuntimeException($url);
        }

        return $request->decodeXml();
    }

    /**
     * @param string $steamid
     * @return array
     * @throws RuntimeException
     */
    public function getBadges(string $steamid): array
    {
        $uri = 'IPlayerService/GetBadges/v1/';
        $queryParams["steamid"] = $steamid;

        try {
            return $this->requestJson($uri, $queryParams);
        } catch (RuntimeException $e) {
            throw new RuntimeException('steam_err_getbadges');
        }
    }

    /***
     * @param string $steamid
     * @return array
     * @throws RuntimeException
     */
    public function getPlayerBans(string $steamid): array
    {
        $uri = 'ISteamUser/GetPlayerBans/v1/';
        $queryParams["steamids"] = $steamid;

        try {
            return $this->requestJson($uri, $queryParams);
        } catch (RuntimeException $e) {
            throw new RuntimeException( 'steam_err_vacbans');
        }
    }

    /***
     * @param string $steamids
     * @return array
     * @throws RuntimeException
     */
    public function getPlayerSummaries(string $steamids): array
    {
        $uri = 'ISteamUser/GetPlayerSummaries/v0002/';
        $queryParams["steamids"] = $steamids;
        $queryParams["format"] = 'json';

        try {
            return $this->requestJson($uri, $queryParams);
        } catch (RuntimeException $e) {
            throw new RuntimeException( 'steam_err_getplayer');
        }
    }

    /**
     * @param string $steamid
     * @return array
     * @throws RuntimeException
     */
    public function getUserGroupList(string $steamid): array
    {
        $uri = 'ISteamUser/GetUserGroupList/v1/';
        $queryParams['steamid'] = $steamid;

        try {
            return $this->requestJson($uri, $queryParams);
        } catch (RuntimeException $e) {
            throw new RuntimeException( 'steam_err_getGroupList');
        }
    }

    /**
     * @param string $steamid
     * @return array
     * @throws RuntimeException
     */
    public function getRecentlyPlayedGames(string $steamid): array
    {
        $uri = 'IPlayerService/GetRecentlyPlayedGames/v0001/';
        $queryParams["steamid"] = $steamid;
        $queryParams["format"] = "json";

        try {
            return $this->requestJson($uri, $queryParams);
        } catch (RuntimeException $e) {
            throw new RuntimeException( 'steam_err_getrecent');
        }
    }

    /**
     * @param string $steamid
     * @return array
     * @throws RuntimeException
     */
    public function getOwnedGames(string $steamid): array
    {
        $uri = 'IPlayerService/GetOwnedGames/v0001/';
        $queryParams["steamid"] = $steamid;
        $queryParams["include_appinfo"] = "1";
        $queryParams["format"] = "json";

        try {
            return $this->requestJson($uri, $queryParams);
        } catch (\RuntimeException $e) {
            throw new RuntimeException( 'steam_err_getowned');
        }
    }

    public function getGroup($groupId): mixed
    {
        if (preg_match('/^\d{18}$/', $groupId)) {
            $url = sprintf(self::baseGroupId64Url, $groupId);
        } else {
            $url = sprintf(self::baseGroupVanityUrl, $groupId);
        }

        try {
            return $this->requestXml($url);
        } catch (\RuntimeException $e) {
            throw new RuntimeException( 'steam_err_getGroup');
        }
    }

    /**
     * @param string $steamid
     * @return string
     * @throws LogicException
     */
    public function getSteamId(string $steamid): string
    {
        if(preg_match('/^\d{17}$/', $steamid)) {
            return $steamid;
        }

        if(preg_match('/STEAM_(\d+?):(\d+?):(\d+?)$/', $steamid)) {
            // Format STEAM_X:Y:Z
            // ID64 = (Z*2) + 76561197960265728 + Y
            $legacySteamid = explode(':', $steamid);

            if (PHP_INT_SIZE === 8) {
                $steamId64 = $legacySteamid[2] * 2 + 76561197960265728 + $legacySteamid[1];
            } elseif (function_exists('bcadd') && function_exists('bcmul') && extension_loaded('bcmath')) {
                $steamId64 = bcadd(bcadd(bcmul($legacySteamid[2], 2), '76561197960265728'), $legacySteamid[1]);
            } else {
                // If we've gotten here it's a 64 bit server with a limit on PHP_INT_SIZE
                // or a 32 bit server w/o bcmath installed
                throw new LogicException('Missing extension: php-bcmath');
            }
            return $steamId64;
        }

        return $this->getByVanityName($steamid);
    }

    /**
     * @param string $steamName
     * @return string
     * @throws RuntimeException
     */
    protected function getByVanityName(string $steamName): string
    {
        $uri = 'ISteamUser/ResolveVanityURL/v0001/';
        $queryParams['vanityurl'] = $steamName;

        try {
            $response = $this->requestJson($uri, $queryParams);
        } catch (\RuntimeException $e) {
            throw new RuntimeException( 'steam_err_getvanity');
        }
        return $response['success'] === 1 ? $response['steamid'] : '';
    }
}
