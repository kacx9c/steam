<?php
/**
 * @brief		Profile extension: steamprofile
 * @author		<a href='http://www.invisionpower.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) 2001 - SVN_YYYY Invision Power Services, Inc.
 * @license		http://www.invisionpower.com/legal/standards/
 * @package		IPS Social Suite
 * @subpackage	Steam Integration
 * @since		17 Feb 2016
 * @version		SVN_VERSION_NUMBER
 */

namespace IPS\steam\extensions\core\Profile;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * @brief	Profile extension: steamprofile
 */
class _steamprofile
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
		$this->stProfile = new \IPS\steam\Profile;
		$this->steam = $this->stProfile->load($this->member->member_id, 'st_member_id');
	}

	/**
	 * Is there content to display?
	 *
	 * @return	bool
	 */
	public function showTab()
	{
		if(!$this->member->group['steam_pull'])
		{
			return FALSE;
		}
		if($this->steam->member_id && $this->steam->steamid)
		{
			return TRUE;
		}
		if(\IPS\Member::loggedIn()->isAdmin() || ($this->member->member_id == \IPS\Member::loggedIn()->member_id))
		{
			return TRUE;
		}

		/* If we are still here, we don't have anything to show */
		return FALSE;
	}

	/**
	 * Display
	 *
	 * @return	string
	 */
	public function render()
	{
		/* Load up a template and return it. */
		if(!$this->steam->member_id)
		{
			/* If there isn't a Steam profile, set the member ID so we have access to the Update / Validate functions */
			$this->steam->member_id = $this->member->member_id;
		}
		\IPS\Output::i()->cssFiles = array_merge(\IPS\Output::i()->cssFiles, \IPS\Theme::i()->css('profile.css', 'steam', 'front'));
		$html = \IPS\Theme::i()->getTemplate( 'global', 'steam' )->steamProfile( $this->steam );
		return $html;
	}
}