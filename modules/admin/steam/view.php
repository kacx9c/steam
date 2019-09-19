<?php


namespace IPS\steam\modules\admin\steam;

use IPS\Http\Url;
use IPS\Helpers\Table;
use IPS\Member;
use IPS\Output;
use IPS\Theme;
use IPS\DateTime;

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!defined('\IPS\SUITE_UNIQUE_KEY')) {
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

/**
 * view
 */
class _view extends \IPS\Dispatcher\Controller
{
    /**
     * @var string
     */
    public $table = '';

    /**
     * @var array
     */
    public $includeRows = array();

    /**
     * Execute
     * @return    void
     */
    public function execute(): void
    {
        $this->includeRows = array(
            'photo',
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
            'st_lastlogoff',
        );
        parent::execute();
    }

    /**
     * Manage
     * @return    void
     */
    protected function manage(): void
    {
        /* Create the table */
        $this->table = new Table\Db('steam_profiles',
            Url::internal('app=steam&module=steam&controller=view'));

        /* To add new rows, overload and array_splice value into $this->includeRows.  Then return parent */
        $this->table->include = $this->includeRows;
        $this->table->mainColumn = array('st_member_id', 'Member ID');
        $this->table->sortBy = $this->table->sortBy ?: 'st_member_id';
        $this->table->sortDirection = $this->table->sortDirection ?: 'asc';
        $this->table->quickSearch = 'name';

        $this->table->advancedSearch = array(
            'name'       => Table\SEARCH_CONTAINS_TEXT,
            'st_steamid' => Table\SEARCH_CONTAINS_TEXT,
        );

        /* $this->table->parsers */
        $this->parsedData();

        /* Break this out later so joins can be added if needed */
        $this->table->joins = array(
            array(
                'select' => 'm.*',
                'from'   => array('core_members', 'm'),
                'where'  => 'm.member_id=steam_profiles.st_member_id',
            ),
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
            'steam_filters_active'   => "st_steamid NOT IN ('','0')",
            'steam_filters_disabled' => 'st_restricted<>0',
            'steam_filters_error'    => "st_error<>''",
            'steam_filters_vacban'   => 'st_vac_status<>0',
        );

        Output::i()->title = Member::loggedIn()->language()->addToStack('menu__steam_steam_view_title');

        /* Display */
        Output::i()->output = Theme::i()->getTemplate('global', 'core')->block('title', (string)$this->table);
    }

    /* Overload and add additional parsers for added columns to $data */
    /**
     * @param array $data
     */
    protected function parsedData($data = array()): void
    {
        $this->table->parsers = array_merge(array(
            'photo'                       => function ($value, $row) {
                return $this->photo($value, $row);
            },
            'name'                        => function ($value, $row) {
                return $this->name($value, $row);
            },
            'st_steamid'                  => function ($value, $row) {
                return $this->steamID($value, $row);
            },
            'st_personaname'              => function ($value, $row) {
                return $this->personaname($value, $row);
            },
            'st_last_update'              => function ($value, $row) {
                return $this->lastUpdate($value, $row);
            },
            'st_lastlogoff'               => function ($value, $row) {
                return $this->lastLogoff($value, $row);
            },
            'st_restricted'               => function ($value, $row) {
                return $this->restricted($value, $row);
            },
            'st_communityvisibilitystate' => function ($value, $row) {
                return $this->communityVisibilityState($value, $row);
            },
            'st_vac_status'               => function ($value, $row) {
                return $this->vacStatus($value, $row);
            },
            'st_personastate'             => function ($value, $row) {
                return $this->personaState($value, $row);
            },
            'st_playtime_2weeks'          => function ($value, $row) {
                return $this->playtime($value, $row);
            },
            'st_error'                    => function ($value, $row) {
                return $this->error($value, $row);
            },
        ), $data);
    }

    /**
     * @param $value
     * @param $row
     * @return string
     */
    protected function photo($value, $row): string
    {
        if ($row['st_avatarmedium']) {
            return "<img src={$row['st_avatarmedium']} class='ipsUserPhoto_small'/>";
        }
        if ($row['st_restricted']) {
            return "<span class='ipsType_warning'><strong>{Member::loggedIn()->language()->addToStack('steam_disabled')}</strong></span>";
        }

        return '';

    }

    /**
     * @param $value
     * @param $row
     * @return string
     */
    protected function name($value, $row): string
    {
        $member = Member::constructFromData($row);

        return "<a href={$member->url()} target='_blank'>{$member->name}</a>";

    }

    /**
     * @param $value
     * @param $row
     * @return string
     */
    protected function steamID($value, $row): string
    {
        if ($value) {
            return "<a href={$row['st_profileurl']} target='_blank'>{$value}</a>";
        }

        return '';

    }

    /**
     * @param $value
     * @param $row
     * @return string
     */
    protected function personaname($value, $row): string
    {
        $member = Member::constructFromData($row);

        return "<a href={$member->acpUrl()} target='_blank'>{$value}</a>";
    }

    /**
     * @param $value
     * @param $row
     * @return string
     */
    protected function lastUpdate($value, $row): string
    {
        if ($value) {
            $ts = DateTime::ts($value);

            return $ts->relative('RELATIVE_FORMAT_SHORT');
        }

        return '';
    }

    /**
     * @param $value
     * @param $row
     * @return string
     */
    protected function lastLogoff($value, $row): string
    {
        if ($value) {
            $ts = DateTime::ts($value);
            if ($value < (time() - 86400)) {
                return $ts->strFormat('%x');
            }

            return $ts->relative('RELATIVE_FORMAT_SHORT');
        }

        return '';
    }

    /**
     * @param $value
     * @param $row
     * @return string
     */
    protected function restricted($value, $row): string
    {
        return $value;
//        if ($value == 0) {
//            return "<span class='ipsType_success'><strong>{Member::loggedIn()->language()->addToStack('steam_no')}</strong></span>";
//        }
//        if ($value < time()) {
//            return "<span class='ipsType_warning'><strong>{Member::loggedIn()->language()->addToStack('steam_yes')}</strong></span>";
//        }
    }

    /**
     * @param $value
     * @param $row
     * @return string
     */
    protected function communityVisibilityState($value, $row): string
    {
        if ($value == 3) {
            return "<span class='ipsType_success'><strong>{Member::loggedIn()->language()->addToStack('steam_public')}</strong></span>";
        }
        if ($value == 1 || $value == 2) {
            return "<span class='ipsType_warning'><strong>{Member::loggedIn()->language()->addToStack('steam_private')}</strong></span>";
        }

        return '';
    }

    /**
     * @param $value
     * @param $row
     * @return string
     */
    protected function vacStatus($value, $row): string
    {
        if ($value == 0) {
            return "<span class='ipsType_success'><strong>{Member::loggedIn()->language()->addToStack('steam_no')}</strong></span>";
        }
        if ($value < time()) {
            return "<span class='ipsType_warning'><strong>{Member::loggedIn()->language()->addToStack('steam_banned')}</strong></span>";
        }

        return '';
    }

    /**
     * @param $value
     * @param $row
     * @return string
     */
    protected function personaState($value, $row): string
    {
        if ($row['st_gameextrainfo'] || $row['st_gameid']) {
            return "<span class='ipsBadge ipsBadge_positive' data-ipsToolTip title='{$row['st_gameextrainfo']}'>{Member::loggedIn()->language()->addToStack('steam_ingame')}</span>";
        }
        if (!$value) {
            return "<span class='ipsBadge ipsBadge_neutral'>{Member::loggedIn()->language()->addToStack('steam_status_' . $value)}</span>";
        }

        return "<span class='ipsBadge' style='background: #86b5d9;'>{Member::loggedIn()->language()->addToStack('steam_status_' . $value)}</span>";

    }

    /**
     * @param $value
     * @param $row
     * @return float
     */
    protected function playtime($value, $row): float
    {
        if ($value) {
            return \round($value / 60, 1);
        }

        return 0;
    }

    /**
     * @param $value
     * @param $row
     * @return string
     */
    protected function error($value, $row): string
    {
        if ($value) {
            return "<span class='ipsType_warning' data-ipsToolTip title='{Member::loggedIn()->language()->addToStack($value)}'><strong>ERROR</strong></span>";
        }

        return '';
    }


}