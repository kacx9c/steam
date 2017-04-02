//<?php

class steam_hook_steamDefault extends _HOOK_CLASS_
{
	protected function manage()
	{
		try
		{
			if(\IPS\Settings::i()->steam_default_tab && !isset(\IPS\Request::i()->tab))
			{
				\IPS\Request::i()->tab = 'node_steam_steamprofile';
			}
			return call_user_func_array( 'parent::manage', func_get_args() );
		}
		catch ( \RuntimeException $e )
		{
			if ( method_exists( get_parent_class(), __FUNCTION__ ) )
			{
				return call_user_func_array( 'parent::' . __FUNCTION__, func_get_args() );
			}
			else
			{
				throw $e;
			}
		}
	}
}