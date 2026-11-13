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
 * The quiet hours and the limits, for every channel: no notice at night or on the days the site says, a few a day to
 * each family at most, what is urgent enough to go out in the quiet hours too and, with WhatsApp, the money of a month.
 * The same sentences as the last step of the wizard of WhatsApp, for a site with only Telegram too.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_oksigeniamarandu\local\limits;
use local_oksigeniamarandu\local\panel;

admin_externalpage_setup('local_oksigeniamarandu_limits', '', null, '', ['nosearch' => true]);

$component = 'local_oksigeniamarandu';
$baseurl = new moodle_url('/local/oksigeniamarandu/limits.php');
$money = \local_oksigeniamarandu\api::channels()['whatsapp']['ready'];
$errors = [];
if (optional_param('action', '', PARAM_ALPHA) === 'limits' && confirm_sesskey()) {
    $errors = limits::save($money);
    if (!$errors) {
        // Back to the card that was touched, with its «Saved».
        $part = optional_param('part', '', PARAM_ALPHA);
        $part = in_array($part, limits::PARTS, true) ? $part : '';
        redirect(new moodle_url($baseurl, $part ? ['saved' => $part] : [], $part ? 'mrd-' . $part : null));
    }
}

$PAGE->set_heading(get_string('limits:title', $component));
$PAGE->set_secondary_navigation(false);
$PAGE->blocks->show_only_fake_blocks(true);
$PAGE->add_header_action($OUTPUT->render_from_template(
    'local_oksigeniamarandu/panel_links',
    ['links' => panel::links('limits')]
));

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniamarandu/limits', [
    'lead' => get_string($money ? 'limits:lead' : 'limits:lead_tg', $component),
    'form' => limits::context($money, $errors, optional_param('saved', '', PARAM_ALPHA), $baseurl) + [
        'formurl' => $baseurl->out(false),
        'sesskey' => sesskey(),
        'submit' => '',
    ],
]);
echo $OUTPUT->footer();
