<?php

namespace IPS\steam\Login;

use IPS\Request;
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

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!\defined('\IPS\SUITE_UNIQUE_KEY')) {
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

class _Steam extends \IPS\Login\Handler
{

    /**
     * @brief    Can we have multiple instances of this handler?
     */
    public static $allowMultiple = false;

    public static $shareService;

    protected $url = 'https://steamcommunity.com/openid/login';


//    /**
//     * ACP Settings Form
//     * @return    array    List of settings to save - settings will be stored to core_login_handlers.login_settings DB
//     *                       field
//     * @code
//     *                       return array( 'savekey'    => new \IPS\Helpers\Form\[Type]( ... ), ... );
//     * @endcode
//     */
//    public function acpForm(): array
//    {
//        return array();
////        $return['api_key'] = new \IPS\Helpers\Form\Text('login_steam_key',
////            isset($this->settings['api_key']) ? $this->settings['api_key'] : '', false);
//
////        $return['use_steam_name'] = new \IPS\Helpers\Form\YesNo('login_steam_name',
////            isset($this->settings['use_steam_name']) ? $this->settings['use_steam_name'] : false, true);
//    }

    /**
     * Get logo to display in user cp sidebar
     * @return    string
     */
    public function logoForUcp(): string
    {
        return 'steam';
    }

    use \IPS\Login\Handler\ButtonHandler;

    /**
     * Authenticate
     * @param \IPS\Login $login The login object
     * @return    Member
     * @throws    \IPS\Login\Exception
     * @license   http://opensource.org/licenses/mit-license.php The MIT License
     * @copyright Lavoaster github.com/lavoaster/
     */
    public function authenticateButton(Login $login): ?Member
    {
        /* If we haven't been redirected back, redirect the user to external site */
        if (!isset(Request::i()->success)) {
            $redirect = Url::external($this->url)->setQueryString(array(
                'openid.ns'           => 'http://specs.openid.net/auth/2.0',
                'openid.mode'         => 'checkid_setup',
                'openid.return_to'    => (string)$login->url->setQueryString(array(
                    'service'       => $this->id,
                    'success'       => '1',
                    '_processLogin' => $this->id,
                    'csrfKey'       => Session::i()->csrfKey,
                )),
                'openid.realm'        => (string)Url::internal('', 'none'),
                'openid.identity'     => 'http://specs.openid.net/auth/2.0/identifier_select',
                'openid.claimed_id'   => 'http://specs.openid.net/auth/2.0/identifier_select',
                'openid.assoc_handle' => $login->url->friendlyUrlComponent === 'settings/login' ? 'ucp' : Dispatcher::i()->controllerLocation,
            ));

            Output::i()->redirect($redirect);
        }

        $steamID = $this->validate();

        if (!$steamID) {
            throw new Login\Exception('steam_err_validateFailed', Login\Exception::INTERNAL_ERROR);
        }

        /* Find their local account if they have already logged in using this method in the past */
        try {
            $link = Db::i()->select(
                '*',
                'core_login_links',
                array(
                    'token_login_method=? AND token_identifier=?',
                    $this->id,
                    $steamID,
                )
            )->first();
            $member = Member::load($link['token_member']);


            /* If the user never finished the linking process, or the account has been deleted, discard this access token */
            if (!$link['token_linked'] or !$member->member_id) {
                Db::i()->delete('core_login_links',
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
            if ($login->type === Login::LOGIN_UCP) {
                $exception = new Login\Exception('steam_err_reauth', Login\Exception::MERGE_SOCIAL_ACCOUNT);
                $exception->handler = $this;
                $exception->member = $login->reauthenticateAs;
                throw $exception;
            }

            /* If an api key is provided, attempt to load the user from steam */
            $key = Settings::i()->steam_api_key;
            $name = null;
            $email = null;

            if ($key) {
                try {
                    /**
                     * @var Curl|Sockets $req
                     */
                    $req = Url::external("http://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/?key={$key}&steamids={$steamID}")->request();
                    $response = $req->get()->decodeJson();

                    if ($response) {
                        // Get the first player
                        $name = $response['response']['players'][0]['personaname'];
                    }

                    // Store the data

                } catch (\IPS\Http\Request\Exception $e) {
                    throw new Login\Exception('steam_err_api_fail', Login\Exception::INTERNAL_ERROR, $e);
                }
            }

            // Try to create one. NOTE: Invision Community will automatically throw an exception which we catch below
            //if $email matches an existing account, if registration is disabled, or if Spam Defense blocks the account creation
            $member = $this->createAccount($name, $email);

            // If we're still here, a new account was created. Store something in core_login_links
            // so that the next time this user logs in, we know they've used this method before
            Db::i()->insert('core_login_links', array(
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
            $member = Member::loggedIn();
            /* If the account creation was rejected because there is already an account with a matching email address
                make a note of it in core_login_links so that after the user re-authenticates they can be set as being
                allowed to use this login handler in future */
            if ($exception->getCode() === \IPS\Login\Exception::MERGE_SOCIAL_ACCOUNT) {
                Db::i()->insert('core_login_links', array(
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

    /**
     * This will validate the incoming Steam OpenID request
     * @return int|bool
     * @license   http://opensource.org/licenses/mit-license.php The MIT License
     * @copyright Lavoaster github.com/lavoaster/
     */
    protected function validate()
    {
        $params = array(
            'openid.ns'           => 'http://specs.openid.net/auth/2.0',
            'openid.assoc_handle' => Request::i()->openid_assoc_handle,
            'openid.signed'       => Request::i()->openid_signed,
            'openid.sig'          => Request::i()->openid_sig,
            'openid.mode'         => 'check_authentication',
        );

        // Get all the params that were sent back and resend them for validation
        $signed = \explode(',', $params['openid.signed']);
        foreach ($signed as $item) {
            // First some security checks, ensure the param exists before attempting to call it
            $parameterName = 'openid_' . \str_replace('.', '_', $item);
            if (!isset(Request::i()->$parameterName)) {
                continue;
            }
            $params['openid.' . $item] = Request::i()->$parameterName;
        }

        // Validate whether it's true and if we have a good ID
        preg_match('/\d{17,25}$/', urldecode($_GET['openid_claimed_id']), $matches);
        $steamID64 = \is_numeric($matches[0]) ? $matches[0] : 0;

        $response = (string)Url::external('https://steamcommunity.com/openid/login')->request()->post($params);

        //DEBUG
        if (Settings::i()->steam_diagnostics) {
            $diagnostics['get'] = $_GET;
            $diagnostics['match'] = $matches;
            $diagnostics['steam'] = $steamID64;
            $diagnostics['urldecode'] = \urldecode($_GET['openid_claimed_id']);
            $diagnostics['response'] = $response;
            Log::log(\json_encode($diagnostics), 'steam');
        }

        // Return our final value
        $isValid = preg_match('/is_valid\s*:\s*true/i', $response) && ($steamID64 !== 0);

        return $isValid ? $steamID64 : false;
    }

//    public function usernameIsInUse( $username, Member $exclude=NULL )
//    {
//        return NULL;
//    }

    /**
     * Get title
     * @return    string
     */
    public static function getTitle(): string
    {
        return 'login_handler_Steam'; // Create a language string for this
    }

    /**
     * Syncing Options
     * @param Member $member              The member we're asking for (can be used to not show certain options iof the
     *                                    user didn't grant those scopes)
     * @param bool   $defaultOnly         If TRUE, only returns which options should be enabled by default for a new
     *                                    account
     * @return    array
     */
    public function syncOptions(Member $member, $defaultOnly = false): array
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
     * Get the button color
     * @return    string
     */
    public function buttonColor(): string
    {
        return '#171a21';
    }

    /**
     * Get the button icon
     * @return    string
     */
    public function buttonIcon(): string
    {
        return 'steam'; // A fontawesome icon
    }

    /**
     * Get button text
     * @return    string
     */
    public function buttonText(): string
    {
        return 'steam_sign_in'; // Create a language string for this
    }

    /**
     * Get button CSS class
     * @return    string
     */
    public function buttonClass(): string
    {
        return '';
    }

    /**
     * Get user's profile photo
     * May return NULL if server doesn't support this
     * @param Member $member Member
     * @return    Url|NULL
     * @throws    \IPS\Login\Exception    The token is invalid and the user needs to reauthenticate
     * @throws    \DomainException        General error where it is safe to show a message to the user
     * @throws    \RuntimeException        Unexpected error from service
     */
    public function userProfilePhoto(Member $member): ?Url
    {
        return Url::external(Profile::load($member->member_id)->avatarfull);
    }

    /**
     * Get user's profile name
     * May return NULL if server doesn't support this
     * @param Member $member Member
     * @return    string
     * @throws    \IPS\Login\Exception    The token is invalid and the user needs to reauthenticate
     * @throws    \DomainException        General error where it is safe to show a message to the user
     * @throws    \RuntimeException        Unexpected error from service
     */
    public function userProfileName(Member $member): string
    {
        return Profile::load($member->member_id)->personaname;
    }

    /**
     * Get link to user's remote profile
     * May return NULL if server doesn't support this
     * @param string $identifier The ID Nnumber/string from remote service
     * @param string $username   The username from remote service
     * @return    bool
     * @throws    \IPS\Login\Exception    The token is invalid and the user needs to reauthenticate
     * @throws    \DomainException        General error where it is safe to show a message to the user
     * @throws    \RuntimeException        Unexpected error from service
     */
    public function userLink($identifier, $username): ?bool
    {
        return null;
//        return Url::external( (string)\IPS\steam\Profile::load($member->member_id)->profileurl);
    }

    /**
     * Unlink Account
     * @param Member $member The member or NULL for currently logged in member
     * @return    void
     */
    public function disassociate(Member $member = null): void
    {
        $member = $member ?: Member::loggedIn();
        if ($member) {
            $member->steamid = null;
            $member->save();
        }
        parent::disassociate($member);
    }

    /**
     * Show in Account Settings?
     * @param Member|NULL $member The member, or NULL for if it should show generally
     * @return  bool Show in UCP or not
     */
    public function showInUcp(Member $member = null): bool
    {
        return true;
    }

}