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
 * The set-up wizard: connect the WhatsApp account of the site, let Meta reach it, and send a test message.
 *
 * The steps of the texts, the notices and the limits come with the notices; until then they are not shown.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_oksigeniamarandu\local\api_exception;
use local_oksigeniamarandu\local\cloud_api;
use local_oksigeniamarandu\local\phone;
use local_oksigeniamarandu\local\test_message;

admin_externalpage_setup('local_oksigeniamarandu_wizard', '', null, '', ['nosearch' => true]);
// One thing at a time: without the tabs of the administration, the next step fits on a small screen.
$PAGE->set_secondary_navigation(false);
$PAGE->blocks->show_only_fake_blocks(true);

$component = 'local_oksigeniamarandu';
$baseurl = new moodle_url('/local/oksigeniamarandu/wizard.php');
$action = optional_param('action', '', PARAM_ALPHA);
$timeformat = get_string('strftimetime', 'langconfig');

// What the page asks every few seconds while the test message travels.
if ($action === 'status') {
    require_sesskey();
    $state = test_message::get();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'delivered' => $state && $state['delivered'] ? userdate($state['delivered'], $timeformat) : '',
        'replied' => $state && $state['replied'] ? userdate($state['replied'], $timeformat) : '',
        'failed' => $state ? $state['failed'] : '',
    ]);
    die();
}

$errors = [];
$typed = [];
$editing = optional_param('edit', 0, PARAM_BOOL);

if ($action === 'connect' && confirm_sesskey()) {
    // Part one of the first step: the details of the app, checked with Meta at once.
    $typed['phonenumberid'] = optional_param('phonenumberid', '', PARAM_RAW_TRIMMED);
    $typed['wabaid'] = optional_param('wabaid', '', PARAM_RAW_TRIMMED);
    $token = optional_param('accesstoken', '', PARAM_RAW_TRIMMED);
    if (!preg_match('/^[0-9]{5,30}$/', $typed['phonenumberid'])) {
        $errors['phonenumberid'] = get_string('wizard:error:digits', $component);
    }
    if ($typed['wabaid'] !== '' && !preg_match('/^[0-9]{5,30}$/', $typed['wabaid'])) {
        $errors['wabaid'] = get_string('wizard:error:digits', $component);
    }
    if ($token === '' && !get_config($component, 'accesstoken')) {
        $errors['accesstoken'] = get_string('wizard:error:notoken', $component);
    }
    if (!$errors) {
        set_config('phonenumberid', $typed['phonenumberid'], $component);
        set_config('wabaid', $typed['wabaid'], $component);
        if ($token !== '') {
            set_config('accesstoken', \core\encryption::encrypt($token), $component);
        }
        unset_config('connectednumber', $component);
        unset_config('connectedname', $component);
        try {
            $info = cloud_api::from_config()->get_phone_number();
            set_config('connectednumber', $info['number'], $component);
            set_config('connectedname', $info['name'], $component);
            redirect(new moodle_url($baseurl, ['step' => 'account']));
        } catch (api_exception $e) {
            $errors['accesstoken'] = $e->getMessage();
        }
    }
    $editing = true;
} else if ($action === 'webhook' && confirm_sesskey()) {
    // Part two: the app secret, and whether Meta has already checked the address of the webhook.
    $secret = optional_param('appsecret', '', PARAM_RAW_TRIMMED);
    if ($secret !== '') {
        set_config('appsecret', \core\encryption::encrypt($secret), $component);
    }
    if (!get_config($component, 'appsecret')) {
        $errors['appsecret'] = get_string('wizard:error:nosecret', $component);
    } else if (!get_config($component, 'webhookverified')) {
        $errors['webhook'] = get_string('wizard:hookbad', $component);
    } else {
        redirect(new moodle_url($baseurl, ['step' => 'test']));
    }
} else if ($action === 'send' && confirm_sesskey()) {
    $typed['phone'] = optional_param('phone', '', PARAM_TEXT);
    $to = phone::normalise($typed['phone']);
    if ($to === null) {
        $errors['phone'] = get_string('error:phone', $component);
    } else {
        try {
            test_message::start($to, cloud_api::from_config()->send_template($to, 'hello_world', 'en_US'));
            redirect(new moodle_url($baseurl, ['step' => 'test']));
        } catch (api_exception $e) {
            $errors['phone'] = $e->getMessage();
        }
    }
}

$config = get_config($component);
$connected = !empty($config->connectednumber);
$hooked = $connected && !empty($config->appsecret) && !empty($config->webhookverified);
$state = test_message::get();

// Which step: the one asked for, never one whose step before is not done.
$step = optional_param('step', '', PARAM_ALPHA);
if (!in_array($step, ['account', 'test', 'done'], true)) {
    $step = !$hooked ? 'account' : ($state && $state['delivered'] ? 'done' : 'test');
}
if (!$hooked) {
    $step = 'account';
}
if ($errors && $action === 'send') {
    $step = 'test';
}

$names = ['account' => get_string('wizard:step:account', $component), 'test' => get_string('wizard:step:test', $component)];
$order = array_keys($names);
$current = array_search($step, $order);
$steps = [];
foreach ($order as $i => $key) {
    $done = $step === 'done' || $i < $current;
    $steps[] = [
        'label' => $names[$key],
        'done' => $done,
        'now' => $key === $step,
        'url' => $done ? (new moodle_url($baseurl, ['step' => $key]))->out(false) : '',
    ];
}

// The title of the step is the heading of the page, and the progress takes the place of the admin search beside it.
$PAGE->set_heading(get_string('wizard:' . $step . ':title', $component));
$PAGE->add_header_action($OUTPUT->render_from_template('local_oksigeniamarandu/wizard_progress', [
    'steps' => $steps,
    'stepinfo' => $step === 'done' ? '' : get_string('wizard:stepof', $component, (object)[
        'num' => $current + 1,
        'total' => count($order),
        'name' => $names[$step],
    ]),
]));

$context = [
    'formurl' => $baseurl->out(false),
    'sesskey' => sesskey(),
];

if ($step === 'account') {
    $context['account'] = [
        'showform' => !$connected || $editing,
        'phonenumberid' => $typed['phonenumberid'] ?? ($config->phonenumberid ?? ''),
        'wabaid' => $typed['wabaid'] ?? ($config->wabaid ?? ''),
        'tokensaved' => !empty($config->accesstoken),
        'phonenumberiderror' => $errors['phonenumberid'] ?? '',
        'wabaiderror' => $errors['wabaid'] ?? '',
        'accesstokenerror' => $errors['accesstoken'] ?? '',
        'connected' => $connected && !$editing ? [
            'line' => get_string('wizard:connected', $component, (object)[
                'number' => s($config->connectednumber),
                'name' => s($config->connectedname ?? ''),
            ]),
            'editurl' => (new moodle_url($baseurl, ['step' => 'account', 'edit' => 1]))->out(false),
            'webhookurl' => (new moodle_url('/local/oksigeniamarandu/webhook.php'))->out(false),
            'verifytoken' => $config->verifytoken ?? '',
            'appsecretsaved' => !empty($config->appsecret),
            'appsecreterror' => $errors['appsecret'] ?? '',
            'webhookerror' => $errors['webhook'] ?? '',
            'hooked' => $hooked,
            'nexturl' => (new moodle_url($baseurl, ['step' => 'test']))->out(false),
        ] : false,
    ];
} else if ($step === 'test') {
    $sent = $state && empty($errors) ? [
        'time' => userdate($state['sent'], $timeformat),
        'delivered' => $state['delivered'] ? userdate($state['delivered'], $timeformat) : '',
        'replied' => $state['replied'] ? userdate($state['replied'], $timeformat) : '',
        'failed' => $state['failed'],
        'statusurl' => (new moodle_url($baseurl, ['action' => 'status', 'sesskey' => sesskey()]))->out(false),
        'nexturl' => (new moodle_url($baseurl, ['step' => 'done']))->out(false),
    ] : false;
    $context['test'] = [
        'phone' => $typed['phone'] ?? ($state ? '+' . $state['phone'] : ''),
        'phoneerror' => $errors['phone'] ?? '',
        'sent' => $sent,
        'sitename' => $config->connectedname ?? '',
    ];
} else {
    $context['done'] = [
        'account' => trim(($config->connectedname ?? '') . ' · ' . ($config->connectednumber ?? ''), ' ·'),
        'test' => $state && $state['delivered'] ? get_string('wizard:done:testok', $component, '+' . $state['phone'])
            : get_string('wizard:done:testpending', $component),
        'contactsurl' => (new moodle_url('/local/oksigeniamarandu/contacts.php'))->out(false),
        'settingsurl' => (new moodle_url('/admin/settings.php', ['section' => 'local_oksigeniamarandu_account']))->out(false),
    ];
}

$PAGE->requires->js_amd_inline(<<<'JS'
require([], function() {
    var root = document.querySelector('.local-oksigeniamarandu-wizard');
    if (!root) {
        return;
    }
    // Copy buttons: what goes from the site to Meta.
    root.querySelectorAll('[data-copy]').forEach(function(button) {
        button.addEventListener('click', function() {
            navigator.clipboard.writeText(button.getAttribute('data-copy')).then(function() {
                button.textContent = button.getAttribute('data-copied');
            });
        });
    });
    // The test message: ask the site every 3 seconds, for 3 minutes, what Meta has told it.
    var track = root.querySelector('[data-status-url]');
    if (!track) {
        return;
    }
    var tries = 0;
    var timer = setInterval(function() {
        tries++;
        fetch(track.getAttribute('data-status-url'), {credentials: 'same-origin'}).then(function(response) {
            return response.json();
        }).then(function(data) {
            ['delivered', 'replied'].forEach(function(name) {
                var item = track.querySelector('[data-track="' + name + '"]');
                if (data[name] && item && !item.classList.contains('done')) {
                    item.classList.add('done');
                    item.querySelector('small').textContent = data[name];
                }
            });
            if (data.failed) {
                var failed = root.querySelector('[data-failed]');
                failed.textContent = failed.getAttribute('data-failed') + ' ' + data.failed;
                failed.hidden = false;
            }
            if ((data.delivered && data.replied) || data.failed || tries > 60) {
                clearInterval(timer);
            }
        });
    }, 3000);
    var nope = root.querySelector('[data-nope]');
    if (nope) {
        nope.addEventListener('click', function() {
            root.querySelector('[data-nope-help]').hidden = false;
        });
    }
});
JS
);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniamarandu/wizard', $context);
echo $OUTPUT->footer();
