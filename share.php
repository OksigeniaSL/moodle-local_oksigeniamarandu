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
 * To the channel: whoever teaches a course sends a photo of a piece of work of the class to the review of the channel of
 * Telegram of the site, with the group and without names.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniamarandu\local\classwork;

$component = 'local_oksigeniamarandu';
$course = get_course(required_param('id', PARAM_INT));
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/oksigeniamarandu:share', $context);

$url = new moodle_url('/local/oksigeniamarandu/share.php', ['id' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('classwork:title', $component));
$PAGE->set_heading(format_string($course->fullname));

$values = ['title' => '', 'group' => format_string($course->fullname), 'text' => ''];
$error = '';
$open = classwork::can_send($context);
if ($open && data_submitted() && confirm_sesskey()) {
    $values = [
        'title' => optional_param('title', '', PARAM_TEXT),
        'group' => optional_param('group', '', PARAM_TEXT),
        'text' => optional_param('text', '', PARAM_TEXT),
    ];
    // The photo comes as a file of the form; it is read once, and drawn again before it is kept.
    $upload = $_FILES['photo'] ?? null;
    if (!optional_param('confirm', 0, PARAM_BOOL)) {
        $error = get_string('classwork:confirmneeded', $component);
    } else if (!$upload || $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        $error = get_string('classwork:nophoto', $component);
    } else {
        try {
            classwork::send(
                $course,
                (string)file_get_contents($upload['tmp_name']),
                $values['title'],
                $values['group'],
                $values['text']
            );
            redirect(new moodle_url($url, ['sent' => 1]));
        } catch (moodle_exception $e) {
            $error = $e->getMessage();
        }
    }
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniamarandu/share', [
    'lead' => get_string('classwork:lead', $component),
    'sent' => optional_param('sent', 0, PARAM_BOOL) ? get_string('classwork:sent', $component) : '',
    'error' => $error,
    'closed' => $open ? '' : get_string('classwork:closed', $component),
    'formurl' => $url->out(false),
    'courseurl' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
    'sesskey' => sesskey(),
    'maxmb' => classwork::MAXBYTES / 1024 / 1024,
    'limits' => classwork::LIMITS,
] + $values);
echo $OUTPUT->footer();
