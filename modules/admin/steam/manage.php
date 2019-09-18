<?php


namespace IPS\steam\modules\admin\steam;

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!defined('\IPS\SUITE_UNIQUE_KEY')) {
    header((isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

/**
 * manage
 */
class _manage extends \IPS\Dispatcher\Controller
{
    /**
     * Execute
     * @return    void
     */
    public function execute()
    {
        \IPS\Dispatcher::i()->checkAcpPermission('steam_manage');
        parent::execute();
    }

    /**
     * ...
     * @return    void
     */
    protected function manage()
    {

        $form = new \IPS\Helpers\Form('form', 'Start');
        $options = array(
            'update'  => 'steam_multi_update',
            'cleanup' => 'steam_multi_cleanup',
        );
        $defaults = array(
            'options'  => $options,
            'multiple' => false,
        );
        $form->add(new \IPS\Helpers\Form\Select('steam_admin_batch_type', 'update', false, $defaults));

        if ($values = $form->values()) {
            \IPS\Output::i()->redirect(\IPS\Http\Url::internal('app=steam&module=steam&controller=manage')->setQueryString(array('do' => $values['steam_admin_batch_type'])));
        }

        \IPS\Output::i()->title = \IPS\Member::loggedIn()->language()->addToStack('menu__steam_steam_manage');
        \IPS\Output::i()->output = $form;
    }

    public function update()
    {
        $perGo = \IPS\Settings::i()->steam_batch_count;
        \IPS\Output::i()->title = \IPS\Member::loggedIn()->language()->addToStack('steam_title_update');
        \IPS\Output::i()->output = new \IPS\Helpers\MultipleRedirect(\IPS\Http\Url::internal('app=steam&module=steam&controller=manage&do=update'),
            function ($doneSoFar) use ($perGo) {
                /* 	Need to search the database for new members not already in the cache.
                    Get steamID, insert into cache (if not already there), process as single's using existing functions.  steam_batch_count at a time.
                */
                try {
                    $steam = new \IPS\steam\Update;
                    $members = $steam->load($doneSoFar);

                    foreach ($members as $id => $m) {
                        $steamid = ($m->steamid ? $m->steamid : $steam->getSteamID($m));

                        $s = \IPS\steam\Profile::load($m->member_id);
                        if (!$s->steamid) {
                            $s->steamid = $steamid;
                            $s->member_id = $m->member_id;
                            $s->setDefaultValues();
                            $s->save();
                        }

                        $steam->updateProfile($m->member_id);
                        $steam->update($m->member_id);

                    }

                    $doneSoFar += $perGo;

                    if (isset(\IPS\Data\Store::i()->steamData)) {
                        $cache = \IPS\Data\Store::i()->steamData;
                        if ($doneSoFar >= $cache['count']) {
                            return null;
                        }
                    }
                } catch (\Exception $e) {
                    \IPS\Output::i()->redirect(\IPS\Http\Url::internal('app=steam&module=steam&controller=manage'),
                        $e->getMessage());
                }

                return array(
                    $doneSoFar,
                    \IPS\Member::loggedIn()->language()->addToStack('steam_update_running'),
                    ($doneSoFar / $cache['count']) * 100,
                );
            }, function () {
                \IPS\Output::i()->redirect(\IPS\Http\Url::internal('app=steam&module=steam&controller=manage'),
                    'steam_update_complete');
            });

    }

    public function cleanup()
    {
        $perGo = \IPS\Settings::i()->steam_batch_count;
        \IPS\Output::i()->title = \IPS\Member::loggedIn()->language()->addToStack('steam_title_cleanup');
        \IPS\Output::i()->output = new \IPS\Helpers\MultipleRedirect(\IPS\Http\Url::internal('app=steam&module=steam&controller=manage&do=cleanup'),
            function ($doneSoFar) use ($perGo) {
                try {
                    $steam = new \IPS\steam\Update;
                    $steam->cleanup($doneSoFar);

                    $doneSoFar += $perGo;

                    if (isset(\IPS\Data\Store::i()->steamData)) {
                        $cache = \IPS\Data\Store::i()->steamData;
                        if ($doneSoFar >= $cache['count']) {
                            return null;
                        }
                    }
                } catch (\Exception $e) {
                    \IPS\Output::i()->redirect(\IPS\Http\Url::internal('app=steam&module=steam&controller=manage'),
                        $e->getMessage());
                }

                return array(
                    $doneSoFar,
                    \IPS\Member::loggedIn()->language()->addToStack('steam_cleanup_running'),
                    ($doneSoFar / $cache['count']) * 100,
                );
            }, function () {
                \IPS\Output::i()->redirect(\IPS\Http\Url::internal('app=steam&module=steam&controller=manage'),
                    'steam_cleanup_complete');
            });

    }

    // Create new methods with the same name as the 'do' parameter which should execute it
}