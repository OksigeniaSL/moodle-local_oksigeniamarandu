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

namespace local_oksigeniamarandu\external;

use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_oksigeniamarandu\local\chat\directory;
use local_oksigeniamarandu\local\chat\templates;

/**
 * What the page needs once: the saved answers, the approved messages, the people of the team and the departments.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_extras extends chat_base {
    /**
     * The parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * What there is.
     *
     * @return array
     */
    public static function execute(): array {
        global $DB;
        $viewer = self::viewer();
        $people = [];
        foreach (directory::people() as $agent) {
            $user = $agent['email'] === '' ? false : $DB->get_record_select(
                'user',
                'deleted = 0 AND ' . $DB->sql_equal('email', ':email', false),
                ['email' => $agent['email']],
                'id',
                IGNORE_MULTIPLE
            );
            $people[] = ['agentid' => $agent['id'], 'userid' => $user ? (int)$user->id : 0, 'name' => $agent['name'],
                'firstname' => (string)strtok($agent['name'], ' '), 'teams' => directory::teams_of($agent['id'])];
        }
        $teams = [];
        foreach (directory::teams() as $team) {
            $teams[] = $team + ['mine' => in_array($team['id'], $viewer->teams)];
        }
        return ['canned' => directory::canned(), 'templates' => templates::all(), 'people' => $people, 'teams' => $teams];
    }

    /**
     * What it returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'canned' => new external_multiple_structure(new external_single_structure([
                'short' => new external_value(PARAM_RAW, 'Its short name'),
                'text' => new external_value(PARAM_RAW, 'Its text'),
            ])),
            'templates' => new external_multiple_structure(new external_single_structure([
                'name' => new external_value(PARAM_RAW, 'Its name'),
                'language' => new external_value(PARAM_RAW, 'Its language'),
                'category' => new external_value(PARAM_ALPHA, 'UTILITY, MARKETING or AUTHENTICATION'),
                'body' => new external_value(PARAM_RAW, 'Its text, with its gaps'),
                'params' => new external_multiple_structure(new external_single_structure([
                    'key' => new external_value(PARAM_RAW, 'The gap'),
                    'example' => new external_value(PARAM_RAW, 'Its example'),
                ])),
                'header' => new external_value(PARAM_RAW, 'Its header'),
                'footer' => new external_value(PARAM_RAW, 'Its foot'),
                'buttons' => new external_multiple_structure(new external_value(PARAM_RAW, 'A button')),
            ])),
            'people' => new external_multiple_structure(new external_single_structure([
                'agentid' => new external_value(PARAM_INT, 'The person in Chatwoot'),
                'userid' => new external_value(PARAM_INT, 'Their user, or 0'),
                'name' => new external_value(PARAM_TEXT, 'Their name'),
                'firstname' => new external_value(PARAM_TEXT, 'Their first name'),
                'teams' => new external_multiple_structure(new external_value(PARAM_INT, 'A department of theirs')),
            ])),
            'teams' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'The department'),
                'name' => new external_value(PARAM_TEXT, 'Its name'),
                'internal' => new external_value(PARAM_BOOL, 'Whether it is not in the menu of the families'),
                'mine' => new external_value(PARAM_BOOL, 'Whether whoever asks is in it'),
            ])),
        ]);
    }
}
