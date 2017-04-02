//<?php

class steam_hook_steamPostJoin extends _HOOK_CLASS_
{
	public $steam = NULL;

	/**
	 * Joins (when loading comments)
	 *
	 * @param	\IPS\Content\Item	$item			The item
	 * @return	array
	 */
	static public function joins( \IPS\Content\Item $item )
	{
		try
		{
			$return = parent::joins( $item );
			if($item instanceof \IPS\forums\Topic and !$item->isArchived())
			{
			    if(isset($return['author']))
			    {
				    $return[]	=	array(	'select' 	=> 'steam.*',
				    						'from'		=> array( 'steam_profiles', 'steam'),
				    						'where'		=> array( 'steam.st_member_id = author.member_id' )
													);
				}
			}
			return $return;
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

	/**
	 * Construct ActiveRecord from database row
	 *
	 * @param	array	$data							Row from database table
	 * @param	bool	$updateMultitonStoreIfExists	Replace current object in multiton store if it already exists there?
	 * @return	static
	 */
	static public function constructFromData( $data, $updateMultitonStoreIfExists=true )
	{
		try
		{
			$steam = NULL;
			$obj = parent::constructFromData($data, $updateMultitonStoreIfExists);

			if ( isset( $data[ static::$databaseTable ] ) and is_array( $data[ static::$databaseTable ] ) )
		    {
				if ( isset( $data['steam'] ) and is_array( $data['steam'] ) )
		        {
		           	$steam = \IPS\steam\Profile::constructFromData( $data['steam'], FALSE );
		        }
		        $obj->steam = $steam;
		    }
		    return $obj;
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