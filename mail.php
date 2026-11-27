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
 * The page «Mail»: where the mails of the tools go out (the addresses of home by the mail of the site, the rest by the
 * mail of the site or by Mailjet), Mailjet, the month against its quota, the last mails and the addresses that do not
 * receive.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_oksigeniamarandu\local\mail\page;
use local_oksigeniamarandu\local\panel;

admin_externalpage_setup('local_oksigeniamarandu_mail', '', null, '', ['nosearch' => true]);

$component = 'local_oksigeniamarandu';
$baseurl = new moodle_url('/local/oksigeniamarandu/mail.php');
$errors = [];
$part = optional_param('part', '', PARAM_ALPHA);
$part = in_array($part, page::PARTS, true) ? $part : '';
if (optional_param('action', '', PARAM_ALPHA) === 'mail' && $part !== '' && confirm_sesskey()) {
    $errors = page::save($part);
    if (!$errors) {
        // Back to the card that was touched, with its «Saved».
        redirect(new moodle_url($baseurl, ['saved' => $part], 'mrd-' . $part));
    }
}

$PAGE->set_heading(get_string('mail:title', $component));
$PAGE->set_secondary_navigation(false);
$PAGE->blocks->show_only_fake_blocks(true);
$PAGE->add_header_action($OUTPUT->render_from_template(
    'local_oksigeniamarandu/panel_links',
    ['links' => panel::links('mail'), 'extra' => \local_oksigeniamarandu\api::proposal_button(
        'local_oksigeniamarandu',
        get_string('mail:title', 'local_oksigeniamarandu')
    )]
));
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniamarandu/mail', [
    'lead' => get_string('mail:lead', $component),
    'sesskey' => sesskey(),
] + page::context($errors ? $part : optional_param('saved', '', PARAM_ALPHA), $errors, $baseurl));
echo $OUTPUT->footer();
