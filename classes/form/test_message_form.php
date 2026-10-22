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

namespace local_oksigeniamarandu\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_oksigeniamarandu\local\phone;

/**
 * The form that sends a test message to the phone of the administrator.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class test_message_form extends \moodleform {
    /**
     * The phone, and the template with its language (the welcome template of Meta by default).
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('text', 'phone', get_string('testmessage:phone', 'local_oksigeniamarandu'));
        $mform->setType('phone', PARAM_TEXT);
        $mform->addRule('phone', null, 'required', null, 'client');
        $mform->addHelpButton('phone', 'testmessage:phone', 'local_oksigeniamarandu');

        $mform->addElement('text', 'template', get_string('testmessage:template', 'local_oksigeniamarandu'));
        $mform->setType('template', PARAM_ALPHANUMEXT);
        $mform->setDefault('template', 'hello_world');
        $mform->addHelpButton('template', 'testmessage:template', 'local_oksigeniamarandu');

        $mform->addElement('text', 'language', get_string('testmessage:language', 'local_oksigeniamarandu'));
        $mform->setType('language', PARAM_ALPHANUMEXT);
        $mform->setDefault('language', 'en_US');
        $mform->addHelpButton('language', 'testmessage:language', 'local_oksigeniamarandu');

        $this->add_action_buttons(false, get_string('testmessage:send', 'local_oksigeniamarandu'));
    }

    /**
     * Checks the number, the template name and the language code before anything leaves the site.
     *
     * @param array $data Submitted values.
     * @param array $files Submitted files.
     * @return array Errors by field.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (phone::normalise($data['phone'] ?? '') === null) {
            $errors['phone'] = get_string('error:phone', 'local_oksigeniamarandu');
        }
        if (!preg_match('/^[a-z0-9_]{1,512}$/', $data['template'] ?? '')) {
            $errors['template'] = get_string('error:templatename', 'local_oksigeniamarandu');
        }
        if (!preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $data['language'] ?? '')) {
            $errors['language'] = get_string('error:language', 'local_oksigeniamarandu');
        }
        return $errors;
    }
}
