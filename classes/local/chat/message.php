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
 * The messages of a conversation: what Chatwoot gives, as the page shows it; and what a person writes, with their
 * signature on top in bold, as the family reads it, and who wrote it kept in Moodle.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class message {
    /** The table of who wrote each message from Moodle. */
    public const TABLE = 'local_oksigeniamarandu_convmsg';

    /** The types of message of Chatwoot, by their number. */
    private const TYPES = [0 => 'incoming', 1 => 'outgoing', 2 => 'activity', 3 => 'template'];

    /**
     * What a message is for the page: incoming (from the contact), outgoing (from a person of the team), note
     * (private), bot (from a bot or automatic) or activity (what happened, such as an assignment).
     *
     * @param array $message The message, as Chatwoot gives it.
     * @return string
     */
    public static function kind(array $message): string {
        $type = $message['message_type'] ?? 0;
        $type = is_numeric($type) ? (self::TYPES[(int)$type] ?? 'activity') : (string)$type;
        if ($type === 'activity') {
            return 'activity';
        }
        if (!empty($message['private'])) {
            return 'note';
        }
        if ($type === 'incoming') {
            return 'incoming';
        }
        $sender = \core_text::strtolower((string)($message['sender_type'] ?? $message['sender']['type'] ?? ''));
        $senderid = (int)($message['sender_id'] ?? $message['sender']['id'] ?? 0);
        if ($sender === 'user' && !in_array($senderid, self::automatic())) {
            return 'outgoing';
        }
        // A bot, an automatic message or a form: not an answer of a person.
        return 'bot';
    }

    /**
     * The users of Chatwoot that write as a bot, but the user of this site (what it sends is from a person of Moodle).
     *
     * @return int[]
     */
    protected static function automatic(): array {
        $self = (int)get_config('local_oksigeniamarandu', 'chatwootself');
        return array_values(array_diff(array_map('intval', explode(
            ',',
            (string)get_config('local_oksigeniamarandu', 'chatautomatic')
        )), [$self, 0]));
    }

    /**
     * A message for the page.
     *
     * @param array $message The message, as Chatwoot gives it.
     * @param viewer $viewer Who looks.
     * @param array $authors Who wrote each message from Moodle, by its id: the id of the user.
     * @return array As the contract of the page says.
     */
    public static function export(array $message, viewer $viewer, array $authors = []): array {
        $kind = self::kind($message);
        $text = (string)($message['content'] ?? '');
        $signature = '';
        if ($kind === 'outgoing' && preg_match('/^\*([^*\n]{1,80})\*\n/u', $text, $match)) {
            $signature = $match[1];
            $text = (string)substr($text, strlen($match[0]));
        }
        $id = (int)($message['id'] ?? 0);
        $userid = (int)($authors[$id] ?? 0);
        $sender = $message['sender'] ?? [];
        $name = (string)($sender['available_name'] ?? $sender['name'] ?? '');
        if ($userid) {
            $user = \core_user::get_user($userid);
            $name = $user ? fullname($user) : $name;
        }
        $attachments = [];
        foreach ($message['attachments'] ?? [] as $attachment) {
            $url = (string)($attachment['data_url'] ?? '');
            $type = (string)($attachment['file_type'] ?? 'file');
            $attachments[] = [
                'type' => in_array($type, ['image', 'audio', 'video']) ? $type : 'file',
                'url' => $url,
                'thumb' => (string)($attachment['thumb_url'] ?? ''),
                'name' => $url === '' ? '' : rawurldecode(basename((string)parse_url($url, PHP_URL_PATH))),
                'size' => (int)($attachment['file_size'] ?? 0),
            ];
        }
        $template = (string)($message['additional_attributes']['template_params']['name'] ?? '');
        $status = (string)($message['status'] ?? '');
        return [
            'id' => $id,
            'kind' => $kind,
            'text' => self::plain_text($text),
            'at' => (int)($message['created_at'] ?? 0),
            'author' => ['name' => $name, 'me' => $userid === $viewer->userid],
            'signature' => $signature,
            'status' => in_array($status, ['sent', 'delivered', 'read', 'failed', 'progress']) ? $status : '',
            'error' => (string)($message['content_attributes']['external_error'] ?? ''),
            'attachments' => $attachments,
            'template' => $template,
        ];
    }

    /**
     * The text of a message as people read it: the mentions of Chatwoot become «@Name».
     *
     * @param string $text The content.
     * @return string
     */
    public static function plain_text(string $text): string {
        return preg_replace('~\[@?([^\]]+)\]\(mention://(?:user|team)/\d+/[^)]*\)~u', '@$1', $text);
    }

    /**
     * The signature of a person: the one the site gave them, or their first name and their first department.
     *
     * @param viewer $viewer The person.
     * @return string
     */
    public static function signature(viewer $viewer): string {
        $people = json_decode((string)get_config('local_oksigeniamarandu', 'chatpeople'), true);
        $own = trim((string)($people[$viewer->agent['id']]['signature'] ?? ''));
        if ($own !== '') {
            return $own;
        }
        $teams = directory::teams();
        foreach ($viewer->teams as $teamid) {
            if (isset($teams[$teamid])) {
                return $viewer->firstname . ' · ' . $teams[$teamid]['name'];
            }
        }
        return $viewer->firstname;
    }

    /**
     * The mentions of a note as Chatwoot takes them, so that it tells whoever is named: «@Name» of a person of the
     * account, by their whole name or their first name.
     *
     * @param string $text The note.
     * @return string
     */
    public static function mentions(string $text): string {
        $names = [];
        foreach (directory::agents() as $agent) {
            $full = trim($agent['name']);
            if ($full === '') {
                continue;
            }
            $names[$full] = $agent;
            $first = strtok($full, ' ');
            if ($first !== false && !isset($names[$first])) {
                $names[$first] = $agent;
            }
        }
        // The longest names first, so that «@Ana Pérez» is not taken as «@Ana».
        uksort($names, function ($a, $b) {
            return \core_text::strlen($b) <=> \core_text::strlen($a);
        });
        foreach ($names as $name => $agent) {
            $text = preg_replace_callback(
                '/(^|[\s(])@' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/u',
                function ($match) use ($agent, $name) {
                    return $match[1] . '[@' . $name . '](mention://user/' . $agent['id'] . '/' . rawurlencode($agent['name']) . ')';
                },
                $text
            );
        }
        return $text;
    }

    /**
     * Who wrote each of these messages from Moodle.
     *
     * @param int[] $ids The ids of the messages.
     * @return array By id of the message, the id of the user.
     */
    public static function authors(array $ids): array {
        global $DB;
        $ids = array_filter(array_map('intval', $ids));
        if (!$ids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($ids);
        return $DB->get_records_select_menu(self::TABLE, "cwmsgid $in", $params, '', 'cwmsgid, userid');
    }

    /**
     * Keeps who wrote a message.
     *
     * @param int $messageid The message in Chatwoot.
     * @param int $conversationid Its conversation.
     * @param int $userid Who wrote it.
     */
    public static function remember(int $messageid, int $conversationid, int $userid): void {
        global $DB;
        if ($messageid && !$DB->record_exists(self::TABLE, ['cwmsgid' => $messageid])) {
            $DB->insert_record(self::TABLE, (object)['cwmsgid' => $messageid, 'convid' => $conversationid,
                'userid' => $userid, 'timecreated' => time()]);
        }
    }
}
