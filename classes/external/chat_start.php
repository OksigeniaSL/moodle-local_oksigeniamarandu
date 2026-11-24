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
use local_oksigeniamarandu\local\chat\starter;
use local_oksigeniamarandu\local\chat\templates;

/**
 * Starts a conversation with someone who has none open: with an approved message of WhatsApp, in a department, kept by
 * whoever starts it.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_start extends chat_base {
    /**
     * The parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'phone' => new external_value(PARAM_RAW_TRIMMED, 'The number, with its country code'),
            'contactname' => new external_value(PARAM_TEXT, 'Who it is, for a new contact', VALUE_DEFAULT, ''),
            'team' => new external_value(PARAM_INT, 'The department, or 0', VALUE_DEFAULT, 0),
            'name' => new external_value(PARAM_RAW_TRIMMED, 'The approved message'),
            'language' => new external_value(PARAM_RAW_TRIMMED, 'Its language'),
            'params' => new external_multiple_structure(new external_single_structure([
                'key' => new external_value(PARAM_ALPHANUMEXT, 'The gap, such as 1'),
                'value' => new external_value(PARAM_TEXT, 'What goes in it'),
            ]), 'What goes in its gaps', VALUE_DEFAULT, []),
        ]);
    }

    /**
     * Starts it.
     *
     * @param string $phone The number.
     * @param string $contactname Who it is.
     * @param int $team The department.
     * @param string $name The approved message.
     * @param string $language Its language.
     * @param array $params Its gaps.
     * @return array status (started, exists or busy), the conversation or where it is.
     */
    public static function execute(
        string $phone,
        string $contactname,
        int $team,
        string $name,
        string $language,
        array $params = []
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), ['phone' => $phone, 'contactname' => $contactname,
            'team' => $team, 'name' => $name, 'language' => $language, 'params' => $params]);
        $viewer = self::writer();
        $number = starter::number($params['phone']);
        if ($number === null) {
            throw new \moodle_exception('chat:start:badnumber', 'local_oksigeniamarandu');
        }
        $template = templates::find($params['name'], $params['language']);
        if (!$template) {
            throw new \moodle_exception('chat:notemplate', 'local_oksigeniamarandu');
        }
        if ($params['team'] && !in_array($params['team'], array_column(directory::teams(), 'id'))) {
            throw new \moodle_exception('chat:start:noteam', 'local_oksigeniamarandu');
        }
        $values = self::values($template, $params['params']);
        $result = starter::start($viewer, $number, trim($params['contactname']), $params['team'], $template, $values);
        $answer = ['status' => $result['status'], 'where' => (string)($result['where'] ?? '')];
        if (!empty($result['row'])) {
            $answer['conversation'] = self::export($viewer, $result['row']);
        }
        return $answer;
    }

    /**
     * What it returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $conversation = self::conversation_structure();
        $conversation->required = VALUE_OPTIONAL;
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'started, exists (it had one open: this one) or busy (another has it)'),
            'where' => new external_value(PARAM_TEXT, 'Where the open one is, when it is busy'),
            'conversation' => $conversation,
        ]);
    }
}
