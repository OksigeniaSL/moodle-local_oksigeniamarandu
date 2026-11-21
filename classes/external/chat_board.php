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
use local_oksigeniamarandu\local\chat\board;

/**
 * The board of the team: what a person has on it, and what they do with it (put a notice, mark one seen, tell again
 * whoever has not seen theirs, change its text or take it off).
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_board extends chat_base {
    /**
     * The parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'action' => new external_value(PARAM_ALPHA, 'Empty to read; post, seen, remind, edit or remove', VALUE_DEFAULT, ''),
            'id' => new external_value(PARAM_INT, 'The notice', VALUE_DEFAULT, 0),
            'userid' => new external_value(PARAM_INT, 'Whom to tell again', VALUE_DEFAULT, 0),
            'text' => new external_value(PARAM_TEXT, 'The text of a notice', VALUE_DEFAULT, ''),
            'teams' => new external_multiple_structure(
                new external_value(PARAM_INT, 'A department'),
                'For these departments',
                VALUE_DEFAULT,
                []
            ),
            'all' => new external_value(PARAM_BOOL, 'For the whole team', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Does it, and gives the board.
     *
     * @param string $action What.
     * @param int $id The notice.
     * @param int $userid Whom to tell again.
     * @param string $text The text.
     * @param array $teams The departments.
     * @param bool $all The whole team.
     * @return array
     */
    public static function execute(
        string $action = '',
        int $id = 0,
        int $userid = 0,
        string $text = '',
        array $teams = [],
        bool $all = false
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), ['action' => $action, 'id' => $id, 'userid' => $userid,
            'text' => $text, 'teams' => $teams, 'all' => $all]);
        $viewer = $params['action'] === '' || $params['action'] === 'seen' ? self::viewer() : self::writer();
        $posted = 0;
        switch ($params['action']) {
            case '':
                break;
            case 'post':
                $posted = board::post($viewer, $params['text'], $params['teams'], $params['all']);
                break;
            case 'seen':
                board::seen($viewer->userid, $params['id']);
                break;
            case 'remind':
                board::remind($viewer, $params['id'], $params['userid']);
                break;
            case 'edit':
                board::change($viewer, $params['id'], $params['text']);
                break;
            case 'remove':
                board::change($viewer, $params['id'], null);
                break;
            default:
                throw new \invalid_parameter_exception('Unknown action');
        }
        return ['items' => board::for_viewer($viewer), 'posted' => $posted] + board::counts($viewer->userid);
    }

    /**
     * What it returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $person = [
            'userid' => new external_value(PARAM_INT, 'The user'),
            'name' => new external_value(PARAM_TEXT, 'Their name'),
            'firstname' => new external_value(PARAM_TEXT, 'Their first name'),
            'agentid' => new external_value(PARAM_INT, 'Their person in Chatwoot, for their critter; 0 if none'),
            'picture' => new external_value(PARAM_RAW, 'The picture of their profile, or empty'),
        ];
        return new external_single_structure([
            'items' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'The notice'),
                'text' => new external_value(PARAM_TEXT, 'Its text'),
                'author' => new external_single_structure($person),
                'mine' => new external_value(PARAM_BOOL, 'Whether whoever looks put it'),
                'all' => new external_value(PARAM_BOOL, 'Whether it is for the whole team'),
                'teams' => new external_multiple_structure(new external_value(PARAM_TEXT, 'A department it is for')),
                'at' => new external_value(PARAM_INT, 'When it was put'),
                'edited' => new external_value(PARAM_BOOL, 'Whether its text changed'),
                'seen' => new external_value(PARAM_INT, 'When whoever looks saw it; 0 if not yet'),
                'total' => new external_value(PARAM_INT, 'How many it is for, with whoever put it'),
                'seencount' => new external_value(PARAM_INT, 'How many have seen it'),
                'people' => new external_multiple_structure(new external_single_structure($person + [
                    'seenat' => new external_value(PARAM_INT, 'When they saw it; 0 if not yet'),
                    'remindedat' => new external_value(PARAM_INT, 'When they were told again; 0 if never'),
                ])),
            ])),
            'posted' => new external_value(PARAM_INT, 'The notice just put, if any'),
            'tosee' => new external_value(PARAM_INT, 'How many notices whoever looks has to see'),
            'rev' => new external_value(PARAM_INT, 'The last time anything of theirs on the board moved'),
        ]);
    }
}
