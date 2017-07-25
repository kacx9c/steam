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
		$this->api 		= \IPS\Settings::i()->steam_api_key;

		if(!$this->api)
		{
			/* // If we don't have an API key, throw an exception to log an error message // */
			throw new \Exception( 'steam_err_noapi' );
		}

		/* Load the cache  data */
		if(isset(\IPS\Data\Store::i()->steamData))
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
		$this->groups = array();
	}

	public function load( $offset = 0)
	{
		/* We are loading new members, if there is anyone still there, dump 'em. */
		unset($this->members);

		if(($this->cfID['pf_id'] && $this->cfID['pf_group_id']) || $this->steamLogin)
		{
			// Build select and where clauses
			$select_member ="m.*";
			//$select_pfields = "p.field_". $this->cfID['pf_id'];
			$select_pfields = "p.*";

			$where = "p.member_id=m.member_id";

			// INNER join, INNER join, INNER join!!!!!

			if($this->cfID['pf_id'] && $this->steamLogin)
			{
				$select_member .= ",m.steamid";
				$select = $select_member. "," . $select_pfields;
				$where .= " AND (p.field_" . $this->cfID['pf_id'] . "<>'' OR m.steamid>0)";

				$query = \IPS\Db::i()->select( $select, array('core_members', 'm'), NULL, 'm.member_id ASC', array( $offset, \IPS\Settings::i()->steam_batch_count), NULL, NULL, '111')
					->join( array( 'core_pfields_content', 'p'), $where, 'INNER');

			}elseif($this->cfID['pf_id'])
			{
				$select = $select_member. "," . $select_pfields;
				$where .= " AND (p.field_" . $this->cfID['pf_id'] . "<>'')";

				$query = \IPS\Db::i()->select( $select, array('core_members', 'm'), NULL, 'm.member_id ASC', array( $offset, \IPS\Settings::i()->steam_batch_count), NULL, NULL, '111')
					->join( array( 'core_pfields_content', 'p'), $where, 'INNER');

			}elseif($this->steamLogin)
			{
				$select_member .= ",m.steamid";
				$select = $select_member;
				$where = "m.steamid>0";

				$query = \IPS\Db::i()->select( $select, array('core_members', 'm'), $where, 'm.member_id ASC', array( $offset, \IPS\Settings::i()->steam_batch_count), NULL, NULL, '111');
			}

			// Execute one of the queries built above
			foreach($query as $row)
			{
				$m = new \IPS\Member;
				if(isset($row['m']))
				{
					$member = $m->constructFromData($row['m']);
				}else
				{
					$member = $m->constructFromData($row);
				}
				if(is_array($row['p']) && count($row['p']))
				{
					foreach ( \IPS\core\ProfileFields\Field::values( $row['p'], 'PROFILE' ) as $group => $fields )
					{
						$member->profileFields[ 'core_pfieldgroups_' . $group ] =  $fields;
					}
				}else
				{
					$member->profileFields = array();
				}
				$this->members[] = $member;
			}
			// Count of all records found ignoring the limit
			$this->extras['count'] = $query->count(TRUE);

		}else
		{
			$this->stError = \IPS\Lang::load( \IPS\Lang::defaultLanguage() )->get('steam_field_invalid');
		}
		return $this->members;
	}

	public function update($single = 0)
	{
		if($single)
		{
			$members[] = \IPS\steam\Profile::load($single);

			if(!$members[0]->steamid)
			{
				$member = \IPS\Member::load($single);
				$steamid = $this->getSteamID($member);

				/* If they set their steamID, lets put them in the cache */
				if($steamid)
				{
					$m = \IPS\steam\Profile::load($member->member_id);
					if(!$m->steamid)
					{
						$m->member_id 			= $member->member_id;
						$m->steamid  			= $steamid;
						$m->members_seo_name	= $member->members_seo_name;
						$m->setDefaultValues();
						$m->save();

						$members[] = $m;
					}

				}else
				{
					/* We don't have a SteamID for this member, jump ship */
					if(\IPS\Settings::i()->steam_diagnostics)
					{
						throw new \Exception( \IPS\Lang::load( \IPS\Lang::defaultLanguage() )->get('steam_id_invalid') );
					}
					return FALSE;
				}
			}
		}else
		{
			$select = "s.*";
			$where = "s.st_steamid>0 AND s.st_restricted!='1'";
			$query = \IPS\Db::i()->select( $select, array('steam_profiles', 's'), $where, 's.st_member_id ASC', array( $this->extras['offset'], \IPS\Settings::i()->steam_batch_count), NULL, NULL, '011');

			foreach($query as $row)
			{
				$members[] = \IPS\steam\Profile::constructFromData($row);
			}

			$this->extras['count'] = $query->count(TRUE);
		}

		foreach($members as $p)
		{
			$err = 0;
			// Load member so we can make changes.
			$m = \IPS\Member::load($p->member_id);

			// Store general information that doesn't rely on an API.
			$p->members_seo_name 	= ($m->members_seo_name ? $m->members_seo_name : '');
			$p->addfriend 			= "steam://friends/add/".$p->steamid;
			$p->last_update			= time();

			// Get Player Level and badges.
			$url = "http://api.steampowered.com/IPlayerService/GetBadges/v1/?key=" . $this->api . "&steamid=" .$p->steamid;
			try
			{
				$req = $this->request( $url );
				if($req->httpResponseCode != 200)
				{
					$this->failed($m, 'steam_err_getlevel');
					$err = 1;

					if(\IPS\Settings::i()->steam_diagnostics)
					{
						throw new \Exception( $req->httpResponseCode . ": getLevel" );
					}

				}
				try
				{
					$level = $req->decodeJson();
				}catch(\RuntimeException $e)
				{
					$this->failed($m, 'steam_err_getlevel');
					$err = 1;
					if(\IPS\Settings::i()->steam_diagnostics)
					{
						throw new \Exception( $e->getMessage() );
					}
				}

				// Store the data and unset the variable to free up memory
				if(isset($level))
				{
					if(is_array($level['response']['badges']) && count($level['response']['badges']))
					{
						// Prune data and only keep what's needed.
						$player_badges = array_filter($level['response']['badges'], array($this, 'badges'));
						unset($level['response']['badges']);
						$level['response']['badges'] = $player_badges;
						unset($player_badges);

					}
					$p->player_level	= json_encode($level['response']);
				}else
				{
					$p->player_level	= json_encode(array());
				}
				unset($req);
				unset($level);

			}catch(\OutOfRangeException $e)
			{
				$this->failed($m, 'steam_err_getlevel');
				$err = 1;

				if(\IPS\Settings::i()->steam_diagnostics)
				{
					throw new \Exception( $e->getMessage() );
				}
			}



			// Get VAC Ban Status
			$url = "http://api.steampowered.com/ISteamUser/GetPlayerBans/v1/?key=" . $this->api . "&steamids=" . $p->steamid;
			try
			{
				$req = $this->request( $url );

				if($req->httpResponseCode != 200)
				{
					$this->failed($m, 'steam_err_vacbans');
					$err = 1;

					if(\IPS\Settings::i()->steam_diagnostics)
					{
						throw new \Exception( $req->httpResponseCode . ": getVACBans" );
					}
				}else
				{
					try
					{
						$vacBans = $req->decodeJson();
					}catch(\RuntimeException $e)
					{
						$this->failed($m, 'steam_err_vacbans');
						$err = 1;

						if(\IPS\Settings::i()->steam_diagnostics)
						{
							throw new \Exception( $e->getMessage() );
						}
					}
					if(is_array($vacBans))
					{
						foreach($vacBans['players'] as $v)
						{
							if($v['CommunityBanned'] || $v['VACBanned'])
							{
								$p->vac_status = '1';
								$p->vac_bans = json_encode($v);
							}else
							{
								$p->vac_status = '0';
								$p->vac_bans = json_encode(array());
							}
						}
					}else
					{
						$p->vac_status = '0';
						$p->vac_bans = json_encode(array());
					}
					unset($vacBans);
					unset($req);
				}
			}catch(\OutOfRangeException $e)
			{
				if(\IPS\Settings::i()->steam_diagnostics)
				{
					throw new \Exception( $e->getMessage() );
				}
				$this->failed($m, 'steam_err_vacbans');
				$err = 1;
			}



			/* Get Games they've played in the last 2 weeks */
			$url = "http://api.steampowered.com/IPlayerService/GetRecentlyPlayedGames/v0001/?key=" . $this->api . "&steamid=" . $p->steamid . "&format=json";

			try
			{
				$req = $this->request( $url );

				if($req->httpResponseCode != 200)
				{
					$this->failed($m,'steam_err_getrecent');
					$err = 1;

					if(\IPS\Settings::i()->steam_diagnostics)
					{
						throw new \Exception( $req->httpResponseCode . ": getRecent" );
					}
				}else
				{
					try
					{
						$games = $req->decodeJson();
					}
					catch( \RuntimeException $e)
					{
						$this->failed($m,'steam_err_getrecent');
						$err = 1;

						if(\IPS\Settings::i()->steam_diagnostics)
						{
							throw new \Exception( $e->getMessage() );
						}
					}

					// Store recently played game data and free up memory
					if(isset($games['response']['total_count']) AND isset($games['response']['games']))
					{
						$p->playtime_2weeks = 0;
						foreach($games['response']['games'] as $id => $g)
						{
							// If we don't have a logo for the game, don't bother storing it. Still tally time played.
							if(isset($g['img_icon_url']) && isset($g['img_logo_url']))
							{
								if($g['img_icon_url'] && $g['img_logo_url'])
								{
									$_games[$g['appid']] = $g;
								}
							}
							$p->playtime_2weeks += $g['playtime_2weeks'];
							//	img_icon_url, img_logo_url - these are the filenames of various images for the game. To construct the URL to the image, use this format:
							//	http://media.steampowered.com/steamcommunity/public/images/apps/{appid}/{hash}.jpg
						}
						$p->games = json_encode($_games);
						$p->total_count = $games['response']['total_count']; // Total counts of games played in last 2 weeks
					}else
					{
						$p->playtime_2weeks 	= 0;
						$p->total_count = 0;
						$p->games = json_encode(array());
					}
					unset($req);
					unset($games);
					unset($_games);
				}
			}catch(\OutOfRangeException $e)
			{
				$this->failed($m,'steam_err_getrecent');
				$err = 1;
				if(\IPS\Settings::i()->steam_diagnostics)
				{
					throw new \Exception( $e->getMessage() );
				}
			}

			// Get a list of games they own.
			if(\IPS\Settings::i()->steam_get_owned)
			{
				$url = "http://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/?key=" . $this->api . "&steamid=" . $p->steamid . "&include_appinfo=1&format=json";

				try
				{
					$req = $this->request( $url );

					if($req->httpResponseCode != 200)
					{
						$this->failed($m, 'steam_err_getowned');
						$err = 1;
						if(\IPS\Settings::i()->steam_diagnostics)
						{
							throw new \Exception( $req->httpResponseCode . ": getOwned" );
						}
					}else
					{
						try
						{
							$owned = $req->decodeJson();
						}
						catch( \RuntimeException $e)
						{
							$this->failed($m,'steam_err_getowned');
							$err = 1;
							if(\IPS\Settings::i()->steam_diagnostics)
							{
								throw new \Exception( $e->getMessage() );
							}
						}

						if(isset($owned['response']['game_count']) && \IPS\Settings::i()->steam_get_owned && isset($owned['response']['games']))
						{
							foreach($owned['response']['games'] as $id => $g)
							{
								if($g['img_icon_url'] && $g['img_logo_url'])
								{
									$_owned[$g['appid']] = $g;
									//	img_icon_url, img_logo_url - these are the filenames of various images for the game. To construct the URL to the image, use this format:
									//	http://media.steampowered.com/steamcommunity/public/images/apps/{appid}/{hash}.jpg
								}
							}
							$p->owned = json_encode($_owned);
							$p->game_count 	= (isset($owned['response']['game_count']) ? $owned['response']['game_count'] : 0);	   	// Total # of owned games, if we are pulling that data
						}else
						{
							$p->owned = json_encode(array());
							$p->game_count 	= 0;
						}
						unset($req);
						unset($owned);
						unset($_owned);
					}
				} catch(\OutOfRangeException $e) {
					$this->failed($m, 'steam_err_getowned');
					$err = 1;
					if(\IPS\Settings::i()->steam_diagnostics)
					{
						throw new \Exception( $e->getMessage() );
					}
				}

			}else
			{
				$p->owned = json_encode(array());
			}



			if(!$err)
			{
				$p->error 	= ''; // Correctly set member, so clear any errors.
			}
			$err = 0;

			// Store the data
			$p->save();

			// Lets clear any errors before we start the next member
			$this->stError = '';
		}
		if(!$single)
		{
			$this->extras['offset'] = $this->extras['offset'] + \IPS\Settings::i()->steam_batch_count;
			if($this->extras['offset'] >= $this->extras['count'])
			{
				$this->extras['offset'] = 0;
			}
			\IPS\Data\Store::i()->steamData = $this->extras;
		}
		return TRUE;
	}

	protected function request( $url )
	{
		if( $url )
		{
			return \IPS\Http\Url::external( $url )->request( 30 )->get();
		}else
		{
			return;
		}
	}

	public function remove($member)
	{
		try
		{
			$r = \IPS\steam\Profile::load($member);
			$r->setDefaultValues();
			$r->save();

		}catch( \Exception $e )
		{
			return;
		}
		return;
	}

	protected function failed( $m, $lang=NULL )
	{
		if(isset($m->member_id))
		{
			$mem = $this->profile->load($m->member_id);
		}else
		{
			return;
		}

		// Either we loaded an existing record, or are working with a new record... Either way, update and save it.
		$mem->member_id = $m->member_id;
		$mem->error = ($lang ? $lang : '');
		$mem->last_update = time();
		$mem->members_seo_name = $m->members_seo_name;
		$mem->save();
		$this->fail[] = $m->member_id;
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