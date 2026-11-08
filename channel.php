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
use local_oksigeniamarandu\local\links;
use local_oksigeniamarandu\local\panel;
use local_oksigeniamarandu\local\telegram_api;
use local_oksigeniamarandu\local\weather;

$component = 'local_oksigeniamarandu';
$baseurl = new moodle_url('/local/oksigeniamarandu/channel.php');
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
            // The kinds come as values: Moodle drops the keys of an array that have a slash, as "component/kind" has.
            $policies = [];
            foreach (optional_param_array('policy', [], PARAM_RAW_TRIMMED) as $value) {
                [$key, $policy] = array_pad(explode('|', (string)$value, 2), 2, '');
                $policies[$key] = $policy;
            }
            channel::set_policies($policies);
            foreach (['review', 'post'] as $which) {
                foreach (['from', 'to'] as $end) {
                    $time = optional_param($which . $end, '', PARAM_RAW_TRIMMED);
                    if (preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
                        set_config($which . $end, $time, $component);
                    }
                }
                $days = array_keys(array_filter(optional_param_array($which . 'days', [], PARAM_INT)));
                set_config($which . 'days', implode(',', array_intersect($days, range(0, 6))), $component);
            }
            // The calendar: its addresses, the words of the days without class, the days with class and the hours.
            $address = function (string $name): string {
                $value = optional_param($name, '', PARAM_RAW_TRIMMED);
                return preg_match('~^https?://\S+$~i', $value) ? $value : '';
            };
            set_config('calendarical', $address('calendarical'), $component);
            set_config('calendarlink', $address('calendarlink'), $component);
            set_config('calendarsite', optional_param('calendarsite', 0, PARAM_BOOL) ? 1 : 0, $component);
            set_config('calendarkeywords', trim(optional_param('calendarkeywords', '', PARAM_TEXT)), $component);
            $days = array_keys(array_filter(optional_param_array('classdays', [], PARAM_INT)));
            set_config('classdays', implode(',', array_intersect($days, range(1, 7))), $component);
            foreach (['weekhour', 'evehour'] as $name) {
                $time = optional_param($name, '', PARAM_RAW_TRIMMED);
                if (preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
                    set_config($name, $time, $component);
                }
            }
            // The weather warnings: their source, the areas and the level; and the place of the site.
            set_config('weatherfeed', $address('weatherfeed'), $component);
            set_config('weatherareas', trim(optional_param('weatherareas', '', PARAM_TEXT)), $component);
            $severity = optional_param('weatherseverity', '', PARAM_ALPHA);
            if (in_array($severity, weather::CHOICES, true)) {
                set_config('weatherseverity', $severity, $component);
            }
            foreach (['latitude' => 90, 'longitude' => 180] as $name => $limit) {
                $value = str_replace(',', '.', optional_param($name, '', PARAM_RAW_TRIMMED));
                if ($value === '') {
                    unset_config($name, $component);
                } else if (is_numeric($value) && abs((float)$value) <= $limit) {
                    set_config($name, (string)round((float)$value, 6), $component);
                }
            }
            // The links: the UTM marks and the shortener, whose signature is kept encrypted and never shown.
            set_config('utm', optional_param('utm', 0, PARAM_BOOL) ? 1 : 0, $component);
            foreach (['utmsource', 'utmmedium'] as $name) {
                set_config($name, optional_param($name, '', PARAM_ALPHANUMEXT), $component);
            }
            set_config('shortener', $address('shortener'), $component);
            // The cards: the colour and the name of the organization, whether they move, and where ffmpeg is.
            $colour = optional_param('cardcolor', '', PARAM_RAW_TRIMMED);
            set_config('cardcolor', preg_match('/^#[0-9a-f]{6}$/i', $colour) && strcasecmp($colour, cards::brand()) !== 0
                ? $colour : (string)get_config($component, 'cardcolor'), $component);
            if (optional_param('cardcolordefault', 0, PARAM_BOOL)) {
                unset_config('cardcolor', $component);
            }
            set_config('cardorg', trim(optional_param('cardorg', '', PARAM_TEXT)), $component);
            set_config('cardanimated', optional_param('cardanimated', 0, PARAM_BOOL) ? 1 : 0, $component);
            $ffmpeg = optional_param('ffmpeg', '', PARAM_RAW_TRIMMED);
            set_config('ffmpeg', preg_match('~^/[\w./-]+$~', $ffmpeg) ? $ffmpeg : '', $component);
            $signature = optional_param('shortenersignature', '', PARAM_RAW_TRIMMED);
            if ($signature !== '') {
                set_config('shortenersignature', \core\encryption::encrypt($signature), $component);
            } else if ($address('shortener') === '') {
                unset_config('shortenersignature', $component);
            }
            redirect(new moodle_url($baseurl, ['saved' => 1]));
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
$context = [
    'lead' => get_string('channel:lead', $component),
    'saved' => optional_param('saved', 0, PARAM_BOOL) ? get_string('limits:saved', $component) : '',
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
        foreach (channel::POLICIES as $option) {
            $choices[] = ['value' => $kind->key . '|' . $option, 'id' => 'mrd-p-' . md5($kind->key) . '-' . $option,
                'label' => get_string('channel:policy:' . $option, $component), 'on' => $policy === $option];
        }
        $kinds[] = ['name' => channel::name($kind), 'desc' => channel::description($kind), 'choices' => $choices,
            'field' => 'mrd-p-' . md5($kind->key)];
    }
    $post = function (stdClass $row) use ($component, $now): array {
        $content = json_decode($row->content, true) ?: [];
        $kind = channel::kinds()[$row->component . '/' . $row->kind] ?? (object)['component' => $row->component,
            'kind' => $row->kind];
        $time = $row->timepublished ?: $row->timeplanned;
        return [
            'id' => $row->id,
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
        'reviewfrom' => $config->reviewfrom ?? channel::DEFAULTS['reviewfrom'],
        'reviewto' => $config->reviewto ?? channel::DEFAULTS['reviewto'],
        'reviewdays' => $days('review'),
        'postfrom' => $config->postfrom ?? channel::DEFAULTS['postfrom'],
        'postto' => $config->postto ?? channel::DEFAULTS['postto'],
        'postdays' => $days('post'),
        'calendarical' => (string)($config->calendarical ?? ''),
        'calendarsite' => !empty($config->calendarsite),
        'calendarlink' => (string)($config->calendarlink ?? ''),
        'calendarkeywords' => isset($config->calendarkeywords) ? (string)$config->calendarkeywords
            : get_string('channel:calendar:keywordsdefault', $component),
        'classdays' => $days('class', calendar::DEFAULTS['classdays'], true),
        'weekhour' => $config->weekhour ?? calendar::DEFAULTS['weekhour'],
        'evehour' => $config->evehour ?? calendar::DEFAULTS['evehour'],
        'weatherfeed' => (string)($config->weatherfeed ?? ''),
        'weatherareas' => (string)($config->weatherareas ?? ''),
        'weatherlevels' => array_map(function (string $level) use ($config, $component): array {
            return ['value' => $level, 'id' => 'mrd-wl-' . strtolower($level),
                'label' => get_string('channel:weather:' . strtolower($level), $component),
                'on' => ($config->weatherseverity ?? weather::DEFAULTSEVERITY) === $level];
        }, weather::CHOICES),
        'weatherstatus' => $weatherstatus,
        'latitude' => (string)($config->latitude ?? ''),
        'longitude' => (string)($config->longitude ?? ''),
        'utm' => !empty($config->utm),
        'utmsource' => (string)($config->utmsource ?? links::DEFAULTS['utmsource']),
        'utmmedium' => (string)($config->utmmedium ?? links::DEFAULTS['utmmedium']),
        'shortener' => (string)($config->shortener ?? ''),
        'hassignature' => !empty($config->shortenersignature),
        'cardcolor' => cards::brand(),
        'cardorg' => (string)($config->cardorg ?? ''),
        'cardorgdefault' => format_string($SITE->fullname),
        'cardanimated' => ($config->cardanimated ?? '1') !== '0',
        'ffmpeg' => (string)($config->ffmpeg ?? ''),
        'cardstatus' => !cards::can_draw() ? get_string('channel:cards:none', $component)
            : (cards::ffmpeg() ? get_string('channel:cards:animatedok', $component, cards::ffmpeg())
            : get_string('channel:cards:stillonly', $component)),
        'previewurl' => (new moodle_url($baseurl, ['preview' => 1]))->out(false) . '#mrd-cards',
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
            $sample = cards::template($type)['sample'];
            unset($sample['org'], $sample['cover']);
            $card = cards::clean(['type' => $type, 'zones' => $sample]);
            $previews[] = ['alt' => (string)($sample['kicker'] ?? $type),
                'src' => 'data:image/jpeg;base64,' . base64_encode(cards::still($card))];
        }
        $context['channel']['previews'] = $previews;
    }
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniamarandu/channel', $context);
echo $OUTPUT->footer();
