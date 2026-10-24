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
 * Then the texts of the notices go to Meta for review, the site chooses which notices go out by WhatsApp, and sets its
 * limits: spending, notices a day for each family, and quiet hours.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_oksigeniamarandu\local\api_exception;
use local_oksigeniamarandu\local\cloud_api;
use local_oksigeniamarandu\local\notices;
use local_oksigeniamarandu\local\phone;
use local_oksigeniamarandu\local\queue;
use local_oksigeniamarandu\local\templates;
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
            test_message::start($to, cloud_api::from_config()->send_template($to, 'hello_world', 'en_US'), $typed['phone']);
            redirect(new moodle_url($baseurl, ['step' => 'test']));
        } catch (api_exception $e) {
            $errors['phone'] = $e->getMessage();
        }
    }
} else if (($action === 'submit' || $action === 'refresh') && confirm_sesskey()) {
    // Step 3: the texts go to Meta for review, or the site asks Meta how they are.
    $wabaid = (string)get_config($component, 'wabaid');
    if ($wabaid === '') {
        $errors['texts'] = get_string('wizard:texts:nowaba', $component);
    } else {
        try {
            $api = cloud_api::from_config();
            if ($action === 'submit') {
                foreach (notices::all() as $notice) {
                    foreach (notices::languages() as $lang) {
                        $template = templates::get($notice, $lang);
                        if (!$template || $template->status === 'rejected') {
                            templates::submit($api, $wabaid, $notice, $lang);
                        }
                    }
                }
            } else {
                templates::refresh($api, $wabaid);
            }
            redirect(new moodle_url($baseurl, ['step' => 'texts']));
        } catch (api_exception $e) {
            $errors['texts'] = $e->getMessage();
        }
    }
} else if ($action === 'notices' && confirm_sesskey()) {
    // Step 4: which notices go out by WhatsApp.
    $chosen = optional_param_array('notice', [], PARAM_RAW);
    $keys = array_intersect(array_keys(notices::all()), array_keys(array_filter($chosen)));
    set_config('enablednotices', implode(',', $keys), $component);
    redirect(new moodle_url($baseurl, ['step' => 'limits']));
} else if ($action === 'limits' && confirm_sesskey()) {
    // Step 5: the limits, as sentences.
    $email = trim(optional_param('capemail', '', PARAM_RAW_TRIMMED));
    if ($email !== '' && !validate_email($email)) {
        $errors['capemail'] = get_string('wizard:limits:bademail', $component);
    } else {
        set_config('capmonth', max(0, unformat_float(optional_param('capmonth', '0', PARAM_RAW_TRIMMED), true) ?: 0), $component);
        set_config('capemail', $email, $component);
        set_config('capfamily', max(0, optional_param('capfamily', 0, PARAM_INT)), $component);
        foreach (['quietfrom', 'quietto'] as $name) {
            $time = optional_param($name, '', PARAM_RAW_TRIMMED);
            set_config($name, preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time) ? $time : '', $component);
        }
        $days = array_keys(array_filter(optional_param_array('quietdays', [], PARAM_INT)));
        set_config('quietdays', implode(',', array_intersect($days, range(0, 6))), $component);
        $urgent = array_keys(array_filter(optional_param_array('urgent', [], PARAM_INT)));
        set_config('urgentnotices', implode(',', array_intersect($urgent, notices::enabled())), $component);
        redirect(new moodle_url($baseurl, ['step' => 'done']));
    }
}

$config = get_config($component);
$connected = !empty($config->connectednumber);
$hooked = $connected && !empty($config->appsecret) && !empty($config->webhookverified);
$state = test_message::get();

// Which step: the one asked for, never one whose step before is not done.
$step = optional_param('step', '', PARAM_ALPHA);
$allnotices = notices::all();
$submitted = $DB->count_records(templates::TABLE);
if (!in_array($step, ['account', 'test', 'texts', 'notices', 'limits', 'done'], true)) {
    if (!$hooked) {
        $step = 'account';
    } else if (!$state) {
        $step = 'test';
    } else if (!$submitted) {
        $step = 'texts';
    } else if (get_config($component, 'enablednotices') === false) {
        $step = 'notices';
    } else if (get_config($component, 'capmonth') === false) {
        $step = 'limits';
    } else {
        $step = 'done';
    }
}
if (!$hooked) {
    $step = 'account';
}
$errorsteps = ['send' => 'test', 'submit' => 'texts', 'refresh' => 'texts', 'limits' => 'limits'];
if ($errors && isset($errorsteps[$action])) {
    $step = $errorsteps[$action];
}

$names = [];
foreach (['account', 'test', 'texts', 'notices', 'limits'] as $key) {
    $names[$key] = get_string('wizard:step:' . $key, $component);
}
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
        'nexturl' => (new moodle_url($baseurl, ['step' => 'texts']))->out(false),
    ] : false;
    $context['test'] = [
        'phone' => $typed['phone'] ?? ($state ? $state['typed'] ?? '+' . $state['phone'] : ''),
        'phoneerror' => $errors['phone'] ?? '',
        'sent' => $sent,
        'sitename' => $config->connectedname ?? '',
    ];
} else if ($step === 'texts') {
    $groups = [];
    $counts = ['todo' => 0, 'pending' => 0];
    foreach ($allnotices as $notice) {
        foreach (notices::languages() as $lang) {
            $template = templates::get($notice, $lang);
            $status = $template ? $template->status : 'todo';
            if ($status === 'todo' || $status === 'rejected') {
                $counts['todo']++;
            } else if ($status === 'pending') {
                $counts['pending']++;
            }
            // The text as Meta will see it, with the examples where the values go.
            $body = s(notices::text($notice, 'template', $lang));
            foreach ($notice->examples as $i => $example) {
                $body = str_replace('{{' . ($i + 1) . '}}', html_writer::span(s($example), 'mrd-var'), $body);
            }
            $groups[$notice->component]['plugin'] = get_string('pluginname', $notice->component);
            $groups[$notice->component]['templates'][] = [
                'name' => notices::text($notice),
                'body' => $body,
                'button' => notices::text($notice, 'button', $lang),
                'status' => get_string('wizard:texts:status:' . $status, $component),
                'statusclass' => $status,
                'reason' => $template && $template->reason ? $template->reason : '',
                'marketing' => $template && $template->category === 'marketing',
            ];
        }
    }
    $context['texts'] = [
        'groups' => array_values($groups),
        'none' => !$allnotices,
        'error' => $errors['texts'] ?? '',
        'submit' => $counts['todo'] ? get_string('wizard:texts:submit', $component, $counts['todo']) : '',
        'pending' => $counts['pending'] > 0,
        'nexturl' => (new moodle_url($baseurl, ['step' => 'notices']))->out(false),
    ];
} else if ($step === 'notices') {
    $families = $DB->count_records(\local_oksigeniamarandu\local\consent::CONTACTS, ['status' => 'active']);
    $estimate = max($families, 1);
    $enabled = get_config($component, 'enablednotices') === false ? array_keys($allnotices) : notices::enabled();
    $rows = [];
    $total = 0.0;
    foreach ($allnotices as $notice) {
        $month = $notice->perweek * 52 / 12 * $estimate * queue::cost();
        $on = in_array($notice->key, $enabled, true);
        $total += $on ? $month : 0;
        $rows[] = [
            'key' => $notice->key,
            'id' => 'mrd-n-' . preg_replace('/[^a-z0-9]/', '-', $notice->key),
            'name' => notices::text($notice),
            'description' => get_string('pluginname', $notice->component) . ' · ' . notices::text($notice, 'desc'),
            'cost' => get_string('wizard:notices:cost', $component, format_float($month, 2)),
            'on' => $on,
        ];
    }
    $context['notices'] = [
        'rows' => $rows,
        'total' => get_string('wizard:notices:cost', $component, format_float($total, 2)),
        'basis' => get_string('wizard:notices:basis', $component, (object)[
            'families' => $estimate,
            'cost' => format_float(queue::cost(), 3),
        ]),
    ];
} else if ($step === 'limits') {
    $days = array_filter(explode(',', (string)($config->quietdays ?? '6,0')), 'strlen');
    $urgent = notices::urgent();
    $daynames = [];
    foreach ([1, 2, 3, 4, 5, 6, 0] as $day) {
        $daynames[] = [
            'value' => $day,
            'label' => get_string('wizard:limits:day' . $day, $component),
            'name' => get_string(['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'][$day], 'calendar'),
            'on' => in_array((string)$day, $days, true),
        ];
    }
    $urgentrows = [];
    foreach (notices::enabled() as $key) {
        if (isset($allnotices[$key])) {
            $urgentrows[] = ['key' => $key, 'name' => notices::text($allnotices[$key]), 'on' => in_array($key, $urgent, true)];
        }
    }
    $context['limits'] = [
        'capmonth' => format_float((float)($config->capmonth ?? 10), 0),
        'capemail' => $config->capemail ?? ($CFG->supportemail ?? ''),
        'capfamily' => (int)($config->capfamily ?? 3),
        'quietfrom' => $config->quietfrom ?? '20:00',
        'quietto' => $config->quietto ?? '08:00',
        'days' => $daynames,
        'urgent' => $urgentrows,
        'hasurgent' => !empty($urgentrows),
        'capemailerror' => $errors['capemail'] ?? '',
    ];
} else {
    $approved = $DB->count_records(templates::TABLE, ['status' => 'approved']);
    $enabled = array_intersect(notices::enabled(), array_keys($allnotices));
    $context['done'] = [
        'account' => trim(($config->connectedname ?? '') . ' · ' . ($config->connectednumber ?? ''), ' ·'),
        'test' => $state && $state['delivered']
            ? get_string('wizard:done:testok', $component, $state['typed'] ?? '+' . $state['phone'])
            : get_string('wizard:done:testpending', $component),
        'texts' => get_string('wizard:done:texts', $component, (object)[
            'approved' => $approved,
            'total' => $DB->count_records(templates::TABLE),
        ]),
        'notices' => get_string('wizard:done:notices', $component, count($enabled)),
        'limits' => get_string('wizard:done:limits', $component, (object)[
            'cap' => format_float((float)($config->capmonth ?? 0), 0),
            'perday' => (int)($config->capfamily ?? 0),
            'from' => $config->quietfrom ?? '',
            'to' => $config->quietto ?? '',
        ]),
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
