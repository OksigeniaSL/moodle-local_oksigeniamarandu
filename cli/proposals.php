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
 * The proposals of improvement from the command line: the ones waiting (or all), as text or JSON, with their pictures
 * copied to a folder; closing one with what was done; and who receives them by Telegram.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_oksigeniamarandu\local\proposals;

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'pending' => false,
    'all' => false,
    'json' => false,
    'files' => '',
    'done' => 0,
    'note' => '',
    'people' => null,
], ['h' => 'help']);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

if ($options['help'] || (!$options['pending'] && !$options['all'] && !$options['done'] && $options['people'] === null)) {
    echo "The proposals of improvement of the tools of the site.

Options:
--pending           The proposals waiting, the newest first.
--all               Every proposal, done too.
--json              As JSON, one object per proposal: id, time, date, who, userid, tool, toolname, release, screen,
                    url, text, summary, guessedtool, status, note and picture (the path of its copy, with --files).
--files=DIR         Copies the picture of each proposal listed to DIR, as propuesta-<id>.jpg (or .png). Without it,
                    to a folder of the temporary files of the site, which the listing says.
--done=ID           Closes a proposal, with --note=\"what was done\".
--people=LIST       Who receives the proposals by Telegram, by username, email or id, comma separated; empty for
                    nobody (they are kept all the same).
-h, --help          This help.

Examples:
php local/oksigeniamarandu/cli/proposals.php --pending --json --files=/tmp/proposals
php local/oksigeniamarandu/cli/proposals.php --done=12 --note=\"Meals 0.116: the whole week in sight\"
";
    exit(0);
}

if ($options['people'] !== null) {
    $ids = [];
    $given = $options['people'] === true ? '' : (string)$options['people'];
    foreach (array_filter(array_map('trim', explode(',', $given)), 'strlen') as $who) {
        $user = ctype_digit($who) ? $DB->get_record('user', ['id' => (int)$who, 'deleted' => 0])
            : ($DB->get_record('user', ['username' => core_text::strtolower($who), 'deleted' => 0])
            ?: $DB->get_record('user', ['email' => $who, 'deleted' => 0], '*', IGNORE_MULTIPLE));
        if (!$user) {
            cli_error('There is no user ' . $who);
        }
        $ids[] = (int)$user->id;
    }
    proposals::set_people($ids);
    mtrace('Who receives the proposals: ' . (implode(', ', array_map('fullname', proposals::people())) ?: 'nobody') . '.');
}

if ($options['done']) {
    $admin = get_admin();
    if (!proposals::done((int)$options['done'], (string)$options['note'], (int)$admin->id)) {
        cli_error('No proposal ' . (int)$options['done'] . ' waiting');
    }
    mtrace('Proposal ' . (int)$options['done'] . ' done.');
}

if ($options['pending'] || $options['all']) {
    $dir = trim((string)$options['files']);
    if ($dir === '') {
        $dir = make_temp_directory('local_oksigeniamarandu/proposals');
    } else if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        cli_error('Cannot make ' . $dir);
    }
    $list = array_map(fn($record) => proposals::export($record, $dir), proposals::list($options['all'] ? '' : 'new'));
    if ($options['json']) {
        echo json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }
    if (!$list) {
        mtrace('No proposals ' . ($options['all'] ? 'yet' : 'waiting') . '.');
    }
    foreach ($list as $one) {
        mtrace('#' . $one['id'] . ' ' . $one['date'] . ' · ' . $one['who'] . ' · ' . $one['toolname'] .
            ($one['release'] !== '' ? ' ' . $one['release'] : '') . ($one['screen'] !== '' ? ' · ' . $one['screen'] : '') .
            ($one['status'] === 'done' ? ' · done' : ''));
        if ($one['summary'] !== '') {
            mtrace('  ' . $one['summary']);
        }
        mtrace('  ' . str_replace("\n", "\n  ", $one['text']));
        mtrace('  ' . $one['url'] . ($one['picture'] !== '' ? "\n  " . $one['picture'] : ''));
    }
}
