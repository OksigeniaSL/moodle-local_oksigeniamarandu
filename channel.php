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
 * review and of the channel, and what is waiting or went out.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_oksigeniamarandu\local\api_exception;
use local_oksigeniamarandu\local\channel;
use local_oksigeniamarandu\local\panel;
use local_oksigeniamarandu\local\telegram_api;

admin_externalpage_setup('local_oksigeniamarandu_channel', '', null, '', ['nosearch' => true]);

$component = 'local_oksigeniamarandu';
$baseurl = new moodle_url('/local/oksigeniamarandu/channel.php');
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
$PAGE->add_header_action($OUTPUT->render_from_template(
    'local_oksigeniamarandu/panel_links',
    ['links' => panel::links('channel')]
));

$now = time();
$current = channel::current();
$context = [
    'lead' => get_string('channel:lead', $component),
    'saved' => optional_param('saved', 0, PARAM_BOOL) ? get_string('limits:saved', $component) : '',
    'error' => $error,
    'nobot' => !$bot,
    'boturl' => (new moodle_url('/local/oksigeniamarandu/telegram.php'))->out(false),
    'formurl' => $baseurl->out(false),
    'sesskey' => sesskey(),
];

if ($bot && !$current) {
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
    $days = function (string $which) use ($config, $component): array {
        $on = array_filter(explode(',', (string)($config->{$which . 'days'} ?? channel::DEFAULTS[$which . 'days'])), 'strlen');
        $list = [];
        foreach ([1, 2, 3, 4, 5, 6, 0] as $day) {
            $list[] = ['group' => $which, 'value' => $day, 'on' => in_array((string)$day, $on, true),
                'label' => get_string('wizard:limits:day' . $day, $component),
                'name' => get_string(
                    ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'][$day],
                    'calendar'
                )];
        }
        return $list;
    };
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
        'review' => $list("status = 'review'", 'timecreated ASC'),
        'ready' => $list("status = 'ready'", 'timeplanned ASC'),
        'published' => $list("status = 'published'", 'timepublished DESC'),
        'failed' => $list("status = 'failed'", 'timemodified DESC', 5),
    ];
    $context['channel']['empty'] = !$context['channel']['review'] && !$context['channel']['ready']
        && !$context['channel']['published'] && !$context['channel']['failed'];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniamarandu/channel', $context);
echo $OUTPUT->footer();
