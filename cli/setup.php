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
 * Sets up the channels of a site from the command line, for whoever hosts it: the bot of Telegram and the notices of
 * its plugins. The token is read from a file or the standard input, never from the arguments.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_oksigeniamarandu\local\setup;
use local_oksigeniamarandu\local\telegram_api;

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'telegram-token-file' => '',
    'whatsapp-number' => '',
    'notices' => '',
    'channels' => 'telegram',
    'org-of' => null,
    'confirm-cards' => null,
    'spend-people' => null,
    'marketing-off' => null,
    'whatsapp-channel' => null,
    'whatsapp-channel-people' => null,
    'whatsapp-channel-name' => null,
    'whatsapp-channel-url' => null,
    'status' => false,
], ['h' => 'help']);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

$nothing = $options['telegram-token-file'] === '' && $options['whatsapp-number'] === '' && $options['notices'] === ''
    && $options['org-of'] === null && $options['confirm-cards'] === null && $options['spend-people'] === null
    && $options['marketing-off'] === null && $options['whatsapp-channel'] === null
    && $options['whatsapp-channel-people'] === null && $options['whatsapp-channel-name'] === null
    && $options['whatsapp-channel-url'] === null;
if ($options['help'] || ($nothing && !$options['status'])) {
    echo "Sets up Oksigenia Marandu on this site.

Options:
--telegram-token-file=PATH  Connects the bot of Telegram whose token is in PATH; - reads it from the standard input.
                            The same bot with a new token keeps everyone; another bot, only from its page if people
                            joined the one of now.
--whatsapp-number=ID        Moves WhatsApp to another number of the same account and token: its phone number id.
                            Checks it with Meta, keeps the number it has (the links to join follow it), closes the
                            24 hours of everyone (they were with the other number) and reads the templates again.
--notices=PLUGINS           Switches on every notice of these plugins (comma separated, or all), for --channels; a
                            single notice as component/notice.
--channels=CHANNELS         telegram, whatsapp or both (comma separated). Default: telegram.
--org-of=TEXT               The name of the organization inside a sentence of the confirmations, in the language of
                            the site, such as \"del Colegio Monte Lirio\"; empty for the plain way.
--confirm-cards=0|1         The confirmations of joining, changing channel and leaving, with their card (1, the
                            default) or as the short text of before (0).
--spend-people=USERS        Who sees what WhatsApp costs and approves the sends to many: user ids, usernames or emails,
                            comma separated; empty for whoever has local/oksigeniamarandu:bulkapprove.
--marketing-off=0|1         What Meta counts as marketing, by WhatsApp: it stops when Meta moves a template there and
                            comes back when a text of it is approved as utility, and the chat does not offer those
                            messages (1, the default); or it goes on as before (0). Telegram does not change.
--whatsapp-channel=0|1      The posts of the channel of Telegram, for the channel of WhatsApp too (1): whoever
                            publishes there gets each one ready to forward or share, and marks it published. Switching
                            it on switches on its notice for WhatsApp and Telegram.
--whatsapp-channel-people=USERS
                            Who publishes in the channel of WhatsApp: user ids, usernames or emails, comma separated;
                            empty for whoever reviews the channel.
--whatsapp-channel-name=TEXT
                            The name of the channel of WhatsApp, for whoever publishes; empty for none.
--whatsapp-channel-url=URL  The link of the channel of WhatsApp (https://whatsapp.com/channel/...), to open it from the
                            page of each post, and for the other plugins beside that of Telegram; empty for none.
--status                    Shows the channels and the notices on.
-h, --help                  This help.

Example:
\$ sudo -u www-data php local/oksigeniamarandu/cli/setup.php --telegram-token-file=- --notices=local_oksigeniaeguahe < token
";
    exit(0);
}

$list = function (string $value): array {
    return array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
};

try {
    if ($options['telegram-token-file'] !== '') {
        $file = $options['telegram-token-file'] === '-' ? 'php://stdin' : $options['telegram-token-file'];
        $token = @file_get_contents($file);
        if ($token === false) {
            cli_error('Cannot read the token from ' . $options['telegram-token-file']);
        }
        $token = trim($token);
        $bot = setup::telegram(new telegram_api($token), $token);
        mtrace('Telegram: @' . ($bot['username'] ?? '') . ($bot['same'] ? ', the same bot with a new token' : '') . ', connected.');
    }
    if ($options['whatsapp-number'] !== '') {
        $moved = setup::whatsapp_number($options['whatsapp-number']);
        mtrace('WhatsApp: ' . $moved['number'] . ($moved['name'] !== '' ? ' (' . $moved['name'] . ')' : '') .
            ($moved['old'] !== '' && $moved['old'] !== $options['whatsapp-number'] ? ', instead of ' . $moved['old'] : '') . '.');
        mtrace('The 24 hours closed for ' . $moved['reset'] . ' people; templates of the account read again: ' .
            $moved['templates'] . '.');
        $hook = $moved['webhook'];
        mtrace('Meta sends what reaches the number to: ' . ($hook['phone_number'] ?? $hook['whatsapp_business_account']
            ?? $hook['application'] ?? 'it does not say') . (isset($hook['phone_number']) ? ' (the webhook of the number)' : ''));
    }
    if ($options['notices'] !== '') {
        $keys = setup::notices($list($options['notices']), $list($options['channels']));
        mtrace('Notices on for ' . implode(', ', $list($options['channels'])) . ': ' . ($keys ? implode(', ', $keys) : 'none'));
    }
    if ($options['org-of'] !== null) {
        set_config('cardorgof', $options['org-of'] === true ? '' : trim((string)$options['org-of']), 'local_oksigeniamarandu');
        mtrace('The name inside a sentence: ' . \local_oksigeniamarandu\local\confirm::of($CFG->lang) . '.');
    }
    if ($options['confirm-cards'] !== null) {
        set_config('confirmcards', empty($options['confirm-cards']) ? 0 : 1, 'local_oksigeniamarandu');
        mtrace('Confirmations with a card: ' . (empty($options['confirm-cards']) ? 'off' : 'on') . '.');
    }
    if ($options['spend-people'] !== null) {
        $ids = [];
        foreach ($list($options['spend-people'] === true ? '' : (string)$options['spend-people']) as $who) {
            $user = ctype_digit($who) ? $DB->get_record('user', ['id' => (int)$who, 'deleted' => 0])
                : ($DB->get_record('user', ['username' => core_text::strtolower($who), 'deleted' => 0])
                ?: $DB->get_record('user', ['email' => $who, 'deleted' => 0], '*', IGNORE_MULTIPLE));
            if (!$user) {
                cli_error('There is no user ' . $who);
            }
            $ids[] = (int)$user->id;
        }
        \local_oksigeniamarandu\local\spend::set_people($ids);
        mtrace('Who sees what WhatsApp costs and approves: ' . (implode(', ', array_map(
            'fullname',
            \local_oksigeniamarandu\local\spend::people()
        )) ?: 'nobody') . '.');
    }
    if ($options['marketing-off'] !== null) {
        $on = !empty($options['marketing-off']);
        set_config('marketingoff', $on ? 1 : 0, 'local_oksigeniamarandu');
        mtrace('What Meta counts as marketing, by WhatsApp: ' . ($on ? 'not' : 'yes, as before') . '.');
    }
    if ($options['whatsapp-channel-name'] !== null) {
        $name = $options['whatsapp-channel-name'] === true ? '' : trim((string)$options['whatsapp-channel-name']);
        set_config('wachannelname', clean_param($name, PARAM_TEXT), 'local_oksigeniamarandu');
    }
    if ($options['whatsapp-channel-url'] !== null) {
        $url = $options['whatsapp-channel-url'] === true ? '' : (string)$options['whatsapp-channel-url'];
        if (!\local_oksigeniamarandu\local\wachannel::set_url($url)) {
            cli_error('Not a link of a channel of WhatsApp: ' . $url);
        }
    }
    if ($options['whatsapp-channel-people'] !== null) {
        $ids = [];
        foreach ($list($options['whatsapp-channel-people'] === true ? '' : (string)$options['whatsapp-channel-people']) as $who) {
            $user = ctype_digit($who) ? $DB->get_record('user', ['id' => (int)$who, 'deleted' => 0])
                : ($DB->get_record('user', ['username' => core_text::strtolower($who), 'deleted' => 0])
                ?: $DB->get_record('user', ['email' => $who, 'deleted' => 0], '*', IGNORE_MULTIPLE));
            if (!$user) {
                cli_error('There is no user ' . $who);
            }
            $ids[] = (int)$user->id;
        }
        \local_oksigeniamarandu\local\wachannel::set_people($ids);
    }
    if ($options['whatsapp-channel'] !== null) {
        \local_oksigeniamarandu\local\wachannel::set_enabled(!empty($options['whatsapp-channel']));
    }
    if (
        $options['whatsapp-channel'] !== null || $options['whatsapp-channel-people'] !== null
            || $options['whatsapp-channel-name'] !== null || $options['whatsapp-channel-url'] !== null
    ) {
        mtrace(\local_oksigeniamarandu\local\setup::whatsapp_channel());
    }
    if ($options['status']) {
        foreach (setup::status() as $line) {
            mtrace($line);
        }
    }
} catch (\moodle_exception $e) {
    cli_error($e->getMessage());
}
exit(0);
