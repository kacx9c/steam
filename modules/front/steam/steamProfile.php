<?php


namespace IPS\steam\modules\front\steam;

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!defined('\IPS\SUITE_UNIQUE_KEY')) {
    header((isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

/**
 * steamProfile
 */
class _steamProfile extends \IPS\Dispatcher\Controller
{
    /**
     * Execute
     * @return    void
     */
    public function execute()
    {
        \IPS\Session::i()->csrfCheck();
        $this->member_id = \intval(\IPS\Request::i()->id);

        if ($this->member_id > 0 && (($this->member_id == \IPS\Member::loggedIn()->member_id) || \IPS\Member::loggedIn()->isAdmin())) {
            $this->member = \IPS\Member::load($this->member_id);
            $this->steam = \IPS\steam\Profile::load($this->member_id);
        } else {
            \IPS\Output::i()->error('node_error', '2ST100/1', 404, '');
        }
        parent::execute();
    }

    /**
     * ...
     * @return    void
     */
    protected function manage()
    {
        /* Replace with default Online user display */
        /* Nothing to see here, send them back to the profile they came from */
        \IPS\Output::i()->redirect($this->member->url()->setQueryString('tab', 'node_steam_steamprofile'));
    }

    // Create new methods with the same name as the 'do' parameter which should execute it

    public function update()
    {
        \IPS\Session::i()->csrfCheck();
        try {
            $profile = \IPS\steam\Profile::load($this->member_id);
            if ($profile->last_update > (time() - 30) && !\IPS\Member::loggedIn()->isAdmin()) {
                $message = \IPS\Member::loggedIn()->language()->addToStack('steam_wait');
            } else {
                $stUpdate = new \IPS\steam\Update;
                $stUpdate->updateProfile($this->member_id);
                if ($stUpdate->update($this->member_id)) {
                    $message = \IPS\Member::loggedIn()->language()->addToStack('steam_updated');
                } else {
                    $message = \IPS\Member::loggedIn()->language()->addToStack('steam_err_updated');
                }
            }

        } catch (\Exception $e) {
            //$message = \IPS\Member::loggedIn()->language()->addToStack( 'steam_err_updated');
            $message = $e->getMessage();
        }

        \IPS\Output::i()->redirect($this->member->url()->setQueryString('tab', 'node_steam_steamprofile'), $message);
    }

    public function disable()
    {
        \IPS\Session::i()->csrfCheck();

        try {
            $this->steam->setDefaultValues();
            $this->steam->restricted = 1;
            $this->steam->save();
            $message = \IPS\Member::loggedIn()->language()->addToStack('steam_disabled');
        } catch (\Exception $e) {
            $message = \IPS\Member::loggedIn()->language()->addToStack('steam_err_disabled');
        }

        \IPS\Output::i()->redirect($this->member->url()->setQueryString('tab', 'node_steam_steamprofile'), $message);
    }

    public function enable()
    {
        \IPS\Session::i()->csrfCheck();

        try {
            $this->steam->setDefaultValues();
            $this->steam->restricted = 0;
            $this->steam->save();
            $message = \IPS\Member::loggedIn()->language()->addToStack('steam_enabled');
        } catch (\Exception $e) {
            $message = \IPS\Member::loggedIn()->language()->addToStack('steam_err_enabled');
        }

        \IPS\Output::i()->redirect($this->member->url()->setQueryString('tab', 'node_steam_steamprofile'), $message);
    }

    public function validate()
    {
        \IPS\Session::i()->csrfCheck();
        try {
            if ($this->member->steamid) {
                /* If LAVO has set a steamid for the member, let's assume it's valid */
                $message = \IPS\Member::loggedIn()->language()->addToStack('steam_validated');
            } else {
                $stUpdate = new \IPS\steam\Update;
                /* Lets check the profile field */
                if ($stUpdate->getSteamID($this->member)) {
                    $message = \IPS\Member::loggedIn()->language()->addToStack('steam_validated');
                } else {
                    $message = \IPS\Member::loggedIn()->language()->addToStack('steam_err_validated');
                }
            }
        } catch (\Exception $e) {
            $message = $e->getMessage();
        }

        \IPS\Output::i()->redirect($this->member->url()->setQueryString('tab', 'node_steam_steamprofile'), $message);

    }

    public function remove()
    {
        \IPS\Session::i()->csrfCheck();
        try {
            $steam = \IPS\steam\Profile::load($this->member->member_id);
            if ($steam->steamid) {
                $steam->delete();
                $message = \IPS\Member::loggedIn()->language()->addToStack('steam_removed');
            } else {
                $message = \IPS\Member::loggedIn()->language()->addToStack('steam_err_removed');
            }
        } catch (\Exception $e) {
            $message = \IPS\Member::loggedIn()->language()->addToStack('steam_err_removed');
        }

        \IPS\Output::i()->redirect($this->member->url()->setQueryString('tab', 'node_steam_steamprofile'), $message);
    }


}