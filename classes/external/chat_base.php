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

use core_external\external_api;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_oksigeniamarandu\local\chat\directory;
use local_oksigeniamarandu\local\chat\mirror;
use local_oksigeniamarandu\local\chat\viewer;

/**
 * What the functions of the page of the conversations share: who calls and the shapes they give.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class chat_base extends external_api {
    /**
     * The person who calls, if they are one of the team.
     *
     * @return viewer
     * @throws \moodle_exception If they are not.
     */
    protected static function viewer(): viewer {
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/oksigeniamarandu:chat', $context);
        $viewer = viewer::for_user();
        if (!$viewer) {
            throw new \moodle_exception('chat:noagent', 'local_oksigeniamarandu');
        }
        return $viewer;
    }

    /**
     * The person who calls, if they can write: not whoever only looks.
     *
     * @return viewer
     * @throws \moodle_exception If they cannot.
     */
    protected static function writer(): viewer {
        $viewer = self::viewer();
        if ($viewer->readonly) {
            throw new \moodle_exception('chat:readonly', 'local_oksigeniamarandu');
        }
        return $viewer;
    }

    /**
     * A conversation the person sees, from the index.
     *
     * @param viewer $viewer The person.
     * @param int $id The conversation.
     * @return \stdClass Its row.
     * @throws \moodle_exception If it does not exist or they do not see it.
     */
    protected static function conversation(viewer $viewer, int $id): \stdClass {
        global $DB;
        $row = $DB->get_record(mirror::TABLE, ['cwid' => $id]) ?: mirror::refresh($id);
        if (!$row || !$viewer->sees($row)) {
            throw new \moodle_exception('chat:notyours', 'local_oksigeniamarandu');
        }
        return $row;
    }

    /**
     * The shape of a conversation.
     *
     * @return external_single_structure
     */
    protected static function conversation_structure(): external_single_structure {
        $person = new external_single_structure([
            'agentid' => new external_value(PARAM_INT, 'The person in Chatwoot'),
            'name' => new external_value(PARAM_TEXT, 'Their name'),
            'firstname' => new external_value(PARAM_TEXT, 'Their first name'),
            'me' => new external_value(PARAM_BOOL, 'Whether it is whoever looks'),
        ], 'Who has it', VALUE_OPTIONAL);
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'The number of the conversation in the account'),
            'status' => new external_value(PARAM_ALPHA, 'open, pending, snoozed or resolved'),
            'section' => new external_value(PARAM_ALPHA, 'reply, waiting, later (apart until a day) or resolved'),
            'teamid' => new external_value(PARAM_INT, 'Its department, or 0'),
            'assignee' => $person,
            'contact' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'The contact in Chatwoot'),
                'name' => new external_value(PARAM_TEXT, 'Its name'),
                'phone' => new external_value(PARAM_RAW, 'Its number, only digits'),
                'kind' => new external_value(PARAM_ALPHA, 'family, applicant, both, staff, supplier, alumni or unknown'),
                'line' => new external_value(PARAM_TEXT, 'Who it is, in a line'),
                'rings' => new external_multiple_structure(new external_single_structure([
                    'color' => new external_value(PARAM_RAW, 'The colour'),
                    'dark' => new external_value(PARAM_RAW, 'The colour on a dark page', VALUE_DEFAULT, ''),
                    'dashed' => new external_value(PARAM_BOOL, 'Whether it is not inside yet'),
                    'name' => new external_value(PARAM_TEXT, 'The child', VALUE_DEFAULT, ''),
                ])),
            ]),
            'preview' => new external_single_structure([
                'text' => new external_value(PARAM_RAW, 'The last message, as text'),
                'kind' => new external_value(PARAM_ALPHA, 'Its kind'),
                'incoming' => new external_value(PARAM_BOOL, 'Whether the contact wrote it'),
                'private' => new external_value(PARAM_BOOL, 'Whether it is a note'),
                'at' => new external_value(PARAM_INT, 'When'),
            ]),
            'waitsince' => new external_value(PARAM_INT, 'Since when the contact waits for an answer, or 0'),
            'lastincoming' => new external_value(PARAM_INT, 'The last message of the contact'),
            'canreply' => new external_value(PARAM_BOOL, 'Whether one can still write freely'),
            'replyuntil' => new external_value(PARAM_INT, 'Until when one can write freely'),
            'unread' => new external_value(PARAM_BOOL, 'Whether whoever looks has not read its last message'),
            'passed' => new external_single_structure([
                'by' => new external_value(PARAM_TEXT, 'Who passed it'),
                'at' => new external_value(PARAM_INT, 'When'),
                'toteam' => new external_value(PARAM_BOOL, 'Whether it went to the department and not to the person'),
            ], 'Whether someone passed it to whoever looks', VALUE_OPTIONAL),
            'remind' => new external_single_structure([
                'at' => new external_value(PARAM_INT, 'When it comes back'),
                'reason' => new external_value(PARAM_TEXT, 'What for'),
            ], 'The day whoever looks asked to have it back', VALUE_OPTIONAL),
            'lastactivity' => new external_value(PARAM_INT, 'Its last movement'),
            'rev' => new external_value(PARAM_INT, 'When its row changed: an answer with an older one is out of date'),
        ]);
    }

    /**
     * The shape of a message.
     *
     * @return external_single_structure
     */
    protected static function message_structure(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'The message in Chatwoot'),
            'kind' => new external_value(PARAM_ALPHA, 'incoming, outgoing, note, bot or activity'),
            'text' => new external_value(PARAM_RAW, 'Its text'),
            'at' => new external_value(PARAM_INT, 'When'),
            'author' => new external_single_structure([
                'name' => new external_value(PARAM_TEXT, 'Who wrote it'),
                'me' => new external_value(PARAM_BOOL, 'Whether it was whoever looks'),
            ]),
            'signature' => new external_value(PARAM_TEXT, 'The signature the family read on top'),
            'status' => new external_value(PARAM_ALPHA, 'sent, delivered, read, failed, progress or empty'),
            'error' => new external_value(PARAM_TEXT, 'Why it did not arrive'),
            'attachments' => new external_multiple_structure(new external_single_structure([
                'type' => new external_value(PARAM_ALPHA, 'image, audio, video or file'),
                'url' => new external_value(PARAM_RAW, 'Where it is'),
                'thumb' => new external_value(PARAM_RAW, 'Its small picture'),
                'name' => new external_value(PARAM_TEXT, 'Its name'),
                'size' => new external_value(PARAM_INT, 'Its size in bytes'),
            ])),
            'template' => new external_value(PARAM_RAW, 'The approved message it was, if it was one'),
        ]);
    }

    /**
     * A conversation for the page, with what changed of it.
     *
     * @param viewer $viewer The person.
     * @param \stdClass $row Its row.
     * @return array
     */
    protected static function export(viewer $viewer, \stdClass $row): array {
        return self::clean($viewer->export($row, $viewer->seen([(int)$row->cwid]), time()));
    }

    /**
     * Takes out the optional parts that are empty, as the shapes want them.
     *
     * @param array $conversation A conversation for the page.
     * @return array
     */
    protected static function clean(array $conversation): array {
        foreach (['assignee', 'passed', 'remind'] as $field) {
            if (($conversation[$field] ?? null) === null) {
                unset($conversation[$field]);
            }
        }
        return $conversation;
    }

    /**
     * The departments, for the shapes.
     *
     * @return array
     */
    protected static function teams(): array {
        return directory::teams();
    }
}
