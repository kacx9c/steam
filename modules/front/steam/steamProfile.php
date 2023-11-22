<?php


namespace IPS\steam\modules\front\steam;

use IPS\Dispatcher\Controller;
use IPS\Session;
use IPS\Member;
use IPS\steam\Profile;
use IPS\steam\Update;
use IPS\Request;
use IPS\Output;

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!defined('\IPS\SUITE_UNIQUE_KEY')) {
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

/**
 * steamProfile
 */
class _steamProfile extends Controller
{
    protected int $memberId;
    protected Member $member;
    protected Profile $profile;
    /**
     * Execute
     * @return    void
     */
    public function execute() : void
    {
        Session::i()->csrfCheck();
        $this->memberId = (int)Request::i()->id;

        if ($this->memberId > 0 && (($this->memberId === Member::loggedIn()->member_id) || Member::loggedIn()->isAdmin())) {
            $this->member = Member::load($this->memberId);
            $this->profile = Profile::load($this->memberId);
            if(!$this->profile->steamid)
            {
                $this->profile->setDefaultValues();
                $this->profile->member_id = $this->member->member_id;
                $this->profile->save();
            }
        } else {
            Output::i()->error('node_error', '2ST100/1', 404, '');
        }
        parent::execute();
    }

    /**
     * ...
     * @return    void
     */
    protected function manage(): void
    {
        /* Replace with default Online user display */
        /* Nothing to see here, send them back to the profile they came from */
        Output::i()->redirect($this->member->url()->setQueryString('tab', 'node_steam_steamprofile'));
    }

    // Create new methods with the same name as the 'do' parameter which should execute it

    /**
     *
     */
    public function update(): void
    {
        Session::i()->csrfCheck();
        try {
            if ($this->profile->last_update > (time() - 30) && !Member::loggedIn()->isAdmin()) {
                $message = Member::loggedIn()->language()->addToStack('steam_wait');
            } else {
                $stUpdate = new Update;
                $message = $stUpdate->updateFullProfile($this->memberId);
            }

        } catch (\Exception $e) {
            //$message = \IPS\Member::loggedIn()->language()->addToStack( 'steam_err_updated');
            $message = $e->getMessage();
        }

        Output::i()->redirect($this->member->url()->setQueryString('tab', 'node_steam_steamprofile'), $message);
    }

    /**
     *
     */
    public function disable(): void
    {
        Session::i()->csrfCheck();

        try {
            $this->profile->setDefaultValues();
            $this->profile->restricted = 1;
            $this->profile->save();
            $message = Member::loggedIn()->language()->addToStack('steam_disabled');
        } catch (\Exception $e) {
            $message = Member::loggedIn()->language()->addToStack('steam_err_disabled');
        }

        Output::i()->redirect($this->member->url()->setQueryString('tab', 'node_steam_steamprofile'), $message);
    }

    /**
     *
     */
    public function enable(): void
    {
        Session::i()->csrfCheck();

        try {
            $this->profile->setDefaultValues();
            $this->profile->restricted = 0;
            $this->profile->save();
            $message = Member::loggedIn()->language()->addToStack('steam_enabled');
        } catch (\Exception $e) {
            $message = Member::loggedIn()->language()->addToStack('steam_err_enabled');
        }

        Output::i()->redirect($this->member->url()->setQueryString('tab', 'node_steam_steamprofile'), $message);
    }

    /**
     *
     */
    public function validate(): void
    {
        Session::i()->csrfCheck();
        try {
            $steamUpdate = new Update;
            /* Let's check the profile field */
            if ($steamUpdate->getSteamID($this->member) || preg_match('/^\d{17}$/', $this->profile->steamid)) {
                $message = Member::loggedIn()->language()->addToStack('steam_validated');
            } else {
                $message = Member::loggedIn()->language()->addToStack('steam_err_validated');
            }
        } catch (\Exception $e) {
            $message = $e->getMessage();
        }

        Output::i()->redirect($this->member->url()->setQueryString('tab', 'node_steam_steamprofile'), $message);
    }

    /**
     *
     */
    public function remove(): void
    {
        Session::i()->csrfCheck();
        try {
            $steam = Profile::load($this->member->member_id);
            if ($steam->steamid) {
                $steam->delete();
                $message = Member::loggedIn()->language()->addToStack('steam_removed');
            } else {
                $message = Member::loggedIn()->language()->addToStack('steam_err_removed');
            }
        } catch (\Exception $e) {
            $message = Member::loggedIn()->language()->addToStack('steam_err_removed');
        }

        Output::i()->redirect($this->member->url()->setQueryString('tab', 'node_steam_steamprofile'), $message);
    }


}