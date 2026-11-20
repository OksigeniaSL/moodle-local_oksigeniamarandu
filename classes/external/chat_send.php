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
use local_oksigeniamarandu\local\chat\chatwoot;
use local_oksigeniamarandu\local\chat\directory;
use local_oksigeniamarandu\local\chat\message;
use local_oksigeniamarandu\local\chat\mirror;

/**
 * Writes in a conversation: an answer for the family, with the signature of whoever writes on top, or a note the
 * family does not see. Answering a conversation nobody has makes it yours.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_send extends chat_base {
    /**
     * The parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'conversation' => new external_value(PARAM_INT, 'The conversation'),
            'text' => new external_value(PARAM_RAW, 'What is written'),
            'private' => new external_value(PARAM_BOOL, 'Whether it is a note the family does not see', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Writes.
     *
     * @param int $conversation The conversation.
     * @param string $text What is written.
     * @param bool $private Whether it is a note.
     * @return array The message and the conversation.
     */
    public static function execute(int $conversation, string $text, bool $private = false): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['conversation' => $conversation, 'text' => $text, 'private' => $private]
        );
        $viewer = self::writer();
        $row = self::conversation($viewer, $params['conversation']);
        $text = trim(str_replace("\r\n", "\n", $params['text']));
        if ($text === '') {
            throw new \invalid_parameter_exception('Empty message');
        }
        if (!$params['private'] && $row->lastincoming + DAYSECS <= time()) {
            throw new \moodle_exception('chat:windowclosed', 'local_oksigeniamarandu');
        }
        $content = $params['private'] ? message::mentions($text) : '*' . message::signature($viewer) . "*\n" . $text;
        $sent = directory::as_person($viewer, function (chatwoot $client) use ($row, $content, $params, $viewer) {
            $sent = $client->call('POST', '/conversations/' . $row->cwid . '/messages', [
                'content' => $content,
                'message_type' => 'outgoing',
                'private' => $params['private'],
            ]);
            if (!$params['private'] && !$row->assigneeid) {
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
