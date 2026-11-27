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
 * What the organisation knows and says, for «Propose an answer» in the conversations of the team: one text for the
 * whole organisation and one for each department that wants its own. Each box keeps itself when one leaves it.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_oksigeniamarandu\local\chat\directory;
use local_oksigeniamarandu\local\chat\draft;
use local_oksigeniamarandu\local\panel;

admin_externalpage_setup('local_oksigeniamarandu_knowledge', '', null, '', ['nosearch' => true]);

$component = 'local_oksigeniamarandu';
$baseurl = new moodle_url('/local/oksigeniamarandu/knowledge.php');
try {
    $teams = directory::teams();
} catch (\Throwable $e) {
    // Without the conversations connected, only the whole organisation.
    $teams = [];
}
if (optional_param('action', '', PARAM_ALPHA) === 'save' && confirm_sesskey()) {
    $part = optional_param('part', 0, PARAM_INT);
    $text = optional_param('text', '', PARAM_RAW);
    if ($part > 0 && isset($teams[$part])) {
        draft::set_team_knowledge($part, $text);
    } else {
        draft::set_knowledge($text);
        $part = 0;
    }
    redirect(new moodle_url($baseurl, ['saved' => $part], 'mrd-k' . $part));
}

$saved = optional_param('saved', -1, PARAM_INT);
$ofteams = draft::team_knowledge();
$parts = [['id' => 0, 'name' => get_string('knowledge:all', $component), 'text' => draft::knowledge(),
    'help' => get_string('knowledge:allhelp', $component)]];
foreach ($teams as $teamid => $team) {
    $parts[] = ['id' => (int)$teamid, 'name' => (string)$team['name'], 'text' => $ofteams[$teamid] ?? '',
        'help' => get_string('knowledge:teamhelp', $component, (string)$team['name'])];
}
// Thousands as the language writes them: 20.000, 20,000.
$number = fn(int $n): string => number_format($n, 0, '', get_string('thousandssep', 'langconfig'));
foreach ($parts as $i => $part) {
    $parts[$i] += ['saved' => $saved === $part['id'], 'count' => get_string(
        'knowledge:count',
        $component,
        (object)['n' => $number(\core_text::strlen($part['text'])), 'max' => $number(draft::MAX_KNOWLEDGE)]
    )];
}

$PAGE->set_heading(get_string('knowledge:title', $component));
$PAGE->set_secondary_navigation(false);
$PAGE->blocks->show_only_fake_blocks(true);
$PAGE->add_header_action($OUTPUT->render_from_template(
    'local_oksigeniamarandu/panel_links',
    ['links' => panel::links('knowledge'), 'extra' => \local_oksigeniamarandu\api::proposal_button(
        'local_oksigeniamarandu',
        get_string('knowledge:title', 'local_oksigeniamarandu')
    )]
));

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniamarandu/knowledge', [
    'lead' => get_string('knowledge:lead', $component),
    'state' => get_string(draft::available() ? 'knowledge:on' : 'knowledge:off', $component),
    'on' => draft::available(),
    'formurl' => $baseurl->out(false),
    'sesskey' => sesskey(),
    'max' => draft::MAX_KNOWLEDGE,
    'parts' => $parts,
]);
echo $OUTPUT->footer();
