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
 * The way of the mails of the tools, from the command line: the same as the page «Mail».
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_oksigeniamarandu\local\mail\check;
use local_oksigeniamarandu\local\mail\events;
use local_oksigeniamarandu\local\mail\mailer;
use local_oksigeniamarandu\local\mail\page;
use local_oksigeniamarandu\local\mail\quota;
use local_oksigeniamarandu\local\mail\settings;

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'keys-file' => '',
    'from' => null,
    'from-name' => null,
    'replyto' => null,
    'home' => null,
    'quota' => null,
    'routine' => null,
    'sandbox' => null,
    'check' => false,
    'way' => null,
    'test' => '',
    'events' => false,
    'release' => false,
    'forget' => '',
    'status' => false,
    'ms-way' => null,
    'ms-domains' => null,
    'ms-warm' => null,
    'site-cap' => null,
    'way-for' => '',
    'send-test' => '',
], ['h' => 'help']);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

$c = 'local_oksigeniamarandu';
$nothing = $options['keys-file'] === '' && $options['from'] === null && $options['from-name'] === null
    && $options['replyto'] === null && $options['home'] === null && $options['quota'] === null
    && $options['routine'] === null && $options['sandbox'] === null && !$options['check'] && $options['way'] === null
    && $options['test'] === '' && !$options['events'] && !$options['release'] && $options['forget'] === ''
    && $options['ms-way'] === null && $options['ms-domains'] === null && $options['ms-warm'] === null
    && $options['site-cap'] === null && $options['way-for'] === '' && $options['send-test'] === '';
if ($options['help'] || ($nothing && !$options['status'])) {
    echo "The way of the mails of the tools: the addresses of home by the mail of the site; the rest by the mail of the
site or by Mailjet, from the authenticated domain of the organization.

Options:
--keys-file=PATH     The key and the secret of Mailjet, one per line, in PATH; - reads them from the standard input.
                     They are kept encrypted and never shown.
--from=EMAIL         The address the mails out of home go from by Mailjet (an active sender of the account, on a
                     domain with DKIM).
--from-name=TEXT     Its name; empty for the name of the site.
--replyto=EMAIL      The address of the organization the answers go to; empty for none.
--home=DOMAINS       The domains of home, comma separated; empty for that of the no-reply address of the site.
--quota=N            The mails a month of the plan of Mailjet; 0 for none.
--routine=PCT        The share of the quota from which what is routine goes by the mail of the site (80 by default).
--sandbox=0|1        Mailjet only checks the mails and does not send them (1), for a site of tests.
--check              Asks Mailjet whether the keys, the sender and the domain are ready.
--way=site|mailjet   The way of the mails out of home; mailjet only once --check says it is ready.
--test=EMAIL         Sends a test by Mailjet, whatever the way is, and says how it went.
--events             Makes Mailjet tell its bounces, blocks and spam to this site.
--release            Lets go by Mailjet what waits for the quota of the month.
--forget=EMAIL       An address that came back, was blocked or marked spam receives again.
--ms-way=site|mailjet The way of the mails to Microsoft (Hotmail, Outlook, Live…): site, by the mail of the site, while
                     the domain of Mailjet makes its name there; mailjet, as the rest (by default).
--ms-domains=LIST    The domains of Microsoft, comma separated; «hotmail.*» is any ending. Empty for the default ones.
--ms-warm=N          With --ms-way=site, the first N mails of the day to Microsoft still by Mailjet (10; 0 for none).
--site-cap=N         With --ms-way=site, the most mails a day to Microsoft by the mail of the site (1500); then Mailjet.
--way-for=EMAIL      Says the way a mail to EMAIL would go now, without sending it nor counting it.
--send-test=EMAIL    Sends a test by the way a mail to EMAIL goes now, and says which.
--status             Shows how it is.
-h, --help           This help.

Example:
  printf '%s\\n%s\\n' \"\$KEY\" \"\$SECRET\" | php cli/mail.php --keys-file=- --from=avisos@example.org --quota=15000 --check
";
    exit(0);
}

if ($options['keys-file'] !== '') {
    $text = $options['keys-file'] === '-' ? stream_get_contents(STDIN) : @file_get_contents($options['keys-file']);
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$text)), 'strlen'));
    if (count($lines) < 2) {
        cli_error('The key and the secret, one per line.');
    }
    settings::set_keys($lines[0], $lines[1]);
    echo "Keys: kept\n";
}
foreach (['from' => 'mjfrom', 'replyto' => 'mjreplyto'] as $option => $setting) {
    if ($options[$option] !== null) {
        $value = trim((string)$options[$option]);
        if ($value !== '' && !validate_email($value)) {
            cli_error('Not an address: ' . $value);
        }
        set_config($setting, $value, $c);
    }
}
if ($options['from-name'] !== null) {
    set_config('mjfromname', clean_param((string)$options['from-name'], PARAM_TEXT), $c);
}
if ($options['home'] !== null) {
    set_config('mailhome', implode(', ', settings::domains((string)$options['home'])), $c);
}
if ($options['quota'] !== null) {
    set_config('mailquota', max(0, (int)$options['quota']), $c);
}
if ($options['routine'] !== null) {
    set_config('mailroutine', max(0, min(100, (int)$options['routine'])), $c);
}
if ($options['sandbox'] !== null) {
    set_config('mailsandbox', (int)!empty($options['sandbox']), $c);
}
if ($options['check'] || $options['keys-file'] !== '' || $options['from'] !== null) {
    if (settings::keys()) {
        $state = check::run();
        echo 'Mailjet: ' . implode(', ', array_map(fn($part) => $part . ' ' . $state[$part], check::PARTS))
            . ($state['error'] !== '' ? ' (' . $state['error'] . ')' : '') . "\n";
    }
}
if ($options['way'] !== null) {
    if (!in_array($options['way'], ['site', 'mailjet'], true)) {
        cli_error('--way is site or mailjet.');
    }
    if ($options['way'] === 'mailjet' && !check::ready()) {
        cli_error('Mailjet is not ready: --check says what is missing.');
    }
    set_config('mailway', $options['way'], $c);
    echo 'Way out of home: ' . $options['way'] . "\n";
}
if ($options['ms-way'] !== null) {
    if (!in_array($options['ms-way'], ['site', 'mailjet'], true)) {
        cli_error('--ms-way is site or mailjet.');
    }
    set_config('mailmsway', $options['ms-way'], $c);
}
if ($options['ms-domains'] !== null) {
    set_config('mailmsdomains', trim((string)$options['ms-domains']), $c);
}
if ($options['ms-warm'] !== null) {
    set_config('mailmswarm', max(0, (int)$options['ms-warm']), $c);
}
if ($options['site-cap'] !== null) {
    set_config('mailsitecap', max(0, (int)$options['site-cap']), $c);
}
if ($options['way-for'] !== '') {
    $email = trim((string)$options['way-for']);
    $way = mailer::route(
        ['email' => \core_text::strtolower($email), 'bulk' => false, 'later' => false, 'routine' => false],
        time(),
        quota::used(time()),
        $why,
        false
    );
    echo 'Way for ' . $email . ': ' . ($way === 'site' ? 'the mail of the site' : $way) . ($why ? ' (' . $why . ')' : '') . "\n";
}
if ($options['send-test'] !== '') {
    $result = check::test(trim((string)$options['send-test']), null, true);
    echo 'Test: ' . ($result['ok'] ? 'sent by ' . ($result['way'] === 'site' ? 'the mail of the site' : 'Mailjet')
        : 'not sent (' . $result['error'] . ')') . "\n";
}
if ($options['test'] !== '') {
    $result = check::test(trim((string)$options['test']));
    echo 'Test: ' . ($result['ok'] ? 'sent by Mailjet' : 'not sent (' . $result['error'] . ')') . "\n";
}
if ($options['events']) {
    echo 'Events of Mailjet to this site: ' . (page::listen() ? 'yes' : 'Mailjet did not take them') . "\n";
}
if ($options['release']) {
    echo 'Let go: ' . mailer::release() . "\n";
}
if ($options['forget'] !== '') {
    echo 'Receives again: ' . (events::forget((string)$options['forget']) ? 'yes' : 'it was not noted') . "\n";
}
if ($options['status'] || !$nothing) {
    $now = time();
    $state = quota::state($now);
    [$from, $name] = settings::from();
    $checked = check::last();
    echo 'Home: ' . implode(', ', settings::home_domains()) . ", by the mail of the site\n";
    echo 'Out of home: ' . (settings::mailjet_chosen() ? 'Mailjet' : 'the mail of the site')
        . (mailer::paused($now) ? ' (Mailjet aside: ' . mailer::paused($now)['error'] . ')' : '') . "\n";
    echo 'Mailjet: keys ' . (settings::keys() ? 'set' : 'none') . ', from ' . ($from ?: '-') . ' (' . $name . '), reply to '
        . (settings::replyto() ?: '-') . (get_config($c, 'mailsandbox') ? ', sandbox' : '') . "\n";
    if ($checked) {
        echo 'Checked: ' . implode(', ', array_map(fn($part) => $part . ' ' . $checked[$part], check::PARTS)) . ' at '
            . userdate((int)$checked['time']) . "\n";
    }
    echo 'Microsoft: ' . (settings::microsoft_way() === 'site' ? 'by the mail of the site, ' . settings::warm()
        . ' a day by Mailjet first, up to ' . settings::site_cap() . ' a day' : 'by Mailjet, as the rest') . ' ('
        . implode(', ', settings::microsoft_domains()) . ")\n";
    echo 'Month: ' . $state['used'] . ($state['quota'] ? ' of ' . $state['quota'] . ' (routine by the site from '
        . $state['routine'] . ')' : '') . ($state['counted'] ? ', Mailjet counted ' . $state['counted']['sent'] : '')
        . ', waiting for the quota ' . $state['waiting'] . "\n";
}
