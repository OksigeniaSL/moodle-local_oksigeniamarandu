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
use local_oksigeniamarandu\local\chat\message;
use local_oksigeniamarandu\local\chat\mirror;

/**
 * What the page of the conversations asks every few seconds: the trays, the conversations that changed and the new
 * messages of the one open.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_poll extends chat_base {
    /**
     * The parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'since' => new external_value(PARAM_INT, 'The cursor of the last answer; 0 for everything', VALUE_DEFAULT, 0),
            'conversation' => new external_value(PARAM_INT, 'The conversation open, or 0', VALUE_DEFAULT, 0),
            'after' => new external_value(PARAM_INT, 'The last message the page has of it; 0 for the latest', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * What changed.
     *
     * @param int $since The cursor.
     * @param int $conversation The conversation open.
     * @param int $after Its last message on the page.
     * @return array
     */
    public static function execute(int $since = 0, int $conversation = 0, int $after = 0): array {
        global $DB;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['since' => $since, 'conversation' => $conversation, 'after' => $after]
        );
        $viewer = self::viewer();
        try {
            mirror::sync_from_page();
        } catch (\moodle_exception $e) {
            // Chatwoot does not answer now: the page goes on with what the index has.
            debugging('Marandu: the conversations did not come: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        $now = time();
        [$where, $wparams] = $viewer->where();
        $conversations = [];
        $removed = [];
        if ($params['since'] > 0) {
            $rows = $DB->get_records_select(mirror::TABLE, 'timemodified >= :since', ['since' => $params['since']]);
            foreach ($rows as $row) {
                if ($viewer->sees($row)) {
                    $conversations[] = $row;
                } else {
                    $removed[] = (int)$row->cwid;
                }
            }
        } else {
            // Everything open, and what was resolved today.
            $today = usergetmidnight($now);
            $conversations = $DB->get_records_sql(
                "SELECT c.* FROM {" . mirror::TABLE . "} c
                                                   WHERE $where AND (c.status <> 'resolved' OR c.lastactivity >= :today)
                                                ORDER BY c.lastactivity DESC",
                $wparams + ['today' => $today]
            );
        }
        $seen = $viewer->seen();
        $messages = [];
        $readby = [];
        $open = null;
        if ($params['conversation']) {
            $open = $DB->get_record(mirror::TABLE, ['cwid' => $params['conversation']]);
            if ($open && $viewer->sees($open)) {
                $viewer->read((int)$open->cwid);
                $seen[$open->cwid] = $now;
                if (!$params['after'] || $open->timemodified >= $params['since']) {
                    $messages = self::messages($viewer, (int)$open->cwid, $params['after']);
                }
                $readby = self::readby($viewer, $open);
            }
        }
        $list = [];
        foreach ($conversations as $row) {
            $list[] = self::clean($viewer->export($row, $seen, $now));
        }
        return [
            'now' => $now,
            'cursor' => $now,
            'inboxes' => $viewer->inboxes(),
            'conversations' => $list,
            'removed' => $removed,
            'messages' => $messages,
            'readby' => $readby,
        ];
    }

    /**
     * Who else of the team read the last message of the contact in a conversation.
     *
     * @param \local_oksigeniamarandu\local\chat\viewer $viewer Who looks.
     * @param \stdClass $row The conversation.
     * @return array Each with name and at.
     */
    protected static function readby($viewer, \stdClass $row): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT s.userid, s.timeseen, u.firstname
                                        FROM {" . \local_oksigeniamarandu\local\chat\viewer::SEEN . "} s
                                        JOIN {user} u ON u.id = s.userid
                                       WHERE s.convid = :convid AND s.userid <> :me AND s.timeseen >= :since
                                    ORDER BY s.timeseen ASC",
            ['convid' => $row->cwid, 'me' => $viewer->userid, 'since' => (int)$row->lastincoming]
        );
        $list = [];
        foreach ($rows as $one) {
            $list[] = ['name' => (string)$one->firstname, 'at' => (int)$one->timeseen];
        }
        return $list;
    }

    /**
     * The messages of a conversation after one, or the latest.
     *
     * @param \local_oksigeniamarandu\local\chat\viewer $viewer Who looks.
     * @param int $id The conversation.
     * @param int $after The last one the page has, or 0.
     * @return array
     */
    protected static function messages($viewer, int $id, int $after): array {
        try {
            $query = $after ? ['after' => $after] : [];
            $answer = directory::client()->call('GET', '/conversations/' . $id . '/messages', $query);
        } catch (\moodle_exception $e) {
            return [];
        }
        $raw = array_values($answer['payload'] ?? []);
        $list = message::export_list($raw, $viewer);
        return $list;
    }

    /**
     * What it returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $tray = new external_single_structure([
            'reply' => new external_value(PARAM_INT, 'How many wait for an answer'),
            'total' => new external_value(PARAM_INT, 'How many are open'),
        ]);
        return new external_single_structure([
            'now' => new external_value(PARAM_INT, 'The time of the server'),
            'cursor' => new external_value(PARAM_INT, 'What to pass as since next time'),
            'inboxes' => new external_single_structure([
                'mine' => $tray,
                'unassigned' => $tray,
                'teams' => new external_multiple_structure(new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'The department'),
                    'name' => new external_value(PARAM_TEXT, 'Its name'),
                    'mine' => new external_value(PARAM_BOOL, 'Whether whoever looks is in it'),
                    'internal' => new external_value(PARAM_BOOL, 'Whether it is not in the menu of the families'),
                    'reply' => new external_value(PARAM_INT, 'How many wait for an answer'),
                    'unassigned' => new external_value(PARAM_INT, 'How many nobody has'),
                    'total' => new external_value(PARAM_INT, 'How many are open'),
                ])),
            ]),
            'conversations' => new external_multiple_structure(self::conversation_structure()),
            'removed' => new external_multiple_structure(new external_value(PARAM_INT, 'A conversation no longer seen')),
            'messages' => new external_multiple_structure(self::message_structure()),
            'readby' => new external_multiple_structure(new external_single_structure([
                'name' => new external_value(PARAM_TEXT, 'The first name'),
                'at' => new external_value(PARAM_INT, 'When they read it'),
            ]), 'Who else of the team read the last message of the contact in the conversation open'),
        ]);
    }
}
