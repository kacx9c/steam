<?php


namespace IPS\steam\modules\admin\steam;

/* To prevent PHP errors (extending class does not exist) revealing path */
if (!defined('\IPS\SUITE_UNIQUE_KEY')) {
    header((isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0') . ' 403 Forbidden');
    exit;
}

/**
 * forums
 */
class _settings extends \IPS\Dispatcher\Controller
{

    /**
     * Execute
     * @return    void
     */
    public function execute()
    {
        \IPS\Dispatcher::i()->checkAcpPermission('steam_settings');
        parent::execute();
    }

    /**
     * Manage Settings
     * @return    void
     */
    protected function manage()
    {

        $form = new \IPS\Helpers\Form;
        $form->addHeader('steam__gen_settings');
        $form->add(new \IPS\Helpers\Form\Text('steam_api_key', \IPS\Settings::i()->steam_api_key, false, array(), null,
            null, null, 'steam_api_key'));
        $form->add(new \IPS\Helpers\Form\Text('steam_profile_count', \IPS\Settings::i()->steam_profile_count, false,
            array(), null, null, null, 'steam_profile_count'));
        $form->add(new \IPS\Helpers\Form\Text('steam_batch_count', \IPS\Settings::i()->steam_batch_count, false,
            array(), null, null, null, 'steam_batch_count'));

        $form->add(new \IPS\Helpers\Form\YesNo('steam_showintopic', \IPS\Settings::i()->steam_showintopic, false,
            array(), null, null, null, 'steam_showintopic'));
        $form->add(new \IPS\Helpers\Form\YesNo('steam_showonhover', \IPS\Settings::i()->steam_showonhover, false,
            array(), null, null, null, 'steam_showonhover'));

        $form->add(new \IPS\Helpers\Form\YesNo('steam_diagnostics', \IPS\Settings::i()->steam_diagnostics, false,
            array(), null, null, null, 'steam_diagnostics'));

        $form->addHeader('steam__mem_profiles');
        $form->add(new \IPS\Helpers\Form\YesNo('steam_showinprofile', \IPS\Settings::i()->steam_showinprofile, false,
            array(
                'togglesOn' => array(
                    'steam_default_tab',
                    'steam_get_owned',
                    'steam_link_stats',
                    'steam_can_clear',
                    'steam_instructions',
                ),
            ), null, null, null, 'steam_showinprofile'));

        $form->add(new \IPS\Helpers\Form\YesNo('steam_default_tab', \IPS\Settings::i()->steam_default_tab, false,
            array(), null, null, null, 'steam_default_tab'));


        $options = array(
            'one' => 'steam_profile_style_image',
            'two' => 'steam_profile_style_list',
        );
        $defaults = array(
            'options'  => $options,
            'multiple' => false,
        );

        $form->add(new \IPS\Helpers\Form\YesNo('steam_get_owned', \IPS\Settings::i()->steam_get_owned, false,
            array('togglesOn' => array('steam_profile_style')), null, null, null, 'steam_get_owned'));
        $form->add(new \IPS\Helpers\Form\Select('steam_profile_style', \IPS\Settings::i()->steam_profile_style, false,
            $defaults, null, null, null, 'steam_profile_style'));
        $form->add(new \IPS\Helpers\Form\YesNo('steam_link_stats', \IPS\Settings::i()->steam_link_stats, false, array(),
            null, null, null, 'steam_link_stats'));
        $form->add(new \IPS\Helpers\Form\YesNo('steam_can_clear', \IPS\Settings::i()->steam_can_clear, false, array(),
            null, null, null, 'steam_can_clear'));

        $form->add(new \IPS\Helpers\Form\Editor('steam_instructions', \IPS\Settings::i()->steam_instructions, false,
            array(
                'app'         => 'core',
                'key'         => 'Admin',
                'autoSaveKey' => "steam_instructions",
                'attachIds'   => array('steam_inst_'),
            ), null, null, null, 'steam_instructions'));

        $form->addHeader('steam__mem_groups');

        $form->add(new \IPS\Helpers\Form\Stack('steam_comm_groups', json_decode(\IPS\Settings::i()->steam_comm_groups),
            false, array(), null, null, null, 'steam_comm_group'));


        if ($values = $form->values()) {
            // If groups array contains a URL to a Steam group, regex and pull out the group data
            $groups = $values['steam_comm_groups'];
            foreach ($groups as $i => $group) {
                if (filter_var($group, FILTER_VALIDATE_URL)) {
                    // Take the last part of the URL that is the group and override this entry.
                    $pieces = explode('/', $group);

                    $temp = array_pop($pieces);
                    if ($temp) {
                        $groups[$i] = $temp;
                    } else {
                        $groups[$i] = array_pop($pieces);
                    }
                }
            }
            try {
                // Add any new entries to the database.
                \IPS\steam\Update\Groups::sync($groups);
            } catch (\Exception $e) {
                // Catch BAD_XML if the sync fails, because not doing so will cause settings to not save
                // Do nothing for now, error handling to come later with update rewrite.
            }

            $values['steam_comm_groups'] = json_encode($groups);
            $form->saveAsSettings($values);
        }

        \IPS\Output::i()->title = \IPS\Member::loggedIn()->language()->addToStack('menu__steam_steam_settings_title');
        \IPS\Output::i()->output = $form;

//		\IPS\Output::i()->output 	= \IPS\Theme::i()->getTemplate( 'global', 'core' )->tabs( $tabs, $activeTab, $activeTabContents, \IPS\Http\Url::internal( "app=forums&module=forums&controller=settings" ) );
    }

}