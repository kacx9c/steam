//<?php

class steam_hook_steamHover extends _HOOK_CLASS_
{

    /* !Hook Data - DO NOT REMOVE */
public static function hookData() {
    if(\IPS\Settings::i()->steam_api_key && !\IPS\Settings::i()->steam_showonhover)
    {
        return array();
    }
 return array_merge_recursive( array (
  'hovercard' => 
  array (
    0 => 
    array (
      'selector' => 'div.cUserHovercard > div.ipsPadding.ipsFlex.ipsFlex-fd:column.ipsFlex-ai:center > div.ipsFlex.ipsFlex-ai:center.ipsFlex-jc:between.ipsMargin_top.ipsFlex-as:stretch',
      'type' => 'add_after',
      'content' => '{template="steamHover" group="global" app="steam" params="$member"}',
    ),
  ),
), parent::hookData() );
}
/* End Hook Data */
}