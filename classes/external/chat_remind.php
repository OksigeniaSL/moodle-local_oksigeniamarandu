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
use local_oksigeniamarandu\local\chat\reminders;

/**
 * «Remind me»: a person asks to have a conversation back on a day, with a line of what for, or takes it away.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_remind extends chat_base {
    /**
     * The parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'conversation' => new external_value(PARAM_INT, 'The conversation'),
            'day' => new external_value(PARAM_RAW, 'The day, Y-m-d; empty to take the reminder away', VALUE_DEFAULT, ''),
            'reason' => new external_value(PARAM_TEXT, 'What for, in a line', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Does it.
     *
     * @param int $conversation The conversation.
     * @param string $day The day, or empty.
     * @param string $reason What for.
     * @return array The conversation.
     */
    public static function execute(int $conversation, string $day = '', string $reason = ''): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['conversation' => $conversation, 'day' => $day, 'reason' => $reason]
        );
        $viewer = self::writer();
        $row = self::conversation($viewer, $params['conversation']);
        if ($params['day'] === '') {
            $now = reminders::remove($viewer, $row);
        } else {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $params['day'])) {
                throw new \invalid_parameter_exception('The day is not a date');
            }
            $now = reminders::set($viewer, $row, $params['day'], $params['reason']);
        }
        $viewer->forget_reminders();
        return ['conversation' => self::export($viewer, $now)];
    }

    /**
     * What it returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'conversation' => self::conversation_structure(),
        ]);
    }
}
