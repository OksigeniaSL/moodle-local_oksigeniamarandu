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
 * Sends a test message to the phone of the administrator, to see that the account of the site is connected.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_oksigeniamarandu\form\test_message_form;
use local_oksigeniamarandu\local\api_exception;
use local_oksigeniamarandu\local\cloud_api;
use local_oksigeniamarandu\local\phone;

admin_externalpage_setup('local_oksigeniamarandu_testmessage');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('testmessage', 'local_oksigeniamarandu'));

if (!cloud_api::is_configured()) {
    echo $OUTPUT->notification(get_string('testmessage:notconfigured', 'local_oksigeniamarandu'), 'info');
    $settings = new moodle_url('/admin/settings.php', ['section' => 'local_oksigeniamarandu_account']);
    echo $OUTPUT->single_button(
        $settings,
        get_string('testmessage:gotosettings', 'local_oksigeniamarandu'),
        'get',
        ['type' => \single_button::BUTTON_PRIMARY]
    );
    echo $OUTPUT->footer();
    die();
}

$form = new test_message_form();
if ($data = $form->get_data()) {
    try {
        $id = cloud_api::from_config()->send_template(phone::normalise($data->phone), $data->template, $data->language);
        echo $OUTPUT->notification(get_string('testmessage:sent', 'local_oksigeniamarandu', s($id)), 'success');
    } catch (api_exception $e) {
        echo $OUTPUT->notification($e->getMessage(), 'error');
    }
}

echo html_writer::tag('p', get_string('testmessage:intro', 'local_oksigeniamarandu'));
$form->display();
echo $OUTPUT->footer();
