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
 * The numbers on WhatsApp: search, filter by status, whom each stands for, and opting one out when the family asks the
 * site, with the reason, in its own row. From the mock-up of the house.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_oksigeniamarandu\local\consent;
use local_oksigeniamarandu\local\keywords;
use local_oksigeniamarandu\local\panel;

admin_externalpage_setup('local_oksigeniamarandu_contacts', '', null, '', ['nosearch' => true]);

$component = 'local_oksigeniamarandu';
$now = time();
$page = 20;
$reasons = ['phone', 'person', 'mail', 'notfamily', 'other'];
$q = optional_param('q', '', PARAM_RAW_TRIMMED);
$status = optional_param('status', 'all', PARAM_ALPHA);
$status = in_array($status, ['all', 'active', 'pending', 'optedout'], true) ? $status : 'all';
$shown = max($page, optional_param('shown', $page, PARAM_INT));
$ask = optional_param('ask', 0, PARAM_INT);
$done = optional_param('done', 0, PARAM_INT);
$baseurl = new moodle_url('/local/oksigeniamarandu/contacts.php', array_filter(['q' => $q,
    'status' => $status === 'all' ? '' : $status]));

// Opting out by hand, with the reason: it stays in the record of consent, with who did it.
$error = '';
$optout = optional_param('optout', 0, PARAM_INT);
if ($optout && confirm_sesskey() && ($contact = $DB->get_record(consent::CONTACTS, ['id' => $optout]))) {
    $reason = optional_param('reason', '', PARAM_ALPHA);
    $other = trim(optional_param('other', '', PARAM_TEXT));
    if (!in_array($reason, $reasons, true) || ($reason === 'other' && $other === '')) {
        $error = get_string('list:ask:error', $component);
        $ask = $contact->id;
    } else if ($contact->status !== 'optedout') {
        $why = $reason === 'other' ? $other : get_string('list:reason:' . $reason, $component);
        consent::optout($contact->phone, 'admin', $USER->id . ':' . core_text::substr($why, 0, 200));
        redirect(new moodle_url($baseurl, ['done' => $contact->id]));
    }
}

$PAGE->set_heading(get_string('contacts', $component));
$PAGE->set_secondary_navigation(false);
$PAGE->blocks->show_only_fake_blocks(true);
$PAGE->add_header_action($OUTPUT->render_from_template('local_oksigeniamarandu/panel_links', ['links' => [
    ['url' => (new moodle_url('/local/oksigeniamarandu/index.php'))->out(false), 'label' => get_string('list:panel', $component),
        'art' => ['size' => 22, 'stroke' => 4.65, 'texts' => true]],
]]));

// The counts of the whole list, and the numbers that match the search and the filter.
$counts = $DB->get_records_sql_menu('SELECT status, COUNT(1) FROM {' . consent::CONTACTS . '} GROUP BY status');
$counts = ['active' => (int)($counts['active'] ?? 0), 'pending' => (int)($counts['pending'] ?? 0),
    'optedout' => (int)($counts['optedout'] ?? 0)];
$counts['all'] = array_sum($counts);
$where = ['1 = 1'];
$params = [];
if ($status !== 'all') {
    $where[] = 'c.status = :status';
    $params['status'] = $status;
}
if ($q !== '') {
    $any = [$DB->sql_like('c.name', ':name', false, false)];
    $params['name'] = '%' . $DB->sql_like_escape($q) . '%';
    $digits = preg_replace('/\D/', '', $q);
    if (strlen($digits) >= 3) {
        $any[] = $DB->sql_like('c.phone', ':phone');
        $params['phone'] = '%' . $digits . '%';
    }
    $any[] = 'c.id IN (SELECT l.contactid FROM {' . consent::LINKS . '} l JOIN {user} u ON u.id = l.userid
                        WHERE ' . $DB->sql_like($DB->sql_fullname('u.firstname', 'u.lastname'), ':user', false, false) . ')';
    $params['user'] = '%' . $DB->sql_like_escape($q) . '%';
    $where[] = '(' . implode(' OR ', $any) . ')';
}
$where = implode(' AND ', $where);
$total = $DB->count_records_sql('SELECT COUNT(1) FROM {' . consent::CONTACTS . "} c WHERE $where", $params);
$contacts = $DB->get_records_sql('SELECT c.* FROM {' . consent::CONTACTS . "} c WHERE $where
                                   ORDER BY c.timemodified DESC, c.id DESC", $params, 0, $shown);

// Whom each one stands for, and the last move of its consent.
$whom = panel::standsfor(array_keys($contacts));
$last = [];
if ($contacts) {
    [$insql, $inparams] = $DB->get_in_or_equal(array_keys($contacts));
    foreach ($DB->get_records_select(consent::LOG, "contactid $insql", $inparams, 'timecreated DESC, id DESC') as $entry) {
        $last[$entry->contactid] = $last[$entry->contactid] ?? $entry;
    }
}
$chosen = optional_param('reason', 'phone', PARAM_ALPHA);
$rows = [];
foreach ($contacts as $contact) {
    $entry = $last[$contact->id] ?? null;
    $who = $whom[$contact->id][0] ?? null;
    $row = [
        'id' => $contact->id,
        'phone' => panel::spaced($contact->phone),
        'name' => (string)$contact->name,
        // Whom it stands for and what that is; only what it is, if the plugin does not name it.
        'who' => $who ? ($who->name !== '' ? $who->name : $who->kind) : '',
        'kind' => $who && $who->name !== '' ? $who->kind : '',
        'status' => $contact->status,
        'chip' => get_string('list:chip:' . $contact->status, $component),
        'since' => panel::when($entry ? (int)$entry->timecreated : (int)$contact->timecreated, $now),
        'how' => $entry ? panel::how($entry) : get_string('panel:how:pending', $component),
        'canout' => $contact->status !== 'optedout',
        'askurl' => (new moodle_url($baseurl, ['ask' => $contact->id, 'shown' => $shown]))->out(false) . '#mrd-' . $contact->id,
    ];
    if ($ask == $contact->id && $row['canout']) {
        $hidden = [];
        foreach ($baseurl->params() + ['optout' => $contact->id, 'shown' => $shown, 'sesskey' => sesskey()] as $name => $value) {
            $hidden[] = ['name' => $name, 'value' => $value];
        }
        $row['ask'] = [
            'question' => get_string('list:ask', $component, (object)['phone' => $row['phone'],
                'who' => $row['who'] !== '' ? ' (' . $row['who'] . ')' : '']),
            'action' => $baseurl->out_omit_querystring(),
            'hidden' => $hidden,
            'reasons' => array_map(function ($reason) use ($component, $chosen) {
                return ['value' => $reason, 'label' => get_string('list:reason:' . $reason, $component),
                    'selected' => $reason === $chosen];
            }, $reasons),
            'other' => optional_param('other', '', PARAM_TEXT),
            'otheropen' => $chosen === 'other',
            'error' => $error,
            'cancel' => (new moodle_url($baseurl, ['shown' => $shown]))->out(false) . '#mrd-' . $contact->id,
        ];
    }
    $rows[] = $row;
}

$filters = [];
foreach (['all', 'active', 'pending', 'optedout'] as $filter) {
    $filters[] = [
        'url' => (new moodle_url('/local/oksigeniamarandu/contacts.php', array_filter(['q' => $q,
            'status' => $filter === 'all' ? '' : $filter])))->out(false),
        'label' => get_string('list:filter:' . $filter, $component),
        'count' => $counts[$filter],
        'current' => $filter === $status,
    ];
}
$donecontact = $done ? $DB->get_record(consent::CONTACTS, ['id' => $done]) : null;

$context = [
    'lede' => implode(' · ', array_map(function ($status) use ($counts, $component) {
        return get_string('list:lede:' . $status . ($counts[$status] === 1 ? '_one' : ''), $component, $counts[$status]);
    }, ['active', 'pending', 'optedout'])),
    'action' => $baseurl->out_omit_querystring(),
    'q' => $q,
    'status' => $status === 'all' ? '' : $status,
    'filters' => $filters,
    'hasany' => $counts['all'] > 0,
    'count' => get_string($total === 1 ? 'list:count:one' : 'list:count:many', $component, $total),
    'done' => $donecontact && $donecontact->status === 'optedout'
        ? get_string('list:done', $component, panel::spaced($donecontact->phone)) : '',
    'rows' => $rows,
    'hasrows' => (bool)$rows,
    'more' => $total > $shown ? [
        'shown' => get_string('list:shown', $component, (object)['shown' => $shown, 'total' => $total]),
        'url' => (new moodle_url($baseurl, ['shown' => $shown + $page]))->out(false),
        'label' => get_string('list:more', $component, $page),
    ] : null,
    'none' => $counts['all'] === 0 ? [
        'text' => get_string('list:none_desc', $component, keywords::first('optinkeywords')),
        'url' => (new moodle_url('/local/oksigeniamarandu/wizard.php', ['step' => 'done']))->out(false),
        'size' => 56, 'stroke' => 1.83,
        'nonumbers' => true,
    ] : null,
    'nothing' => $counts['all'] > 0 && !$rows ? [
        'title' => $q !== '' ? get_string('list:nothing:q', $component, $q) : get_string('list:nothing:filter', $component),
        'clear' => (new moodle_url('/local/oksigeniamarandu/contacts.php'))->out(false),
        'size' => 56, 'stroke' => 1.83,
        'noresults' => true,
    ] : null,
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniamarandu/contacts', $context);
echo $OUTPUT->footer();
