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

namespace local_oksigeniamarandu\local\chat;

/**
 * What a person does with a conversation, in their name: keep it, resolve it or open it again, pass it to a department
 * or to a person, say what kind of contact it is, or leave a note. The page does it, and Telegram too.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class actions {
    /** The kinds of contact a person can say. */
    public const KINDS = ['staff', 'supplier', 'alumni', 'none'];

    /**
     * Does it.
     *
     * @param viewer $viewer Who does it, who can write.
     * @param \stdClass $row The conversation, one the person sees.
     * @param string $action take, resolve, reopen, team, person, kind or optout.
     * @param int $target The department or the person.
     * @param string $value The kind; for optout, out (no more messages) or in.
     * @return \stdClass|null The conversation as it is now, or null if the person no longer sees it.
     */
    public static function run(viewer $viewer, \stdClass $row, string $action, int $target = 0, string $value = ''): ?\stdClass {
        global $DB;
        $id = (int)$row->cwid;
        $path = '/conversations/' . $id;
        switch ($action) {
            case 'take':
                directory::as_person($viewer, function (chatwoot $client) use ($path, $viewer) {
                    $client->call('POST', $path . '/assignments', ['assignee_id' => $viewer->agent['id']]);
                });
                break;
            case 'resolve':
            case 'reopen':
                $status = $action === 'resolve' ? 'resolved' : 'open';
                directory::as_person($viewer, function (chatwoot $client) use ($path, $status) {
                    $client->call('POST', $path . '/toggle_status', ['status' => $status]);
                });
                break;
            case 'team':
                if (!isset(directory::teams()[$target])) {
                    throw new \invalid_parameter_exception('Unknown department');
                }
                directory::as_person($viewer, function (chatwoot $client) use ($path, $target) {
                    // To the department, and nobody has it until someone of it keeps it.
                    $client->call('POST', $path . '/assignments', ['team_id' => $target]);
                    $client->call('POST', $path . '/assignments', ['assignee_id' => null]);
                });
                mirror::refresh($id, false);
                mirror::passed($id, $viewer->userid, 0, $target);
                break;
            case 'person':
                if (!directory::agent($target)) {
                    throw new \invalid_parameter_exception('Unknown person');
                }
                directory::as_person($viewer, function (chatwoot $client) use ($path, $target) {
                    $client->call('POST', $path . '/assignments', ['assignee_id' => $target]);
                });
                mirror::refresh($id, false);
                mirror::passed($id, $viewer->userid, $target, 0);
                break;
            case 'kind':
                if (!in_array($value, self::KINDS) || !$row->contactid) {
                    throw new \invalid_parameter_exception('Unknown kind');
                }
                directory::client()->call('PUT', '/contacts/' . $row->contactid, [
                    'custom_attributes' => [
                        'marandu_kind' => $value === 'none' ? '' : $value,
                        'marandu_kind_by' => fullname(\core_user::get_user($viewer->userid)),
                        'marandu_kind_at' => time(),
                    ],
                ]);
                break;
            case 'optout':
                // Said in the conversation: no more messages from the centre, on this number.
                if ($row->phone === '') {
                    throw new \invalid_parameter_exception('No number');
                }
                $contact = \local_oksigeniamarandu\local\consent::get_or_create((string)$row->phone);
                \local_oksigeniamarandu\local\consent::set_optout($contact, 'all', $value !== 'in', 'chat:' . $viewer->firstname);
                break;
            default:
                throw new \invalid_parameter_exception('Unknown action');
        }
        $now = in_array($action, ['team', 'person']) ? $DB->get_record(mirror::TABLE, ['cwid' => $id])
            : mirror::refresh($id, false);
        $now = $now ?: $row;
        return $viewer->sees($now) ? $now : null;
    }

    /**
     * Leaves a note of the team in a conversation, in the person's name: the family does not see it. «@Name» tells
     * whoever it names, as on the page.
     *
     * @param viewer $viewer Who writes it.
     * @param \stdClass $row The conversation.
     * @param string $text The note.
     * @return array The message, as Chatwoot gives it.
     */
    public static function note(viewer $viewer, \stdClass $row, string $text): array {
        $content = message::mentions(trim($text));
        $sent = directory::as_person($viewer, function (chatwoot $client) use ($row, $content) {
            return $client->call('POST', '/conversations/' . (int)$row->cwid . '/messages', [
                'content' => $content,
                'message_type' => 'outgoing',
                'private' => true,
            ]);
        });
        message::remember((int)($sent['id'] ?? 0), (int)$row->cwid, $viewer->userid);
        mirror::refresh((int)$row->cwid);
        return $sent;
    }
}
