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
 * The numbers that have joined or left, with whom they stand for, and opting one out when the family asks the site.
 * A plain list until the panel arrives.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_oksigeniamarandu\local\consent;
use local_oksigeniamarandu\local\keywords;
use local_oksigeniamarandu\local\queue;

admin_externalpage_setup('local_oksigeniamarandu_contacts');

$baseurl = new moodle_url('/local/oksigeniamarandu/contacts.php');
$optout = optional_param('optout', 0, PARAM_INT);
if ($optout && ($contact = $DB->get_record(consent::CONTACTS, ['id' => $optout])) && $contact->status !== 'optedout') {
    // When a family asks the site, by phone or in person: it stays in the record of consent, with who did it.
    if (optional_param('confirm', 0, PARAM_BOOL) && confirm_sesskey()) {
        consent::optout($contact->phone, 'admin', 'user:' . $USER->id);
        redirect(
            $baseurl,
            get_string('contacts:optedout', 'local_oksigeniamarandu', '+' . $contact->phone),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('contacts:optout_confirm', 'local_oksigeniamarandu', (object)[
            'phone' => '+' . $contact->phone,
            'word' => keywords::first('optinkeywords'),
        ]),
        new moodle_url($baseurl, ['optout' => $contact->id, 'confirm' => 1, 'sesskey' => sesskey()]),
        $baseurl
    );
    echo $OUTPUT->footer();
    die();
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('contacts', 'local_oksigeniamarandu'));
if ($blocked = queue::blocked()) {
    echo $OUTPUT->notification(get_string('wizard:blocked', 'local_oksigeniamarandu', s($blocked->message)), 'error');
}

$contacts = $DB->get_records(consent::CONTACTS, null, 'timemodified DESC', '*', 0, 500);
if (!$contacts) {
    echo html_writer::tag('p', get_string('contacts:none', 'local_oksigeniamarandu', keywords::first('optinkeywords')));
    echo $OUTPUT->footer();
    die();
}

$counts = $DB->get_records_sql_menu('SELECT status, COUNT(1) FROM {' . consent::CONTACTS . '} GROUP BY status');
$summary = [];
foreach (['active', 'pending', 'optedout'] as $status) {
    $summary[] = get_string('status:' . $status, 'local_oksigeniamarandu') . ': ' . (int)($counts[$status] ?? 0);
}
echo html_writer::tag('p', implode(' · ', $summary));

[$insql, $params] = $DB->get_in_or_equal(array_keys($contacts));
$links = $DB->get_records_select(consent::LINKS, "contactid $insql", $params);
$userids = array_filter(array_column($links, 'userid'));
$users = $userids ? $DB->get_records_list('user', 'id', $userids) : [];

$table = new html_table();
$table->head = [
    get_string('contacts:phone', 'local_oksigeniamarandu'),
    get_string('contacts:name', 'local_oksigeniamarandu'),
    get_string('contacts:status', 'local_oksigeniamarandu'),
    get_string('contacts:since', 'local_oksigeniamarandu'),
    get_string('contacts:standsfor', 'local_oksigeniamarandu'),
    '',
];
foreach ($contacts as $contact) {
    $for = [];
    foreach ($links as $link) {
        if ($link->contactid != $contact->id) {
            continue;
        }
        if ($link->userid && isset($users[$link->userid])) {
            $for[] = fullname($users[$link->userid]);
        } else {
            $for[] = s($link->component) . ($link->itemid ? ' #' . (int)$link->itemid : '');
        }
    }
    $time = $contact->status === 'optedout' ? $contact->optouttime : $contact->consenttime;
    $table->data[] = [
        s('+' . $contact->phone),
        s((string)$contact->name),
        get_string('status:' . $contact->status, 'local_oksigeniamarandu'),
        $time ? userdate($time, get_string('strftimedatetimeshort', 'langconfig')) : '',
        $for ? implode(', ', $for) : get_string('contacts:unlinked', 'local_oksigeniamarandu'),
        $contact->status === 'optedout' ? '' : html_writer::link(
            new moodle_url($baseurl, ['optout' => $contact->id]),
            get_string('contacts:optout', 'local_oksigeniamarandu')
        ),
    ];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
