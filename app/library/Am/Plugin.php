<?php

/**
 * Base class for plugin
 * @package Am_Plugin
 */
class Am_Plugin extends Am_Plugin_Base
{
    /**
     * Function will be called when user access amember/payment/pluginid/xxx url directly
     * This can be used for IPN actions, or for displaying confirmation page
     * @see getPluginUrl()
     * @throws Am_Exception_NotImplemented
     */
    function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        throw new Am_Exception_NotImplemented("'direct' action is not implemented in " . get_class($this));
    }

    static function activate($id, $pluginType)
    {
        if ($xml = static::getDbXml()) {
            self::syncDb($xml, Am_Di::getInstance()->db);
        }
        if ($xml = static::getEtXml($id)) {
            self::syncEt($xml, Am_Di::getInstance()->emailTemplateTable);
        }
    }

    function onDbSync(Am_Event $e)
    {
        if ($xml = static::getDbXml()) {
            $e->getDbsync()->parseXml($xml);
        }
    }

    function onEtSync(Am_Event $e)
    {
        if ($xml = static::getEtXml($this->getId())) {
            $e->addReturn($xml, 'Plugin::' . $this->getId());
        }
    }

    static final function syncDb($xml, $db)
    {
        $origDb = new Am_DbSync();
        $origDb->parseTables($db);

        $desiredDb = new Am_DbSync();
        $desiredDb->parseXml($xml);

        $diff = $desiredDb->diff($origDb);
        if ($diff->getSql($db->getPrefix())) {
            $diff->apply($db);
        }
    }

    static final function syncEt($xml, $t)
    {
        $t->importXml($xml);
    }

    static function getDbXml()
    {
        return null;
    }

    /**
     * Plugin ID will be passed as parameter to this method.
     * It is not included in method signature to avoid issues with custom plugins.
     * access through func_get_args call
     * @param $id
     * @return null
     */
    static function getEtXml()
    {
        return null;
    }

    /**
     * Get user data using plugin getId(), to support constants for duplicated plugins
     * @return string
     */
    public function getPluginsUserData(User $user, string $key)
    {
        return $user->data()->get($key . '-' . $this->getId()) ?: $user->data()->get($key);
    }

    /**
     * Set user data using plugin getId(), to support constants for duplicated plugins
     * @param string $value
     */
    public function setPluginsUserData(User $user, string $key, $value)
    {
        $user->data()->set($key . '-' . $this->getId(), $value)->update();
    }
}