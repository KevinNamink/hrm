<?php
// This file is part of the Huygens Remote Manager
// Copyright and license notice: see license.txt

require_once(dirname(__FILE__) . "/AbstractUserManager.inc.php");
require_once(dirname(__FILE__) . "/../Database.inc.php");
require_once(dirname(__FILE__) . "/../hrm_config.inc.php");
require_once(dirname(__FILE__) . "/../Mail.inc.php");

global $hrm_url;
global $email_sender;
global $email_admin;
global $image_host;
global $image_folder;
global $image_source;
global $userManager;

/*!
  \class   ExternalReadOnly
  \brief   Manages the HRM users relying on an external authentication mechanism.
           No user-related information can be modified using this Manager; for
           instance, user password or e-mail address cannot be modified from
           the HRM.
*/

class ExternalReadOnlyUserManager extends AbstractUserManager {

    /*!
    \brief Return false since the external, read only manager can not
           create or delete users.
    \return always true.
    */
    public static function canCreateUsers() { return false; }

    /*!
    \brief Return false since the external, read only manager can not
           modify users.
    \return always true.
     */
    public static function canModifyUsers() { return false; }

    /*!
    \brief Store (update) the user information.
    \param User $user User to store in the database.
    */
    public function storeUser(User $user) {

        // Make sure the user is in the database, otherwise add it
        if (! $this->existsInHRM($user)) {
            $randomPasswd = substr(md5(microtime()), rand(0, 26), 12);
            $this->createUser($user->name(), $randomPasswd,
                $user->emailAddress(), $user->userGroup(), 'a');
            return;
        }

        // Update the user information
        $db = new DatabaseConnection();
        $db->updateUserNoPassword($user->name(), $user->emailAddress(),
            $user->userGroup());

        // Update last access time
        $db->updateLastAccessDate($user->name());

    }


}
