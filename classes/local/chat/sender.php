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
 * Writes to the family of a conversation in the name of a person: a message of theirs, signed, or an approved message. The
 * conversation that nobody has is theirs from then on.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sender {
    /**
     * A message of a person, as the family reads it: their signature on top.
     *
     * @param viewer $viewer Who writes.
     * @param string $text What they write.
     * @return string
     */
    public static function signed(viewer $viewer, string $text): string {
        return '*' . message::signature($viewer) . "*\n" . $text;
    }

    /**
     * Sends a message of a person.
     *
     * @param viewer $viewer Who writes.
     * @param \stdClass $row The conversation.
     * @param string $text What they write.
     * @return array The message, as Chatwoot gives it.
     */
    public static function text(viewer $viewer, \stdClass $row, string $text): array {
        return self::send($viewer, $row, ['content' => self::signed($viewer, $text), 'message_type' => 'outgoing',
            'private' => false]);
    }

    /**
     * Sends an approved message.
     *
     * @param viewer $viewer Who sends it.
     * @param \stdClass $row The conversation.
     * @param array $template The message, as templates::find() gives it.
     * @param array $values What goes in its gaps, by gap.
     * @return array The message, as Chatwoot gives it.
     */
    public static function template(viewer $viewer, \stdClass $row, array $template, array $values): array {
        return self::send($viewer, $row, templates::message($template, $values), (string)$template['name']);
    }

    /**
     * Sends it, and keeps who did.
     *
     * @param viewer $viewer Who sends it.
     * @param \stdClass $row The conversation.
     * @param array $data The message, as Chatwoot takes it.
     * @param string $template The name of its template, for an approved one.
     * @return array The message, as Chatwoot gives it.
     */
    protected static function send(viewer $viewer, \stdClass $row, array $data, string $template = ''): array {
        $sent = directory::as_person($viewer, function (chatwoot $client) use ($row, $data, $viewer) {
            $sent = $client->call('POST', '/conversations/' . $row->cwid . '/messages', $data);
            if (!$row->assigneeid) {
                $client->call('POST', '/conversations/' . $row->cwid . '/assignments', ['assignee_id' => $viewer->agent['id']]);
            }
            return $sent;
        });
        message::remember((int)($sent['id'] ?? 0), (int)$row->cwid, $viewer->userid, '', $template);
        return $sent;
    }
}
