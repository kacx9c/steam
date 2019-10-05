<?php


namespace IPS\steam\modules\admin\steam;

use IPS\Helpers\Form;
use IPS\Http\Url;
use IPS\Output;
use IPS\Member;
use IPS\Dispatcher\Controller;
use IPS\Dispatcher;
use IPS\Settings;
use IPS\Helpers\MultipleRedirect;
use IPS\steam\Update;
use IPS\steam\Profile;
use IPS\Data\Store;

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!defined('\IPS\SUITE_UNIQUE_KEY')) {
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

/**
 * manage
 */
class _manage extends Controller
{
    /**
     * Execute
     * @return    void
     */
    public function execute()
    {
        Dispatcher::i()->checkAcpPermission('steam_manage');
        parent::execute();
    }

    /**
     * ...
     * @return    void
     */
    protected function manage()
    {

        $form = new Form('form', 'Start');
        $options = array(
            'update'  => 'steam_multi_update',
            'cleanup' => 'steam_multi_cleanup',
        );
        $defaults = array(
            'options'  => $options,
            'multiple' => false,
        );
        $form->add(new Form\Select('steam_admin_batch_type', 'update', false, $defaults));

        if ($values = $form->values()) {
            Output::i()->redirect(Url::internal('app=steam&module=steam&controller=manage')->setQueryString(array('do' => $values['steam_admin_batch_type'])));
        }

        Output::i()->title = Member::loggedIn()->language()->addToStack('menu__steam_steam_manage');
        Output::i()->output = $form;
    }

    public function update()
    {
        $perGo = Settings::i()->steam_batch_count;
        Output::i()->title = Member::loggedIn()->language()->addToStack('steam_title_update');
        Output::i()->output = new MultipleRedirect(Url::internal('app=steam&module=steam&controller=manage&do=update'),
            function ($doneSoFar) use ($perGo) {
                /* 	Need to search the database for new members not already in the cache.
                    Get steamID, insert into cache (if not already there), process as single's using existing functions.  steam_batch_count at a time.
                */
                try {
                    /**
                     * @var int $donesofar
                     */
                    /**
                     * @var int $perGo
                     */
                    $cache = array();
                    $steam = new Update;
                    $steam->load($doneSoFar);

                    foreach ($steam->members as $id => $m) {
                        $steamid = ($m->steamid ?: $steam->getSteamID($m));

                        $s = Profile::load($m->member_id);
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

                    if (isset(Store::i()->steamData)) {
                        /**
                         * @var array $cache
                         */
                        $cache = Store::i()->steamData;
                        if ($doneSoFar >= $cache['count']) {
                            return null;
                        }
                    }
                } catch (\Exception $e) {
                    Output::i()->redirect(Url::internal('app=steam&module=steam&controller=manage'),
                        $e->getMessage());
                }

                return array(
                    $doneSoFar,
                    Member::loggedIn()->language()->addToStack('steam_update_running'),
                    ($doneSoFar / $cache['count']) * 100,
                );
            }, function () {
                Output::i()->redirect(Url::internal('app=steam&module=steam&controller=manage'),
                    'steam_update_complete');
            });

    }

    public function cleanup()
    {
        $perGo = Settings::i()->steam_batch_count;
        Output::i()->title = Member::loggedIn()->language()->addToStack('steam_title_cleanup');
        Output::i()->output = new MultipleRedirect(Url::internal('app=steam&module=steam&controller=manage&do=cleanup'),
            function ($doneSoFar) use ($perGo) {
                try {
                    /**
                     * @var int $donesofar
                     */
                    /**
                     * @var int $perGo
                     */
                    $cache = array();
                    $steam = new Update;
                    $steam->cleanup($doneSoFar);

                    $doneSoFar += $perGo;

                    if (isset(Store::i()->steamData)) {
                        /**
                         * @var array $cache
                         */
                        $cache = Store::i()->steamData;
                        if ($doneSoFar >= $cache['count']) {
                            return null;
                        }
                    }
                } catch (\Exception $e) {
                    Output::i()->redirect(Url::internal('app=steam&module=steam&controller=manage'),
                        $e->getMessage());
                }

                return array(
                    $doneSoFar,
                    Member::loggedIn()->language()->addToStack('steam_cleanup_running'),
                    ($doneSoFar / $cache['count']) * 100,
                );
            }, function () {
                Output::i()->redirect(Url::internal('app=steam&module=steam&controller=manage'),
                    'steam_cleanup_complete');
            });

    }

    // Create new methods with the same name as the 'do' parameter which should execute it
}