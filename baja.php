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
 * The way out of a mail of the tools, without an account: a POST «List-Unsubscribe=One-Click» from the mail program
 * (RFC 8058) leaves at once; opening the address shows what it stops, with a button, and lets the person come back.
 * Opening it never changes anything: the programs that look at the links of a mail open them too.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- Whoever got the mail has no account; the signed token is the key.
define('NO_MOODLE_COOKIES', true);

require(__DIR__ . '/../../config.php');

use local_oksigeniamarandu\local\cards;
use local_oksigeniamarandu\local\mail\optout;

$component = 'local_oksigeniamarandu';
$token = optional_param('t', '', PARAM_RAW_TRIMMED);
$lang = optional_param('lang', '', PARAM_LANG);
$data = $token === '' ? null : optout::read($token);

$params = ['t' => $token];
if ($lang !== '') {
    $params['lang'] = $lang;
}
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/oksigeniamarandu/baja.php', $params));
$PAGE->set_pagelayout('embedded');
$PAGE->blocks->show_only_fake_blocks(true);

$sitename = cards::org();
$initials = '';
foreach (preg_split('/\s+/', core_text::strtoupper($sitename)) as $word) {
    if (core_text::strlen($initials) < 2 && preg_match('/^\p{L}/u', $word)) {
        $initials .= core_text::substr($word, 0, 1);
    }
}
$PAGE->set_title(get_string('mail:optout:title', $component, $sitename));
$context = ['sitename' => $sitename, 'initials' => $initials, 'invalid' => $data === null];

if ($data !== null) {
    $posted = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    // The mail program posts List-Unsubscribe=One-Click: a name with a dash, read as it comes.
    // phpcs:ignore moodle.PHP.ForbiddenGlobalUse.BadGlobal
    $oneclick = $posted && (string)($_POST['List-Unsubscribe'] ?? '') === 'One-Click';
    $action = $posted ? optional_param('action', '', PARAM_ALPHA) : '';
    if ($oneclick || $action === 'out') {
        optout::set($data['email'], $data['component'], $data['category'], true, $oneclick ? 'oneclick' : 'page');
    } else if ($action === 'in') {
        optout::set($data['email'], $data['component'], $data['category'], false, 'page');
    }
    if ($oneclick) {
        // The mail program does not show the page: a short answer is enough.
        header('Content-Type: text/plain; charset=utf-8');
        echo get_string('mail:optout:done', $component);
        die();
    }
    $out = optout::state($data['email'], $data['component'], $data['category']);
    $what = optout::category_name($data['component'], $data['category'])
        ?: get_string('mail:optout:generic', $component, $sitename);
    if ($out) {
        $heading = get_string('mail:optout:done', $component);
        $text = get_string('mail:optout:donetext', $component, $what);
    } else if ($action === 'in') {
        $heading = get_string('mail:optout:back', $component);
        $text = get_string('mail:optout:backtext', $component, $what);
    } else {
        $heading = get_string('mail:optout:ask', $component);
        $text = get_string('mail:optout:asktext', $component, $what);
    }
    $context += [
        'done' => $out,
        'heading' => $heading,
        'text' => $text,
        'keep' => get_string('mail:optout:keep', $component),
        'forwhom' => get_string('mail:optout:for', $component, $data['email']),
        'actionurl' => $PAGE->url->out(false),
        'action' => $out ? 'in' : 'out',
        'button' => get_string($out ? 'mail:optout:return' : 'mail:optout:leave', $component),
        'primary' => !$out,
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniamarandu/mail_optout', $context);
echo $OUTPUT->footer();
