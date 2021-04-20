//<?php

class steam_hook_steamTopic extends _HOOK_CLASS_
{

    /* !Hook Data - DO NOT REMOVE */
    public static function hookData(): array
    {
        if (\IPS\Settings::i()->steam_api_key && \IPS\Settings::i()->steam_showintopic) {
            $return = '{template="steamTopic" group="global" app="steam" params="$comment->steam, $comment->pid, $comment->author()"}';
        } else {
            $return = '';
        }

        return array_merge_recursive(array(
            'postContainer' =>
                array(
                    0 =>
                        array(
                            'selector' => 'article > aside.ipsComment_author.cAuthorPane.ipsColumn.ipsColumn_medium.ipsResponsive_hidePhone > ul.cAuthorPane_info.ipsList_reset',
                            'type'     => 'add_after',
                            'content'  => $return,
                        ),
                ),
        ), parent::hookData());
    }
    /* End Hook Data */

}