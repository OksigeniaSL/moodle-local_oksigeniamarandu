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
 * Telegram, the second channel: the bot of the site, connected with its token, and a test from the phone of whoever
 * sets it up.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_oksigeniamarandu\api;
use local_oksigeniamarandu\local\api_exception;
use local_oksigeniamarandu\local\consent;
use local_oksigeniamarandu\local\telegram;
use local_oksigeniamarandu\local\telegram_api;

admin_externalpage_setup('local_oksigeniamarandu_telegram', '', null, '', ['nosearch' => true]);

$component = 'local_oksigeniamarandu';
$baseurl = new moodle_url('/local/oksigeniamarandu/telegram.php');
$action = optional_param('action', '', PARAM_ALPHA);
$editing = optional_param('edit', 0, PARAM_BOOL) || !telegram_api::is_configured();
$error = '';
$sent = '';

if ($action === 'connect' && confirm_sesskey()) {
    $token = trim(optional_param('token', '', PARAM_RAW_TRIMMED));
    if (!preg_match('/^[0-9]{5,15}:[A-Za-z0-9_-]{30,}$/', $token)) {
        $error = get_string('telegram:error:token', $component);
    } else {
        try {
            telegram::connect(new telegram_api($token), $token);
            redirect($baseurl);
        } catch (api_exception $e) {
            $error = $e->getMessage();
        }
    }
    $editing = true;
}

// The test: the code of whoever sets it up, so that their own chat joins and gets a message.
$code = api::invite('core', 0, (int)$USER->id);
$chat = $DB->get_record_sql("SELECT c.*
                               FROM {" . consent::LINKS . "} l
                               JOIN {" . consent::CONTACTS . "} c ON c.id = l.contactid
                              WHERE l.userid = :userid AND c.channel = :telegram AND c.status = :active", [
    'userid' => $USER->id, 'telegram' => 'telegram', 'active' => 'active',
], IGNORE_MULTIPLE);
if ($action === 'test' && confirm_sesskey() && $chat) {
    try {
        telegram_api::from_config()->send_text(
            telegram::chat($chat->phone),
            get_string('telegram:test:text', $component, format_string(get_site()->fullname, true, ['escape' => false])),
            (new moodle_url('/local/oksigeniamarandu/index.php'))->out(false),
            get_string('telegram:test:button', $component)
        );
        $sent = get_string('telegram:test:sent', $component);
    } catch (api_exception $e) {
        $error = $e->getMessage();
    }
}

$PAGE->set_heading(get_string('telegram:title', $component));
$PAGE->set_secondary_navigation(false);
$PAGE->blocks->show_only_fake_blocks(true);

// Where this is going: connect the bot, join from the phone, try it.
$steps = ['connect' => !$editing, 'join' => !$editing && $chat, 'test' => $sent !== ''];
$track = [];
$now = true;
$num = 0;
foreach ($steps as $step => $done) {
    $track[] = ['num' => ++$num, 'label' => get_string('telegram:track:' . $step, $component), 'done' => (bool)$done,
        'now' => !$done && $now];
    $now = $now && (bool)$done;
}

$context = [
    'track' => $track,
    'editing' => $editing,
    'action' => $baseurl->out(false),
    'sesskey' => sesskey(),
    'error' => $error,
    'privacy' => get_string('telegram:privacy', $component),
];
if (!$editing) {
    $joinurl = telegram::join_url($code);
    $qr = new core_qrcode($joinurl);
    $svg = $qr->getBarcodeSVGcode(5, 5, '#17212b');
    $svg = substr($svg, strpos($svg, '<svg'));
    $svg = preg_replace('/<svg width="([0-9.]+)" height="([0-9.]+)"/', '<svg viewBox="0 0 $1 $2"', $svg, 1);
    $context += [
        'bot' => '@' . get_config($component, 'telegrambot'),
        'botname' => (string)get_config($component, 'telegramname'),
        'editurl' => (new moodle_url($baseurl, ['edit' => 1]))->out(false),
        'joinurl' => $joinurl,
        'qr' => $svg,
        'joined' => (bool)$chat,
        'sent' => $sent,
    ];
}

$PAGE->requires->js_amd_inline(<<<'JS'
require([], function() {
    var root = document.querySelector('.local-oksigeniamarandu-telegram');
    if (!root) {
        return;
    }
    root.querySelectorAll('[data-copy]').forEach(function(button) {
        button.addEventListener('click', function() {
            navigator.clipboard.writeText(button.getAttribute('data-copy')).then(function() {
                button.textContent = button.getAttribute('data-copied');
            });
        });
    });
    // The form of the token, said as it is pasted: digits, a colon and the rest.
    var input = root.querySelector('#mrd-tgtoken');
    var check = root.querySelector('[data-tokencheck]');
    if (input && check) {
        input.addEventListener('input', function() {
            var value = input.value.trim();
            var ok = /^[0-9]{5,15}:[A-Za-z0-9_-]{30,}$/.test(value);
            check.textContent = value === '' ? '' : check.getAttribute(ok ? 'data-ok' : 'data-bad');
            check.className = 'mrd-tokencheck' + (value === '' ? '' : (ok ? ' ok' : ' bad'));
        });
    }
});
JS
);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniamarandu/telegram', $context);
echo $OUTPUT->footer();
