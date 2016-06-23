<?php
/**
 * AuthenticatorFactory
 *
 * @package hrm
 *
 * This file is part of the Huygens Remote Manager
 * Copyright and license notice: see license.txt
 */

namespace hrm\user\auth;

// Include the HRM configuration files.
require_once dirname(__FILE__) . '/../../bootstrap.php';

/**
 * Returns the Authenticator object to be used to manage the user based on the
 * value of $authenticateAgainst from the configuration files.
 *
 * @package hrm
 */
class AuthenticatorFactory {

    /**
     * Returns the correct authenticator object depending on the value of the
     * $authenticateAgainst variable in the configuration files and whether
     * or not the user is the administrator.
     * @param bool $isAdmin (optional, default is False). True if the user is the
     * administrator, False otherwise.
     * @return ActiveDirectoryAuthenticator|InternalAdminAuthenticator|InternalAuthenticator|LDAPAuthenticator
     * @throws \Exception
     */
    public static function getAuthenticator($isAdmin = false) {

        global $authenticateAgainst;

        // If the user is the Admin, we currently must return
        // an InternalAuthenticator
        if ($isAdmin) {
            return new InternalAdminAuthenticator();
        }

        // Initialize the authenticator
        switch ($authenticateAgainst) {

            case "MYSQL":

                return new InternalAuthenticator();

            case "LDAP":

                return new LDAPAuthenticator();

            case "ACTIVE_DIR":

                // Initialize the ActiveDirectoryAuthenticator object
                return new ActiveDirectoryAuthenticator();

            default:

                // Unknown authentication method.
                throw new \Exception("Bad value $authenticateAgainst.");
        }

    }
};