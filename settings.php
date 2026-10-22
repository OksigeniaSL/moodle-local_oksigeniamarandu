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
 * Administration: the WhatsApp account of the site and the test message.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_category(
        'local_oksigeniamarandu',
        new lang_string('pluginname', 'local_oksigeniamarandu')
    ));

    $settings = new admin_settingpage(
        'local_oksigeniamarandu_account',
        new lang_string('settings:account', 'local_oksigeniamarandu')
    );
    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_heading(
            'local_oksigeniamarandu/accountintro',
            '',
            new lang_string('settings:account_intro', 'local_oksigeniamarandu')
        ));
        $settings->add(new admin_setting_configtext(
            'local_oksigeniamarandu/phonenumberid',
            new lang_string('settings:phonenumberid', 'local_oksigeniamarandu'),
            new lang_string('settings:phonenumberid_desc', 'local_oksigeniamarandu'),
            '',
            '/^[0-9]*$/'
        ));
        $settings->add(new admin_setting_configtext(
            'local_oksigeniamarandu/wabaid',
            new lang_string('settings:wabaid', 'local_oksigeniamarandu'),
            new lang_string('settings:wabaid_desc', 'local_oksigeniamarandu'),
            '',
            '/^[0-9]*$/'
        ));
        $settings->add(new admin_setting_encryptedpassword(
            'local_oksigeniamarandu/accesstoken',
            new lang_string('settings:accesstoken', 'local_oksigeniamarandu'),
            new lang_string('settings:accesstoken_desc', 'local_oksigeniamarandu')
        ));
        $settings->add(new admin_setting_configtext(
            'local_oksigeniamarandu/graphversion',
            new lang_string('settings:graphversion', 'local_oksigeniamarandu'),
            new lang_string('settings:graphversion_desc', 'local_oksigeniamarandu'),
            \local_oksigeniamarandu\local\cloud_api::DEFAULT_GRAPH_VERSION,
            '/^v[0-9]+\.[0-9]+$/'
        ));
    }
    $ADMIN->add('local_oksigeniamarandu', $settings);

    $ADMIN->add('local_oksigeniamarandu', new admin_externalpage(
        'local_oksigeniamarandu_testmessage',
        new lang_string('testmessage', 'local_oksigeniamarandu'),
        new moodle_url('/local/oksigeniamarandu/testmessage.php')
    ));
}
