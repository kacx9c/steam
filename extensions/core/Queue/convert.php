<?php
/**
 * @brief            Background Task
 * @author           <a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright    (c) Invision Power Services, Inc.
 * @license          https://www.invisioncommunity.com/legal/standards/
 * @package          Invision Community
 * @subpackage       Steam Integration
 * @since            15 May 2018
 */

namespace IPS\steam\extensions\core\Queue;

use IPS\Db;
use IPS\Member;


/* To prevent PHP errors (extending class does not exist) revealing path */
if (!defined('\IPS\SUITE_UNIQUE_KEY')) {
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

/**
 * Background Task
 */
class _convert
{
    /**
     * Parse data before queuing
     * @param array $data
     * @return    array
     */
    public function preQueueData($data): array
    {
        $duplicates = array();
        try {
            $query = \IPS\Db::i()->select('COUNT(member_id),steamid', 'core_members', null, null, null, 'steamid',
                'COUNT(member_id) > 1', null);
            foreach ($query as $row) {
                $duplicates[] = $row['steamid'];
            }
            if (\count($duplicates)) {
                $toRemove = \IPS\Db::i()->select('*', 'core_members',
                    array('steamid IN(?)', implode(',', $duplicates)));
                foreach ($toRemove as $r) {
                    $remove = \IPS\Member::constructFromData($r);
                    $remove->steamid = null;
                    $remove->save();
                }
            }
        } catch (\UnderflowException $e) {
            // No duplicates, your users are smarter than the average bear!
        }

        return $data;
    }

    /**
     * Run Background Task
     * @param mixed $data   Data as it was passed to \IPS\Task::queue()
     * @param int   $offset Offset
     * @return    int                            New offset
     * @throws    \IPS\Task\Queue\OutOfRangeException    Indicates offset doesn't exist and thus task is complete
     */
    public function run($data, $offset): int
    {
        if ($data['total'] === 0) {
            // No conversion to be done
            throw new \IPS\Task\Queue\OutOfRangeException;
        }

        /** @var \IPS\steam\Login\Steam $method */
        $method = \IPS\Login\Handler::findMethod('IPS\steam\Login\Steam');

        $select = 'm.*';
        $where = 'm.steamid>0';

        $query = Db::i()->select($select, array('core_members', 'm'), $where, 'm.member_id ASC',
            array($offset, 100), null, null, '111');

        $insert = array();
        foreach ($query as $row) {
            $member = Member::constructFromData($row);
            $insert[] = array(
                'token_login_method' => $method->id,
                'token_member'       => $member->member_id,
                'token_identifier'   => $member->steamid,
                'token_linked'       => 1,
            );
            ++$offset;
        }

        Db::i()->insert('core_login_links', $insert);

        $count = $query->count(false);
        if ($count <= $offset) {
            // Conversion complete
            throw new \IPS\Task\Queue\OutOfRangeException;
        }

        return $offset;
    }

    /**
     * Get Progress
     * @param mixed $data      Data as it was passed to \IPS\Task::queue()
     * @param int   $offset    Offset
     * @return    array( 'text' => 'Doing something...', 'complete' => 50 )    Text explaining task and percentage
     *                         complete
     * @throws    \OutOfRangeException    Indicates offset doesn't exist and thus task is complete
     */
    public function getProgress($data, $offset): array
    {
        $percent = 100;

        if ($data['total'] > 0) {
            $percent = round($offset / $data['total'], 2);
        }

        return array(
            'text'     => Member::loggedIn()->language()->addToStack('steam_queue_convert', false),
            'complete' => $percent,
        );
    }

    /**
     * Perform post-completion processing
     * @param array $data
     * @return    void
     */
    public function postComplete($data): void
    {

    }
}