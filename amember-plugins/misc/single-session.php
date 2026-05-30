<?php
/**
 * @title Single Session Per User
 * @desc Enforces that only one device/browser can be logged in as a given
 *       user at a time. When a user successfully logs in, all OTHER active
 *       sessions for the same user are wiped from the am_session table.
 *       Any browser holding a cookie for one of those killed sessions
 *       gets logged out on its very next request.
 *
 *       Built for Scholar Genie. aMember 6.3.x.
 *
 * @license GPL
 * @version 1.0
 *
 * Behavior:
 *   - On AUTH_AFTER_LOGIN: DELETE other sessions for this user_id from
 *     am_session, keeping only the current PHP session id.
 *   - Admin sessions are not affected (admins frequently need multi-device
 *     access for administration).
 *   - Wraps everything in try/catch so a DB hiccup never breaks login.
 */
class Am_Plugin_SingleSession extends Am_Plugin
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '1.0';

    function init()
    {
        $this->getDi()->hook->add(Am_Event::AUTH_AFTER_LOGIN, [$this, 'onAfterLogin']);
    }

    public function onAfterLogin(Am_Event_AuthAfterLogin $event)
    {
        try {
            $user = $event->getUser();
            if (!$user || !$user->pk()) return;

            $userId = (int) $user->pk();
            if ($userId <= 0) return;

            $currentSid = $this->getDi()->session->getId();
            if (!$currentSid) return;

            $db = $this->getDi()->db;

            // Drop every other session that aMember has tagged as
            // belonging to this user. The user_id column is populated
            // automatically by aMember on login (we verified this on prod).
            $db->query(
                "DELETE FROM ?_session WHERE user_id = ? AND id != ?",
                $userId, $currentSid
            );

            // Belt-and-braces: also catch any rows where user_id is NULL
            // but the serialized data contains this user (rare, but happens
            // if PHP wrote the session data before aMember wrote the
            // foreign key column).
            $needle = '%"user_id";i:' . $userId . ';%';
            $db->query(
                "DELETE FROM ?_session WHERE id != ? AND user_id IS NULL AND data LIKE ?",
                $currentSid, $needle
            );

            // OPTIONAL: also kill "remember me" cookies for this user
            // so they can't auto-log-in on the kicked browser. The
            // am_session_cookie table tracks long-lived auth cookies.
            // Uncomment if you want full kick (recommended for high-security):
            //
            // $db->query("DELETE FROM ?_session_cookie WHERE user_id = ?", $userId);
        } catch (Exception $e) {
            // Never block login because of this plugin.
            try {
                Am_Di::getInstance()->errorLogTable->logError(
                    'single-session', $e->getMessage()
                );
            } catch (Exception $e2) { /* truly nothing we can do */ }
        }
    }
}
