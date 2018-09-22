<?php

namespace IPS\steam\Login;

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!defined('\IPS\SUITE_UNIQUE_KEY')) {
    header((isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

class _Steam extends \IPS\Login\Handler
{

    /**
     * @brief    Can we have multiple instances of this handler?
     */
    public static $allowMultiple = false;

    public static $shareService = null;

    protected $url = 'https://steamcommunity.com/openid/login';


    /**
     * ACP Settings Form
     * @return    array    List of settings to save - settings will be stored to core_login_handlers.login_settings DB
     *                       field
     * @code
     *                       return array( 'savekey'    => new \IPS\Helpers\Form\[Type]( ... ), ... );
     * @endcode
     */
    public function acpForm()
    {
        return array();
//        $return['api_key'] = new \IPS\Helpers\Form\Text('login_steam_key',
//            isset($this->settings['api_key']) ? $this->settings['api_key'] : '', false);

//        $return['use_steam_name'] = new \IPS\Helpers\Form\YesNo('login_steam_name',
//            isset($this->settings['use_steam_name']) ? $this->settings['use_steam_name'] : false, true);
    }

    /**
     * Get logo to display in user cp sidebar
     * @return    \IPS\Http\Url|string
     */
    public function logoForUcp()
    {
        return 'steam';
    }

    use \IPS\Login\Handler\ButtonHandler;

    /**
     * Show in Account Settings?
     * @param    \IPS\Member|NULL $member The member, or NULL for if it should show generally
     * @return  bool Show in UCP or not
     */
    public function showInUcp(\IPS\Member $member = null)
    {
        return true;
    }

    /**
     * Authenticate
     * @param    \IPS\Login $login The login object
     * @return    \IPS\Member
     * @copyright Lavoaster github.com/lavoaster/
     * @license   http://opensource.org/licenses/mit-license.php The MIT License
     * @throws    \IPS\Login\Exception
     */
    public function authenticateButton(\IPS\Login $login)
    {
        /* If we haven't been redirected back, redirect the user to external site */
        if (!isset(\IPS\Request::i()->success)) {
            $redirect = \IPS\Http\Url::external($this->url)->setQueryString(array(
                'openid.ns'           => 'http://specs.openid.net/auth/2.0',
                'openid.mode'         => 'checkid_setup',
                'openid.return_to'    => (string)$login->url->setQueryString(array(
                    'service'       => $this->id,
                    'success'       => '1',
                    '_processLogin' => $this->id,
                    'csrfKey'       => \IPS\Session::i()->csrfKey,
                )),
                'openid.realm'        => (string)\IPS\Http\Url::internal('', 'none'),
                'openid.identity'     => 'http://specs.openid.net/auth/2.0/identifier_select',
                'openid.claimed_id'   => 'http://specs.openid.net/auth/2.0/identifier_select',
                'openid.assoc_handle' => $login->url->getFurlQuery() === 'settings/login' ? 'ucp' : \IPS\Dispatcher::i()->controllerLocation,
            ));

            \IPS\Output::i()->redirect($redirect);
        }

        $steamID = $this->validate();

        if (!$steamID) {
            throw new \IPS\Login\Exception('generic_error', \IPS\Login\Exception::INTERNAL_ERROR);
        }

        /* Find their local account if they have already logged in using this method in the past */
        try {
            $link = \IPS\Db::i()->select('*', 'core_login_links',
                array('token_login_method=? AND token_identifier=?', $this->id, $steamID))->first();
            $member = \IPS\Member::load($link['token_member']);


            /* If the user never finished the linking process, or the account has been deleted, discard this access token */
            if (!$link['token_linked'] or !$member->member_id) {
                \IPS\Db::i()->delete('core_login_links',
                    array('token_login_method=? AND token_member=?', $this->id, $link['token_member']));
                throw new \UnderflowException;
            }

            /* ... and return the member object */
            if ($member->member_id) {

                $member->steamid = $steamID;
                $member->save();
            }

            return $member;
        } catch (\UnderflowException $e) {

        }

        /* Otherwise, we need to either create one or link it to an existing one */
        try {
            /* If the user is setting this up in the User CP, they are already logged in. Ask them to reauthenticate to link those accounts */
            if ($login->type === \IPS\Login::LOGIN_UCP) {
                $exception = new \IPS\Login\Exception('generic_error', \IPS\Login\Exception::MERGE_SOCIAL_ACCOUNT);
                $exception->handler = $this;
                $exception->member = $login->reauthenticateAs;
                throw $exception;
            }

            /* If an api key is provided, attempt to load the user from steam */
            $response = null;
            $userData = null;
            $key = \IPS\Settings::i()->steam_api_key;

            if ($key) {

                try {
                    $response = \IPS\Http\Url::external("http://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key={$key}&steamids={$steamID}")->request()->get()->decodeJson();

                    if ($response) {
                        // Get the first player
                        $userData = $response['response']['players'][0];
                    }
                } catch (\IPS\Http\Request\Exception $e) {
                    throw new \IPS\Login\Exception('generic_error', \IPS\Login\Exception::INTERNAL_ERROR, $e);
                }

            }

            $name = $userData['personaname'];
            $email = null;

            /* Try to create one. NOTE: Invision Community will automatically throw an exception which we catch below if $email matches an existing account, if registration is disabled, or if Spam Defense blocks the account creation */
            $member = $this->createAccount($name, $email);


            /* If we're still here, a new account was created. Store something in core_login_links so that the next time this user logs in, we know they've used this method before */
            \IPS\Db::i()->insert('core_login_links', array(
                'token_login_method' => $this->id,
                'token_member'       => $member->member_id,
                'token_identifier'   => $steamID,
                'token_linked'       => 1,
            ));

            /* Log something in their history so we know that this login handler created their account */
            $member->logHistory('core', 'social_account', array(
                'service'      => static::getTitle(),
                'handler'      => $this->id,
                'account_id'   => $steamID,
                'account_name' => $name,
                'linked'       => true,
                'registered'   => true,
            ));

            /* Set up syncing options. NOTE: See later steps of the documentation for more details - it is fine to just copy and paste this code */
            if ($syncOptions = $this->syncOptions($member, true)) {
                $profileSync = array();
                foreach ($syncOptions as $option) {
                    $profileSync[$option] = array('handler' => $this->id, 'ref' => null, 'error' => null);
                }
                $member->profilesync = $profileSync;
            }
            if ($member->member_id) {

                $member->steamid = $steamID;
            }

            $member->save();

            return $member;
        } catch (\IPS\Login\Exception $exception) {
            $member = \IPS\Member::loggedIn();
            /* If the account creation was rejected because there is already an account with a matching email address
                make a note of it in core_login_links so that after the user reauthenticates they can be set as being
                allowed to use this login handler in future */
            if ($exception->getCode() === \IPS\Login\Exception::MERGE_SOCIAL_ACCOUNT) {
                \IPS\Db::i()->insert('core_login_links', array(
                    'token_login_method' => $this->id,
                    'token_member'       => $exception->member->member_id,
                    'token_identifier'   => $steamID,
                    'token_linked'       => 0,
                ));
                if ($member->member_id) {
                    $member->steamid = $steamID;
                }
                $member->save();
            }

            throw $exception;
        }
    }

//    public function usernameIsInUse( $username, \IPS\Member $exclude=NULL )
//    {
//        return NULL;
//    }

    /**
     * This will validate the incoming Steam OpenID request
     * @package       Steam Community API
     * @copyright (c) 2010 ichimonai.com
     * @license       http://opensource.org/licenses/mit-license.php The MIT License
     * @return int|bool
     */
    protected function validate()
    {
        $params = array(
            'openid.signed' => \IPS\Request::i()->openid_signed,
            'openid.sig'    => str_replace(' ', '+', \IPS\Request::i()->openid_sig),
            'openid.ns'     => 'http://specs.openid.net/auth/2.0',
        );

        foreach ($params as $key => &$value) {
            $value = urldecode($value);
        }

        // Get all the params that were sent back and resend them for validation
        $signed = explode(',', urldecode(\IPS\Request::i()->openid_signed));
        foreach ($signed as $item) {
            $val = \IPS\Request::i()->{'openid_' . str_replace('.', '_', $item)};

            if ($item !== 'response_nonce' || mb_strpos($val, '%') !== false) {
                $val = urldecode($val);
            }

            $params['openid.' . $item] = get_magic_quotes_gpc() ? stripslashes($val) : $val;
        }

        // Finally, add the all important mode.
        $params['openid.mode'] = 'check_authentication';

        // Validate whether it's true and if we have a good ID
        preg_match('/\d{17}$/', urldecode($_GET['openid_claimed_id']), $matches);
        $steamID64 = is_numeric($matches[0]) ? $matches[0] : 0;

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
     * Get title
     * @return    string
     */
    public static function getTitle()
    {
        return 'login_handler_Steam'; // Create a langauge string for this
    }

    /**
     * Syncing Options
     * @param    \IPS\Member $member      The member we're asking for (can be used to not show certain options iof the
     *                                    user didn't grant those scopes)
     * @param    bool        $defaultOnly If TRUE, only returns which options should be enabled by default for a new
     *                                    account
     * @return    array
     */
    public function syncOptions(\IPS\Member $member, $defaultOnly = false)
    {
        $return = array();

//        if ( isset( $this->settings['use_steam_name'] ) and $this->settings['use_steam_name'] === 'optional')
//        {
//            $return[] = 'name';
//        }

        $return[] = 'photo';

        return $return;
    }

    /**
     * Get user's profile photo
     * May return NULL if server doesn't support this
     * @param    \IPS\Member $member Member
     * @return    \IPS\Http\Url|NULL
     * @throws    \IPS\Login\Exception    The token is invalid and the user needs to reauthenticate
     * @throws    \DomainException        General error where it is safe to show a message to the user
     * @throws    \RuntimeException        Unexpected error from service
     */
    public function userProfilePhoto(\IPS\Member $member)
    {
        return \IPS\Http\Url::external(\IPS\steam\Profile::load($member->member_id)->avatarfull);
    }

    /**
     * Get user's profile name
     * May return NULL if server doesn't support this
     * @param    \IPS\Member $member Member
     * @return    string|NULL
     * @throws    \IPS\Login\Exception    The token is invalid and the user needs to reauthenticate
     * @throws    \DomainException        General error where it is safe to show a message to the user
     * @throws    \RuntimeException        Unexpected error from service
     */
    public function userProfileName(\IPS\Member $member)
    {
        return \IPS\steam\Profile::load($member->member_id)->personaname;
    }

    /**
     * Get link to user's remote profile
     * May return NULL if server doesn't support this
     * @param    string $identifier The ID Nnumber/string from remote service
     * @param    string $username   The username from remote service
     * @return    \IPS\Http\Url|NULL
     * @throws    \IPS\Login\Exception    The token is invalid and the user needs to reauthenticate
     * @throws    \DomainException        General error where it is safe to show a message to the user
     * @throws    \RuntimeException        Unexpected error from service
     */
    public function userLink($identifier, $username)
    {
        return null;
//        return \IPS\Http\Url::external( (string)\IPS\steam\Profile::load($member->member_id)->profileurl);
    }

    /**
     * Get the button color
     * @return    string
     */
    public function buttonColor()
    {
        return '#171a21';
    }

    /**
     * Get the button icon
     * @return    string
     */
    public function buttonIcon()
    {
        return 'steam'; // A fontawesome icon
    }

    /**
     * Get button text
     * @return    string
     */
    public function buttonText()
    {
        return 'steam_sign_in'; // Create a language string for this
    }

    /**
     * Get button CSS class
     * @return    string
     */
    public function buttonClass()
    {
        return '';
    }

    /**
     * Unlink Account
     * @param    \IPS\Member $member The member or NULL for currently logged in member
     * @return    void
     */
    public function disassociate(\IPS\Member $member = null)
    {
        $member = $member ?: \IPS\Member::loggedIn();

        $member->steamid = null;
        $member->save();

        parent::disassociate($member);
    }

}