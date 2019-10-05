//<?php

class steam_hook_steamHover extends _HOOK_CLASS_
{

    /* !Hook Data - DO NOT REMOVE */
    public static function hookData()
    {

        if (\IPS\Settings::i()->steam_api_key && \IPS\Settings::i()->steam_showonhover) {
            $return = '{template="steamHover" group="global" app="steam" params="$member"}';
        } else {
            $return = '';
        }

        return array_merge_recursive(array(
            'hovercard' =>
                array(
                    0 =>
                        array(
                            'selector' => 'div.ipsPad_half.cUserHovercard > div.cUserHovercard_data > ul.ipsDataList.ipsDataList_reducedSpacing > li.ipsDataItem:nth-child(3)',
                            'type'     => 'add_after',
                            'content'  => $return,
                        ),
                ),
        ), parent::hookData());
    }
    /* End Hook Data */
}