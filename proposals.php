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
 * «Sugerencias», the page of whoever receives them (the people chosen and whoever has the capability): what does not
 * work first, then the ideas, each with its picture and its marks, who, when and where; and three ways out, saved as
 * they are made: done in a version, not and why, or to Oksigenia. «Deshacer» while the person has not heard it.
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
$tab = optional_param('tab', 'new', PARAM_ALPHA);
$tab = in_array($tab, ['new', 'away', 'done'], true) ? $tab : 'new';
$baseurl = new moodle_url('/local/oksigeniamarandu/proposals.php', $tab === 'new' ? [] : ['tab' => $tab]);

// A gesture on a card: saved at once; by the page's script, the card comes back as it is now.
$action = optional_param('action', '', PARAM_ALPHA);
if ($action !== '' && confirm_sesskey()) {
    $id = required_param('id', PARAM_INT);
    $ok = false;
    if ($action === 'done') {
        $ok = proposals::close($id, 'done', '', optional_param('release', '', PARAM_TEXT), (int)$USER->id);
    } else if ($action === 'no') {
        $reason = trim(optional_param('reason', '', PARAM_TEXT));
        $ok = $reason !== '' && proposals::close($id, 'no', $reason, '', (int)$USER->id);
    } else if ($action === 'forward') {
        $ok = proposals::forward($id, (int)$USER->id);
    } else if ($action === 'undo') {
        $ok = proposals::undo($id);
    }
    if (optional_param('ajax', 0, PARAM_BOOL)) {
        $record = $DB->get_record(proposals::TABLE, ['id' => $id], '*', MUST_EXIST);
        $PAGE->set_context(context_system::instance());
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'late' => !$ok && $action === 'undo' ? get_string('proposals:late', $component) : '',
            'html' => $OUTPUT->render_from_template('local_oksigeniamarandu/proposal_card', proposals::card($record, time()))]);
        die();
    }
    redirect(new moodle_url($baseurl, [], 'p' . $id));
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url($baseurl);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('proposals:title', $component));
$PAGE->set_heading(get_string('proposals:title', $component));

$now = time();
$counts = [];
foreach (['new', 'away', 'done'] as $status) {
    $counts[$status] = $DB->count_records(proposals::TABLE, ['status' => $status]);
}
$tabs = [];
foreach (['new', 'away', 'done'] as $status) {
    $tabs[] = ['label' => get_string('proposals:tab:' . $status, $component), 'count' => $counts[$status],
        'url' => (new moodle_url('/local/oksigeniamarandu/proposals.php', $status === 'new' ? [] : ['tab' => $status]))->out(false),
        'current' => $status === $tab];
}
$records = $DB->get_records(
    proposals::TABLE,
    ['status' => $tab],
    $tab === 'done' ? 'timedone DESC, id DESC' : 'timecreated DESC, id DESC',
    '*',
    0,
    $tab === 'done' ? 100 : 0
);
$groups = [];
foreach (['issue' => 'fallo', 'idea' => 'idea'] as $kind => $class) {
    $cards = [];
    foreach ($records as $record) {
        if (($record->kind === 'issue') === ($kind === 'issue')) {
            $cards[] = proposals::card($record, $now);
        }
    }
    if ($cards) {
        $groups[] = ['kind' => $class, 'isissue' => $kind === 'issue',
            'label' => get_string('proposals:' . ($kind === 'issue' ? 'issues' : 'ideas'), $component),
            'count' => count($cards), 'cards' => $cards];
    }
}

// The piece on this page too, and the script of the gestures.
$PAGE->add_header_action(\local_oksigeniamarandu\api::proposal_button(
    'local_oksigeniamarandu',
    get_string('proposals:title', 'local_oksigeniamarandu')
));
$PAGE->requires->js(new moodle_url('/local/oksigeniamarandu/proposal/recibe.js', ['v' => get_config($component, 'version')]));
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniamarandu/proposals', [
    'lead' => get_string('proposals:lead', $component),
    'tabs' => $tabs,
    'groups' => $groups,
    'none' => $groups ? '' : get_string('proposals:none:' . $tab, $component),
]);
echo $OUTPUT->footer();
