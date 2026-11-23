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
use local_oksigeniamarandu\local\chat\draft;
use local_oksigeniamarandu\local\chat\hours;
use local_oksigeniamarandu\local\chat\mirror;
use local_oksigeniamarandu\local\chat\team_hours;

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'env' => '',
    'inboxes' => null,
    'webhook' => false,
    'internal' => null,
    'automatic' => null,
    'calendar' => null,
    'closed-words' => null,
    'team-hours' => null,
    'knowledge' => null,
    'team-knowledge' => null,
    'colors' => null,
    'stages' => null,
    'team-names' => null,
    'prices' => null,
    'start-templates' => null,
    'templates-info' => null,
    'access-role' => null,
    'adult-students' => null,
    'birth-field' => null,
    'call-template' => null,
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
--closed-words=WORDS   The words that start the name of a closed day in it, comma separated. An event named
                       «Department: word» («Canteen: closed») closes only that department, and «Department: hours»
                       («Office: 8:00-12:00») gives it other hours that day.
--team-hours=HOURS     The week of each department that does not keep the hours of the inbox, all of them at once
                       (those not written keep the inbox's); empty for none. Departments separated by «;», each
                       «department=days hours»: the department by its name or its number; days by their names in
                       English, Spanish, Portuguese or Arabic, whole or short (mon, lun, seg), alone or as a range
                       (mon-fri), separated by commas; then the hours of those days, one span or several (9:00-14:00,
                       16:00-18:00; 9-14 and 24:00 are fine). The days not written are closed. Example:
                       \"Canteen=mon-fri 7:30-15:30; Admissions=mon-fri 9:00-14:00,16:00-18:00;
                       Office=mon-thu 8:00-14:00, fri 8:00-13:00\"
--knowledge=FILE       What the organisation knows and says, for «Propose an answer»: its tone, its links, what it
                       says after a visit. A text file (Markdown is fine), «-» for the standard input; empty clears it.
                       Nothing of it is sent until a person asks for a proposal, and the person sends the answer.
--team-knowledge=LIST  The same for some departments, each «department=file», separated by «;» (the department by its
                       name or its number); «department=» clears that one. The others keep theirs.
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
--adult-students=0|1   Whether a student of age (18, by the day of birth the record of families gives) with a mobile
                       (the second phone of their profile) is written to beside the adults of their family, never
                       instead of them. Off by default.
--birth-field=NAME     For a site whose record of families gives no day of birth (or without that record): the short
                       name of the field of the profile with it, a date field or a text one written as 2008-03-15,
                       15/03/2008, 15-03-2008 or 15.03.2008; empty for none.
--call-template=NAME   The approved message offered after a call nobody answered, once the 24 hours are over
                       (chat_llamada_sin_respuesta if not given); empty for none.
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
    if ($options['team-hours'] !== null) {
        try {
            $weeks = team_hours::set($options['team-hours'] === true ? '' : (string)$options['team-hours']);
        } catch (\invalid_parameter_exception $e) {
            cli_error('--team-hours: ' . $e->debuginfo);
        }
        $teams = directory::teams();
        mtrace('Departments with hours of their own: ' . count($weeks));
        foreach ($weeks as $teamid => $week) {
            mtrace('  ' . ($teams[$teamid]['name'] ?? $teamid) . ': ' . team_hours::describe($week));
        }
    }
    if ($options['knowledge'] !== null) {
        $file = $options['knowledge'] === true ? '' : trim((string)$options['knowledge']);
        $text = '';
        if ($file !== '') {
            $text = $file === '-' ? stream_get_contents(STDIN) : (is_readable($file) ? file_get_contents($file) : false);
            if ($text === false) {
                cli_error('--knowledge: cannot read ' . $file);
            }
        }
        mtrace('Knowledge of the organisation: ' . draft::set_knowledge((string)$text) . ' characters');
    }
    if ($options['team-knowledge'] !== null) {
        $teams = directory::teams();
        foreach (array_filter(array_map('trim', explode(';', (string)$options['team-knowledge'])), 'strlen') as $part) {
            [$name, $file] = array_map('trim', explode('=', $part, 2)) + [1 => ''];
            $teamid = team_hours::find($name, $teams);
            if ($teamid === null) {
                cli_error('--team-knowledge: no department ' . $name);
            }
            $text = $file === '' ? '' : (is_readable($file) ? file_get_contents($file) : false);
            if ($text === false) {
                cli_error('--team-knowledge: cannot read ' . $file);
            }
            mtrace('Knowledge of ' . $teams[$teamid]['name'] . ': ' . draft::set_team_knowledge($teamid, (string)$text) .
                ' characters');
        }
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
    if ($options['birth-field'] !== null) {
        $short = trim((string)$options['birth-field']);
        if ($short !== '' && !$DB->record_exists('user_info_field', ['shortname' => $short])) {
            cli_error('No field of the profile with the short name ' . $short);
        }
        set_config('chatbirthfield', $short, $component);
        mtrace('Date of birth: ' . ($short === '' ? '-' : 'from the field ' . $short));
    }
    if ($options['adult-students'] !== null) {
        set_config('chatadultstudents', empty($options['adult-students']) ? 0 : 1, $component);
    }
    if ($options['adult-students'] !== null || $options['birth-field'] !== null) {
        // The families kept a few minutes say it again at once.
        \local_oksigeniamarandu\local\chat\family::forget();
        \cache::make($component, 'chat')->purge();
        mtrace('Students of age beside the adults: ' . (\local_oksigeniamarandu\local\chat\family::adult_students_on() ? 'on'
            : 'off') . '; day of birth: ' . \local_oksigeniamarandu\local\chat\family::births_from());
    }
    if ($options['call-template'] !== null) {
        set_config('chatcalltemplate', trim((string)$options['call-template']), $component);
        mtrace('After a call nobody answered: ' . (trim((string)$options['call-template']) ?: '-'));
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
        $site = hours::of_site();
        $today = date('Y-m-d');
        $ahead = $site->days_ahead($today);
        mtrace('Hours of the inbox: ' . $site->describe() . ' (' . $site->export()['timezone'] . '); closed days ahead: ' .
            $ahead['closed']);
        // Each department: its own week, or the inbox's, with what the calendar says only of it.
        $byteam = hours::by_team();
        $own = team_hours::get();
        foreach (directory::teams() as $teamid => $team) {
            $hours = $byteam[$teamid];
            $ahead = $hours->days_ahead($today);
            $special = array_map(function ($day, $spans) {
                return $day . ' ' . $spans;
            }, array_keys($ahead['special']), $ahead['special']);
            mtrace('  ' . $team['name'] . ': ' . (isset($own[$teamid]) ? $hours->describe() : 'as the inbox') .
                '; closed days ahead: ' . $ahead['closed'] . ($special ? '; other hours: ' . implode(', ', $special) : ''));
        }
        // «Propose an answer»: whether the site can, and what it knows.
        $teamknowledge = draft::team_knowledge();
        $teamsbyid = directory::teams();
        mtrace('Propose an answer: ' . (draft::available() ? 'on (text generation of the site)' :
            'off (no provider of the site with text generation)') . '; knowledge of the organisation: ' .
            \core_text::strlen(draft::knowledge()) . ' characters' . ($teamknowledge ? '; of ' . implode(', ', array_map(
                fn($teamid, $text) => ($teamsbyid[$teamid]['name'] ?? $teamid) . ' ' . \core_text::strlen($text),
                array_keys($teamknowledge),
                $teamknowledge
            )) : ''));
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
        $call = \local_oksigeniamarandu\local\chat\templates::call_name();
        $usable = in_array($call, array_column($approved, 'name'), true);
        mtrace('After a call nobody answered: ' . ($call === '' ? '-' : $call .
            ($usable ? '' : ' (not among the approved messages it may send)')));
        mtrace('Students of age beside the adults: ' . (empty($config->chatadultstudents) ? 'off' : 'on') .
            '; day of birth: ' . \local_oksigeniamarandu\local\chat\family::births_from());
    }
} catch (\moodle_exception $e) {
    cli_error($e->getMessage() . (empty($e->debuginfo) ? '' : ' (' . $e->debuginfo . ')'));
}
exit(0);
