<?php

namespace IPS\steam;

use IPS\Member;
use IPS\Application;
use IPS\Request;
use IPS\Output;

/**
 * Blog Application Class
 */
class _Application extends Application
{
    /**
     * Init
     * @return    void
     */
    public function init(): void
    {
        /* If the viewing member cannot view the board (ex: guests must login first), then send a 404 Not Found header here, before the Login page shows in the dispatcher */
        if (!Member::loggedIn()->group['g_view_board'] && (Request::i()->module == 'steam' && Request::i()->controller == 'view' && Request::i()->do == 'rss')) {
            Output::i()->error('node_error', 'STEAM001/1', 404, '');
        }
    }

    /**
     * Install 'other' items. Left blank here so that application classes can override for app
     *  specific installation needs. Always run as the last step.
     * @return void
     */
    public function installOther(): void
    {

    }
}