//<?php

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	exit;
}

/**
 * @mixin \IPS\Theme\class_forums_front_topics
 */
class steam_hook_topic extends _HOOK_CLASS_
{

/* !Hook Data - DO NOT REMOVE */
public static function hookData() {
    if(\IPS\Settings::i()->steam_api_key && !\IPS\Settings::i()->steam_showintopic)
    {
        return array();
    }
 return array_merge_recursive( array (
  'postContainer' => 
  array (
    0 => 
    array (
      'selector' => 'article > aside.ipsComment_author.cAuthorPane.ipsColumn.ipsColumn_medium.ipsResponsive_hidePhone > ul.cAuthorPane_info.ipsList_reset',
      'type' => 'add_inside_end',
      'content' => '{template="steamTopic" group="global" app="steam" params="$comment->pid, $comment->author()"}',
    ),
  ),
), parent::hookData() );
}
/* End Hook Data */


}
