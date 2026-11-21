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
use local_oksigeniamarandu\local\chat\team;

/**
 * The messages of the team: the list of conversations of whoever calls, opening one (or the one with a person that does
 * not exist yet), sending a text or the card of a conversation with a family, marking one read and reading older
 * messages.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_team extends chat_base {
    /**
     * Its parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'action' => new external_value(PARAM_ALPHA, 'Empty for the list; open, send, read or older', VALUE_DEFAULT, ''),
            'id' => new external_value(PARAM_INT, 'The conversation', VALUE_DEFAULT, 0),
            'userid' => new external_value(PARAM_INT, 'The person, to open or start the one between two', VALUE_DEFAULT, 0),
            'teamid' => new external_value(PARAM_INT, 'The department, to open its conversation', VALUE_DEFAULT, 0),
            'text' => new external_value(PARAM_RAW, 'What is sent', VALUE_DEFAULT, ''),
            'cwid' => new external_value(PARAM_INT, 'A conversation with a family, passed as a card', VALUE_DEFAULT, 0),
            'before' => new external_value(PARAM_INT, 'Older: the messages before this one', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Does it.
     *
     * @param string $action Empty, open, send, read or older.
     * @param int $id The conversation.
     * @param int $userid The person.
     * @param int $teamid The department.
     * @param string $text The text.
     * @param int $cwid The conversation with a family.
     * @param int $before The message.
     * @return array
     */
    public static function execute(
        string $action = '',
        int $id = 0,
        int $userid = 0,
        int $teamid = 0,
        string $text = '',
        int $cwid = 0,
        int $before = 0
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), ['action' => $action, 'id' => $id,
            'userid' => $userid, 'teamid' => $teamid, 'text' => $text, 'cwid' => $cwid, 'before' => $before]);
        $viewer = $params['action'] === 'send' ? self::writer() : self::viewer();
        $result = [];
        switch ($params['action']) {
            case '':
                break;
            case 'open':
                if ($params['id']) {
                    $conv = team::get($viewer, $params['id']);
                } else if ($params['teamid']) {
                    $conv = team::of_team($params['teamid']);
                    if (!team::sees($viewer, $conv)) {
                        throw new \moodle_exception('chat:team:notyours', 'local_oksigeniamarandu');
                    }
                } else {
                    $other = team::other($viewer, $params['userid']);
                    $conv = team::between($viewer->userid, $other, false) ?? (object)['id' => 0, 'kind' => 'dm',
                        'teamid' => 0, 'usera' => min($viewer->userid, $other), 'userb' => max($viewer->userid, $other)];
                }
                team::read($viewer, $conv);
                $result['conversation'] = team::export($viewer, $conv);
                break;
            case 'send':
                $sent = team::send(
                    $viewer,
                    $params['id'],
                    $params['userid'],
                    $params['teamid'],
                    $params['text'],
                    $params['cwid']
                );
                $result['conversation'] = team::export($viewer, $sent['conversation']);
                $result['sent'] = $sent['id'];
                break;
            case 'read':
                $conv = team::get($viewer, $params['id']);
                team::read($viewer, $conv);
                $result['conversation'] = team::export($viewer, $conv);
                break;
            case 'older':
                $result['conversation'] = team::export($viewer, team::get($viewer, $params['id']), $params['before']);
                break;
            default:
                throw new \invalid_parameter_exception('Unknown action');
        }
        $result += [
            'list' => team::list($viewer),
            'people' => team::people($viewer),
            'teams' => team::teams($viewer),
            'unread' => team::counts($viewer)['unread'],
        ];
        return self::without_nulls($result);
    }

    /**
     * The same, without the keys that are null: the page reads them as missing.
     *
     * @param array $data The result.
     * @return array
     */
    protected static function without_nulls(array $data): array {
        foreach ($data as $key => $value) {
            if ($value === null) {
                unset($data[$key]);
            } else if (is_array($value)) {
                $data[$key] = self::without_nulls($value);
            }
        }
        return $data;
    }

    /**
     * What it returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $person = function (int $required = VALUE_REQUIRED): external_single_structure {
            return new external_single_structure([
                'userid' => new external_value(PARAM_INT, 'The user'),
                'name' => new external_value(PARAM_TEXT, 'Their name'),
                'firstname' => new external_value(PARAM_TEXT, 'Their first name'),
                'agentid' => new external_value(PARAM_INT, 'The person of the account of conversations'),
                'picture' => new external_value(PARAM_RAW, 'The picture of their profile, or empty'),
            ], 'A person of the team', $required);
        };
        $head = [
            'id' => new external_value(PARAM_INT, 'The conversation; 0 for one between two people that has nothing yet'),
            'kind' => new external_value(PARAM_ALPHA, 'dm or team'),
            'teamid' => new external_value(PARAM_INT, 'The department'),
            'teamname' => new external_value(PARAM_TEXT, 'Its name'),
            'other' => $person(VALUE_OPTIONAL),
            'people' => new external_multiple_structure($person(), 'Who is in it'),
        ];
        return new external_single_structure([
            'list' => new external_multiple_structure(new external_single_structure($head + [
                'last' => new external_single_structure([
                    'text' => new external_value(PARAM_RAW, 'What it says, in one line'),
                    'kind' => new external_value(PARAM_ALPHA, 'text, conv, joined or left'),
                    'at' => new external_value(PARAM_INT, 'When'),
                    'userid' => new external_value(PARAM_INT, 'Who'),
                    'firstname' => new external_value(PARAM_TEXT, 'Their first name'),
                ], 'The last message', VALUE_OPTIONAL),
                'unread' => new external_value(PARAM_INT, 'Messages of others not read'),
                'at' => new external_value(PARAM_INT, 'Its last move'),
            ])),
            'people' => new external_multiple_structure(new external_single_structure($person()->keys + [
                'teamids' => new external_multiple_structure(new external_value(PARAM_INT, 'A department')),
                'dm' => new external_value(PARAM_INT, 'The conversation with whoever looks, or 0'),
            ])),
            'teams' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'The department'),
                'name' => new external_value(PARAM_TEXT, 'Its name'),
                'count' => new external_value(PARAM_INT, 'How many people'),
            ])),
            'unread' => new external_value(PARAM_INT, 'All the messages not read'),
            'conversation' => new external_single_structure($head + [
                'messages' => new external_multiple_structure(new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'The message'),
                    'kind' => new external_value(PARAM_ALPHA, 'text, conv, joined or left'),
                    'text' => new external_value(PARAM_RAW, 'The text'),
                    'at' => new external_value(PARAM_INT, 'When'),
                    'author' => $person(),
                    'mine' => new external_value(PARAM_BOOL, 'Whether whoever looks wrote it'),
                    'conv' => new external_single_structure([
                        'cwid' => new external_value(PARAM_INT, 'The conversation with a family'),
                        'name' => new external_value(PARAM_TEXT, 'Its contact'),
                        'phone' => new external_value(PARAM_RAW, 'Its number, only digits'),
                        'kind' => new external_value(PARAM_RAW, 'What the team marked the contact as, comma separated'),
                        'status' => new external_value(PARAM_ALPHA, 'open, pending, snoozed or resolved'),
                        'teamname' => new external_value(PARAM_TEXT, 'Its department'),
                    ], 'The card of a conversation with a family', VALUE_OPTIONAL),
                ])),
                'more' => new external_value(PARAM_BOOL, 'Whether there are older ones'),
            ], 'The conversation open', VALUE_OPTIONAL),
            'sent' => new external_value(PARAM_INT, 'The message sent', VALUE_OPTIONAL),
        ]);
    }
}
