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
 * The panel: whether the notices go out and, if not, the one thing to do, with each channel on its own; on a brand-new
 * site, the way to the first notice and what will appear; then today, the month day by day, what WhatsApp costs, what
 * failed, who joined or left, and each notice by channel. From the mock-up of the house.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_oksigeniamarandu\local\consent;
use local_oksigeniamarandu\local\drawings;
use local_oksigeniamarandu\local\keywords;
use local_oksigeniamarandu\local\notices;
use local_oksigeniamarandu\local\panel;
use local_oksigeniamarandu\local\templates;

admin_externalpage_setup('local_oksigeniamarandu_panel', '', null, '', ['nosearch' => true]);

$component = 'local_oksigeniamarandu';
$now = time();
$wizard = new moodle_url('/local/oksigeniamarandu/wizard.php');
$tgpage = new moodle_url('/local/oksigeniamarandu/telegram.php');
$noticespage = new moodle_url('/local/oksigeniamarandu/notices.php');
$listurl = new moodle_url('/local/oksigeniamarandu/contacts.php');
$str = function (string $id, $a = null) use ($component): string {
    return get_string($id, $component, $a);
};
$money = function (float $amount) use ($str): string {
    return $str('panel:money', format_float($amount, 2));
};
$names = ['whatsapp' => 'WhatsApp', 'telegram' => 'Telegram'];
$short = ['whatsapp' => 'wa', 'telegram' => 'tg'];
$icon = function (string $channel, int $px = 22) use ($short): string {
    return drawings::svg($channel, $px, 'mrd-ico ' . $short[$channel]);
};

$state = panel::state($now);
$ready = array_keys($state->channels);
$firsts = panel::firsts();
$people = panel::people();
$fresh = !$firsts->sent;
$words = implode($str('panel:and'), array_map(function ($channel) use ($names) {
    return $names[$channel];
}, $ready));

$PAGE->set_heading($ready ? $str('panel:title', $words) : $str('panel'));
$PAGE->set_secondary_navigation(false);
$PAGE->blocks->show_only_fake_blocks(true);
$PAGE->add_header_action($OUTPUT->render_from_template(
    'local_oksigeniamarandu/panel_links',
    ['links' => panel::links('panel')]
));

// 1. The state: the hero, with its scene, its channels and the one thing to do.
$hero = ['tone' => 'accent', 'scene' => 'escena-marcha', 'ribbon' => '', 'text' => '', 'actions' => [], 'choices' => []];
$button = function (string $url, string $label, string $kind = 'primary'): array {
    return ['url' => $url, 'label' => $label, 'kind' => $kind];
};
$waiting = $str($state->waiting === 1 ? 'panel:waiting:one' : 'panel:waiting:many', $state->waiting);
$enabledcount = count(array_unique(array_merge(...array_map(function ($channel) {
    return array_intersect(notices::enabled($channel), array_keys(notices::all()));
}, $ready ?: ['whatsapp']))));
$event = '';
if ($state->kind === 'connect') {
    $hero = array_merge($hero, ['scene' => 'escena-canales', 'title' => $str('panel:hero:connect'),
        'text' => $str('panel:hero:connect_desc')]);
    $hero['choices'] = [
        ['url' => $tgpage->out(false), 'icon' => $icon('telegram', 40), 'name' => 'Telegram',
            'text' => $str('panel:choice:telegram'), 'cta' => $str('panel:choice:telegram_cta')],
        ['url' => $wizard->out(false), 'icon' => $icon('whatsapp', 40), 'name' => 'WhatsApp',
            'text' => $str('panel:choice:whatsapp'), 'cta' => $str('panel:choice:whatsapp_cta')],
    ];
} else if ($state->kind === 'disconnected') {
    $hero = array_merge($hero, ['tone' => 'warn', 'scene' => 'escena-canales', 'title' => $str('panel:hero:disconnected'),
        'text' => $str($state->waiting ? 'panel:hero:disconnected_desc' : 'panel:hero:disconnected_none', $state->waiting)]);
    $hero['actions'] = [$button($tgpage->out(false), $str('panel:choice:telegram_cta')),
        $button($wizard->out(false), $str('panel:choice:whatsapp_cta'), 'link')];
} else if ($state->kind === 'choose') {
    $text = $fresh || !$people->active
        ? $str(count($ready) > 1 ? 'panel:hero:choose_fresh2' : 'panel:hero:choose_fresh', $words)
        : $str('panel:hero:choose_desc', $people->active);
    $hero = array_merge($hero, ['scene' => 'escena-elige', 'title' => $str('panel:hero:choose'), 'text' => $text]);
    $hero['actions'] = [$button($noticespage->out(false), $str('panel:hero:choose_cta'))];
} else if ($state->kind === 'blocked') {
    $text = [
        'token' => $str('panel:blocked:token_desc', panel::when($state->since, $now)),
        'restricted' => $str('panel:blocked:restricted_desc'),
        'payment' => $str('panel:blocked:payment_desc'),
        'account' => $str('panel:blocked:account_desc', $state->message),
        'telegram' => $str('panel:blocked:telegram_desc', $state->message),
    ][$state->what];
    $title = $str('panel:blocked:' . $state->what);
    if (count($ready) > 1) {
        // With two channels, which one: «WhatsApp, parado: …».
        $title = $names[$state->channel] . ', ' . core_text::strtolower(core_text::substr($title, 0, 1))
            . core_text::substr($title, 1);
    }
    $other = $names[$state->channel === 'telegram' ? 'whatsapp' : 'telegram'];
    $others = $state->others ? ' ' . $str('panel:hero:others', $other) : '';
    $hero = array_merge($hero, ['tone' => 'bad', 'scene' => 'escena-parado', 'title' => $title,
        'text' => $text . ($state->waiting ? ' ' . $waiting : '') . $others]);
    if ($state->what === 'token' || $state->what === 'account') {
        $hero['actions'] = [$button(
            (new moodle_url($wizard, ['step' => 'account', 'edit' => 1]))->out(false),
            $str('panel:fix:token')
        ), $button('https://developers.facebook.com/apps/', $str('panel:fix:where'), 'link')];
    } else if ($state->what === 'telegram') {
        $hero['actions'] = [$button((new moodle_url($tgpage, ['edit' => 1]))->out(false), $str('panel:fix:telegram'))];
    } else if ($state->what === 'restricted') {
        $hero['actions'] = [$button('https://business.facebook.com/wa/manage/home/', $str('panel:fix:restricted'))];
    } else {
        $hero['actions'] = [$button('https://business.facebook.com/billing_hub/', $str('panel:fix:payment'))];
    }
} else if ($state->kind === 'cap') {
    $month = panel::month($now);
    $next = new DateTime('@' . $now);
    $next->setTimezone(core_date::get_server_timezone_object());
    $next->modify('first day of next month midnight');
    $hero = array_merge($hero, ['tone' => 'warn', 'scene' => 'escena-tope',
        'title' => $str('panel:cap', (object)['spent' => $money($month->spent), 'cap' => $money($month->cap)]),
        'text' => $str('panel:cap_desc', userdate($next->getTimestamp(), $str('panel:when:date')))
            . ($state->others ? ' ' . $str('panel:hero:othersfree', 'Telegram') : '')]);
    $hero['actions'] = [$button((new moodle_url($wizard, ['step' => 'limits']))->out(false), $str('panel:fix:cap'))];
} else if ($fresh && !$people->active) {
    $a = $str($enabledcount === 1 ? 'panel:hero:on_one' : 'panel:hero:on_many', $enabledcount);
    $hero = array_merge($hero, ['scene' => 'escena-apuntarse', 'title' => $str('panel:hero:families'),
        'text' => in_array('whatsapp', $ready, true) && get_config($component, 'displayphone')
            ? $str('panel:hero:families_wa', (object)['notices' => $a, 'word' => keywords::first('optinkeywords'),
                'phone' => get_config($component, 'displayphone')])
            : $str('panel:hero:families_desc', $a)]);
    $hero['actions'] = [$button($noticespage->out(false), $str('panel:notices'), 'link')];
} else if ($fresh && $firsts->joincontact && $firsts->join > $now - DAYSECS) {
    $event = 'firstjoin';
    $hero = array_merge($hero, ['tone' => 'ok', 'scene' => 'escena-primera-alta', 'ribbon' => $str('panel:hero:firstjoin_ribbon'),
        'title' => $str('panel:hero:firstjoin'), 'text' => $str('panel:hero:firstjoin_desc', (object)[
            'who' => panel::who($firsts->joincontact), 'when' => panel::when($firsts->join, $now),
            'channel' => $names[panel::channel($firsts->joincontact->phone)]])]);
    $hero['actions'] = [$button($listurl->out(false), $str('panel:people:list'), 'second')];
} else if ($fresh) {
    $hero = array_merge($hero, ['scene' => 'escena-apuntarse', 'title' => $str(
        $people->active === 1 ? 'panel:hero:joined_one' : 'panel:hero:joined',
        $people->active
    ), 'text' => $str('panel:hero:joined_desc')]);
    $hero['actions'] = [$button($listurl->out(false), $str('panel:people:list'), 'second')];
} else if ($firsts->sent > $now - DAYSECS) {
    $event = 'firstsent';
    $row = $firsts->sentrow;
    $notice = notices::get($row->component, $row->notice);
    $contact = $DB->get_record(consent::CONTACTS, ['id' => $row->contactid]);
    $hero = array_merge($hero, ['tone' => 'ok', 'scene' => 'escena-primer-aviso', 'ribbon' => $str('panel:hero:firstsent_ribbon'),
        'title' => $str('panel:hero:firstsent'), 'text' => $str('panel:hero:firstsent_desc', (object)[
            'notice' => $notice ? notices::text($notice) : $row->notice, 'when' => panel::when($firsts->sent, $now),
            'who' => $contact ? panel::who($contact) : '', 'channel' => $contact ? $names[panel::channel($contact->phone)] : ''])]);
} else {
    $hero = array_merge($hero, ['tone' => 'ok', 'title' => $str('panel:ok'),
        'text' => $state->next ? $str('panel:ok:quiet', panel::when($state->next, $now))
            : ($state->waiting ? $str('panel:ok:going') : $str('panel:ok:idle'))]);
}
$hero['scene'] = drawings::scene($hero['scene']);
$hero['hasactions'] = (bool)$hero['actions'];
// Each channel, with how it is.
$hero['chips'] = [];
foreach ($state->channels as $channel => $how) {
    $how = $how === 'ok' && ($fresh || $state->kind === 'choose') ? 'connected' : $how;
    $hero['chips'][] = ['icon' => $icon($channel), 'name' => $names[$channel], 'status' => $str('panel:chip:' . $how),
        'class' => ['ok' => '', 'connected' => '', 'stopped' => 'off', 'cap' => 'cap'][$how]];
}
// The confetti, once for each person: the first time they see the first number join or the first notice go out.
$hero['party'] = [];
if ($event) {
    $seen = array_filter(explode(',', (string)get_user_preferences('local_oksigeniamarandu_party', '')));
    if (!in_array($event, $seen, true)) {
        set_user_preference('local_oksigeniamarandu_party', implode(',', array_merge($seen, [$event])));
        foreach (range(0, 15) as $i) {
            $hero['party'][] = ['style' => '--a:' . ($i * 22.5 + ($i % 2 ? 9 : -6)) . 'deg;--r:'
                . (($i % 3 ? 5.2 : 4) + ($i % 4) * .5)
                . 'em;--d:' . (($i % 5) * 45) . 'ms;--c:var(--mrd-c' . (($i % 3) + 1) . ')'];
        }
    }
}

// 2. A brand-new site: the way to the first notice, four steps, and what will appear here.
$path = null;
$ghosts = null;
if ($fresh || $event === 'firstsent') {
    $order = ['connect' => 0, 'disconnected' => 0, 'choose' => 1];
    $at = $order[$state->kind] ?? ($people->active ? 3 : 2);
    $at = $event === 'firstsent' ? 4 : $at;
    $word = in_array('whatsapp', $ready, true) ? keywords::first('optinkeywords') : '';
    $steps = [
        ['connect', 'canales', $str('panel:path:connect_todo'),
            $str(count($ready) > 1 ? 'panel:path:connect_done2' : 'panel:path:connect_done', $words)],
        ['choose', 'elige', $str('panel:path:choose_todo'), $str(
            $enabledcount === 1 ? 'panel:hero:on_one' : 'panel:hero:on_many',
            $enabledcount
        )],
        ['join', 'apuntarse', $word ? $str('panel:path:join_todo_wa', $word) : $str('panel:path:join_todo'),
            $str($people->active === 1 ? 'panel:path:join_done_one' : 'panel:path:join_done', $people->active)],
        ['first', 'primer-aviso', $str('panel:path:first_todo'), $firsts->sent ? $str(
            'panel:path:first_done',
            panel::when($firsts->sent, $now)
        ) : ''],
    ];
    $path = [];
    foreach ($steps as $i => [$key, $drawing, $todo, $done]) {
        $isdone = $i < $at;
        $path[] = ['class' => $isdone ? 'done' : ($i === $at ? 'now' : ''), 'now' => $i === $at, 'num' => $isdone ? '✓' : $i + 1,
            'title' => $str('panel:path:' . $key), 'small' => $isdone ? $done : $todo, 'icon' => drawings::svg($drawing, 48)];
    }
}
if ($fresh) {
    $wa = in_array('whatsapp', $ready, true);
    $tg = in_array('telegram', $ready, true);
    $join = [];
    if ($wa && get_config($component, 'displayphone')) {
        $join[] = ['icon' => $icon('whatsapp'), 'text' => $str('panel:ghost:join_wa', (object)[
            'word' => keywords::first('optinkeywords'), 'phone' => get_config($component, 'displayphone')])];
    }
    if ($tg) {
        $join[] = ['icon' => $icon('telegram'), 'text' => $str('panel:ghost:join_tg')];
    }
    $ghosts = [
        'today' => $str($wa ? 'panel:ghost:today_wa' : 'panel:ghost:today'),
        'month' => $str($wa ? 'panel:ghost:month_wa' : ($tg ? 'panel:ghost:month_tg' : 'panel:ghost:month')),
        'cols' => array_map(function ($h) {
            return ['height' => $h * 15];
        }, [3, 5, 4, 6, 2, .4, .3, 5, 6, 4, 5, 3]),
        'people' => $str('panel:ghost:people'),
        'peopleicon' => drawings::svg('personas', 48),
        'join' => $join,
        'real' => $people->active && $firsts->joincontact ? [
            'count' => $people->active,
            'label' => $str($people->active === 1 ? 'panel:people:active_one' : 'panel:people:active'),
            'text' => $str('panel:ghost:first', (object)['when' => panel::when($firsts->join, $now),
                'who' => panel::who($firsts->joincontact), 'channel' => $names[panel::channel($firsts->joincontact->phone)]]),
        ] : null,
    ];
}

// 3. The boxes, once something has gone out.
$grid = null;
if (!$fresh) {
    $shown = $ready ?: ['whatsapp', 'telegram'];
    $today = panel::today($now);
    $rows = [];
    foreach ($shown as $channel) {
        $t = $today[$channel];
        $row = ['channel' => $short[$channel], 'icon' => $icon($channel), 'name' => $names[$channel],
            'count' => count($shown) > 1 ? $t['sent'] : '', 'whatsapp' => $channel === 'whatsapp' && $t['sent'] > 0];
        if ($row['whatsapp']) {
            $row += ['delivered' => format_float(100 * $t['delivered'] / $t['sent'], 1, false),
                'read' => format_float(100 * $t['read'] / $t['sent'], 1, false),
                'label' => $str('panel:today:funnel', (object)$t), 'nread' => $t['read'], 'ndelivered' => $t['delivered'],
                'nsent' => $t['sent']];
        } else {
            $how = $state->channels[$channel] ?? '';
            $why = in_array($how, ['cap', 'stopped'], true) ? $how : '';
            $row['note'] = $t['sent'] ? $str('panel:today:tgnote') : $str('panel:today:nothing' . ($why ? '_' . $why : ''));
        }
        $rows[] = $row;
    }
    $waits = [];
    foreach (panel::waiting($now) as $group) {
        $drawing = ['blocked' => 'parado', 'retry' => 'reintento', 'quiet' => 'silencio', 'template' => 'revision',
            'cap' => 'tope', 'now' => 'en-marcha'][$group->reason] ?? 'en-marcha';
        $waits[] = [
            'icon' => drawings::svg($drawing, 22, 'mrd-ico'),
            'count' => $group->count,
            'label' => $str('panel:wait:' . $group->reason, $group->name),
            'when' => $group->when ? panel::when($group->when, $now) : $str('panel:wait:' . $group->reason . '_when'),
        ];
    }

    // The month, day by day: thin columns stacked by channel, the busiest day and today labelled.
    $days = panel::days($now);
    $monthshown = $shown;
    $totals = [];
    for ($i = 0; $i < $days['today']; $i++) {
        $totals[$i] = ($days['whatsapp'][$i] ?? 0) + ($days['telegram'][$i] ?? 0);
    }
    $sum = ['whatsapp' => array_sum($days['whatsapp']), 'telegram' => array_sum($days['telegram'])];
    $total = $sum['whatsapp'] + $sum['telegram'];
    $top = max(10, ...$totals);
    $peak = array_search(max($totals), $totals, true);
    $monthstart = (new DateTime('@' . $now))->setTimezone(core_date::get_server_timezone_object())
        ->modify('first day of this month');
    $cols = [];
    $table = [];
    for ($i = 0; $i < $days['length']; $i++) {
        if ($i >= $days['today']) {
            $cols[] = ['future' => true];
            continue;
        }
        $day = (clone $monthstart)->modify('+' . $i . ' days')->getTimestamp();
        $both = count($monthshown) > 1 && $days['whatsapp'][$i] && $days['telegram'][$i];
        $segments = [];
        foreach ($monthshown as $channel) {
            $v = $days[$channel][$i] ?? 0;
            if ($v) {
                $segments[] = ['channel' => $short[$channel], 'height' => format_float(100 * $v / $top, 2, false),
                    'gap' => $both ? 1 : 0, 'n' => $i];
            }
        }
        $label = $i + 1 === $days['today'] ? $str('panel:month:today') : userdate($day, $str('panel:month:day'));
        $parts = [];
        foreach ($monthshown as $channel) {
            $parts[] = $names[$channel] . ': ' . ($days[$channel][$i] ?? 0);
        }
        $cols[] = ['i' => $i, 'segments' => $segments,
            'peak' => $i === $peak && $totals[$i] > 0
                ? ['n' => $totals[$i], 'bottom' => format_float(100 * $totals[$i] / $top, 2, false)] : null,
            'tip' => $label . ' · ' . $str($totals[$i] === 1 ? 'panel:month:notice_one' : 'panel:month:notice_many', $totals[$i])
                . (count($monthshown) > 1 ? ' · ' . implode(' · ', $parts) : '')];
        $cells = [];
        foreach ($monthshown as $channel) {
            $cells[] = ['v' => $days[$channel][$i] ?? 0];
        }
        $table[] = ['day' => userdate($day, $str('panel:month:day')), 'cells' => $cells,
            'total' => count($monthshown) > 1 ? $totals[$i] : null];
    }
    $axis = [];
    for ($i = 0; $i < $days['length']; $i++) {
        $axis[] = ['today' => $i + 1 === $days['today'],
            'label' => $i + 1 === $days['today'] ? $str('panel:month:todayshort') : (in_array($i, [0, 7, 14], true)
                || $i === $days['length'] - 1 ? $i + 1 : '')];
    }
    $legend = [];
    if (count($monthshown) > 1) {
        foreach ($monthshown as $channel) {
            $legend[] = ['channel' => $short[$channel], 'icon' => $icon($channel), 'name' => $names[$channel],
                'count' => $sum[$channel]];
        }
    }
    $monthname = userdate($now, '%B');
    $cost = null;
    if (in_array('whatsapp', $ready, true)) {
        $m = panel::month($now);
        $top2 = $m->cap > 0 ? $m->cap : max($m->likely, $m->spent, 0.01);
        $pct = min(100, $m->spent / $top2 * 100);
        $likely = min(100, $m->likely / $top2 * 100);
        $cost = [
            'spent' => $money($m->spent),
            'cap' => $m->cap > 0 ? $str('panel:month:cap', $money($m->cap)) : $str('panel:month:nocap'),
            'pct' => format_float($pct, 1, false),
            'likely' => $m->likely > $m->spent ? format_float($likely, 1, false) : '',
            'class' => $m->full ? 'full' : ($likely >= 80 && $m->cap > 0 ? 'near' : ''),
            'label' => $str('panel:cost:label', (object)['spent' => $money($m->spent), 'cap' => $m->cap > 0 ? $money($m->cap) : '',
                'likely' => $money($m->likely)]),
            'scale' => $m->cap > 0 ? [$money(0), $money($m->cap / 2), $money($m->cap)] : [],
            'fine' => ($m->full ? $str('panel:cost:full') : $str('panel:cost:likely', $money($m->likely))) . ' '
                . $str('panel:cost:split', (object)['paid' => $m->templates, 'cost' => $money($m->cost), 'free' => $m->free]),
        ];
    }

    // What failed, people, and by notice.
    $failed = [];
    foreach (panel::failed() as $item) {
        $failed[] = ['time' => panel::when($item->time, $now), 'name' => $item->name, 'phone' => $item->phone,
            'icon' => $icon($item->channel), 'why' => $str('panel:why:' . $item->why)];
    }
    $moves = [];
    foreach ($people->moves as $move) {
        $moves[] = ['time' => panel::when($move->time, $now), 'in' => $move->action === 'optin', 'who' => $move->who,
            'how' => $move->how, 'icon' => $icon($move->channel),
            'tag' => $str($move->action === 'optin' ? 'panel:tag:in' : 'panel:tag:out')];
    }
    $split = [];
    $bych = array_intersect_key($people->bychannel, array_flip($shown));
    if (count(array_filter($bych)) > 1) {
        foreach ($shown as $channel) {
            $split[] = ['channel' => $short[$channel], 'icon' => $icon($channel), 'name' => $names[$channel],
                'count' => (int)($bych[$channel] ?? 0)];
        }
    }
    $bynotice = panel::bynotice($now);
    $most = max(1, ...array_merge([1], array_column($bynotice->on, 'count')));
    $items = [];
    foreach ($bynotice->on as $item) {
        $bars = [];
        $sub = [];
        foreach ($shown as $channel) {
            if ($item->$channel) {
                $bars[] = ['channel' => $short[$channel], 'width' => format_float(100 * $item->$channel / $most, 2, false)];
                $sub[] = $str('panel:bynotice:by', (object)['n' => $item->$channel, 'channel' => $names[$channel]]);
            }
        }
        $notice = notices::get($item->component, explode('/', $item->key, 2)[1]);
        $review = in_array('whatsapp', $ready, true) && !$item->whatsapp && $notice && !templates::approved($notice)
            && in_array($item->key, notices::enabled('whatsapp'), true);
        $items[] = ['icon' => drawings::svg($item->icon, 26), 'name' => $item->name,
            'small' => $item->label . (count($shown) > 1 && $sub ? ' · ' . implode(' · ', $sub) : '')
                . ($review ? ' · ' . $str('panel:bynotice:review') : ''),
            'count' => $item->count, 'bars' => $bars, 'empty' => !$bars];
    }
    $offs = [];
    foreach ($bynotice->off as $item) {
        $offs[] = ['icon' => drawings::svg($item->icon, 20), 'name' => $item->name];
    }
    $grid = [
        'today' => [
            'until' => $str('panel:today:until', userdate($now, get_string('strftimetime24', 'langconfig'))),
            'sent' => $today['sent'],
            'sentlabel' => $str($today['sent'] === 1 ? 'panel:today:sent_one' : 'panel:today:sent_many'),
            'failed' => $today['failed'] ? $str(
                $today['failed'] === 1 ? 'panel:today:failed_one' : 'panel:today:failed_many',
                $today['failed']
            ) : '',
            'nofailed' => $str('panel:today:nofailed'),
            'rows' => $rows,
            'waits' => $waits,
            'nowait' => !$waits,
        ],
        'month' => [
            'name' => $str('panel:month:until', $monthname),
            'total' => $total,
            'totallabel' => $str($total === 1 ? 'panel:month:notice' : 'panel:month:notices'),
            'legend' => $legend,
            'haslegend' => (bool)$legend,
            'cols' => $cols,
            'axis' => $axis,
            'label' => $str('panel:month:label', (object)['month' => $monthname, 'total' => $total,
                'day' => $peak + 1, 'n' => $totals[$peak] ?? 0]),
            'heads' => array_map(function ($channel) use ($names) {
                return ['name' => $names[$channel]];
            }, $monthshown),
            'totalhead' => count($monthshown) > 1,
            'table' => $table,
            'cost' => $cost,
            'tgonly' => in_array('telegram', $ready, true) && !in_array('whatsapp', $ready, true),
            'tgtoo' => in_array('telegram', $ready, true) && in_array('whatsapp', $ready, true),
            'tgicon' => $icon('telegram', 26),
            'tgiconsmall' => $icon('telegram'),
        ],
        'failed' => $failed,
        'nofailed' => !$failed,
        'allgood' => drawings::svg('en-marcha', 48),
        'people' => [
            'active' => $people->active,
            'activelabel' => $str($people->active === 1 ? 'panel:people:active_one' : 'panel:people:active'),
            'pending' => $people->pending,
            'pendingclass' => $people->pending ? 'warn' : '',
            'pendinglabel' => $str($people->pending === 1 ? 'panel:people:pending_one' : 'panel:people:pending'),
            'optedout' => $people->optedout,
            'split' => $split,
            'hassplit' => (bool)$split,
            'splitlabel' => implode(', ', array_map(function ($s) {
                return $s['name'] . ': ' . $s['count'];
            }, $split)),
            'moves' => $moves,
            'nomoves' => !$moves,
            'listurl' => $listurl->out(false),
        ],
        'notices' => [
            'total' => $str('panel:bynotice:total', array_sum(array_column($bynotice->on, 'count'))),
            'legend' => count($shown) > 1 && $items ? array_map(function ($channel) use ($short, $icon, $names) {
                return ['channel' => $short[$channel], 'icon' => $icon($channel), 'name' => $names[$channel]];
            }, $shown) : [],
            'items' => $items,
            'hasitems' => (bool)$items,
            'offs' => $offs,
            'offlabel' => $items ? $str('panel:bynotice:off') : $str('panel:bynotice:none'),
            'offcta' => $items ? $str('panel:bynotice:switchon') : $str('panel:hero:choose_cta'),
            'showoff' => (bool)$offs || !$items,
            'noticesurl' => $noticespage->out(false),
        ],
    ];
}

$PAGE->requires->js_amd_inline(<<<'JS'
require([], function() {
    // The month: hovering or the arrow keys tell each day.
    var box = document.querySelector('.local-oksigeniamarandu-board .mrd-days');
    if (!box) {
        return;
    }
    var tip = box.querySelector('.mrd-tip');
    var cols = Array.prototype.slice.call(box.querySelectorAll('.mrd-col:not(.future)'));
    var total = box.querySelectorAll('.mrd-col').length;
    var sel = -1;
    var show = function(i) {
        cols.forEach(function(col, k) {
            col.classList.toggle('sel', k === i);
        });
        if (i < 0) {
            tip.hidden = true;
            return;
        }
        var r = cols[i].getBoundingClientRect();
        var b = box.getBoundingClientRect();
        tip.textContent = cols[i].getAttribute('data-tip');
        tip.hidden = false;
        var x = Math.min(Math.max(r.left - b.left + r.width / 2, tip.offsetWidth / 2), b.width - tip.offsetWidth / 2);
        tip.style.left = x + 'px';
    };
    box.addEventListener('pointermove', function(e) {
        var b = box.getBoundingClientRect();
        var i = Math.floor((e.clientX - b.left) / (b.width / total));
        show(i >= 0 && i < cols.length ? i : -1);
    });
    box.addEventListener('pointerleave', function() {
        show(sel);
    });
    box.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
            e.preventDefault();
            sel = sel < 0 ? cols.length - 1 : Math.max(0, Math.min(cols.length - 1, sel + (e.key === 'ArrowRight' ? 1 : -1)));
            show(sel);
        }
        if (e.key === 'Escape') {
            sel = -1;
            show(-1);
        }
    });
    box.addEventListener('blur', function() {
        sel = -1;
        show(-1);
    });
});
JS
);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniamarandu/panel', [
    'hero' => $hero,
    'path' => $path,
    'pathlabel' => $str('panel:path'),
    'ghosts' => $ghosts,
    'grid' => $grid,
]);
echo $OUTPUT->footer();
