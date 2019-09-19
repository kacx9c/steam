<?php
/**
 * @brief            Profile extension: steamprofile
 * @author           <a href='http://www.invisionpower.com'>Invision Power Services, Inc.</a>
 * @copyright    (c) 2001 - SVN_YYYY Invision Power Services, Inc.
 * @license          http://www.invisionpower.com/legal/standards/
 * @package          IPS Social Suite
 * @subpackage       Steam Integration
 * @since            17 Feb 2016
 * @version          SVN_VERSION_NUMBER
 */

namespace IPS\steam\extensions\core\Profile;

use IPS\Member;
use IPS\steam\Profile;
use IPS\Theme;
use IPS\Output;

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!defined('\IPS\SUITE_UNIQUE_KEY')) {
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

/**
 * @brief    Profile extension: steamprofile
 */
class _steamprofile
{
    /**
     * Member
     */
    protected $member;

    /**
     * Constructor
     * @param \IPS\Member $member Member whose profile we are viewing
     * @return    void
     */
    public function __construct(Member $member)
    {
        $this->member = $member;
        $this->stProfile = new Profile;
        $this->steam = $this->stProfile->load($this->member->member_id, 'st_member_id');
    }

    /**
     * Is there content to display?
     * @return    bool
     */
    public function showTab(): bool
    {
        if (!$this->member->group['steam_pull']) {
            return false;
        }
        if ($this->steam->member_id && $this->steam->steamid) {
            return true;
        }
        if (($this->member->member_id == Member::loggedIn()->member_id) || Member::loggedIn()->isAdmin()) {
            return true;
        }

        /* If we are still here, we don't have anything to show */

        return false;
    }

    /**
     * Display
     * @return    string
     */
    public function render(): string
    {
        /* Load up a template and return it. */
        if (!$this->steam->member_id) {
            /* If there isn't a Steam profile, set the member ID so we have access to the Update / Validate functions */
            $this->steam->member_id = $this->member->member_id;
        }
        Output::i()->cssFiles = array_merge(Output::i()->cssFiles,
            Theme::i()->css('profile.css', 'steam', 'front'));

        return Theme::i()->getTemplate('global', 'steam')->steamProfile($this->steam);
    }
}