<?php
/**
 * @brief		Member Sync
 */

namespace IPS\steam\extensions\core\MemberSync;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Member Sync
 */
class _Steam
{
	/**
	 * Member account has been created
	 *
	 * @param	$member	\IPS\Member	New member account
	 * @return	void
	 */
	public function onCreateAccount( $member )
	{	$this->onValidate( $member );	}

	/**
	 * Member has validated
	 *
	 * @param	\IPS\Member	$member		Member validated
	 * @return	void
	 */
	public function onValidate( $member )
	{
		if(isset(\IPS\Data\Store::i()->steamData))
		{
			$cache = \IPS\Data\Store::i()->steamData;
		}
		if(!$member->steamid && !isset($cache['pf_id']))
		{

			/* If they don't have a steam login set, or there isn't a profile field ID return. */
			/* If it's just a cache issue, they'll get caught in the cleanup routine */
			return;
		}

		$steam = new \IPS\steam\Update;
		$steamid = $steam->getSteamID($member);

		/* If they set their steamID, lets put them in the cache */
		if($steamid)
		{
			$m = \IPS\steam\Profile::load($member->member_id);
			if(!$m->steamid)
			{
				$m->member_id 			= $member->member_id;
				$m->steamid  			= $steamid;
				$m->setDefaultValues();

				$m->save();

				$steam->updateProfile($m->member_id);
				$steam->update($m->member_id);
			}
		}else
		{
			/* We don't have a SteamID on the account, jump ship */
			return;
		}
	}

	/**
	 * Member has logged on
	 *
	 * @param	\IPS\Member	$member		Member that logged in
	 * @param	\IPS\Http\Url	$redirectUrl	The URL to send the user back to
	 * @return	void
	 */
	public function onLogin( $member, $returnUrl )
	{		/* Nothing to do.... For now... */		}

	/**
	 * Member has logged out
	 *
	 * @param	\IPS\Member		$member			Member that logged out
	 * @param	\IPS\Http\Url	$redirectUrl	The URL to send the user back to
	 * @return	void
	 */
	public function onLogout( $member, $returnUrl )
	{		/* Nothing to do */		}

	/**
	 * Member account has been updated
	 *
	 * @param	$member		\IPS\Member	Member updating profile
	 * @param	$changes	array		The changes
	 * @return	void
	 */
	public function onProfileUpdate( $member, $changes )
	{
		/* Did they change their SteamID?  If so, store them in the profile table */
		/* If they are using the steam login, ignore profile field.  */
		try
		{
			if($member->steamid && !isset($changes['steamid']))
			{
				/* Steam Login has priority, if it's set ignore profile fields. */
				return;
			}
			if(isset(\IPS\Data\Store::i()->steamData))
			{
				$cache = \IPS\Data\Store::i()->steamData;
			}

			$group = "core_pfieldgroups_";
			$field = 'core_pfield_';
			$_field = 'field_';

			if(isset($cache['pf_id']) && isset($cache['pf_group_id']))
			{
				$group .= $cache['pf_group_id'];
				$field .= $cache['pf_id'];
				$_field .= $cache['pf_id'];
			}

			if(isset($changes['steamid']) || isset($changes[ $_field ]))
			{
				$steam = new \IPS\steam\Update;

				if(!isset($member->profileFields[$group][$field]))
				{
					$member->profileFields = $member->profileFields();
				}

				$steamid = (isset($changes['steamid']) ? $changes['steamid'] : $steam->getSteamID( $member ));

				$s = \IPS\steam\Profile::load($member->member_id);
				$s->setDefaultValues();
				$s->member_id = $member->member_id;

				/* If we don't have a steamid, it's invalid.  Create the row, but leave the steamid empty. */
				$s->steamid = ($steamid ? $steamid : '');
				$s->save();

				/* If the steamid is valid, go ahead and update the cache right now */
				if($s->steamid)
				{
					$steam->updateProfile($s->member_id);
					$steam->update($s->member_id);
				}
			}else
			{
				/* Do Nothing */
			}
		}catch(\OutOfRangeException $e)
		{
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
		try
		{
			/* Set steam restriction */
			$steam = new \IPS\steam\Update;
			$steam->restrict($member->member_id);

		}catch(\OutOfRangeException $e)
		{
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
		try
		{
			/* Unrestrict steam account */
			$steam = new \IPS\steam\Update;
			$steam->unrestrict($member->member_id);
			/* Try to update the profile */
			$steam->updateProfile($member->member_id);
			$steam->update($member->member_id);

		}catch(\OutOfRangeException $e)
		{
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
		try
		{
			$steam = \IPS\steam\Profile::load($member->member_id);
			$steam->delete();

		}catch(\OutOfRangeException $e)
		{
			throw new \OutOfRangeException;
		}
	}
}