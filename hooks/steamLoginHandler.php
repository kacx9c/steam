//<?php

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!defined('\IPS\SUITE_UNIQUE_KEY')) {
    exit;
}

abstract class steam_hook_SteamLoginHandler extends _HOOK_CLASS_
{

    /**
     * @return array
     */
    public static function handlerClasses(): array
    {
        $return = parent::handlerClasses();
        $return[] = 'IPS\steam\Login\Steam';

        return $return;
    }

}
