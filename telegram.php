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
 * Telegram, the second channel, in three moments, from the mock-up of the house: connect the bot of the site with its
 * token; join from the phone, which the page notices by itself; and a test message, with how a notice arrives. Changing
 * the bot goes back to the first moment, and asks before leaving the people of the old bot waiting for the new one.
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
use local_oksigeniamarandu\local\keywords;
use local_oksigeniamarandu\local\telegram;
use local_oksigeniamarandu\local\telegram_api;
use local_oksigeniamarandu\task\send_reply;

admin_externalpage_setup('local_oksigeniamarandu_telegram', '', null, '', ['nosearch' => true]);

$component = 'local_oksigeniamarandu';
$baseurl = new moodle_url('/local/oksigeniamarandu/telegram.php');
$action = optional_param('action', '', PARAM_ALPHA);
$configured = telegram_api::is_configured();
$editing = optional_param('edit', 0, PARAM_BOOL) || !$configured;
$error = '';
$sent = 0;
$confirm = null;

// The chat of whoever sets it up, joined with their own code.
$code = api::invite('core', 0, (int)$USER->id);
$mychat = function () use ($DB, $USER) {
    return $DB->get_record_sql("SELECT c.*
                                  FROM {" . consent::LINKS . "} l
                                  JOIN {" . consent::CONTACTS . "} c ON c.id = l.contactid
                                 WHERE l.userid = :userid AND c.channel = :telegram AND c.status = :active", [
        'userid' => $USER->id, 'telegram' => 'telegram', 'active' => 'active',
    ], IGNORE_MULTIPLE);
};

// What the page asks every few seconds while it waits for the «Start» of the phone.
if ($action === 'status') {
    $chat = $mychat();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['joined' => (bool)$chat, 'name' => $chat ? (string)$chat->name : '']);
    die();
}

if ($action === 'connect' && confirm_sesskey()) {
    $token = trim(optional_param('token', '', PARAM_RAW_TRIMMED));
    $editing = true;
    if (!preg_match('/^[0-9]{5,15}:[A-Za-z0-9_-]{30,}$/', $token)) {
        $error = get_string('telegram:error:token', $component);
    } else {
        try {
            $newapi = new telegram_api($token);
            $bot = $newapi->get_me();
            if ($configured && !telegram::is_same_bot($bot) && telegram::joined()) {
                // Another bot, and people joined the one of now: ask first. The token waits encrypted, not in the page.
                set_config('telegrampending', json_encode([
                    'token' => \core\encryption::encrypt($token),
                    'name' => (string)($bot['first_name'] ?? ''),
                    'username' => (string)($bot['username'] ?? ''),
                ]), $component);
                redirect(new moodle_url($baseurl, ['edit' => 1, 'confirm' => 1]));
            }
            $same = $configured && telegram::is_same_bot($bot);
            telegram::connect($newapi, $token);
            redirect(new moodle_url($baseurl, $same ? ['same' => 1] : []));
        } catch (api_exception $e) {
            $error = $e->getMessage();
        }
    }
}

$pending = json_decode((string)get_config($component, 'telegrampending'));
if ($action === 'switch' && confirm_sesskey() && is_object($pending)) {
    $token = \core\encryption::decrypt($pending->token);
    try {
        $farewell = optional_param('farewell', 0, PARAM_BOOL);
        $oldtoken = $farewell ? \core\encryption::decrypt((string)get_config($component, 'telegramtoken')) : null;
        telegram::switch_bot($pending->username, $oldtoken);
        telegram::connect(new telegram_api($token), $token);
        unset_config('telegrampending', $component);
        redirect($baseurl);
    } catch (api_exception $e) {
        $error = $e->getMessage();
        $editing = true;
    }
}
if ($action === 'cancel' && confirm_sesskey()) {
    unset_config('telegrampending', $component);
    redirect(new moodle_url($baseurl, ['edit' => 1]));
}
if (optional_param('confirm', 0, PARAM_BOOL) && is_object($pending)) {
    $joined = count(telegram::joined());
    $confirm = [
        'name' => $pending->name,
        'user' => '@' . $pending->username,
        'people' => get_string($joined === 1 ? 'telegram:confirm:people1' : 'telegram:confirm:people', $component, (object)[
            'people' => $joined, 'user' => s('@' . get_config($component, 'telegrambot')),
        ]),
        'farewell' => get_string('telegram:farewell:text', $component, (object)[
            'site' => format_string(get_site()->fullname, true, ['escape' => false]),
            'bot' => '@' . $pending->username,
        ]),
    ];
}

$chat = $configured ? $mychat() : false;
if ($action === 'test' && confirm_sesskey() && $chat) {
    try {
        telegram_api::from_config()->send_text(
            telegram::chat($chat->phone),
            get_string('telegram:test:text', $component, format_string(get_site()->fullname, true, ['escape' => false])),
            (new moodle_url('/local/oksigeniamarandu/index.php'))->out(false),
            get_string('telegram:test:button', $component)
        );
        $sent = time();
    } catch (api_exception $e) {
        $error = $e->getMessage();
    }
}

// The moment: the token, joining from the phone, or the test.
$moment = $editing ? 'token' : ($chat ? 'test' : 'join');
$done = [$moment !== 'token', $moment !== 'token' && (bool)$chat, $sent > 0];
$now = array_search(false, $done, true);
$steps = [];
foreach (['connect', 'join', 'test'] as $i => $step) {
    $steps[] = [
        'label' => get_string('telegram:track:' . $step, $component),
        'pos' => get_string('telegram:pos', $component, (object)['n' => $i + 1, 'of' => 3]),
        'done' => $done[$i],
        'now' => $now === $i,
        'shown' => $now === false && $i === 2,
    ];
}

$PAGE->set_heading(get_string('telegram:title', $component));
$PAGE->set_secondary_navigation(false);
$PAGE->blocks->show_only_fake_blocks(true);
$PAGE->add_header_action($OUTPUT->render_from_template('local_oksigeniamarandu/telegram_progress', ['steps' => $steps]));

$site = format_string(get_site()->fullname, true, ['escape' => false]);
$hhmm = get_string('strftimetime24', 'langconfig');
$botname = (string)get_config($component, 'telegramname');
$bot = '@' . get_config($component, 'telegrambot');
$joinurl = $configured ? telegram::join_url($code) : '';
$people = count(telegram::joined());
$count = get_string(
    $people === 0 ? 'telegram:count:none' : ($people === 1 ? 'telegram:count:one' : 'telegram:count:many'),
    $component,
    $people
);
$returned = telegram::returned();
$missingurl = new moodle_url('/local/oksigeniamarandu/contacts.php', ['status' => 'pending', 'channel' => 'telegram']);
if ($returned) {
    $peopletext = get_string($returned->told ? 'telegram:people:backtold' : 'telegram:people:back', $component, $returned);
} else if ($people === 1 && $chat) {
    $peopletext = get_string('telegram:people:you', $component);
} else {
    $peopletext = $count;
}
$initials = '';
foreach (preg_split('/\s+/', core_text::strtoupper($botname !== '' ? $botname : $site)) as $word) {
    if (core_text::strlen($initials) < 2 && preg_match('/^\p{L}/u', $word)) {
        $initials .= core_text::substr($word, 0, 1);
    }
}
$qr = '';
if ($joinurl) {
    $svg = (new core_qrcode($joinurl))->getBarcodeSVGcode(5, 5, '#17212b');
    $svg = substr($svg, strpos($svg, '<svg'));
    $qr = preg_replace('/<svg width="([0-9.]+)" height="([0-9.]+)"/', '<svg viewBox="0 0 $1 $2" aria-hidden="true"', $svg, 1);
}
// The sketch of the talk with @BotFather, with names of example from the site.
$example = core_text::strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', core_text::specialtoascii(get_site()->shortname)));

$context = [
    'action' => $baseurl->out(false),
    'sesskey' => sesskey(),
    'error' => $error,
    'privacy' => get_string('telegram:privacy', $component),
    'token' => $moment === 'token',
    'change' => $configured,
    'current' => $configured ? get_string('telegram:current', $component, (object)[
        'name' => s($botname), 'user' => s($bot), 'people' => $count,
    ]) : '',
    'keepurl' => $baseurl->out(false),
    'sketch' => [
        'name' => $site,
        'user' => trim($example, '_') . '_bot',
        'token' => '7350912846:AAHk3vQ9xZ_ejemplo-NoEsReal4fT2mWq8',
    ],
    'confirm' => $confirm,
    'bot' => $moment !== 'token' ? [
        'name' => $botname,
        'user' => $bot,
        'people' => $peopletext,
        // The list; after a change of bot, those who have not come back yet, to tell them some other way.
        'listurl' => $returned ? $missingurl->out(false)
            : ($people > 1 ? (new moodle_url('/local/oksigeniamarandu/contacts.php'))->out(false) : ''),
        'listlabel' => get_string($returned ? 'telegram:people:missing' : 'telegram:people:list', $component),
        'same' => optional_param('same', 0, PARAM_BOOL),
        'changeurl' => (new moodle_url($baseurl, ['edit' => 1]))->out(false),
    ] : null,
    'join' => $moment === 'join' ? [
        'url' => $joinurl,
        'link' => preg_replace('#^https://#', '', $joinurl),
        'qr' => $qr,
        'statusurl' => (new moodle_url($baseurl, ['action' => 'status']))->out(false),
        'joinedsub' => get_string('telegram:join:joinedsub', $component, '__NAME__'),
        'nexturl' => (new moodle_url($baseurl, ['joined' => 1]))->out(false),
    ] : null,
    'test' => $moment === 'test' ? [
        'name' => (string)$chat->name,
        'sent' => $sent ? get_string('telegram:test:sentat', $component, userdate($sent, $hhmm)) : '',
        'panelurl' => (new moodle_url('/local/oksigeniamarandu/index.php'))->out(false),
        'initials' => $initials,
        'botname' => $botname,
        'botuser' => $bot,
        'reply' => send_reply::text('optin'),
        'text' => get_string('telegram:test:text', $component, $site),
        'button' => get_string('telegram:test:button', $component),
        'stop' => get_string('telegram:legend:stop', $component, keywords::first('optoutkeywords')),
        'focus' => optional_param('joined', 0, PARAM_BOOL),
        'time' => userdate($sent ?: time(), get_string('strftimetime24', 'langconfig')),
        'jointime' => userdate((int)($chat->consenttime ?: time()), get_string('strftimetime24', 'langconfig')),
    ] : null,
];

$PAGE->requires->js_amd_inline(<<<'JS'
require([], function() {
    var root = document.querySelector('.local-oksigeniamarandu-tg');
    if (!root) {
        return;
    }
    var still = window.matchMedia('(prefers-reduced-motion: reduce)').matches
        || document.body.classList.contains('oks-a11y-pause');
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
    // Connecting asks Telegram, which takes a moment: the button says so.
    var form = root.querySelector('form[data-connect]');
    if (form) {
        form.addEventListener('submit', function() {
            var button = form.querySelector('[data-connect-button]');
            button.disabled = true;
            form.querySelector('[data-connect-wait]').textContent = button.getAttribute('data-wait');
        });
    }
    var focus = root.querySelector('[data-focus]');
    if (focus) {
        focus.focus();
    }
    // The confirm of another bot, as a dialog over the page.
    var dialog = root.querySelector('dialog[data-confirm]');
    if (dialog && dialog.showModal) {
        dialog.removeAttribute('open');
        dialog.showModal();
    }
    // Waiting for the «Start» of the phone: the page asks every 3 seconds for 10 minutes, and changes by itself.
    var live = root.querySelector('[data-status-url]');
    if (live) {
        var url = live.getAttribute('data-status-url');
        var tries = 0;
        var ask = function() {
            return fetch(url, {credentials: 'same-origin'}).then(function(response) {
                return response.json();
            }).then(function(data) {
                if (data.joined) {
                    live.classList.add('is-joined');
                    live.querySelector('[data-live-title]').textContent = live.getAttribute('data-joined');
                    live.querySelector('[data-live-sub]').textContent =
                        live.getAttribute('data-joined-sub').replace('__NAME__', data.name);
                    clearInterval(timer);
                    setTimeout(function() {
                        window.location.href = live.getAttribute('data-next');
                    }, still ? 400 : 1600);
                }
                return data.joined;
            });
        };
        var timer = setInterval(function() {
            tries++;
            ask();
            if (tries > 200) {
                clearInterval(timer);
            }
        }, 3000);
        setTimeout(function() {
            root.querySelector('[data-stuck]').hidden = false;
        }, 45000);
        root.querySelector('[data-recheck]').addEventListener('click', function() {
            ask().then(function(joined) {
                if (!joined) {
                    var help = root.querySelector('[data-stuck-help]');
                    help.textContent = help.getAttribute('data-notyet');
                }
            });
        });
    }
    var nope = root.querySelector('[data-nope]');
    if (nope) {
        nope.addEventListener('click', function() {
            var help = root.querySelector('#mrd-nope-help');
            help.hidden = !help.hidden;
            nope.setAttribute('aria-expanded', String(!help.hidden));
        });
    }
});
JS
);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniamarandu/telegram', $context);
echo $OUTPUT->footer();
