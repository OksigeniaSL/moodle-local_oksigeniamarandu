<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Steps of local_oksigeniamarandu for Behat.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Steps of local_oksigeniamarandu for Behat.
 */
class behat_local_oksigeniamarandu extends behat_base {
    /**
     * Opens the page where a family joins, with a new code for a user.
     *
     * @Given /^I open the WhatsApp sign-up page of "(?P<username_string>(?:[^"]|\\")*)"$/
     * @param string $username The user the code stands for.
     */
    public function i_open_the_whatsapp_signup_page_of(string $username): void {
        global $DB;
        $userid = (int)$DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $code = \local_oksigeniamarandu\api::invite('core', 0, $userid);
        $this->execute('behat_general::i_visit', [\local_oksigeniamarandu\api::signup_url($code)->out_as_local_url(false)]);
    }

    /**
     * Opens the page where a family joins with a code that does not exist.
     *
     * @Given /^I open the WhatsApp sign-up page with a code that does not exist$/
     */
    public function i_open_the_whatsapp_signup_page_with_a_code_that_does_not_exist(): void {
        $this->execute('behat_general::i_visit', ['/local/oksigeniamarandu/alta.php?code=ZZZZ2222']);
    }

    /**
     * A number joins for a user, as if it had written the opt-in word with the code of the user.
     *
     * @Given /^the number "(?P<phone_string>[0-9]+)" has joined on WhatsApp for "(?P<username_string>(?:[^"]|\\")*)"$/
     * @param string $phone Digits with the country code, made up.
     * @param string $username The user.
     */
    public function the_number_has_joined_on_whatsapp_for(string $phone, string $username): void {
        global $DB;
        $userid = (int)$DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $code = \local_oksigeniamarandu\api::invite('core', 0, $userid);
        \local_oksigeniamarandu\local\consent::optin_from_message($phone, $code, 'wamid.BEHAT');
    }
}
