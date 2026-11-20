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
 * A file a person sends in a conversation: with its text and their signature as the caption the family reads, or as a
 * note. Answering a conversation nobody has makes it theirs, as with a text.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload {
    /** The largest file: what WhatsApp takes for a picture or an audio is less, for a document more. */
    public const MAX = 16 * 1024 * 1024;

    /**
     * Sends it.
     *
     * @param int $conversation The conversation.
     * @param string $path The file on disk.
     * @param string $name Its name.
     * @param int $size Its size.
     * @param string $text What goes with it.
     * @param bool $private Whether it is a note.
     * @return array message and conversation, as the page takes them.
     * @throws \moodle_exception If the person cannot, the window is closed or the file is too big.
     */
    public static function send(int $conversation, string $path, string $name, int $size, string $text, bool $private): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/filelib.php');
        $viewer = viewer::for_user();
        if (!$viewer || $viewer->readonly) {
            throw new \moodle_exception('chat:readonly', 'local_oksigeniamarandu');
        }
        $row = $DB->get_record(mirror::TABLE, ['cwid' => $conversation]);
        if (!$row || !$viewer->sees($row)) {
            throw new \moodle_exception('chat:notyours', 'local_oksigeniamarandu');
        }
        if (!$private && $row->lastincoming + DAYSECS <= time()) {
            throw new \moodle_exception('chat:windowclosed', 'local_oksigeniamarandu');
        }
        if ($size <= 0 || $size > self::MAX) {
            throw new \moodle_exception('chat:filetoobig', 'local_oksigeniamarandu', '', display_size(self::MAX));
        }
        $text = trim(str_replace("\r\n", "\n", $text));
        $content = $private ? message::mentions($text) : trim('*' . message::signature($viewer) . "*\n" . $text);
        $mimetype = mimeinfo('type', $name) ?: 'application/octet-stream';
        $sent = directory::as_person($viewer, function (chatwoot $client) use (
            $row,
            $content,
            $private,
            $path,
            $name,
            $mimetype,
            $viewer
) {
            $sent = $client->upload((int)$row->cwid, ['content' => $content, 'message_type' => 'outgoing',
                'private' => $private], $path, $name, $mimetype);
            if (!$private && !$row->assigneeid) {
                $client->call('POST', '/conversations/' . $row->cwid . '/assignments', ['assignee_id' => $viewer->agent['id']]);
            }
            return $sent;
        });
        message::remember((int)($sent['id'] ?? 0), (int)$row->cwid, $viewer->userid);
        $row = mirror::refresh((int)$row->cwid) ?? $row;
        $exported = $viewer->export($row, $viewer->seen([(int)$row->cwid]), time());
        foreach (['assignee', 'passed'] as $field) {
            if ($exported[$field] === null) {
                unset($exported[$field]);
            }
        }
        return [
            'message' => message::export($sent, $viewer, [(int)($sent['id'] ?? 0) => $viewer->userid]),
            'conversation' => $exported,
        ];
    }
}
