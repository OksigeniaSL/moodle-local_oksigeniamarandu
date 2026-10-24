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
 * The panel: whether the notices go out and, if not, the one thing to do; today, the month, what failed, who joined or
 * left, and each notice. From the mock-up of the house.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_oksigeniamarandu\local\consent;
use local_oksigeniamarandu\local\panel;

admin_externalpage_setup('local_oksigeniamarandu_panel', '', null, '', ['nosearch' => true]);

$component = 'local_oksigeniamarandu';
$now = time();
$wizard = new moodle_url('/local/oksigeniamarandu/wizard.php');
$listurl = new moodle_url('/local/oksigeniamarandu/contacts.php');
$money = function (float $amount): string {
    return get_string('panel:money', 'local_oksigeniamarandu', format_float($amount, 2));
};

$PAGE->set_heading(get_string('panel', $component));
$PAGE->set_secondary_navigation(false);
$PAGE->blocks->show_only_fake_blocks(true);
$PAGE->add_header_action($OUTPUT->render_from_template('local_oksigeniamarandu/panel_links', ['links' => [
    ['url' => $listurl->out(false), 'label' => get_string('panel:numbers', $component),
        'count' => $DB->count_records(consent::CONTACTS), 'art' => ['size' => 22, 'stroke' => 4.65, 'numbers' => true]],
    ['url' => (new moodle_url($wizard, ['step' => 'texts']))->out(false), 'label' => get_string('panel:texts', $component),
        'art' => ['size' => 22, 'stroke' => 4.65, 'texts' => true]],
    ['url' => $wizard->out(false), 'label' => get_string('panel:settings', $component),
        'art' => ['size' => 22, 'stroke' => 4.65, 'settings' => true]],
]]));

// 1. The state, first and alone.
$state = panel::state($now);
$month = panel::month($now);
$waiting = get_string($state->waiting === 1 ? 'panel:waiting:one' : 'panel:waiting:many', $component, $state->waiting);
$box = ['kind' => $state->kind, 'actions' => []];
if ($state->kind === 'notready') {
    $box += ['title' => get_string('panel:notready', $component), 'text' => get_string('panel:notready_desc', $component),
        'art' => ['size' => 56, 'stroke' => 1.83, 'settings' => true]];
    $box['actions'][] = ['url' => $wizard->out(false), 'label' => get_string('panel:fix:setup', $component), 'primary' => true];
} else if ($state->kind === 'blocked') {
    $text = [
        'token' => get_string('panel:blocked:token_desc', $component, panel::when($state->since, $now)),
        'restricted' => get_string('panel:blocked:restricted_desc', $component),
        'payment' => get_string('panel:blocked:payment_desc', $component),
        'account' => get_string('panel:blocked:account_desc', $component, $state->message),
    ][$state->what];
    $box += ['bad' => true, 'title' => get_string('panel:blocked:' . $state->what, $component),
        'text' => $text . ' ' . ($state->waiting ? $waiting : ''), 'art' => ['size' => 56, 'stroke' => 1.83, 'stopped' => true]];
    if ($state->what === 'token' || $state->what === 'account') {
        $box['actions'][] = ['url' => (new moodle_url($wizard, ['step' => 'account', 'edit' => 1]))->out(false),
            'label' => get_string('panel:fix:token', $component), 'primary' => true];
        $box['actions'][] = ['url' => 'https://developers.facebook.com/apps/',
            'label' => get_string('panel:fix:where', $component)];
    } else if ($state->what === 'restricted') {
        $box['actions'][] = ['url' => 'https://business.facebook.com/wa/manage/home/',
            'label' => get_string('panel:fix:restricted', $component), 'primary' => true];
    } else {
        $box['actions'][] = ['url' => 'https://business.facebook.com/billing_hub/',
            'label' => get_string('panel:fix:payment', $component), 'primary' => true];
    }
} else if ($state->kind === 'cap') {
    $next = new DateTime('@' . $now);
    $next->setTimezone(core_date::get_server_timezone_object());
    $next->modify('first day of next month midnight');
    $box += ['cap' => true, 'art' => ['size' => 56, 'stroke' => 1.83, 'cap' => true],
        'title' => get_string('panel:cap', $component, (object)['spent' => $money($month->spent), 'cap' => $money($month->cap)]),
        'text' => get_string(
            'panel:cap_desc',
            $component,
            userdate($next->getTimestamp(), get_string('panel:when:date', $component))
        )];
    $box['actions'][] = ['url' => (new moodle_url($wizard, ['step' => 'limits']))->out(false),
        'label' => get_string('panel:fix:cap', $component), 'primary' => true];
} else {
    $box += ['title' => get_string('panel:ok', $component), 'art' => ['size' => 56, 'stroke' => 1.83, 'running' => true],
        'text' => $state->next ? get_string('panel:ok:quiet', $component, panel::when($state->next, $now))
            : ($state->waiting ? get_string('panel:ok:going', $component) : get_string('panel:ok:idle', $component))];
}

// 2. Today.
$today = panel::today($now);
$waits = [];
foreach (panel::waiting($now) as $group) {
    $waits[] = [
        'count' => $group->count,
        'label' => get_string('panel:wait:' . $group->reason, $component, $group->name),
        'when' => $group->when ? panel::when($group->when, $now) : get_string('panel:wait:' . $group->reason . '_when', $component),
    ];
}

// 3. The month: the bar against the limit, or against what is likely when there is none.
$top = $month->cap > 0 ? $month->cap : max($month->likely, $month->spent, 0.01);
$percent = function (float $amount) use ($top): string {
    return format_float(min(100, max(0, $amount / $top * 100)), 1, false);
};

// 4 to 6.
$failed = [];
foreach (panel::failed() as $item) {
    $failed[] = ['time' => panel::when($item->time, $now), 'name' => $item->name, 'phone' => $item->phone,
        'why' => get_string('panel:why:' . $item->why, $component)];
}
$people = panel::people();
$moves = [];
foreach ($people->moves as $move) {
    $moves[] = ['time' => panel::when($move->time, $now), 'in' => $move->action === 'optin', 'who' => $move->who,
        'how' => $move->how,
        'tag' => get_string($move->action === 'optin' ? 'panel:tag:in' : 'panel:tag:out', $component)];
}
$bynotice = panel::bynotice($now);
$most = max(1, ...array_merge([1], array_column($bynotice, 'count')));
$total = array_sum(array_column($bynotice, 'count'));
foreach ($bynotice as $item) {
    $item->percent = format_float($item->count / $most * 100, 1, false);
}

$context = [
    'state' => $box,
    'today' => [
        'until' => get_string('panel:today:until', $component, userdate($now, get_string('strftimetime24', 'langconfig'))),
        'sent' => $today['sent'],
        'delivered' => $today['delivered'],
        'read' => $today['read'],
        'failed' => $today['failed'],
        'waits' => $waits,
        'nowait' => !$waits,
    ],
    'month' => [
        'name' => get_string('panel:month:name', $component, userdate($now, '%B')),
        'spent' => $money($month->spent),
        'cap' => $month->cap > 0 ? get_string('panel:month:cap', $component, $money($month->cap))
            : get_string('panel:month:nocap', $component),
        'spentpercent' => $percent($month->spent),
        'likelypercent' => $percent($month->likely),
        'full' => $month->full,
        'scale' => $month->cap > 0 ? [$money(0), $money($month->cap / 2), $money($month->cap)] : [],
        'templates' => $month->templates,
        'templatecost' => get_string('panel:month:templates', $component, $money($month->cost)),
        'free' => $month->free,
        'fine' => $month->full ? get_string('panel:month:full', $component)
            : get_string('panel:month:likely', $component, $money($month->likely)),
    ],
    'failed' => $failed,
    'nofailed' => !$failed,
    'people' => [
        'active' => $people->active,
        'pending' => $people->pending,
        'optedout' => $people->optedout,
        'moves' => $moves,
        'nomoves' => !$moves,
        'listurl' => $listurl->out(false),
    ],
    'bynotice' => array_values(array_map(function ($item) {
        return (array)$item;
    }, $bynotice)),
    'nonotices' => !$bynotice,
    'total' => get_string('panel:bynotice:total', $component, $total),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniamarandu/panel', $context);
echo $OUTPUT->footer();
