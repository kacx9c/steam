//<?php

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	exit;
}

class steam_hook_SteamFurl extends _HOOK_CLASS_
{

	public static function furlDefinition( $revert=FALSE )
    {
        $furls = parent::furlDefinition($revert);

        if (!isset($furls['settings_Steam'])) {
            $furls['settings_Steam'] = array(
                'friendly' => 'settings/steam',
                'real'     => 'app=core&module=system&controller=settings&area=profilesync&service=Steam',
                'regex'    => array('settings\/steam'),
                'params'   => array(),
            );
        }

        return $furls;
    }
}
