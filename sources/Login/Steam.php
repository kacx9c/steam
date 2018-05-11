<?php

namespace IPS\steam\Login;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
    header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
    exit;
}

class _Steam extends \IPS\Login\Handler
{
    use \IPS\Login\Handler\ButtonHandler;

    /**
     * @brief	Can we have multiple instances of this handler?
     */
    public static $allowMultiple = FALSE;

    /**
     * Get title
     *
     * @return	string
     */
    public static function getTitle()
    {
        return '__app_steam'; // Create a langauge string for this
    }

    /**
     * Authenticate
     *
     * @param	\IPS\Login	$login				The login object
     * @return	\IPS\Member
     * @copyright Lavoaster github.com/lavoaster/
     * @license http://opensource.org/licenses/mit-license.php The MIT License
     * @throws	\IPS\Login\Exception
     */
    public function authenticateButton( \IPS\Login $login )
    {
        $steamID = $this->validate();

        if (!$steamID) {
            throw new \IPS\Login\Exception('generic_error', \IPS\Login\Exception::INTERNAL_ERROR);
        }

        /* If an api key is provided, attempt to load the user from steam */
        $response = null;
        $userData = null;

        if ($this->settings['api_key']) {
            try {
                $response = \IPS\Http\Url::external("http://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key={$this->settings['api_key']}&steamids={$steamID}")->request()->get()->decodeJson();

                if ($response) {
                    // Get the first player
                    $userData = $response['response']['players'][0];
                }
            } catch (\IPS\Http\Request\Exception $e) {
                throw new \IPS\Login\Exception('generic_error', \IPS\Login\Exception::INTERNAL_ERROR, $e);
            }
        }

        /* Find  member */
        $newMember = false;

        if ($member === null) {
            try {
                $memberData = \IPS\Db::i()->select('*', 'core_members', array('steamid=?', $steamID))->first();
                $member = \IPS\Member::constructFromData($memberData);
            } catch (\UnderflowException $e) {
                $member = \IPS\Member::load(null);
            }

            if (!$member->member_id) {

                $member = new \IPS\Member;
                $member->member_group_id = \IPS\Settings::i()->member_group;

                if (\IPS\Settings::i()->reg_auth_type === 'admin' or \IPS\Settings::i()->reg_auth_type === 'admin_user') {
                    $member->members_bitoptions['validating'] = true;
                }

                if (isset($userData)) {

                    if ($this->settings['use_steam_name']) {
                        $existingUsername = \IPS\Member::load($userData['personaname'], 'name');
                        if (!$existingUsername->member_id) {

                            $member->name = $userData['personaname'];
                        }
                    }

                    $member->profilesync = json_encode(array(
                        static::$loginKey => array(
                            'photo' => true,
                            'cover' => false,
                            'status' => ''
                        )
                    ));

                }
                $newMember = true;
            }
        }

        /* Create member */
        $member->steamid = $steamID;
        $member->save();

        /* Sync */
        if ($newMember) {
            if (\IPS\Settings::i()->reg_auth_type === 'admin_user') {
                \IPS\Db::i()->update('core_validating', array('user_verified' => 1),
                    array('member_id=?', $member->member_id));
            }

            $sync = new \IPS\core\ProfileSync\Steam($member);
            $sync->sync();
        }

        /* Return */
        return $member;
    }

    /**
     * This will validate the incoming Steam OpenID request
     *
     * @package Steam Community API
     * @copyright (c) 2010 ichimonai.com
     * @license http://opensource.org/licenses/mit-license.php The MIT License
     * @return int|bool
     */
    protected function validate()
    {
        $params = array(
            'openid.signed' => \IPS\Request::i()->openid_signed,
            'openid.sig' => str_replace(' ', '+', \IPS\Request::i()->openid_sig),
            'openid.ns' => 'http://specs.openid.net/auth/2.0',
        );

        foreach ($params as $key => &$value) {
            $value = urldecode($value);
        }

        // Get all the params that were sent back and resend them for validation
        $signed = explode(',', urldecode(\IPS\Request::i()->openid_signed));
        foreach ($signed as $item) {
            $val = \IPS\Request::i()->{'openid_' . str_replace('.', '_', $item)};

            if ($item !== 'response_nonce' || strpos($val, '%') !== false) {
                $val = urldecode($val);
            }

            $params['openid.' . $item] = get_magic_quotes_gpc() ? stripslashes($val) : $val;
        }

        // Finally, add the all important mode.
        $params['openid.mode'] = 'check_authentication';

        // Validate whether it's true and if we have a good ID
        preg_match("#^http://steamcommunity.com/openid/id/([0-9]{17,25})#", urldecode($_GET['openid_claimed_id']), $matches);
        $steamID64 = is_numeric($matches[1]) ? $matches[1] : 0;

        $response = (string)\IPS\Http\Url::external('https://steamcommunity.com/openid/login')->request()->post($params);

        $values = array();

        foreach (explode("\n", $response) as $value) {
            $data = explode(":", $value);

            $key = $data[0];
            unset($data[0]);

            $values[$key] = implode(':', $data);
        }

        // Return our final value
        return $values['is_valid'] === 'true' ? $steamID64 : false;
    }

    /**
     * Get the button color
     *
     * @return	string
     */
    public function buttonColor()
    {
        return '#ff3399';
    }

    /**
     * Get the button icon
     *
     * @return	string
     */
    public function buttonIcon()
    {
        return 'steam'; // A fontawesome icon
    }

    /**
     * Get button text
     *
     * @return	string
     */
    public function buttonText()
    {
        return 'sign_in_with_my_custom_login_handler'; // Create a language string for this
    }

    /**
     * Get button CSS class
     *
     * @return	string
     */
    public function buttonClass()
    {
        return '';
    }


}