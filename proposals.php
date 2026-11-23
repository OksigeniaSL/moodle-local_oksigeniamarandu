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
 * The proposals of improvement of the tools of the site, for the people chosen to receive them and whoever has the
 * capability: each one with the picture of the screen and its marks, who, when and where, and a way to close it with
 * what was done.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniamarandu\local\proposals;

require_login();
$component = 'local_oksigeniamarandu';
if (!proposals::can_read((int)$USER->id)) {
    throw new required_capability_exception(context_system::instance(), 'local/oksigeniamarandu:proposals', 'nopermissions', '');
}
$all = optional_param('all', 0, PARAM_BOOL);
$baseurl = new moodle_url('/local/oksigeniamarandu/proposals.php', $all ? ['all' => 1] : []);
if (optional_param('action', '', PARAM_ALPHA) === 'done' && confirm_sesskey()) {
    $id = required_param('id', PARAM_INT);
    proposals::done($id, optional_param('note', '', PARAM_TEXT), (int)$USER->id);
    redirect(new moodle_url($baseurl, [], 'p' . $id));
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url($baseurl);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('proposals:title', $component));
$PAGE->set_heading(get_string('proposals:title', $component));

$items = [];
foreach (proposals::list($all ? '' : 'new') as $record) {
    $data = proposals::export($record);
    $image = proposals::image_url((int)$record->id);
    $done = $record->status === 'done';
    $items[] = [
        'id' => (int)$record->id,
        'tool' => $data['toolname'] . ($data['release'] !== '' ? ' ' . $data['release'] : ''),
        'screen' => $data['screen'],
        'from' => get_string('proposals:from', $component, (object)['name' => $data['who'],
            'date' => userdate((int)$record->timecreated, get_string('strftimedaydatetime', 'langconfig'))]),
        'text' => $data['text'],
        'summary' => $data['summary'],
        'guessed' => $data['guessedtool'] !== '' && $data['guessedtool'] !== $data['tool']
            ? get_string('proposals:guessed', $component, proposals::tool_name($data['guessedtool'])) : '',
        'url' => $data['url'],
        'image' => $image ? $image->out(false) : '',
        'done' => $done,
        'donenote' => $done ? get_string('proposals:donenote', $component, (object)[
            'date' => userdate((int)$record->timedone, get_string('strftimedate', 'langconfig')),
            'note' => (string)$record->note,
        ]) : '',
    ];
}

// «Propose an improvement» of this page, the piece the tools of the site share.
$PAGE->add_header_action(\local_oksigeniamarandu\api::proposal_button(
    'local_oksigeniamarandu',
    get_string('proposals:title', 'local_oksigeniamarandu')
));
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniamarandu/proposals', [
    'lead' => get_string('proposals:lead', $component),
    'items' => $items,
    'none' => !$items,
    'formurl' => $baseurl->out(false),
    'sesskey' => sesskey(),
    'all' => $all,
    'allurl' => (new moodle_url('/local/oksigeniamarandu/proposals.php', ['all' => 1]))->out(false),
]);
echo $OUTPUT->footer();
