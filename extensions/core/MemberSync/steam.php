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

            /* If they don't have a steam login set, or there isn't a profile field ID return. */
            /* If it's just a cache issue, they'll get caught in the cleanup routine */
            return;
        }

        $steam = new Update;
        $steamid = $steam->getSteamID($member);

        /* If they set their steamID, lets put them in the cache */
        if ($steamid) {
            $m = Profile::load($member->member_id);
            if (!$m->steamid) {
                $m->member_id = $member->member_id;
                $m->steamid = $steamid;
                $m->setDefaultValues();

                $m->save();

                $steam->updateProfile($m->member_id);
                $steam->update($m->member_id);
            }
        } else {
            /* We don't have a SteamID on the account, jump ship */
            return;
        }
    }

    /**
     * Member account has been updated
     * @param    $member        \IPS\Member    Member updating profile
     * @param    $changes       array        The changes
     * @return    void
     */
    public function onProfileUpdate($member, $changes): void
    {
        /* Did they change their SteamID?  If so, store them in the profile table */
        /* If they are using the steam login, ignore profile field.  */
        try {
            /**
             * @var array $cache
             */
            $cache = array();
            if ($member->steamid && !isset($changes['steamid'])) {
                /* Steam Login has priority, if it's set ignore profile fields. */
                return;
            }
            if (isset(Store::i()->steamData)) {
                $cache = Store::i()->steamData;
            }
            /**
             * @var string $group
             */
            $group = '';
            /**
             * @var string $field
             */
            $field = '';
            /**
             * @var string $_field
             */
            $_field = '';
            $delete = false;
            if (isset($cache['pf_id'], $cache['pf_group_id'])) {
                $group = 'core_pfieldgroups_';
                $field = 'core_pfield_';
                $_field = 'field_';

                $group .= $cache['pf_group_id'];
                $field .= $cache['pf_id'];
                $_field .= $cache['pf_id'];
            }

            if (isset($changes[$_field])) {
                $delete = $changes[$_field] ? false : true;
            } elseif (isset($changes['steamid'])) {
                $delete = $changes['steamid'] ? false : true;
            }

            if ($delete) {
                $s = Profile::load($member->member_id);
                if ($s->member_id) {
                    $s->delete();
                }
            }
            if (!$delete && (isset($changes['steamid']) || isset($changes[$_field]))) {
                $steam = new Update;

                $member->profileFields = $member->profileFields();
                if (isset($changes[$_field])) {
                    $member->profileFields[$group][$field] = $changes[$_field];
                }

                $steamid = ($changes['steamid'] ?? $steam->getSteamID($member));

                $s = Profile::load($member->member_id);

                /* If the steamid is valid, go ahead and save and update the cache right now */
                if ($steamid) {
                    $s->setDefaultValues();
                    $s->member_id = $member->member_id;
                    $s->steamid = $steamid;
                    $s->save();
                    $steam->updateProfile($s->member_id);
                    $steam->update($s->member_id);
                } elseif ($s->member_id) {
                    // If we actually loaded a profile, but there isn't a steamid, delete their cache entry entirely.
                    $s->delete();
                } else {
                    // Was an empty object, just taking out the trash.
                    unset($s);
                }
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
            /* Unrestrict steam account */
            $steam = new Update;
            $steam->unrestrict($member->member_id);
            /* Try to update the profile */
            $steam->updateProfile($member->member_id);
            $steam->update($member->member_id);

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
            try{
                Db::i()->delete('core_login_links',
                    array('token_member=? AND token_identifier=?',
                          $member->member_id,
                          $steam->st_steamid
                    ));
            }catch(\Exception $e) {
                // Do nothing, they don't have a linked account
            }
            $steam->delete();
        } catch (\OutOfRangeException $e) {
            throw new \OutOfRangeException;
        }
    }
}