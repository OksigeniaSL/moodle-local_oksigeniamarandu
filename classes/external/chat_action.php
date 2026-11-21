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

/**
 * What a person does with a conversation: keep it, pass it to a department or to a person, resolve it, open it again,
 * mark it read, or say who the contact is.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_action extends chat_base {
    /** The kinds a person can mark a contact with. */

    /**
     * The parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'conversation' => new external_value(PARAM_INT, 'The conversation'),
            'action' => new external_value(PARAM_ALPHA, 'take, resolve, reopen, read, team, person, kind, whois or optout'),
            'target' => new external_value(PARAM_INT, 'The department or the person', VALUE_DEFAULT, 0),
            'value' => new external_value(
                PARAM_RAW,
                'The kind of contact; for whois, the change as JSON; for optout, out or in',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Does it.
     *
     * @param int $conversation The conversation.
     * @param string $action What.
     * @param int $target The department or the person.
     * @param string $value The kind of contact.
     * @return array The conversation.
     */
    public static function execute(int $conversation, string $action, int $target = 0, string $value = ''): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['conversation' => $conversation, 'action' => $action, 'target' => $target, 'value' => $value]
        );
        $viewer = $params['action'] === 'read' ? self::viewer() : self::writer();
        $row = self::conversation($viewer, $params['conversation']);
        if ($params['action'] === 'read') {
            $viewer->read((int)$row->cwid);
            return ['conversation' => self::export($viewer, $row)];
        }
        $now = actions::run($viewer, $row, $params['action'], $params['target'], $params['value']);
        if (!$now) {
            // Passed to a department this person does not see: the page takes it out of the list.
            $row->status = 'gone';
            return ['conversation' => self::export($viewer, $row)];
        }
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
