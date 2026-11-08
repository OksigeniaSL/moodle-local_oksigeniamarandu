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
 * Which notices go out, by channel: the notices of each plugin, a switch per channel that saves as it is pressed, what
 * each one costs a month by WhatsApp with the families of now, and Telegram, which is free. From the mock-up of the house.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_oksigeniamarandu\local\consent;
use local_oksigeniamarandu\local\drawings;
use local_oksigeniamarandu\local\notices;
use local_oksigeniamarandu\local\panel;
use local_oksigeniamarandu\local\queue;
use local_oksigeniamarandu\local\templates;

admin_externalpage_setup('local_oksigeniamarandu_notices', '', null, '', ['nosearch' => true]);

$component = 'local_oksigeniamarandu';
$baseurl = new moodle_url('/local/oksigeniamarandu/notices.php');
$names = ['whatsapp' => 'WhatsApp', 'telegram' => 'Telegram'];
$short = ['whatsapp' => 'wa', 'telegram' => 'tg'];
$all = notices::all();
$ready = array_keys(array_filter(\local_oksigeniamarandu\api::channels(), function ($info) {
    return $info['ready'];
}));
// Without any channel yet, both: what is switched on goes out as soon as one is connected.
$channels = $ready ?: ['whatsapp', 'telegram'];
$families = $DB->count_records(consent::CONTACTS, ['status' => 'active', 'channel' => 'whatsapp']);
$monthly = function (stdClass $notice) use ($families): float {
    return $notice->perweek * 52 / 12 * max($families, 1) * queue::cost();
};
$money = function (float $amount) use ($component): string {
    return get_string('panel:money', $component, format_float($amount, 2));
};
$total = function () use ($all, $channels, $monthly, $money, $component): string {
    $on = [];
    $cost = 0.0;
    foreach ($channels as $channel) {
        foreach (array_intersect(notices::enabled($channel), array_keys($all)) as $key) {
            $on[$key] = true;
            if ($channel === 'whatsapp') {
                $cost += $monthly($all[$key]);
            }
        }
    }
    $text = get_string(count($on) === 1 ? 'notices:total_one' : 'notices:total', $component, count($on));
    if (in_array('whatsapp', $channels, true)) {
        $text .= ' · ' . get_string('notices:total_wa', $component, $money($cost));
    }
    if (in_array('telegram', $channels, true)) {
        $text .= ' · ' . get_string('notices:total_tg', $component);
    }
    return $text . '.';
};

// A switch: on or off for a channel, at once. With JavaScript it answers in JSON; without, the page comes back.
$saved = '';
$toggle = optional_param('toggle', '', PARAM_RAW_TRIMMED);
$allon = optional_param('allon', '', PARAM_ALPHA);
if (($toggle !== '' || $allon !== '') && confirm_sesskey()) {
    if ($allon !== '' && in_array($allon, $channels, true)) {
        foreach (array_keys($all) as $key) {
            notices::set($key, $allon, true);
        }
        $saved = get_string('notices:savedall', $component, $names[$allon]);
    } else {
        [$key, $channel] = array_pad(explode('|', $toggle, 2), 2, '');
        if (isset($all[$key]) && in_array($channel, $channels, true)) {
            $on = !in_array($key, notices::enabled($channel), true);
            notices::set($key, $channel, $on);
            $saved = get_string($on ? 'notices:savedon' : 'notices:savedoff', $component, (object)[
                'name' => notices::text($all[$key]), 'channel' => $names[$channel]]);
        }
    }
    if (optional_param('ajax', 0, PARAM_BOOL)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['saved' => $saved, 'total' => $total(), 'on' => [
            'whatsapp' => notices::enabled('whatsapp'), 'telegram' => notices::enabled('telegram')]]);
        die();
    }
    redirect(new moodle_url($baseurl, ['saved' => $saved]));
}
$saved = optional_param('saved', '', PARAM_TEXT);

$PAGE->set_heading(get_string('notices:title', $component));
$PAGE->set_secondary_navigation(false);
$PAGE->blocks->show_only_fake_blocks(true);
$PAGE->add_header_action($OUTPUT->render_from_template(
    'local_oksigeniamarandu/panel_links',
    ['links' => panel::links('notices')]
));

// The notices by plugin, the plugins of the house first and Marandu's own at the end.
$groups = [];
foreach ($all as $key => $notice) {
    if (!isset($groups[$notice->component])) {
        $group = notices::group($notice->component);
        $groups[$notice->component] = ['title' => $group->title, 'name' => $group->name,
            'icon' => drawings::svg(notices::icon($notice->component), 26), 'rows' => []];
    }
    $cells = [];
    foreach ($channels as $channel) {
        $on = in_array($key, notices::enabled($channel), true);
        if ($channel === 'telegram') {
            $note = get_string('notices:free', $component);
            $review = false;
        } else if (templates::problem(notices::text($notice, 'template', $CFG->lang)) !== null) {
            // Meta would refuse its text: it is for whoever makes the plugin to fix it.
            $note = get_string('notices:wrongtext', $component);
            $review = true;
        } else if (templates::approved($notice)) {
            $note = get_string('notices:cost', $component, $money($monthly($notice)));
            $review = false;
        } else {
            $note = get_string(templates::get($notice, $CFG->lang) ? 'notices:review' : 'notices:notext', $component);
            $review = true;
        }
        $cells[] = ['key' => $key, 'channel' => $channel, 'value' => $key . '|' . $channel, 'on' => $on ? 'true' : 'false',
            'label' => get_string('notices:switch', $component, (object)['name' => notices::text($notice),
                'channel' => $names[$channel]]),
            'icon' => drawings::svg($channel, 22, 'mrd-ico ' . $short[$channel]), 'name' => $names[$channel],
            'note' => $note, 'review' => $review];
    }
    $groups[$notice->component]['rows'][] = ['name' => notices::text($notice), 'desc' => notices::text($notice, 'desc'),
        'cells' => $cells];
}
uksort($groups, function ($a, $b) {
    return [$a === 'local_oksigeniamarandu', $a] <=> [$b === 'local_oksigeniamarandu', $b];
});

$fresh = !$DB->record_exists_select(queue::TABLE, "status IN ('sent', 'delivered', 'read')");
$anyon = notices::any_on() || (!$ready && (notices::enabled('whatsapp') || notices::enabled('telegram')));
$context = [
    'action' => $baseurl->out(false),
    'sesskey' => sesskey(),
    'lead' => get_string(count($channels) > 1 ? 'notices:lead2' : 'notices:lead', $component)
        . ($ready ? '' : ' ' . get_string('notices:nochannel', $component)),
    'saved' => $saved,
    'chs' => count($channels),
    'heads' => array_map(function ($channel) use ($names, $short, $component) {
        return ['icon' => drawings::svg($channel, 22, 'mrd-ico ' . $short[$channel]), 'name' => $names[$channel],
            'note' => get_string($channel === 'whatsapp' ? 'notices:paid' : 'notices:free', $component)];
    }, $channels),
    'groups' => array_values($groups),
    'none' => !$all,
    'total' => $total(),
    'alltg' => in_array('telegram', $channels, true),
    'next' => $fresh && $anyon && !$DB->record_exists(consent::CONTACTS, ['status' => 'active']) ? [
        'icon' => drawings::svg('apuntarse', 48),
        'url' => (new moodle_url('/local/oksigeniamarandu/index.php'))->out(false),
    ] : null,
];

$PAGE->requires->js_amd_inline(<<<'JS'
require([], function() {
    var root = document.querySelector('.local-oksigeniamarandu-notices');
    if (!root) {
        return;
    }
    var form = root.querySelector('form[data-notices]');
    var saved = root.querySelector('[data-saved]');
    var total = root.querySelector('[data-total]');
    var send = function(name, value, done) {
        var body = new FormData();
        body.append('sesskey', form.querySelector('[name=sesskey]').value);
        body.append('ajax', '1');
        body.append(name, value);
        fetch(form.action, {method: 'POST', body: body, credentials: 'same-origin'}).then(function(r) {
            return r.json();
        }).then(function(data) {
            saved.textContent = data.saved;
            saved.hidden = !data.saved;
            total.textContent = data.total;
            root.querySelectorAll('.mrd-switch').forEach(function(sw) {
                var on = (data.on[sw.getAttribute('data-channel')] || []).indexOf(sw.getAttribute('data-key')) >= 0;
                sw.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            if (done) {
                done();
            }
        }).catch(function() {
            form.submit();
        });
    };
    // Each switch saves as it is pressed; the page stays where it is.
    root.addEventListener('click', function(e) {
        var sw = e.target.closest('.mrd-switch');
        if (sw) {
            e.preventDefault();
            sw.setAttribute('aria-pressed', sw.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
            send('toggle', sw.value, function() {
                sw.focus();
            });
            return;
        }
        var all = e.target.closest('[data-all]');
        if (all) {
            e.preventDefault();
            send('allon', all.value);
        }
    });
});
JS
);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniamarandu/notices', $context);
echo $OUTPUT->footer();
