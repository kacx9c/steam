<?php


namespace IPS\steam\modules\admin\steam;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * view
 */
class _view extends \IPS\Dispatcher\Controller
{
	public $table = '';

	public $includeRows = array();
	/**
	 * Execute
	 *
	 * @return	void
	 */
	public function execute()
	{
		$this->includeRows = array(	'photo',
									'name',
									'st_personaname',
									'st_steamid',
									'st_last_update',
									'st_communityvisibilitystate',
									'st_personastate',
									'st_playtime_2weeks',
									'st_game_count',
									'st_vac_status',
									'st_error',
									'st_lastlogoff'
								);
		parent::execute();
	}

	/**
	 * Manage
	 *
	 * @return	void
	 */
	protected function manage()
	{
		/* Create the table */
		$this->table = new \IPS\Helpers\Table\Db( 'steam_profiles', \IPS\Http\Url::internal( 'app=steam&module=steam&controller=view' ) );

		/* To add new rows, overload and array_splice value into $this->includeRows.  Then return parent */
		$this->table->include = $this->includeRows;
		$this->table->mainColumn		= array('st_member_id', 'Member ID');
		$this->table->sortBy 			= $this->table->sortBy ?: 'st_member_id';
		$this->table->sortDirection 	= $this->table->sortDirection ?: 'asc';
		$this->table->quickSearch		= 'name';

		$this->table->advancedSearch 	= array(
									'name' => \IPS\Helpers\Table\SEARCH_CONTAINS_TEXT,
									'st_steamid' => \IPS\Helpers\Table\SEARCH_CONTAINS_TEXT
									);

		/* $this->table->parsers */
		$this->parsedData();

		/* Break this out later so joins can be added if needed */
		$this->table->joins = array(
			array(
				'select' => 'm.*',
				'from' => array( 'core_members', 'm' ),
				'where' => 'm.member_id=steam_profiles.st_member_id' )
			);

		// $this->table->rowButtons = function($row)
		// {
		// 	return array(
		// 			'delete'	=> array(
		// 				'icon'		=> 'trash',
		// 				'title'		=> 'delete',
		// 				'link'		=> \IPS\Http\Url::internal( 'app=steam&module=steam&controller=view&do=delete&id=' ) . $row['st_member_id'],
		// 			),
		// 		);
		// };

		/* Quick Filters */
		$this->table->filters = array(
			'steam_filters_active'			=> "st_steamid NOT IN ('','0')",
			'steam_filters_disabled'		=> 'st_restricted<>0',
			'steam_filters_error'			=> "st_error<>''",
			'steam_filters_vacban'			=> 'st_vac_status<>0'
		);

		\IPS\Output::i()->title = \IPS\Member::loggedIn()->language()->addToStack('menu__steam_steam_view_title');

		/* Display */
		\IPS\Output::i()->output	= \IPS\Theme::i()->getTemplate( 'global', 'core' )->block( 'title', (string) $this->table );
	}

	/* Overload and add additional parsers for added columns to $data */
	protected function parsedData($data=array())
	{
		$this->table->parsers = array_merge( array(
			'photo' => function($value, $row)
		{
			return $this->photo($value, $row);
		},
			'name' => function($value,$row)
		{
			return $this->name($value, $row);
		},
			'st_steamid' => function($value, $row)
		{
			return $this->steamID($value, $row);
		},
			'st_personaname' => function($value, $row)
		{
			return $this->personaname($value, $row);
		},
			'st_last_update' => function($value, $row)
		{
			return $this->lastUpdate($value, $row);
		},
			'st_lastlogoff' => function($value, $row)
		{
			return $this->lastLogoff($value, $row);
		},
			'st_restricted' => function($value, $row)
		{
			return $this->restricted($value, $row);
		},
			'st_communityvisibilitystate' => function($value, $row)
		{
			return $this->communityVisibilityState($value, $row);
		},
			'st_vac_status' => function($value, $row)
		{
			return $this->vacStatus($value, $row);
		},
			'st_personastate' => function($value, $row)
		{
			return $this->personaState($value,$row);
		},
			'st_playtime_2weeks' => function($value, $row)
		{
			return $this->playtime($value,$row);
		},
			'st_error' => function($value, $row)
		{
			return $this->error($value, $row);
		}), $data);
	}

	protected function photo($value, $row)
	{
		if($row['st_avatarmedium'])
			return "<img src=" . $row['st_avatarmedium'] . " class='ipsUserPhoto_small'/>";
		elseif($row['st_restricted'])
			return "<span class='ipsType_warning'><strong>" . \IPS\Member::loggedIn()->language()->addToStack( 'steam_disabled' ) . "</strong></span>";
		else
			return;
	}

	protected function personaname($value, $row)
	{
		$member = \IPS\Member::constructFromData($row);
		return "<a href=" . $member->acpUrl() . " target='_blank'>" . $value . "</a>";
	}

	protected function name($value, $row)
	{
		$member = \IPS\Member::constructFromData($row);

		return "<a href=" . $member->url() . " target='_blank'>" . $member->name . "</a>";

	}

	protected function steamID($value, $row)
	{
		if($value)
			return "<a href=" . $row['st_profileurl'] . " target='_blank'>" . $value . "</a>";
		else
			return;
	}

	protected function lastUpdate($value, $row)
	{
		if($value)
		{
			$ts = \IPS\DateTime::ts($value);
			return $ts->relative('RELATIVE_FORMAT_SHORT');
		}else
		{
			return '';
		}
	}

	protected function lastLogoff($value, $row)
	{
		if($value)
		{
			$ts = \IPS\DateTime::ts($value);
			if($value < (time() - 86400))
			{
				return $ts->strFormat( '%x');
			}else
			{
				return $ts->relative('RELATIVE_FORMAT_SHORT');
			}
		}else
		{
			return '';
		}
	}

	protected function restricted($value, $row)
	{
		return $value;
		if($value == 0)
		{
			return "<span class='ipsType_success'><strong>" . \IPS\Member::loggedIn()->language()->addToStack( 'steam_no' ) . "</strong></span>";
		}
		if($value < time())
		{
			return "<span class='ipsType_warning'><strong>" . \IPS\Member::loggedIn()->language()->addToStack( 'steam_yes' ) . "</strong></span>";
		}
	}

	protected function communityVisibilityState($value, $row)
	{
		if($value == 3)
		{
			return "<span class='ipsType_success'><strong>" . \IPS\Member::loggedIn()->language()->addToStack( 'steam_public' ) . "</strong></span>";
		}elseif($value == 1 || $value == 2)
		{
			return "<span class='ipsType_warning'><strong>" . \IPS\Member::loggedIn()->language()->addToStack( 'steam_private' ) . "</strong></span>";
		}else
		{
			return '';
		}
	}

	protected function vacStatus($value, $row)
	{
		if($value == 0)
		{
			return "<span class='ipsType_success'><strong>" . \IPS\Member::loggedIn()->language()->addToStack( 'steam_no' ) . "</strong></span>";
		}
		if($value < time())
		{
			return "<span class='ipsType_warning'><strong>" . \IPS\Member::loggedIn()->language()->addToStack( 'steam_banned' ) . "</strong></span>";
		}
	}

	protected function personaState($value, $row)
	{
		if($row['st_gameextrainfo'] || $row['st_gameid']) {
			return '<span class="ipsBadge ipsBadge_positive" data-ipsToolTip title="' . $row['st_gameextrainfo'] . '">' . \IPS\Member::loggedIn()->language()->addToStack('steam_ingame') . '</span>';
		}else
		{
			if(!$value)
			{
				return '<span class="ipsBadge ipsBadge_neutral">' . \IPS\Member::loggedIn()->language()->addToStack( 'steam_status_'.$value) . '</span>';
			}else
			{
				return '<span class="ipsBadge" style="background: #86b5d9;">' . \IPS\Member::loggedIn()->language()->addToStack( 'steam_status_'.$value) . '</span>';
			}
		}
	}

	protected function playtime($value, $row)
	{
		if($value)
		{
			return round(($value / 60), 1);
		}
	}

	protected function error($value, $row)
	{
		if($value)
		{
			return '<span class="ipsType_warning" data-ipsToolTip title="' . \IPS\Member::loggedIn()->language()->addToStack( $value ) . '"><strong>ERROR</strong></span>';
		}
	}


}