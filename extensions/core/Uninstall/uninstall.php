<?php
/**
 * @brief            Uninstall callback
 * @author           <a href='http://www.invisionpower.com'>Invision Power Services, Inc.</a>
 * @copyright    (c) 2001 - SVN_YYYY Invision Power Services, Inc.
 * @license          http://www.invisionpower.com/legal/standards/
 * @package          IPS Social Suite
 * @subpackage       Steam Integration
 * @since            06 May 2016
 * @version          SVN_VERSION_NUMBER
 */

namespace IPS\steam\extensions\core\Uninstall;

use IPS\Login\Handler;
use IPS\Db;

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!defined('\IPS\SUITE_UNIQUE_KEY')) {
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

/**
 * Uninstall callback
 */
class _uninstall
{
    /**
     * Code to execute before the application has been uninstalled
     * @param string $application Application directory
     * @return    void
     */
    public function preUninstall($application): void
    {
        /**
         * @var \IPS\Login\Handler $handler
         */
        $handler = Handler::findMethod('IPS\steam\Login\Steam');
        try {
            Db::i()->update('core_login_methods', array('login_enabled=?', 0), array('login_id=?', $handler->id));
        } catch (\IPS\Db\Exception $e) {
        }

        try {
            Db::i()->update('core_pfields_data', array('pf_type' => 'Text'), array('pf_type=?', 'Steamid'));
            // TODO: If Group data is moved, this will no longer apply.
            if(Db::i()->checkForColumn('core_groups', array('steam_index', 'steam_pull'))){
                Db::i()->dropColumn( 'core_groups', array( 'steam_index', 'steam_pull' ));
            }
        } catch (Db\Exception $e) {
            /* Ignore "Cannot drop because it does not exist" */
            if ($e->getCode() <> 1091) {
                throw $e;
            }
        }
    }

    /**
     * Code to execute after the application has been uninstalled
     * @param string $application Application directory
     * @return    void
     */
    public function postUninstall($application): void
    {

    }
}