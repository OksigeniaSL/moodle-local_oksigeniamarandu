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
 * The private page where a family joins the notices on WhatsApp, on the phone and without a Moodle account.
 *
 * It is reached by the link with its code (api::signup_url()). On a phone it opens WhatsApp with the opt-in word and the
 * code written; on a computer it shows them as a QR code. Sending that message is the consent, and it gives the site the
 * number. There is no login: whoever has the code can join for whom the code stands for.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing

define('NO_MOODLE_COOKIES', true);

require(__DIR__ . '/../../config.php');

use local_oksigeniamarandu\api;
use local_oksigeniamarandu\local\consent;
use local_oksigeniamarandu\local\keywords;

$component = 'local_oksigeniamarandu';
$code = strtoupper(optional_param('code', '', PARAM_ALPHANUM));
$action = optional_param('action', '', PARAM_ALPHA);

$link = $code === '' ? false : $DB->get_record(consent::LINKS, ['code' => $code]);
$contact = $link && $link->contactid ? $DB->get_record(consent::CONTACTS, ['id' => $link->contactid]) : false;
$joined = $contact && $contact->status === 'active';
$site = get_site();
// Plain text: the template escapes it.
$sitename = format_string($site->fullname, true, ['context' => context_system::instance(), 'escape' => false]);
$number = preg_replace('/[^0-9]/', '', (string)get_config($component, 'displayphone'));

// What the page asks every few seconds while the family is in WhatsApp: only whether it has joined.
if ($action === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['joined' => (bool)$joined]);
    die();
}

// The number of the site as a contact card, so that the family recognises the notices.
if ($action === 'vcard' && $link && $number !== '') {
    $name = str_replace(["\r", "\n", ';', ','], ' ', $sitename);
    header('Content-Type: text/vcard; charset=utf-8');
    header('Content-Disposition: attachment; filename="whatsapp.vcf"');
    echo "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:{$name}\r\nORG:{$name}\r\nTEL;TYPE=CELL:+{$number}\r\nEND:VCARD\r\n";
    die();
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/oksigeniamarandu/alta.php', $code === '' ? [] : ['code' => $code]));
$PAGE->set_pagelayout('embedded');
// Nothing floats over what the family taps.
$PAGE->blocks->show_only_fake_blocks(true);
$PAGE->set_title(get_string('alta:title', $component, $sitename));

// Whom the code stands for: the user, or what the plugin that asked for it says.
$for = '';
if ($link && $link->userid) {
    $user = core_user::get_user($link->userid);
    $for = $user ? fullname($user) : '';
} else if ($link && $link->component !== 'core') {
    $label = component_callback($link->component, 'oksigeniamarandu_label', [(int)$link->itemid], null);
    $for = is_string($label) ? $label : '';
}

$initials = '';
foreach (preg_split('/\s+/', core_text::strtoupper($sitename)) as $word) {
    if (core_text::strlen($initials) < 2 && preg_match('/^\p{L}/u', $word)) {
        $initials .= core_text::substr($word, 0, 1);
    }
}

$context = [
    'sitename' => $sitename,
    'initials' => $initials,
    'invalid' => !$link || $number === '',
];
if (!$context['invalid']) {
    $optin = keywords::first('optinkeywords');
    $optout = keywords::first('optoutkeywords');
    $context += [
        'title' => get_string('alta:title', $component, $sitename),
        'for' => $for === '' ? '' : get_string('alta:for', $component, $for),
        'howto' => get_string('alta:howto', $component),
        'leavenote' => get_string('alta:leavenote', $component, $optout),
        'message' => $optin . ' ' . $code,
    ];
    if ($joined) {
        $phone = $contact->phone;
        $context['done'] = [
            'phone' => '+' . substr($phone, 0, 3) . str_repeat('•', max(0, strlen($phone) - 5)) . substr($phone, -2),
            'number' => trim((string)get_config($component, 'displayphone')),
            'vcardurl' => (new moodle_url($PAGE->url, ['action' => 'vcard']))->out(false),
            'optouturl' => 'https://wa.me/' . $number . '?text=' . rawurlencode($optout),
            'optoutnote' => get_string('alta:optoutnote', $component, $optout),
        ];
    } else {
        $waurl = api::optin_url($code);
        $qr = new core_qrcode($waurl);
        $svg = $qr->getBarcodeSVGcode(6, 6, '#17212b');
        $svg = substr($svg, strpos($svg, '<svg'));
        $svg = preg_replace('/<svg width="([0-9.]+)" height="([0-9.]+)"/', '<svg viewBox="0 0 $1 $2"', $svg, 1);
        $context['choose'] = [
            'waurl' => $waurl,
            'qr' => $svg,
            'statusurl' => (new moodle_url($PAGE->url, ['action' => 'status']))->out(false),
        ];
    }
}

$PAGE->requires->js_amd_inline(<<<'JS'
require([], function() {
    var root = document.querySelector('.local-oksigeniamarandu-alta');
    if (!root) {
        return;
    }
    var show = function(name) {
        root.querySelectorAll('[data-screen]').forEach(function(screen) {
            screen.hidden = screen.getAttribute('data-screen') !== name;
        });
    };
    // Back from WhatsApp, or scanned from a computer: the page changes as soon as the message arrives.
    var choose = root.querySelector('[data-status-url]');
    if (choose) {
        var tries = 0;
        var timer = setInterval(function() {
            tries++;
            fetch(choose.getAttribute('data-status-url')).then(function(response) {
                return response.json();
            }).then(function(data) {
                if (data.joined) {
                    clearInterval(timer);
                    window.location.reload();
                }
            });
            if (tries > 200) {
                clearInterval(timer);
            }
        }, 3000);
        root.querySelectorAll('[data-open]').forEach(function(open) {
            open.addEventListener('click', function() {
                setTimeout(function() {
                    show('wait');
                }, 500);
                setTimeout(function() {
                    root.querySelector('[data-stuck]').hidden = false;
                }, 30000);
            });
        });
    }
    var leave = root.querySelector('[data-leave]');
    if (leave) {
        leave.addEventListener('click', function() {
            root.querySelector('[data-leave-box]').hidden = false;
        });
    }
});
JS
);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniamarandu/alta', $context);
echo $OUTPUT->footer();
