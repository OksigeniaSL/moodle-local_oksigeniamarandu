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

/**
 * The messages of a conversation before one, as the page asks for them going up.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_messages extends chat_base {
    /**
     * The parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'conversation' => new external_value(PARAM_INT, 'The conversation'),
            'before' => new external_value(PARAM_INT, 'The first message the page has; 0 for the latest', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * The messages.
     *
     * @param int $conversation The conversation.
     * @param int $before The first message the page has.
     * @return array
     */
    public static function execute(int $conversation, int $before = 0): array {
        $params = self::validate_parameters(self::execute_parameters(), ['conversation' => $conversation, 'before' => $before]);
        $viewer = self::viewer();
        $row = self::conversation($viewer, $params['conversation']);
        $answer = directory::client()->call(
            'GET',
            '/conversations/' . $row->cwid . '/messages',
            $params['before'] ? ['before' => $params['before']] : []
        );
        $raw = array_values($answer['payload'] ?? []);
        $list = message::export_list($raw, $viewer);
        // Chatwoot gives twenty at a time: fewer means there are no more.
        return ['messages' => $list, 'more' => count($raw) >= 20];
    }

    /**
     * What it returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'messages' => new external_multiple_structure(self::message_structure()),
            'more' => new external_value(PARAM_BOOL, 'Whether there are older ones'),
        ]);
    }
}
