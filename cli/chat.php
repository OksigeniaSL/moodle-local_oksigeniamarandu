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
 * Connects the conversations of the team with an account of Chatwoot from the command line. The tokens are read from a
 * file of KEY=VALUE lines or the standard input, never from the arguments.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_oksigeniamarandu\local\chat\connection;
use local_oksigeniamarandu\local\chat\directory;
use local_oksigeniamarandu\local\chat\hours;
use local_oksigeniamarandu\local\chat\mirror;

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'env' => '',
    'inboxes' => null,
    'webhook' => false,
    'internal' => null,
    'automatic' => null,
    'calendar' => null,
    'closed-words' => null,
    'colors' => null,
    'stages' => null,
    'sync' => false,
    'status' => false,
    'disconnect' => false,
], ['h' => 'help']);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

$asked = array_filter($options, function ($value, $key) {
    return $key !== 'help' && $value !== null && $value !== false && $value !== '';
}, ARRAY_FILTER_USE_BOTH);
if ($options['help'] || !$asked) {
    echo "Connects the conversations of the team (Oksigenia Marandu) with an account of Chatwoot.

Options:
--env=PATH             Reads CHATWOOT_URL, CHATWOOT_ACCOUNT, CHATWOOT_TOKEN (an administrator of the account) and,
                       optionally, CHATWOOT_PLATFORM_TOKEN (a platform app, so that each person answers as themself)
                       and CHATWOOT_INBOX from PATH, as KEY=VALUE lines; - reads them from the standard input.
--inboxes=IDS          The inboxes to attend (comma separated); empty for all. CHATWOOT_INBOX if not given.
--webhook              Creates the webhook of the account towards this site (or brings it up to date).
--internal=TEAMS       The departments that are not in the menu of the families: ids or names, comma separated.
--automatic=USERS      The users of the account that are a bot and not a person (such as the one of a bot of the
                       menu): ids or emails, comma separated.
--calendar=URL         An iCal with the closed days; empty for none.
--closed-words=WORDS   The words that start the name of a closed day in it, comma separated.
--colors=COLOURS       The colours of the organisation for the page, comma separated (#rrggbb).
--stages=JSON          The stages of the organisation, youngest first: a list of {label, color, dark, code, match,
                       show}; code is the stage the record of families guesses from a group (such as PRI), match a
                       pattern of the group, show a pattern of the part of the group that is shown.
--sync                 Fills the index with the conversations of the last month now.
--status               Shows what is connected.
--disconnect           Forgets the account, its tokens and the index.
-h, --help             This help.

Example:
\$ sudo -u www-data php local/oksigeniamarandu/cli/chat.php --env=- --webhook --sync --status < chatwoot.env
";
    exit(0);
}

$component = 'local_oksigeniamarandu';
try {
    if ($options['disconnect']) {
        connection::disconnect();
        mtrace('Disconnected.');
        exit(0);
    }
    if ($options['env'] !== '') {
        $file = $options['env'] === '-' ? 'php://stdin' : $options['env'];
        $text = @file_get_contents($file);
        if ($text === false) {
            cli_error('Cannot read ' . $options['env']);
        }
        $env = [];
        foreach (preg_split('/\R/', $text) as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Z_]+)\s*=\s*(.*?)\s*$/', $line, $match)) {
                $env[$match[1]] = trim($match[2], "\"'");
            }
        }
        $inboxes = $options['inboxes'] ?? ($env['CHATWOOT_INBOX'] ?? '');
        $counts = connection::connect(
            $env['CHATWOOT_URL'] ?? '',
            (int)($env['CHATWOOT_ACCOUNT'] ?? 0),
            $env['CHATWOOT_TOKEN'] ?? '',
            $env['CHATWOOT_PLATFORM_TOKEN'] ?? '',
            array_filter(array_map('intval', explode(',', (string)$inboxes)))
        );
        mtrace('Connected: ' . $counts['agents'] . ' users, ' . $counts['teams'] . ' departments, ' . $counts['inboxes'] .
            ' inboxes' . (empty($env['CHATWOOT_PLATFORM_TOKEN']) ? ', without platform app.' : ', with platform app.'));
    } else if ($options['inboxes'] !== null) {
        set_config(
            'chatwootinboxes',
            implode(',', array_filter(array_map('intval', explode(',', $options['inboxes'])))),
            $component
        );
        directory::forget();
    }
    if ($options['internal'] !== null) {
        $wanted = array_filter(array_map(function ($one) {
            return \core_text::strtolower(trim($one));
        }, explode(',', $options['internal'])), 'strlen');
        $ids = [];
        foreach (directory::teams() as $id => $team) {
            if (in_array((string)$id, $wanted, true) || in_array(\core_text::strtolower($team['name']), $wanted, true)) {
                $ids[] = $id;
            }
        }
        set_config('chatinternal', implode(',', $ids), $component);
        mtrace('Internal departments: ' . count($ids));
    }
    if ($options['automatic'] !== null) {
        $wanted = array_filter(array_map(function ($one) {
            return \core_text::strtolower(trim($one));
        }, explode(',', $options['automatic'])), 'strlen');
        $ids = [];
        foreach (directory::agents() as $id => $agent) {
            if (in_array((string)$id, $wanted, true) || in_array($agent['email'], $wanted, true)) {
                $ids[] = $id;
            }
        }
        set_config('chatautomatic', implode(',', $ids), $component);
        directory::forget();
        mtrace('Automatic users: ' . count($ids));
    }
    if ($options['calendar'] !== null) {
        set_config('chatcalendar', trim($options['calendar']), $component);
    }
    if ($options['closed-words'] !== null) {
        set_config('chatclosedwords', trim($options['closed-words']), $component);
    }
    if ($options['colors'] !== null) {
        set_config('chatcolors', trim($options['colors']), $component);
    }
    if ($options['stages'] !== null) {
        $stages = json_decode($options['stages'], true);
        if (!is_array($stages)) {
            cli_error('--stages is a JSON list');
        }
        set_config('chatstages', json_encode(array_values($stages), JSON_UNESCAPED_UNICODE), $component);
        mtrace('Stages: ' . count(\local_oksigeniamarandu\local\chat\family::stages()));
    }
    if ($options['webhook']) {
        mtrace('Webhook: ' . connection::webhook());
    }
    if ($options['sync']) {
        set_config('chatsynced', 0, $component);
        mtrace('Conversations in the index: ' . mirror::sync());
    }
    if ($options['status']) {
        $config = get_config($component);
        mtrace('Chatwoot: ' . ($config->chatwooturl ?? '-') . ', account ' . ($config->chatwootaccount ?? '-') .
            (empty($config->chatwootplatform) ? ', without platform app' : ', with platform app'));
        mtrace('Inboxes: ' . (($config->chatwootinboxes ?? '') ?: 'all') . '; webhook: ' .
            (($config->chatwootwebhook ?? 0) ?: 'none'));
        $automatic = count(directory::agents()) - count(directory::people());
        mtrace('People: ' . count(directory::people()) . ($automatic ? ' (and ' . $automatic . ' automatic users)' : '') .
            '; departments: ' . count(directory::teams()));
        $hours = hours::of_site()->export();
        mtrace('Hours: ' . ($hours['enabled'] ? $hours['timezone'] : 'always open') . '; closed days ahead: ' .
            count(array_filter($hours['closed'], function ($day) {
                return $day >= date('Y-m-d');
            })));
        mtrace('Index: ' . $GLOBALS['DB']->count_records(mirror::TABLE) . ' conversations; last pass ' .
            (empty($config->chatsynced) ? 'never' : userdate((int)$config->chatsynced)));
    }
} catch (\moodle_exception $e) {
    cli_error($e->getMessage() . (empty($e->debuginfo) ? '' : ' (' . $e->debuginfo . ')'));
}
exit(0);
