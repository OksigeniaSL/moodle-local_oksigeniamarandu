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
use local_oksigeniamarandu\local\chat\chatwoot;
use local_oksigeniamarandu\local\chat\directory;
use local_oksigeniamarandu\local\chat\message;
use local_oksigeniamarandu\local\chat\mirror;
use local_oksigeniamarandu\local\chat\templates;

/**
 * Sends an approved message of WhatsApp, the only way to write to a family more than a day after its last message.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_template extends chat_base {
    /**
     * The parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'conversation' => new external_value(PARAM_INT, 'The conversation'),
            'name' => new external_value(PARAM_RAW_TRIMMED, 'The approved message'),
            'language' => new external_value(PARAM_RAW_TRIMMED, 'Its language'),
            'params' => new external_multiple_structure(new external_single_structure([
                'key' => new external_value(PARAM_ALPHANUMEXT, 'The gap, such as 1'),
                'value' => new external_value(PARAM_TEXT, 'What goes in it'),
            ]), 'What goes in its gaps', VALUE_DEFAULT, []),
        ]);
    }

    /**
     * Sends it.
     *
     * @param int $conversation The conversation.
     * @param string $name The approved message.
     * @param string $language Its language.
     * @param array $params Its gaps.
     * @return array The message and the conversation.
     */
    public static function execute(int $conversation, string $name, string $language, array $params = []): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['conversation' => $conversation, 'name' => $name, 'language' => $language, 'params' => $params]
        );
        $viewer = self::writer();
        $row = self::conversation($viewer, $params['conversation']);
        $template = templates::find($params['name'], $params['language']);
        if (!$template) {
            throw new \moodle_exception('chat:notemplate', 'local_oksigeniamarandu');
        }
        $values = [];
        foreach ($params['params'] as $param) {
            $values[$param['key']] = trim(preg_replace('/\s+/', ' ', $param['value']));
        }
        foreach ($template['params'] as $gap) {
            if (($values[$gap['key']] ?? '') === '') {
                throw new \moodle_exception('chat:templategap', 'local_oksigeniamarandu');
            }
        }
        $sent = directory::as_person($viewer, function (chatwoot $client) use ($row, $template, $values, $viewer) {
            $sent = $client->call('POST', '/conversations/' . $row->cwid . '/messages', templates::message($template, $values));
            if (!$row->assigneeid) {
                $client->call('POST', '/conversations/' . $row->cwid . '/assignments', ['assignee_id' => $viewer->agent['id']]);
            }
            return $sent;
        });
        message::remember((int)($sent['id'] ?? 0), (int)$row->cwid, $viewer->userid);
        $row = mirror::refresh((int)$row->cwid) ?? $row;
        return [
            'message' => message::export($sent, $viewer, [(int)($sent['id'] ?? 0) => $viewer->userid]),
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
