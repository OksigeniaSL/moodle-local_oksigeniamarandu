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
 * The channel of Telegram of the site: which one, what goes there by itself and what is reviewed first, the hours of
 * review and of the channel, and what is waiting or went out. Whoever may review (local/oksigeniamarandu:channel) sees
 * what is waiting and decides it here too; the rest is for whoever runs the site.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_oksigeniamarandu\local\api_exception;
use local_oksigeniamarandu\local\calendar;
use local_oksigeniamarandu\local\cards;
use local_oksigeniamarandu\local\channel;
use local_oksigeniamarandu\local\gestures;
use local_oksigeniamarandu\local\links;
use local_oksigeniamarandu\local\panel;
use local_oksigeniamarandu\local\sky;
use local_oksigeniamarandu\local\telegram_api;
use local_oksigeniamarandu\local\weather;
use local_oksigeniamarandu\local\work;

$component = 'local_oksigeniamarandu';
$baseurl = new moodle_url('/local/oksigeniamarandu/channel.php');
// The cards of the settings, each saved on its own: what goes to the channel, its day, and what is set once.
$parts = ['what', 'day', 'calendar', 'weather', 'place', 'links', 'cards'];
$system = context_system::instance();
$canconfig = has_capability('moodle/site:config', $system);
if ($canconfig) {
    admin_externalpage_setup('local_oksigeniamarandu_channel', '', null, '', ['nosearch' => true]);
} else {
    // Whoever reviews without running the site: the page is not in the administration they see.
    require_login();
    require_capability('local/oksigeniamarandu:channel', $system);
    $PAGE->set_context($system);
    $PAGE->set_url($baseurl);
    $PAGE->set_pagelayout('admin');
    $PAGE->set_title(get_string('channel:title', $component));
}
$action = optional_param('action', '', PARAM_ALPHA);
$error = '';
$bot = null;
try {
    $bot = telegram_api::is_configured() ? telegram_api::from_config() : null;
} catch (api_exception $e) {
    $error = $e->getMessage();
}

if ($action !== '' && confirm_sesskey()) {
    try {
        if (!$canconfig && !in_array($action, ['publish', 'skip'], true)) {
            throw new required_capability_exception($system, 'moodle/site:config', 'nopermissions', '');
        }
        if ($action === 'connect' && $bot) {
            channel::connect($bot, optional_param('chat', '', PARAM_RAW_TRIMMED));
            redirect(new moodle_url($baseurl, ['saved' => 1]));
        } else if ($action === 'disconnect') {
            channel::disconnect();
            redirect($baseurl);
        } else if ($action === 'save') {
            // Each card of the page sends only what it has, and saves itself at a touch: what does not come stays as it
            // was. A switch or a day carries what it has now as a hidden field and, after it, a button with the same name
            // and the value it changes to; PHP keeps the last one.
            $part = optional_param('part', '', PARAM_ALPHA);
            $posted = function (string $name): ?string {
                return optional_param($name, null, PARAM_RAW_TRIMMED);
            };
            // The kinds come as values: Moodle drops the keys of an array that have a slash, as "component/kind" has.
            $policies = [];
            foreach (optional_param_array('policy', [], PARAM_RAW_TRIMMED) as $value) {
                [$key, $policy] = array_pad(explode('|', (string)$value, 2), 2, '');
                $policies[$key] = $policy;
            }
            if ($policies) {
                channel::set_policies($policies);
            }
            $times = ['reviewfrom', 'reviewto', 'postfrom', 'postto', 'weekhour', 'evehour'];
            foreach ($times as $name) {
                $time = $posted($name);
                if ($time !== null && preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
                    set_config($name, $time, $component);
                }
            }
            // The days of review and of the channel count from Sunday as 0; the days with class, from Monday as 1 to Sunday as 7.
            foreach (['reviewdays' => range(0, 6), 'postdays' => range(0, 6), 'classdays' => range(1, 7)] as $name => $range) {
                $days = optional_param_array($name, null, PARAM_INT);
                if ($days !== null) {
                    set_config($name, implode(',', array_intersect(array_keys(array_filter($days)), $range)), $component);
                }
            }
            // The pace of the channel: the hours between two posts, among those offered, and the most in a day and in a week.
            $gap = $posted('postgap');
            if (in_array($gap, array_map('strval', channel::GAPS), true)) {
                set_config('postgap', $gap, $component);
            }
            foreach (['postperday' => 50, 'postperweek' => 300] as $name => $most) {
                $value = $posted($name);
                if ($value !== null && preg_match('/^\d+$/', $value)) {
                    set_config($name, min((int)$value, $most), $component);
                }
            }
            // The switches: the events of the site, the marks of the links and the cards that move.
            foreach (['calendarsite', 'utm', 'cardanimated'] as $name) {
                if ($posted($name) !== null) {
                    set_config($name, optional_param($name, 0, PARAM_BOOL) ? 1 : 0, $component);
                }
            }
            // The addresses: the calendar, the link to add it, the source of the warnings, the elements of the station and the
            // shortener; an address that is not one is left empty.
            foreach (['calendarical', 'calendarlink', 'weatherfeed', 'skytle', 'shortener'] as $name) {
                $value = $posted($name);
                if ($value !== null) {
                    set_config($name, preg_match('~^https?://\S+$~i', $value) ? $value : '', $component);
                }
            }
            // What is written: the words of the days without class, the areas and the name of the organization on the cards.
            foreach (['calendarkeywords', 'weatherareas', 'cardorg'] as $name) {
                if ($posted($name) !== null) {
                    set_config($name, trim(optional_param($name, '', PARAM_TEXT)), $component);
                }
            }
            foreach (['utmsource', 'utmmedium'] as $name) {
                if ($posted($name) !== null) {
                    set_config($name, optional_param($name, '', PARAM_ALPHANUMEXT), $component);
                }
            }
            // The weather warnings from a level.
            $severity = $posted('weatherseverity');
            if (in_array($severity, weather::CHOICES, true)) {
                set_config('weatherseverity', $severity, $component);
            }
            // The place of the site: empty, it is taken away.
            foreach (['latitude' => 90, 'longitude' => 180] as $name => $limit) {
                $value = $posted($name);
                if ($value === null) {
                    continue;
                }
                $value = str_replace(',', '.', $value);
                if ($value === '') {
                    unset_config($name, $component);
                } else if (is_numeric($value) && abs((float)$value) <= $limit) {
                    set_config($name, (string)round((float)$value, 6), $component);
                }
            }
            // The signature of the shortener is kept encrypted and never shown; without a shortener, it goes.
            $signature = $posted('shortenersignature');
            if ($signature !== null && $signature !== '') {
                set_config('shortenersignature', \core\encryption::encrypt($signature), $component);
            } else if ($posted('shortener') !== null && !get_config($component, 'shortener')) {
                unset_config('shortenersignature', $component);
            }
            // The cards: the colour of the organization, or that of the theme again; and where ffmpeg is.
            $colour = $posted('cardcolor');
            if ($colour !== null && preg_match('/^#[0-9a-f]{6}$/i', $colour) && strcasecmp($colour, cards::brand()) !== 0) {
                set_config('cardcolor', $colour, $component);
            }
            if (optional_param('cardcolordefault', 0, PARAM_BOOL)) {
                unset_config('cardcolor', $component);
            }
            $ffmpeg = $posted('ffmpeg');
            if ($ffmpeg !== null) {
                set_config('ffmpeg', preg_match('~^/[\w./-]+$~', $ffmpeg) ? $ffmpeg : '', $component);
            }
            // Back to the card that was touched, open, with its «Saved».
            $part = in_array($part, $parts, true) ? $part : '';
            redirect(new moodle_url($baseurl, ['saved' => $part ?: 1], $part ? 'mrd-' . $part : null));
        } else if (in_array($action, ['publish', 'skip'], true)) {
            $post = $DB->get_record(channel::TABLE, ['id' => required_param('post', PARAM_INT)], '*', MUST_EXIST);
            channel::decide($post, $action === 'publish', (int)$USER->id, $bot);
            redirect($baseurl);
        }
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

$PAGE->set_heading(get_string('channel:title', $component));
$PAGE->set_secondary_navigation(false);
$PAGE->blocks->show_only_fake_blocks(true);
if ($canconfig) {
    $PAGE->add_header_action($OUTPUT->render_from_template(
        'local_oksigeniamarandu/panel_links',
        ['links' => panel::links('channel')]
    ));
}

$now = time();
$current = channel::current();
// The card just saved, «1» for the channel just connected.
$saved = optional_param('saved', '', PARAM_ALPHANUM);
$context = [
    'lead' => get_string('channel:lead', $component),
    'saved' => $saved === '1' ? get_string('limits:saved', $component) : '',
    'error' => $error,
    'canconfig' => $canconfig,
    'nobot' => !$bot,
    'boturl' => (new moodle_url('/local/oksigeniamarandu/telegram.php'))->out(false),
    'formurl' => $baseurl->out(false),
    'sesskey' => sesskey(),
];

if ($bot && !$current && $canconfig) {
    $candidate = channel::candidate();
    $context['connect'] = [
        'botname' => '@' . get_config($component, 'telegrambot'),
        'candidate' => $candidate ? ['id' => $candidate->id, 'title' => $candidate->title,
            'username' => $candidate->username === '' ? '' : '@' . $candidate->username] : null,
    ];
}

if ($current) {
    $members = channel::members($now);
    $reviewers = channel::reviewers();
    $names = [];
    foreach (array_keys($reviewers) as $userid) {
        $names[] = fullname(core_user::get_user($userid));
    }
    $config = get_config($component);
    $days = function (string $which, ?string $default = null, bool $iso = false) use ($config, $component): array {
        $default = $default ?? channel::DEFAULTS[$which . 'days'];
        $on = array_filter(explode(',', (string)($config->{$which . 'days'} ?? $default)), 'strlen');
        $list = [];
        foreach ([1, 2, 3, 4, 5, 6, 0] as $day) {
            // The days with class count from Monday as 1 to Sunday as 7; the hours, from Sunday as 0.
            $value = $iso && $day === 0 ? 7 : $day;
            $list[] = ['group' => $which, 'value' => $value, 'on' => in_array((string)$value, $on, true),
                'label' => get_string('wizard:limits:day' . $day, $component),
                'name' => get_string(
                    ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'][$day],
                    'calendar'
                )];
        }
        return $list;
    };
    // What the last reading of the weather warnings said.
    $weatherstatus = '';
    if (weather::is_set()) {
        $last = weather::last();
        if (!$last) {
            $weatherstatus = get_string('channel:weather:never', $component);
        } else if ($last->source === null) {
            $weatherstatus = get_string('channel:weather:failed', $component, panel::when((int)$last->time, $now));
        } else {
            $weatherstatus = get_string('channel:weather:read', $component, (object)['when' => panel::when((int)$last->time, $now),
                'source' => (int)$last->source, 'area' => (int)$last->area]);
        }
    }
    $kinds = [];
    foreach (channel::kinds() as $kind) {
        $policy = channel::policy($kind->key);
        $choices = [];
        foreach (array_diff(channel::POLICIES, $kind->always ? ['auto'] : []) as $option) {
            $choices[] = ['value' => $kind->key . '|' . $option, 'id' => 'mrd-p-' . md5($kind->key) . '-' . $option,
                'label' => get_string('channel:policy:' . $option, $component), 'on' => $policy === $option];
        }
        // The card of its posts, small, with the colour and the name of the site.
        $kinds[] = ['name' => channel::name($kind), 'desc' => channel::description($kind), 'choices' => $choices,
            'field' => 'mrd-p-' . md5($kind->key),
            'thumb' => $kind->card !== '' && $canconfig ? cards::thumbnail(cards::sample($kind->card), 144) : null];
    }
    $post = function (stdClass $row) use ($component, $now): array {
        $content = json_decode($row->content, true) ?: [];
        $kind = channel::kinds()[$row->component . '/' . $row->kind] ?? (object)['component' => $row->component,
            'kind' => $row->kind];
        $time = $row->timepublished ?: $row->timeplanned;
        // What goes to the channel, to see it before saying yes: its image, or its card drawn now.
        $thumb = $full = null;
        if ($row->status === 'review') {
            // The card, once made, is its image; until then, it is drawn now, if the server can.
            $card = !empty($content['card']) && empty($content['cardhash']);
            $thumb = $card ? cards::thumbnail($content['card'], 320) : null;
            $full = $card ? cards::thumbnail($content['card'], 960) : null;
            if ($thumb === null && !empty($content['image'])) {
                $thumb = $full = (string)$content['image'];
            }
        }
        // When it goes out if it is said yes, after its kind.
        $at = channel::goes_at($row, $now);
        if ($at === null) {
            $when = get_string('channel:review:nofit', $component);
        } else {
            $when = $at <= $now ? get_string('channel:review:now', $component) : work::when($at, $now);
        }
        $goes = get_string('channel:review:goes', $component, (object)['kind' => channel::name($kind), 'when' => $when]);
        return [
            'id' => $row->id,
            'goes' => $goes,
            'thumb' => $thumb,
            'full' => $full,
            'title' => (string)($content['title'] ?? ($content['text'][0] ?? '')),
            'kind' => channel::name($kind),
            'when' => panel::when((int)$time, $now),
            'clicks' => (int)$row->clicks ? get_string('channel:clicks', $component, (int)$row->clicks) : '',
            'error' => (string)$row->error,
            'urgent' => (bool)$row->urgent,
        ];
    };
    $list = function (string $where, string $sort, int $limit = 10) use ($DB, $post): array {
        return array_values(array_map($post, $DB->get_records_select(channel::TABLE, $where, null, $sort, '*', 0, $limit)));
    };
    // The settings, as gestures of the channel: each card saves itself, and the one just saved comes back open, with its
    // «Saved» (Jujol). Hours are plates with half an hour less and more; a number, one less and one more.
    $join = function (array $list) use ($component): string {
        $last = array_pop($list);
        return $list ? get_string('channel:and', $component, (object)['list' => implode(', ', $list), 'last' => $last])
            : (string)$last;
    };
    $host = function (string $url): string {
        return preg_replace('/^www\./', '', (string)parse_url($url, PHP_URL_HOST));
    };
    // The cards drawn small, only for whoever sees the settings.
    $thumb = function (string $type, int $px) use ($canconfig): ?string {
        return $canconfig && cards::can_draw() ? cards::thumbnail(cards::sample($type), $px) : null;
    };
    $folded = function (string $key) use ($saved): array {
        return ['key' => $key, 'saved' => $saved === $key, 'open' => $saved === $key];
    };

    // The day of the channel: the time of review, that of the channel and the pace, with a busy day drawn on the ruler.
    $reviewfrom = $config->reviewfrom ?? channel::DEFAULTS['reviewfrom'];
    $reviewto = $config->reviewto ?? channel::DEFAULTS['reviewto'];
    $postfrom = $config->postfrom ?? channel::DEFAULTS['postfrom'];
    $postto = $config->postto ?? channel::DEFAULTS['postto'];
    $gap = (float)($config->postgap ?? channel::PACE['postgap']);
    $perday = (int)($config->postperday ?? channel::PACE['postperday']);
    $perweek = (int)($config->postperweek ?? channel::PACE['postperweek']);
    $gaps = array_map(function (float $hours) use ($gap, $component): array {
        if ($hours == 0) {
            $label = get_string('channel:pace:nogap', $component);
        } else if ($hours < 1) {
            $label = get_string('numminutes', 'moodle', (int)round($hours * 60));
        } else {
            $label = get_string($hours == 1 ? 'channel:pace:hour' : 'channel:pace:hours', $component, (int)$hours);
        }
        return ['value' => (string)$hours, 'label' => $label, 'on' => $gap === $hours];
    }, channel::GAPS);
    $first = gestures::minutes($postfrom);
    $last = gestures::minutes($postto) + (gestures::minutes($postto) <= $first ? 1440 : 0);
    $step = (int)round($gap * 60);
    $most = min($perday ?: 12, $perweek ?: 12, $step ? 12 : 5);
    $planes = [];
    for ($at = $first; $at <= $last && count($planes) < $most; $at += $step) {
        $planes[] = ['a' => round(($at % 1440) / 14.4, 3), 'n' => count($planes), 'time' => gestures::clock($at % 1440)];
    }
    $times = array_column($planes, 'time');
    if (!$step) {
        $caption = $perday ? get_string('channel:day:togethercap', $component, $perday)
            : get_string('channel:day:together', $component);
    } else if (count($times) > 5) {
        $gaplabel = '';
        foreach ($gaps as $one) {
            $gaplabel = $one['on'] ? $one['label'] : $gaplabel;
        }
        // «One every hour», not «one every 1 hour».
        $caption = get_string($step === 60 ? 'channel:day:everyhour' : 'channel:day:every', $component,
            (object)['gap' => $gaplabel, 'from' => $times[0], 'to' => end($times)]);
    } else {
        $caption = get_string('channel:day:times', $component, $join($times));
    }
    $postdays = $days('post');
    $daysout = count(array_filter(array_column($postdays, 'on')));
    if ($perweek && $perweek < count($times) * $daysout) {
        $caption .= ' ' . get_string('channel:day:weekcap', $component, $perweek);
    }
    $day = $folded('day') + [
        'marks' => gestures::marks(),
        'reviewspans' => gestures::spans($reviewfrom, $reviewto),
        'postspans' => gestures::spans($postfrom, $postto),
        'planes' => $planes,
        'together' => !$step,
        'caption' => $caption,
        'reviewfrom' => gestures::plate('reviewfrom', $reviewfrom, get_string('wizard:limits:from', $component), true),
        'reviewto' => gestures::plate('reviewto', $reviewto, get_string('wizard:limits:to', $component), true),
        'reviewdays' => $days('review'),
        'postfrom' => gestures::plate('postfrom', $postfrom, get_string('wizard:limits:from', $component), true),
        'postto' => gestures::plate('postto', $postto, get_string('wizard:limits:to', $component), true),
        'postdays' => $postdays,
        'gap' => (string)$gap,
        'gaps' => $gaps,
        'perday' => gestures::stepper('postperday', $perday, 50, get_string('channel:day:perday', $component)),
        'perweek' => gestures::stepper('postperweek', $perweek, 300, get_string('channel:day:perweek', $component)),
    ];

    // The calendar: where it comes from, the days with class as the sheets of a week, and the two posts it makes.
    $ical = (string)($config->calendarical ?? '');
    $site = !empty($config->calendarsite);
    if ($ical !== '') {
        $source = get_string($site ? 'channel:calendar:fromsite' : 'channel:calendar:from', $component, $host($ical));
    } else {
        $source = get_string($site ? 'channel:calendar:siteonly' : 'channel:calendar:nosource', $component);
    }
    $weekhour = $config->weekhour ?? calendar::DEFAULTS['weekhour'];
    $evehour = $config->evehour ?? calendar::DEFAULTS['evehour'];
    $calendarcard = $folded('calendar') + [
        'face' => $thumb('this_week', 144),
        'line' => $ical === '' && !$site ? $source : get_string('channel:calendar:line', $component, (object)['source' => $source,
            'week' => $weekhour, 'eve' => $evehour]),
        'nosource' => $ical === '' && !$site,
        'classdays' => $days('class', calendar::DEFAULTS['classdays'], true),
        'site' => $site,
        'weekthumb' => $thumb('this_week', 144),
        'evethumb' => $thumb('no_class', 144),
        'weekhour' => gestures::plate('weekhour', $weekhour, get_string('channel:calendar:weekhour', $component)),
        'evehour' => gestures::plate('evehour', $evehour, get_string('channel:calendar:evehour', $component)),
        'ical' => $ical,
        'link' => (string)($config->calendarlink ?? ''),
        'keywords' => isset($config->calendarkeywords) ? (string)$config->calendarkeywords
            : get_string('channel:calendar:keywordsdefault', $component),
    ];

    // The weather warnings: the source and the areas, written; the level, as the plates of the card.
    $feed = (string)($config->weatherfeed ?? '');
    $severity = $config->weatherseverity ?? weather::DEFAULTSEVERITY;
    $chosen = array_search($severity, weather::CHOICES, true);
    $levels = [];
    foreach (weather::CHOICES as $index => $level) {
        $levels[] = ['value' => $level, 'colour' => strtolower($level), 'on' => $level === $severity,
            'lit' => $index >= $chosen, 'label' => get_string('channel:weather:' . strtolower($level), $component)];
    }
    $areas = (string)($config->weatherareas ?? '');
    $weathercard = $folded('weather') + [
        'face' => $thumb('weather', 144),
        'line' => $feed === '' ? get_string('channel:weather:nofeed', $component)
            : get_string('channel:weather:line', $component, (object)['source' => $host($feed),
                'level' => get_string('channel:weather:' . strtolower($severity), $component)])
                . ($areas !== '' ? ' · ' . $areas : ''),
        'nofeed' => $feed === '',
        'levels' => $levels,
        'severity' => $severity,
        'status' => $weatherstatus,
        'feed' => $feed,
        'areas' => $areas,
    ];

    // The place and its sky: the place is written (or taken from the device); the sky comes from it.
    $place = weather::place();
    $latitude = (string)($config->latitude ?? '');
    $longitude = (string)($config->longitude ?? '');
    $sky = $place ? sky::summary($now) : [];
    $placecard = $folded('place') + [
        'face' => $thumb('sky', 144),
        'line' => $place ? get_string('channel:place:coords', $component, (object)[
                'lat' => format_float(abs((float)$latitude), 4), 'ns' => get_string((float)$latitude < 0
                    ? 'channel:place:s' : 'channel:place:n', $component),
                'lon' => format_float(abs((float)$longitude), 4), 'ew' => get_string((float)$longitude < 0
                    ? 'channel:place:w' : 'channel:place:e', $component),
            ]) . ($sky ? ' · ' . $sky[0] : '')
            : get_string('channel:sky:noplace', $component),
        'noplace' => !$place,
        'sky' => $sky,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'tle' => (string)($config->skytle ?? ''),
        'tledefault' => sky::DEFAULTTLE,
    ];

    // The links: whether they are measured, with an example of where a button goes, and the shortener.
    $utm = !empty($config->utm);
    $utmsource = (string)($config->utmsource ?? links::DEFAULTS['utmsource']);
    $utmmedium = (string)($config->utmmedium ?? links::DEFAULTS['utmmedium']);
    $shortener = (string)($config->shortener ?? '');
    $linkscard = $folded('links') + [
        'line' => ($utm ? get_string('channel:links:lineutm', $component, $utmsource . ' · ' . $utmmedium)
            : get_string('channel:links:linenoutm', $component)) . ' · '
            . ($shortener !== '' ? get_string('channel:links:lineshort', $component, $host($shortener))
            : get_string('channel:links:linenoshort', $component)),
        'utm' => $utm,
        'example' => $utm ? $CFG->wwwroot . '/?utm_source=' . rawurlencode($utmsource) . '&utm_medium='
            . rawurlencode($utmmedium) . '&utm_campaign=open_day' : '',
        'utmsource' => $utmsource,
        'utmmedium' => $utmmedium,
        'shortener' => $shortener,
        'hassignature' => !empty($config->shortenersignature),
    ];

    // The cards: the colour, that of the theme or one of its own; the name on them; whether they move.
    $theme = null;
    foreach ([get_config('theme_' . $CFG->theme, 'brandcolor'), get_config('theme_boost', 'brandcolor')] as $colour) {
        if ($theme === null && is_string($colour) && preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($colour))) {
            $theme = '#' . ltrim(trim($colour), '#');
        }
    }
    $own = (string)($config->cardcolor ?? '') !== '';
    $animated = ($config->cardanimated ?? '1') !== '0';
    $org = cards::org();
    if (!cards::can_draw()) {
        $how = get_string('channel:cards:hownone', $component);
    } else {
        $how = get_string($animated && cards::ffmpeg() ? 'channel:cards:howanimated' : 'channel:cards:howstill', $component);
    }
    $cardscard = $folded('cards') + [
        'line' => $org . ' · ' . $how,
        'colour' => cards::brand(),
        'theme' => $theme ?? cards::DEFAULTCOLOR,
        'own' => $own,
        'sample' => $thumb('this_week', 640),
        'org' => (string)($config->cardorg ?? ''),
        'orgdefault' => format_string($SITE->fullname),
        'animated' => $animated,
        'ffmpeg' => (string)($config->ffmpeg ?? ''),
        'status' => !cards::can_draw() ? get_string('channel:cards:none', $component)
            : (cards::ffmpeg() ? get_string('channel:cards:animatedok', $component, cards::ffmpeg())
            : get_string('channel:cards:stillonly', $component)),
        'previewurl' => (new moodle_url($baseurl, ['preview' => 1]))->out(false) . '#mrd-cards',
    ];
    $context['channel'] = [
        'title' => $current->title,
        'username' => $current->username === '' ? '' : '@' . $current->username,
        'url' => $current->username === '' ? '' : 'https://t.me/' . $current->username,
        'problem' => $current->problem !== '' ? get_string('channel:problem', $component) : '',
        'members' => $members ? get_string('channel:members', $component, $members->now) : '',
        'growth' => $members && $members->before !== null ? get_string(
            'channel:growth',
            $component,
            ($members->now - $members->before >= 0 ? '+' : '') . ($members->now - $members->before)
        ) : '',
        'reviewers' => $names ? get_string('channel:reviewers', $component, implode(', ', $names))
            : get_string('channel:noreviewers', $component),
        'noreviewers' => !$names,
        'kinds' => $kinds,
        'whatsaved' => $saved === 'what',
        'day' => $day,
        'calendar' => $calendarcard,
        'weather' => $weathercard,
        'place' => $placecard,
        'links' => $linkscard,
        'cards' => $cardscard,
        'review' => $list("status = 'review'", 'timecreated ASC'),
        'ready' => $list("status = 'ready'", 'timeplanned ASC'),
        'published' => $list("status = 'published'", 'timepublished DESC'),
        'failed' => $list("status = 'failed'", 'timemodified DESC', 5),
    ];
    $context['channel']['empty'] = !$context['channel']['review'] && !$context['channel']['ready']
        && !$context['channel']['published'] && !$context['channel']['failed'];
    // The seven cards with the colour and the name of the site, from the example texts of each template.
    if ($canconfig && optional_param('preview', 0, PARAM_BOOL) && cards::can_draw()) {
        $previews = [];
        foreach (cards::TYPES as $type) {
            $card = cards::sample($type);
            $previews[] = ['alt' => (string)($card['zones']['kicker'] ?? $type),
                'src' => 'data:image/jpeg;base64,' . base64_encode(cards::still($card))];
        }
        $context['channel']['previews'] = $previews;
        $context['channel']['cards']['open'] = true;
    }
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniamarandu/channel', $context);
echo $OUTPUT->footer();
