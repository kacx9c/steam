//<?php

abstract class steam_hook_steamid extends _HOOK_CLASS_
{


    /**
     * @brief    [CustomField] Additional Field Classes
     */
    public static $additionalFieldTypes = array();

    /**
     * @brief    [CustomField] Additional Field Toggles
     */
    public static $additionalFieldToggles = array();


    /**
     * [Node] Add/Edit Form
     * @param \IPS\Helpers\Form $form The form
     * @return    void
     */
    public function form(&$form)
    {

        try {
            static::$additionalFieldTypes['Steamid'] = 'pf_type_steamid';
            static::$additionalFieldToggles['Steamid'] = array(
                'pf_not_null',
                'pf_max_input',
                'pf_input_format',
                'pf_search_type',
                "{$form->id}_header_pfield_displayoptions",
            );
            parent::form($form);

        } catch (\RuntimeException $e) {
            if (method_exists(get_parent_class(), __FUNCTION__)) {
                return \call_user_func_array('parent::' . __FUNCTION__, \func_get_args());
            }
            throw $e;
        }

    }

    /**
     * @param $newType
     * @return bool|mixed|void
     */
    protected function canKeepValueOnChange($newType)
    {
        try {
            if ($newType == 'Steamid') {
                return \in_array($this->type, array(
                    'Email',
                    'Password',
                    'Text',
                    'Tel',
                    'Url',
                    'Editor',
                    'TextArea',
                    'Code',
                    'Codemirror',
                    'Steamid',
                ));
            }
            if ($this->type == 'Steamid') {
                return \in_array($newType, array(
                    'Email',
                    'Password',
                    'Text',
                    'Tel',
                    'Url',
                    'Editor',
                    'TextArea',
                    'Code',
                    'Codemirror',
                    'Steamid',
                ));
            }

            return \call_user_func_array('parent::canKeepValueOnChange', \func_get_args());
        } catch (\RuntimeException $e) {
            if (method_exists(get_parent_class(), __FUNCTION__)) {
                return \call_user_func_array('parent::' . __FUNCTION__, \func_get_args());
            }
            throw $e;
        }
    }

    /**
     * @param null $value
     * @param null $customValidationCode
     * @return mixed|void
     */
    public function buildHelper($value = null, $customValidationCode = null)
    {
        try {
            if ($this->type === 'Steamid') {
                $this->type = 'Text';
            }

            //return parent::buildHelper( $value, $customValidationCode);
            return \call_user_func_array('parent::buildHelper', \func_get_args());
        } catch (\RuntimeException $e) {
            if (method_exists(get_parent_class(), __FUNCTION__)) {
                return \call_user_func_array('parent::' . __FUNCTION__, \func_get_args());
            }
            throw $e;
        }
    }
}