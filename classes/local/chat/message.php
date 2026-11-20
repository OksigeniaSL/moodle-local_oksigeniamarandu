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
        if (!empty($message['content_attributes']['external_echo'])) {
            // Written by a person in the app of WhatsApp Business of the number (coexistence), or brought from it.
            return 'outgoing';
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
        } else if ($name === '' && !empty($message['content_attributes']['external_echo'])) {
            $name = get_string('chat:fromphone', 'local_oksigeniamarandu');
        }
        $attachments = [];
        $conversation = (int)($message['conversation_id'] ?? 0);
        foreach (array_values($message['attachments'] ?? []) as $index => $attachment) {
            $url = (string)($attachment['data_url'] ?? '');
            $type = (string)($attachment['file_type'] ?? 'file');
            // Served by the site, to whoever sees the conversation: it opens inside the app too.
            $proxy = new \moodle_url('/local/oksigeniamarandu/chatfile.php', ['c' => $conversation, 'm' => $id, 'a' => $index]);
            $attachments[] = [
                'type' => in_array($type, ['image', 'audio', 'video']) ? $type : 'file',
                'url' => $url === '' ? '' : $proxy->out(false),
                'thumb' => empty($attachment['thumb_url']) ? '' : (new \moodle_url($proxy, ['t' => 1]))->out(false),
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
     * The lines of Chatwoot about what happened in a conversation, as its locales write them (Spanish and English), by
     * what they say. The names go in %{...}.
     */
    private const ACTIVITIES = [
        'took' => ['%{user_name} auto-asignado a esta conversación', '%{user_name} self-assigned this conversation'],
        'gaveteam' => ['Asignado a %{assignee_name} via %{team_name} por %{user_name}',
            'Assigned to %{assignee_name} via %{team_name} by %{user_name}'],
        'gave' => ['Asignado a %{assignee_name} por %{user_name}', 'Assigned to %{assignee_name} by %{user_name}'],
        'unassigned' => ['Conversación no asignada por %{user_name}', 'Conversation unassigned by %{user_name}'],
        'leftteam' => ['Desasignado de %{team_name} por %{user_name}', 'Unassigned from %{team_name} by %{user_name}'],
        'resolved' => ['La conversación fue marcada por %{user_name}', 'Conversation was marked resolved by %{user_name}'],
        'reopened' => ['La conversación fue reabierta por %{user_name}', 'Conversation was reopened by %{user_name}'],
        'pending' => ['La conversación fue marcada como pendiente por %{user_name}',
            'Conversation was marked as pending by %{user_name}'],
        'snoozed' => ['La conversación fue pospuesta por %{user_name}', 'Conversation was snoozed by %{user_name}'],
        'autoopen' => ['El sistema reabrió la conversación debido a un nuevo mensaje entrante.',
            'System reopened the conversation due to a new incoming message.'],
    ];

    /**
     * A line of Chatwoot about what happened, said as the page says things: «Ana passed it to Recibos». Lines it does
     * not know stay as they came.
     *
     * @param string $text The line.
     * @return array [text, what] where what is the kind of line, or empty if unknown.
     */
    public static function activity(string $text): array {
        $text = trim($text);
        $teams = [];
        foreach (directory::teams() as $team) {
            $teams[\core_text::strtolower($team['name'])] = $team['name'];
        }
        $agents = [];
        foreach (directory::agents() as $agent) {
            $agents[\core_text::strtolower($agent['name'])] = $agent;
        }
        $first = function (string $name) use ($agents): string {
            $agent = $agents[\core_text::strtolower(trim($name))] ?? null;
            if ($agent && in_array($agent['id'], directory::automatic_ids())) {
                return $agent['name'];
            }
            return (string)strtok(trim($name), ' ');
        };
        // Team assignments and person assignments share their words: «Asignado a X por Y» is a department if X is one.
        foreach (self::ACTIVITIES as $what => $patterns) {
            foreach ($patterns as $pattern) {
                $regex = '/^' . preg_replace('/%\\\{(\w+)\\\}/', '(?P<$1>.+?)', preg_quote($pattern, '/')) . '$/u';
                if (!preg_match($regex, $text, $m)) {
                    continue;
                }
                $a = (object)['by' => isset($m['user_name']) ? $first($m['user_name']) : ''];
                if ($what === 'gave' && isset($teams[\core_text::strtolower(trim($m['assignee_name']))])) {
                    $what = 'passedteam';
                    $a->team = $teams[\core_text::strtolower(trim($m['assignee_name']))];
                } else if (isset($m['assignee_name'])) {
                    $a->to = $first($m['assignee_name']);
                }
                if (isset($m['team_name'])) {
                    $a->team = $teams[\core_text::strtolower(trim($m['team_name']))] ?? directory::label($m['team_name']);
                }
                // Whoever gives it to themself keeps it, as when a person starts a conversation.
                if (in_array($what, ['gave', 'gaveteam']) && isset($a->to) && trim($m['assignee_name']) === trim($m['user_name'])) {
                    $what = 'took';
                }
                return [get_string('chat:activity:' . $what, 'local_oksigeniamarandu', $a), $what];
            }
        }
        return [$text, ''];
    }

    /**
     * The messages of a conversation for the page, with the lines of what happened said our way, and without the
     * «unassigned» that comes with passing it to a department (it is the same gesture).
     *
     * @param array $raw The messages, as Chatwoot gives them, in order.
     * @param viewer $viewer Who looks.
     * @return array
     */
    public static function export_list(array $raw, viewer $viewer): array {
        $authors = self::authors(array_column($raw, 'id'));
        $list = [];
        $last = ['', 0, ''];
        foreach ($raw as $one) {
            $message = self::export($one, $viewer, $authors);
            if ($message['kind'] === 'activity') {
                [$message['text'], $what] = self::activity($message['text']);
                $by = $message['text'];
                if ($what === 'unassigned' && $last[0] === 'passedteam' && abs($message['at'] - $last[1]) <= 30) {
                    continue;
                }
                $last = [$what, $message['at'], $by];
            }
            $list[] = $message;
        }
        return $list;
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
     * The signature of a person: the one the site gave them, the one they have in Chatwoot, or else their first name
     * and their first department.
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
        // The one the person has in Chatwoot, as it is: some sign only with their department.
        $chatwoot = directory::signature_of($viewer->agent);
        if ($chatwoot !== '') {
            return $chatwoot;
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
