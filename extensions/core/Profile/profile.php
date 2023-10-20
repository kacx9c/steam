<?php
/**
 * @brief		Profile extension: profile
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @subpackage	Steam Integration
 * @since		17 Nov 2023
 */

namespace IPS\steam\extensions\core\Profile;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * @brief	Profile extension: profile
 */
class _profile
{
	/**
	 * Member
	 */
	protected $member;
	
	/**
	 * Constructor
	 *
	 * @param	\IPS\Member	$member	Member whose profile we are viewing
	 * @return	void
	 */
	public function __construct( \IPS\Member $member )
	{
		$this->member = $member;
        $this->profile = \IPS\steam\Profile::load($this->member->member_id, 'st_member_id');
    }
	
	/**
	 * Is there content to display?
	 *
	 * @return	bool
	 */
	public function showTab(): bool
	{
        if ($this->profile->member_id && $this->profile->steamid) {
            return true;
        }
        if (($this->member->member_id === \IPS\Member::loggedIn()->member_id) || \IPS\Member::loggedIn()->isAdmin()) {
            return true;
        }
        return false;
	}
	
	/**
	 * Display
	 *
	 * @return	string
	 */
	public function render(): string
	{
        if (!$this->profile->member_id) {
            /* If there isn't a Steam profile, set the member ID, so we have access to the Update / Validate functions */
            $this->profile->member_id = $this->member->member_id;
        }
        \IPS\Output::i()->cssFiles = array_merge(\IPS\Output::i()->cssFiles,
            \IPS\Theme::i()->css('profile.css', 'steam', 'front'));

        return \IPS\Theme::i()->getTemplate('global', 'steam')->steamProfile($this->profile);
	}
}