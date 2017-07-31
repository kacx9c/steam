<?php

namespace IPS\steam\Update;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Steam Update Class
 */
class _Groups
{
	/**
	 * @brief	[IPS\Member]	Member object
	 */
	public $groups 		= array();
	public $api 		= '';
	public $fail		= array();
	public $extras		= array();
	public $stError 	= '';
	public $cacheData 	= array();
	public $query 		= '';
	public $cache 		= array();
	public $count 		= 0;


	public function __construct()
	{
		/* Load the cache  data */
		if(isset(\IPS\Data\Store::i()->steamGroupData))
		{
			$this->extras = \IPS\Data\Store::i()->steamGroupData;
		}else
		{
			$this->extras = array('offset'       => 0,
			                      'count'        => 0,
			);
		}
		if(!isset($this->extras['offset']))
		{
			$this->extras['offset'] = 0;
		}

		\IPS\Data\Store::i()->steamGroupData = $this->extras;
	}

	// enhance to accept single groups to update.
	public function update()
	{

		$select = "g.*";
		$query = \IPS\Db::i()->select( $select, array('steam_groups', 'g'), $where, 'g.stg_id ASC', array( $this->extras['offset'], 5), NULL, NULL, '011');

		foreach($query as $row)
		{
			$groups[] = \IPS\steam\Profile\Groups::constructFromData($row);
		}

		$this->extras['count'] = $query->count(TRUE);

		foreach($groups as $g)
		{
			$err = 0;

			// Get Group Data
			if($g->name) {
				$url = "http://steamcommunity.com/groups/" . $g->name . "/memberslistxml/?xml=1";
			}elseif($g->id){
				$url = "http://steamcommunity.com/gid/" . $g->id . "/memberslistxml/?xml=1";
			}else{
				$err = '1';
				continue;
			}
			try
			{
				$req = $this->request( $url );
				if($req->httpResponseCode != 200)
				{
					$this->failed($g, 'group_err_request');
					$err = 1;

					if(\IPS\Settings::i()->steam_diagnostics)
					{
						throw new \Exception( $req->httpResponseCode . ": getGroup" );
					}
				}
				try
				{
					$data = $req->decodeXml();
					$this->storeXML($data);
				}catch(\RuntimeException $e)
				{
					$this->failed($m, 'steam_err_getlevel');
					$err = 1;
					if(\IPS\Settings::i()->steam_diagnostics)
					{
						throw new \Exception( $e->getMessage() );
					}
					continue;
				}

				unset($data);

			}catch(\OutOfRangeException $e)
			{
				$this->failed($m, 'steam_err_getlevel');
				$p->error = 1;

				if(\IPS\Settings::i()->steam_diagnostics)
				{
					throw new \Exception( $e->getMessage() );
				}
			}


			// Store general information that doesn't rely on an API.
			$g->last_update			= time();

			// Store the data
			$p->save();

			$err = 0;
		}

		$this->extras['offset'] = $this->extras['offset'] + 5;

		// If offset is greater than count we've hit the end.  Reset Offset for the next query.
		if($this->extras['offset'] >= $this->extras['count'])
		{
			$this->extras['offset'] = 0;
		}
		\IPS\Data\Store::i()->steamGroupData = $this->extras;
	}

	public static function sync($data = array())
	{
		$groups = array();
		try {
			$select = 'g.*';
			$where = '';
			$query = \IPS\Db::i()->select($select, array('steam_groups', 'g'), $where);

			foreach($query as $row)
			{
				$groups[$row['stg_id']] = \IPS\steam\Profile\Groups::constructFromData($row);
			}
		}catch(\UnderflowException $e)
		{

		}

		if(is_array($data) && count($data))
		{
			$_data = array();
			// Add groups that are missing
			foreach($data as $d)
			{
				// If we have an ID, search for ID's.
				if(preg_match( '/^\d{18}$/', $d))
				{
					if(!in_array($d, $groups))
					{
						$_data[] = $d;
					}
				}else
				{
					$found = NULL;
					if(is_array($groups) && count($groups))
					{
						foreach($groups as $g)
						{
							if(!strcasecmp($g->name, $d))
							{
								$found = $g->name;
							}
						}
					}
					if(!$found)
					{
						$_data[] = $d;
						$found = NULL;
					}
				}
			}

			foreach($_data as $g)
			{
				$new = new \IPS\steam\Profile\Groups;

				if(preg_match('/^\d{18}$/', $g))
				{
					$url = "http://steamcommunity.com/gid/" . $g . "/memberslistxml/?xml=1";
				}else
				{
					$url = "http://steamcommunity.com/groups/" . $g . "/memberslistxml/?xml=1";
				}

				$req = static::request($url);

				if($req->httpResponseCode != 200)
				{

					if(\IPS\Settings::i()->steam_diagnostics)
					{
						throw new \Exception( $req->httpResponseCode . ": getGroup" );
					}
				}
				try
				{
					$values = $req->decodeXml();
					$new->storeXML($values);
				}catch(\RuntimeException $e)
				{
					$err = 1;
					if(\IPS\Settings::i()->steam_diagnostics)
					{
						throw new \Exception( $e->getMessage() );
					}
					continue;
				}
				$new->save();
			}
		}else
		{
			return FALSE;
		}
	}

	public static function request( $url )
	{
		if( $url )
		{
			return \IPS\Http\Url::external( $url )->request( 30 )->get();
		}else
		{
			return;
		}
	}

	public function remove($group)
	{
		try
		{
			$r = \IPS\steam\Profile\Groups::load($group);
			$r->setDefaultValues();
			$r->save();

		}catch( \Exception $e )
		{
			return;
		}
		return;
	}

	protected function failed( $g, $lang=NULL )
	{

		if(isset($g->id) || isset($g->name))
		{
			$groupToLoad = isset($g->id) ? $g->id : $g->name;
			$group = \IPS\steam\Profile\Groups::load($groupToLoad);
		}else
		{
			return;
		}

		$group->error = ($lang ? $lang : '');
		$group->last_update = time();
		$group->save();
		return;
	}

	public function error($raw = true)
	{
		if($raw)
		{
			return $this->stError;
		}

		if($this->stError)
		{
			$return = $this->stError;
		}elseif(is_array($this->failed) && count($this->failed))
		{
			$return = \IPS\Lang::load( \IPS\Lang::defaultLanguage() )->get('task_steam_profile')." - ".implode(',', $this->fail);
		}else
		{
			$return = NULL;
		}
		return $return;
	}


}