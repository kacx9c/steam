<?php
/**
 * @brief        Member Sync
 */

namespace IPS\steam\extensions\core\MemberSync;

use IPS\Data\Store;
use IPS\steam\Profile;
use IPS\steam\Update;
use IPS\Db;

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!defined('\IPS\SUITE_UNIQUE_KEY')) {
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

/**
 * Member Sync
 */
class _Steam
{

    /**
     * @param $member
     * @throws \Exception
     */
    public function onCreateAccount($member): void
    {
        $this->onValidate($member);
    }


    /**
     * @param $member
     * @throws \Exception
     */
    public function onValidate($member): void
    {
        $cache = array();
        if (isset(Store::i()->steamData)) {
            $cache = Store::i()->steamData;
        }
        if (!$member->steamid && !isset($cache['pf_id'])) {

            /* If they don't have a steamUpdate login set, or there isn't a profile field ID return. */
            /* If it's just a cache issue, they'll get caught in the cleanup routine */
            return;
        }

        $steamUpdate = new Update;
        $steamid = $steamUpdate->getSteamID($member);
        $steamProfile = Profile::load($member->member_id);

        /* If they set their steamID, lets put them in the cache */
        if ($steamid === '' || !$steamProfile->steamid) {
            return;
        }

        $steamProfile->setDefaultValues();
        $steamProfile->member_id = $member->member_id;
        $steamProfile->steamid = $steamid;
        $steamProfile->save();

        $steamUpdate->updateFullProfile($steamProfile->member_id);
    }

    /**
     * Member account has been updated
     * @param    $member        \IPS\Member    Member updating profile
     * @param    $changes       array        The changes
     * @return    void
     */
    public function onProfileUpdate($member, $changes): void
    {
        try {
            $cache = Store::i()->steamData ?? array();
            $group = '';
            $pField = '';
            $_field = '';
            $delete = false;
            if (isset($cache['pf_id'], $cache['pf_group_id'])) {
                $group = 'core_pfieldgroups_';
                $pField = 'core_pfield_';
                $_field = 'field_';

                $group .= $cache['pf_group_id'];
                $pField .= $cache['pf_id'];
                $_field .= $cache['pf_id'];
            }

            if (isset($changes[$_field])) {
                $delete = !$changes[$_field];
            }

            if ($delete) {
                $steamProfile = Profile::load($member->member_id);
                if ($steamProfile->member_id) {
                    $steamProfile->delete();
                    return;
                }
            }

            if (!isset($changes[$_field])) {
                return;
            }

            $steamUpdate = new Update;

            $member->profileFields = $member->profileFields();
            $member->profileFields[$group][$pField] = $changes[$_field];
            $steamid = ($changes['steamid'] ?? $steamUpdate->getSteamID($member));

            $steamProfile = Profile::load($member->member_id);

            /* If the steamid is valid, go ahead and save and update the cache right now */
            if ($steamid) {
                $steamProfile->setDefaultValues();
                $steamProfile->member_id = $member->member_id;
                $steamProfile->steamid = $steamid;
                $steamProfile->save();
                $steamUpdate->updateFullProfile($steamProfile->member_id);
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
     * @param    $member    \IPS\Member    The member
     * @return    void
     */
    public function onSetAsSpammer($member): void
    {
        try {
            /* Set steam restriction */
            $steam = new Update;
            $steam->restrict($member->member_id);
        } catch (\OutOfRangeException $e) {
            throw new \OutOfRangeException;
        }
    }

    /**
     * Member is unflagged as spammer
     * @param    $member    \IPS\Member    The member
     * @return    void
     */
    public function onUnSetAsSpammer($member): void
    {
        try {
            $steam = new Update;
            $steam->unrestrict($member->member_id);
            /* Try to update the profile */
            $steam->updateFullProfile($member->member_id);
        } catch (\Exception $e) {
            throw new \OutOfRangeException;
        }
    }

    /**
     * Member is merged with another member
     * @param \IPS\Member $member  Member being kept
     * @param \IPS\Member $member2 Member being removed
     * @return    void
     */
    public function onMerge($member, $member2): void
    {
        /* Purge member2 steam data */
        $this->onDelete($member2);
    }

    /**
     * Member is deleted
     * @param    $member    \IPS\Member    The member
     * @return    void
     */
    public function onDelete($member): void
    {
        /* Purge member steam data */
        try {
            $steam = Profile::load($member->member_id);
            $steam->delete();
        } catch (\OutOfRangeException $e) {
            throw new \OutOfRangeException;
        }
    }
}