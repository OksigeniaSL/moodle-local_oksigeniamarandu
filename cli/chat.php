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

use local_oksigeniamarandu\local\chat\access;
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
    'team-names' => null,
    'prices' => null,
    'start-templates' => null,
    'templates-info' => null,
    'access-role' => null,
    'sync' => false,
    'sync-all' => false,
    'rebuild' => false,
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
--team-names=JSON      The name to show of each department, by its name in Chatwoot, which keeps them in small letters:
                       {\"dirección de eso\": \"Dirección de ESO\"}. Without one, its first letter in capitals.
--prices=JSON          What a message of each category costs, for the cards of the approved messages, cheapest first:
                       {\"currency\": \"EUR\", \"utility\": 0.02, \"marketing\": 0.06, \"authentication\": 0.03,
                       \"service\": 0.02}; service, a plain text past the 1,000 free of the month of a number.
--start-templates=NAMES The approved messages to write first to someone who never wrote, in their order, comma
                       separated: they go first when a person starts a conversation.
--templates-info=JSON  What each approved message is called and does, for the cards: an object by name with title,
                       description and, optionally, gaps, what goes in each gap by its key ({\"2\": \"the reason\"}).
--access-role[=ROLE]   Only the people of the account open the chat: gives them this role of the site (its short
                       name; without it, the role of the plugin, made if missing), from now on also to whoever joins
                       the account, and takes the capability away from the role of every authenticated user.
--sync                 Fills the index with the conversations of the last month now.
--sync-all             Fills the index with every conversation of the inboxes, whenever it was: after bringing a
                       history into Chatwoot (import_history.rb), which never comes by the webhook.
--rebuild              With --sync-all: every row written again from Chatwoot (its department, its state, its 24
                       hours), also what changed there without moving its time, such as departments set in bulk.
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
        mtrace('Connected: ' . $counts['agents'] . ' users of the account, ' . $counts['teams'] . ' departments, ' .
            $counts['inboxes'] . ' inboxes' .
            (empty($env['CHATWOOT_PLATFORM_TOKEN']) ? ', without platform app.' : ', with platform app.'));
        if (!access::role()) {
            // Nobody opens the chat but its people: the role of the plugin, given to them now and to whoever joins.
            $role = access::choose();
            $done = access::sync();
            mtrace('Access to the chat: by the role ' . $role->shortname . ', given to ' . $done['given'] . ' people.');
        }
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
    if ($options['team-names'] !== null) {
        $names = json_decode($options['team-names'], true);
        if (!is_array($names)) {
            cli_error('--team-names is a JSON object');
        }
        $clean = [];
        foreach ($names as $from => $to) {
            $clean[\core_text::strtolower(trim((string)$from))] = trim((string)$to);
        }
        set_config('chatteamnames', $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : '', $component);
        \local_oksigeniamarandu\local\chat\directory::forget();
        mtrace('Departments with a name of their own: ' . count($clean));
    }
    if ($options['prices'] !== null) {
        $prices = json_decode($options['prices'], true);
        if (!is_array($prices)) {
            cli_error('--prices is a JSON object');
        }
        set_config('chatprices', json_encode($prices), $component);
        mtrace('Prices: ' . json_encode(\local_oksigeniamarandu\local\chat\templates::prices()));
    }
    if ($options['start-templates'] !== null) {
        $names = array_filter(array_map('trim', explode(',', $options['start-templates'])), 'strlen');
        set_config('chatstarttemplates', implode(',', $names), $component);
        mtrace('Messages to start with: ' . (implode(', ', $names) ?: '-'));
    }
    if ($options['templates-info'] !== null) {
        $info = json_decode($options['templates-info'], true);
        if (!is_array($info)) {
            cli_error('--templates-info is a JSON object');
        }
        set_config('chattemplatesinfo', $info ? json_encode($info, JSON_UNESCAPED_UNICODE) : '', $component);
        mtrace('Approved messages described: ' . count($info));
    }
    if ($options['access-role'] !== null) {
        $role = access::choose($options['access-role'] === true ? '' : trim((string)$options['access-role']));
        $done = access::sync();
        $closed = access::close();
        mtrace('Access to the chat: by the role ' . $role->shortname . '; given to ' . $done['given'] . ' people' .
            ($closed ? '; taken away from ' . $closed . ' roles of every account' : ''));
    }
    if ($options['webhook']) {
        mtrace('Webhook: ' . connection::webhook());
    }
    if ($options['sync-all']) {
        [$kept, $all] = mirror::sync_all((bool)$options['rebuild']);
        mtrace("Conversations in the index: $all ($kept new or changed)");
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
        $role = access::role();
        $open = access::open_roles();
        mtrace('Access to the chat: ' . ($role ? 'by the role ' . $role->shortname : 'no role chosen (--access-role)') .
            ($open ? '; OPEN TO EVERY ACCOUNT by ' . implode(', ', array_column($open, 'shortname')) : ''));
        foreach (access::missing() as $one) {
            mtrace('  Cannot open the chat: ' . $one['agent']['name'] . ' <' . $one['agent']['email'] . '>');
        }
        foreach (access::unmatched() as $agent) {
            mtrace('  No user of the site with the email of: ' . $agent['name'] . ' <' . $agent['email'] . '>');
        }
        $hours = hours::of_site()->export();
        mtrace('Hours: ' . ($hours['enabled'] ? $hours['timezone'] : 'always open') . '; closed days ahead: ' .
            count(array_filter($hours['closed'], function ($day) {
                return $day >= date('Y-m-d');
            })));
        mtrace('Index: ' . $GLOBALS['DB']->count_records(mirror::TABLE) . ' conversations; last pass ' .
            (empty($config->chatsynced) ? 'never' : userdate((int)$config->chatsynced)));
        $approved = \local_oksigeniamarandu\local\chat\templates::usable();
        $first = array_column(array_filter($approved, function ($one) {
            return $one['start'];
        }), 'name');
        $hidden = count(\local_oksigeniamarandu\local\chat\templates::all()) - count($approved);
        mtrace('Approved messages: ' . count($approved) . ($first ? ' (to start with: ' . implode(', ', $first) . ')' : '') .
            ($hidden ? '; not offered, being marketing: ' . $hidden : '') .
            '; waiting for Meta: ' . \local_oksigeniamarandu\local\chat\templates::pending());
    }
} catch (\moodle_exception $e) {
    cli_error($e->getMessage() . (empty($e->debuginfo) ? '' : ' (' . $e->debuginfo . ')'));
}
exit(0);
