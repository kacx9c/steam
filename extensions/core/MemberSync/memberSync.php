<?php
/**
 * @brief		Member Sync
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @subpackage	Steam Integration
 * @since		17 Nov 2023
 */

namespace IPS\steam\extensions\core\MemberSync;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Member Sync
 */
class _memberSync
{
	/**
	 * Member account has been created
	 *
	 * @param	$member	\IPS\Member	New member account
	 * @return	void
	 */
	public function onCreateAccount( $member )
	{
        $this->onValidate($member);
	}
	
	/**
	 * Member has validated
	 *
	 * @param	\IPS\Member	$member		Member validated
	 * @return	void
	 */
	public function onValidate( $member )
	{
        $cache = array();
        if (isset(\IPS\Data\Store::i()->steamData)) {
            $cache = \IPS\Data\Store::i()->steamData;
        }
        $hasProfileField = isset($cache['pf_id'], $cache['pf_group_id']);
        $hasLoginHandler = \IPS\Login\Handler::findMethod('IPS\steamlogin\sources\Login\Steam') !== null;
        if (!$hasProfileField && !$hasLoginHandler)
        {
            return;
        }

        $steamid = \IPS\steam\Update::i()->getSteamID($member);
        $steamProfile = \IPS\steam\Profile::load($member->member_id);

        /* If they set their steamID, lets put them in the cache */
        if ($steamid === '' || !$steamProfile->steamid) {
            return;
        }

        $steamProfile->setDefaultValues();
        $steamProfile->member_id = $member->member_id;
        $steamProfile->steamid = $steamid;
        if(PHP_INT_SIZE === 8)
        {
            $steamProfile->steamid_hex = dechex((int) $steamid);
        }
        $steamProfile->save();

        \IPS\steam\Update::i()->updateFullProfile($steamProfile->member_id);
	}
	
	/**
	 * Member has logged on
	 *
	 * @param	\IPS\Member	$member		Member that logged in
	 * @return	void
	 */
	public function onLogin( $member )
	{
	
	}
	
	/**
	 * Member has logged out
	 *
	 * @param	\IPS\Member		$member			Member that logged out
	 * @param	\IPS\Http\Url	$returnUrl	    The URL to send the user back to
	 * @return	void
	 */
	public function onLogout( $member, $returnUrl )
	{
	
	}
	
	/**
	 * Member account has been updated
	 *
	 * @param	$member		\IPS\Member	Member updating profile
	 * @param	$changes	array		The changes
	 * @return	void
	 */
	public function onProfileUpdate( $member, $changes )
	{
        try {
            $cache = \IPS\Data\Store::i()->steamData ?? array();
            $group = '';
            $pField = '';
            $_field = '';
            $delete = false;
            if (isset($cache['pf_id'], $cache['pf_group_id'])) {
                $group = 'core_pfieldgroups_' . $cache['pf_group_id'];
                $pField = 'core_pfield_' . $cache['pf_id'];
                $_field = 'field_' . $cache['pf_id'];
            }

            if (isset($changes[$_field])) {
                $delete = !$changes[$_field];
            }

            if ($delete) {
                $steamProfile = \IPS\steam\Profile::load($member->member_id);
                if ($steamProfile->member_id) {
                    $steamProfile->delete();
                    return;
                }
            }

            if (!isset($changes[$_field])) {
                return;
            }

            $member->profileFields = $member->profileFields();
            $member->profileFields[$group][$pField] = $changes[$_field];
            $steamid = ($changes['steamid'] ?? \IPS\steam\Update::i()->getSteamID($member));

            $steamProfile = \IPS\steam\Profile::load($member->member_id);

            /* If the steamid is valid, go ahead and save and update the cache right now */
            if ($steamid) {
                $steamProfile->setDefaultValues();
                $steamProfile->member_id = $member->member_id;
                $steamProfile->steamid = $steamid;
                $steamProfile->steamid_hex = bin2hex($steamid);
                $steamProfile->save();
                \IPS\steam\Update::i()->updateFullProfile($steamProfile->member_id);
            } elseif ($steamProfile->member_id) {
                // If we actually loaded a profile, but there isn't a steamid, delete their cache entry entirely.
                $steamProfile->delete();
            } else {
                // Was an empty object, just taking out the trash.
                unset($steamProfile);
            }
        } catch (\Exception $e) {
            //throw new \OutOfRangeException;
        }
	}
	
	/**
	 * Member is flagged as spammer
	 *
	 * @param	$member	\IPS\Member	The member
	 * @return	void
	 */
	public function onSetAsSpammer( $member )
	{
        try {
            /* Set steam restriction */
            \IPS\steam\Update::i()->restrict($member->member_id);
        } catch (\OutOfRangeException $e) {
            throw new \OutOfRangeException;
        }
	}
	
	/**
	 * Member is unflagged as spammer
	 *
	 * @param	$member	\IPS\Member	The member
	 * @return	void
	 */
	public function onUnSetAsSpammer( $member )
	{
        try {
            \IPS\steam\Update::i()->unrestrict($member->member_id);
            /* Try to update the profile */
            \IPS\steam\Update::i()->updateFullProfile($member->member_id);
        } catch (\Exception $e) {
            throw new \OutOfRangeException;
        }
	}
	
	/**
	 * Member is merged with another member
	 *
	 * @param	\IPS\Member	$member		Member being kept
	 * @param	\IPS\Member	$member2	Member being removed
	 * @return	void
	 */
	public function onMerge( $member, $member2 )
	{
        /* Purge member2 steam data */
        $this->onDelete($member2);
	}
	
	/**
	 * Member is deleted
	 *
	 * @param	$member	\IPS\Member	The member
	 * @return	void
	 */
	public function onDelete( $member )
	{
        /* Purge member steam data */
        try {
            $steam = \IPS\steam\Profile::load($member->member_id);
            $steam->delete();
        } catch (\OutOfRangeException $e) {
            throw new \OutOfRangeException;
        }
	}

	/**
	 * Email address is changed
	 *
	 * @param	\IPS\Member	$member	The member
	 * @param 	string		$new	New email address
	 * @param 	string		$old	Old email address
	 * @return	void
	 */
	public function onEmailChange( $member, $new, $old )
	{

	}

	/**
	 * Password is changed
	 *
	 * @param	\IPS\Member	$member	The member
	 * @param 	string		$new		New password, wrapped in an object that can be cast to a string so it doesn't show in any logs
	 * @return	void
	 */
	public function onPassChange( $member, $new )
	{

	}
}