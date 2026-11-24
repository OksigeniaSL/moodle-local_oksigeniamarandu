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
use local_oksigeniamarandu\local\chat\mirror;
use local_oksigeniamarandu\local\chat\scheduled;
use local_oksigeniamarandu\local\chat\templates;

/**
 * A message that goes out later: written, taken back, or sent now.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_schedule extends chat_base {
    /**
     * The parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'conversation' => new external_value(PARAM_INT, 'The conversation'),
            'action' => new external_value(PARAM_ALPHA, 'add, remove or now'),
            'id' => new external_value(PARAM_INT, 'The message, to take it back or send it now', VALUE_DEFAULT, 0),
            'at' => new external_value(PARAM_INT, 'When it goes, to write it', VALUE_DEFAULT, 0),
            'text' => new external_value(PARAM_RAW, 'What it says', VALUE_DEFAULT, ''),
            'name' => new external_value(PARAM_RAW_TRIMMED, 'The approved message, if it is one', VALUE_DEFAULT, ''),
            'language' => new external_value(PARAM_RAW_TRIMMED, 'Its language', VALUE_DEFAULT, ''),
            'params' => new external_multiple_structure(new external_single_structure([
                'key' => new external_value(PARAM_ALPHANUMEXT, 'The gap, such as 1'),
                'value' => new external_value(PARAM_TEXT, 'What goes in it'),
            ]), 'What goes in its gaps', VALUE_DEFAULT, []),
        ]);
    }

    /**
     * Does it.
     *
     * @param int $conversation The conversation.
     * @param string $action What to do.
     * @param int $id The message.
     * @param int $at When.
     * @param string $text What it says.
     * @param string $name The approved message.
     * @param string $language Its language.
     * @param array $params Its gaps.
     * @return array The conversation.
     */
    public static function execute(
        int $conversation,
        string $action,
        int $id = 0,
        int $at = 0,
        string $text = '',
        string $name = '',
        string $language = '',
        array $params = []
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), ['conversation' => $conversation, 'action' => $action,
            'id' => $id, 'at' => $at, 'text' => $text, 'name' => $name, 'language' => $language, 'params' => $params]);
        $viewer = self::writer();
        $row = self::conversation($viewer, $params['conversation']);
        switch ($params['action']) {
            case 'add':
                $template = null;
                $values = [];
                if ($params['name'] !== '') {
                    $template = templates::find($params['name'], $params['language']);
                    if (!$template) {
                        throw new \moodle_exception('chat:notemplate', 'local_oksigeniamarandu');
                    }
                    $values = self::values($template, $params['params']);
                }
                scheduled::add(
                    $viewer,
                    $row,
                    $params['at'],
                    str_replace("\r\n", "\n", $params['text']),
                    $template,
                    $values
                );
                break;
            case 'remove':
                if (!scheduled::remove($row, $params['id'])) {
                    throw new \moodle_exception('chat:schedgone', 'local_oksigeniamarandu');
                }
                break;
            case 'now':
                scheduled::now($viewer, $row, $params['id']);
                break;
            default:
                throw new \invalid_parameter_exception('Unknown action');
        }
        $viewer->forget_scheduled();
        return ['conversation' => self::export($viewer, mirror::refresh((int)$row->cwid, false) ?? $row)];
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
