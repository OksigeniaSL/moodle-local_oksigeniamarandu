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
 * The conversations of the team: where the people of the organisation answer the WhatsApp, over Chatwoot.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniamarandu\external\chat_prefs;
use local_oksigeniamarandu\local\chat\chatwoot;
use local_oksigeniamarandu\local\chat\hours;
use local_oksigeniamarandu\local\chat\message;
use local_oksigeniamarandu\local\chat\viewer;

$conversation = optional_param('c', 0, PARAM_INT);
$tray = optional_param('b', '', PARAM_ALPHANUMEXT);
$aviso = optional_param('aviso', 0, PARAM_INT);

$context = context_system::instance();
$url = new moodle_url('/local/oksigeniamarandu/chat.php', array_filter(['c' => $conversation, 'b' => $tray]));
$PAGE->set_context($context);
$PAGE->set_url($url);
require_login();
require_capability('local/oksigeniamarandu:chat', $context);

$title = get_string('chat:title', 'local_oksigeniamarandu');
$PAGE->set_title($title);
$PAGE->set_heading($title);
$PAGE->add_body_class('local-oksigeniamarandu-chat');

$viewer = null;
$problem = '';
if (!chatwoot::is_configured()) {
    $problem = 'chat:notconnected';
} else {
    try {
        $viewer = viewer::for_user();
        $problem = $viewer ? '' : 'chat:noagent';
    } catch (moodle_exception $e) {
        $problem = 'chat:unreachable';
    }
}

if ($problem !== '') {
    $PAGE->set_pagelayout('standard');
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string($problem, 'local_oksigeniamarandu'), 'info', false);
    echo $OUTPUT->footer();
    die();
}

$PAGE->set_pagelayout('embedded');
$version = (int)get_config('local_oksigeniamarandu', 'version');
$hours = hours::of_site();

// The texts of the page: every string of the plugin that starts with «chatui:», without it.
$strings = [];
$all = get_string_manager()->load_component_strings('local_oksigeniamarandu', current_language());
foreach ($all as $key => $unused) {
    if (strpos($key, 'chatui:') === 0) {
        $strings[substr($key, 7)] = get_string($key, 'local_oksigeniamarandu');
    }
}

$logo = $OUTPUT->get_compact_logo_url(200, 200) ?: $OUTPUT->get_logo_url(200, 200);
$sitename = format_string($SITE->fullname, true, ['context' => $context]);
$brandcolor = (string)get_config('theme_' . $PAGE->theme->name, 'brandcolor');
$colors = array_values(array_filter(array_map('trim', explode(',', (string)get_config('local_oksigeniamarandu', 'chatcolors')))));
if (!$colors && $brandcolor !== '') {
    $colors = [$brandcolor];
}

// The guides in PDF of whoever attends and whoever coordinates (help/guide), in the language of the person if there is one.
$guides = [];
$lang = substr(current_language(), 0, 2);
foreach (['attend' => 'atender', 'coordinate' => 'coordinar'] as $kind => $file) {
    if ($kind === 'coordinate' && !$viewer->seesall) {
        continue;
    }
    foreach (array_unique([$lang, 'es', 'en']) as $one) {
        $path = '/local/oksigeniamarandu/help/guide/guia-chat-' . $file . '-' . $one . '.pdf';
        if (is_readable($CFG->dirroot . $path)) {
            $guides[] = ['kind' => $kind, 'url' => (new moodle_url($path, ['v' => $version]))->out(false)];
            break;
        }
    }
}

$config = [
    'wwwroot' => $CFG->wwwroot,
    'sesskey' => sesskey(),
    'pollms' => 4000,
    'inapp' => core_useragent::is_moodle_app(),
    'me' => [
        'userid' => (int)$USER->id,
        'agentid' => (int)$viewer->agent['id'],
        // First name and first surname, whatever the order of names of the site.
        'name' => trim($USER->firstname . ' ' . strtok((string)$USER->lastname, ' ')),
        'firstname' => (string)$USER->firstname,
        'signature' => $viewer->readonly ? '' : message::signature($viewer),
        'seesall' => $viewer->seesall,
        'picture' => \local_oksigeniamarandu\external\chat_extras::picture($USER),
        'readonly' => $viewer->readonly,
        // What WhatsApp costs and the sends to many that wait: the people the site chose; whoever administers chooses them.
        'spend' => \local_oksigeniamarandu\local\spend::is_person((int)$USER->id),
        'canchoose' => has_capability('moodle/site:config', $context),
    ],
    'brand' => [
        'name' => $sitename,
        'logo' => $logo ? $logo->out(false) : '',
        'initial' => core_text::strtoupper(core_text::substr(trim($sitename), 0, 1)),
        'colors' => $colors,
    ],
    'hours' => $hours->export(),
    'start' => ['conversation' => $conversation, 'inbox' => $tray !== '' ? $tray : 'mine', 'aviso' => $aviso],
    'backgrounds' => chat_prefs::current(),
    'flags' => \local_oksigeniamarandu\external\chat_flag::current(),
    'favs' => \local_oksigeniamarandu\local\chat\agenda::favourites((int)$USER->id),
    'prices' => \local_oksigeniamarandu\local\chat\templates::prices(),
    'guides' => $guides,
    'stages' => array_map(function ($stage) {
        return ['label' => $stage['label'], 'color' => $stage['color'], 'dark' => $stage['dark']];
    }, \local_oksigeniamarandu\local\chat\family::stages()),
    'strings' => $strings,
];

$PAGE->requires->css(new moodle_url('/local/oksigeniamarandu/chat/chat.css', ['v' => $version]));
$PAGE->requires->js(new moodle_url('/local/oksigeniamarandu/chat/bichito.js', ['v' => $version]));
$PAGE->requires->js(new moodle_url('/local/oksigeniamarandu/chat/chat.js', ['v' => $version]));
$PAGE->requires->js_init_code('window.MaranduChat.start(' . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP) . ', ' .
    'window.MaranduChat.moodleTransport(' . json_encode(['wwwroot' => $CFG->wwwroot, 'sesskey' => sesskey()]) . '));');

echo $OUTPUT->header();
echo html_writer::div('', '', ['id' => 'mrd-chat']);
echo $OUTPUT->footer();
