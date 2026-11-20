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
 * The chats and contacts of a number that comes from the app of WhatsApp Business (coexistence): asks Meta for them,
 * says what came, and gives them in one file for the shared inbox (cli/chatwoot/import_history.rb).
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_oksigeniamarandu\local\cloud_api;
use local_oksigeniamarandu\local\history;

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'phone' => '',
    'request' => '',
    'status' => false,
    'export' => '',
    'forget' => false,
], ['h' => 'help']);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

if ($options['help'] || ($options['request'] === '' && !$options['status'] && $options['export'] === '' && !$options['forget'])) {
    echo "The chats of a number that comes from the app of WhatsApp Business (coexistence).

Options:
--phone=ID        The phone number id; the one of the site if not given.
--request=WHAT    Asks Meta for them, once, in the day after the number joins: contacts, history or both (contacts
                  first, as Meta asks). They come afterwards to the webhook of the site.
--status          What came, by number: pieces, contacts, chats, messages and how far each phase went.
--export=FILE     Writes the contacts and chats of the number as JSON, in order and without repeats; - for the
                  standard output. For cli/chatwoot/import_history.rb.
--forget          Forgets what came for the number, once the inbox has it.
-h, --help        This help.

Example:
\$ sudo -u www-data php local/oksigeniamarandu/cli/history.php --request=both --phone=123456789012345
";
    exit(0);
}

$phone = $options['phone'] !== '' ? $options['phone'] : (string)get_config('local_oksigeniamarandu', 'phonenumberid');
try {
    if ($options['request'] !== '') {
        $types = ['contacts' => ['smb_app_state_sync'], 'history' => ['history'], 'both' => ['smb_app_state_sync', 'history']];
        if (!isset($types[$options['request']]) || $phone === '') {
            cli_error('--request is contacts, history or both, for a number');
        }
        $api = cloud_api::from_config();
        foreach ($types[$options['request']] as $type) {
            mtrace($type . ': asked, request ' . $api->request_app_data($phone, $type));
        }
    }
    if ($options['status']) {
        foreach (history::status() as $number => $status) {
            $progress = [];
            foreach ($status['progress'] as $phase => $percent) {
                $progress[] = 'phase ' . $phase . ' ' . $percent . '%';
            }
            mtrace($number . ': ' . $status['pieces'] . ' pieces, ' . $status['contacts'] . ' contacts, ' .
                ($status['threads'] ?? 0) . ' chats, ' . ($status['messages'] ?? 0) . ' messages' .
                ($progress ? ', ' . implode(', ', $progress) : '') . ($status['declined'] ? ', NOT SHARED by the business' : ''));
        }
    }
    if ($options['export'] !== '') {
        $all = history::export($options['phone']);
        if (count($all) !== 1) {
            cli_error(count($all) ? 'Several numbers came: say which with --phone' : 'Nothing came yet');
        }
        $json = json_encode(reset($all), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($options['export'] === '-') {
            echo $json . "\n";
        } else {
            file_put_contents($options['export'], $json);
            mtrace('Written: ' . $options['export']);
        }
    }
    if ($options['forget']) {
        mtrace('Forgotten: ' . history::forget($phone) . ' pieces');
    }
} catch (\moodle_exception $e) {
    cli_error($e->getMessage());
}
exit(0);
