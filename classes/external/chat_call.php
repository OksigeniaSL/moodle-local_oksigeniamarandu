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
use core_external\external_single_structure;
use core_external\external_value;
use local_oksigeniamarandu\local\chat\actions;
use local_oksigeniamarandu\local\chat\message;
use local_oksigeniamarandu\local\chat\mirror;

/**
 * Writes down a call of the team to the contact of a conversation: whether they answered and, if the person wants, a
 * line of what was said. It stays as a note the family does not see, drawn on the page as a call.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_call extends chat_base {
    /**
     * The parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'conversation' => new external_value(PARAM_INT, 'The conversation'),
            'answered' => new external_value(PARAM_BOOL, 'Whether they answered'),
            'text' => new external_value(PARAM_TEXT, 'What was said, if the person wants', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Writes it down.
     *
     * @param int $conversation The conversation.
     * @param bool $answered Whether they answered.
     * @param string $text What was said.
     * @return array The message and the conversation.
     */
    public static function execute(int $conversation, bool $answered, string $text = ''): array {
        global $DB;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['conversation' => $conversation, 'answered' => $answered, 'text' => $text]
        );
        $viewer = self::writer();
        $row = self::conversation($viewer, $params['conversation']);
        $line = \core_text::substr(trim($params['text']), 0, 1000);
        $sent = actions::call($viewer, $row, $params['answered'], $line);
        $id = (int)($sent['id'] ?? 0);
        // Written down, the index is already up to date with it.
        $row = $DB->get_record(mirror::TABLE, ['cwid' => $row->cwid]) ?: $row;
        $call = [$id => $params['answered'] ? 'answered' : 'missed'];
        return [
            'message' => message::export($sent, $viewer, [$id => $viewer->userid], $call),
            'conversation' => self::export($viewer, $row),
        ];
    }

    /**
     * What it returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'message' => self::message_structure(),
            'conversation' => self::conversation_structure(),
        ]);
    }
}
