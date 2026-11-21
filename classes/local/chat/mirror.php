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
 * The index of the conversations in Moodle: one row for each conversation of Chatwoot, with its department, who has
 * it, its state, the contact, since when the family waits and its last message. The page reads it and filters it by
 * person; Chatwoot keeps the messages.
 *
 * The wait is counted here, not with the waiting_since of Chatwoot, which any message of a bot clears: it starts with
 * the first message of the contact after the last answer of a person (going out, not private and not from a bot).
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mirror {
    /** The table of the conversations. */
    public const TABLE = 'local_oksigeniamarandu_conv';

    /** How long the page waits between two passes of its own when the webhook of Chatwoot does not come. */
    public const PAGE_PASS = 5;

    /** The furthest back a pass from the page goes; older changes are for the task. */
    public const PAGE_WINDOW = HOURSECS;

    /** The furthest back the first pass goes. */
    public const FIRST_WINDOW = 31 * DAYSECS;

    /** How many conversations Chatwoot gives in a page of its list. */
    public const PAGE_SIZE = 25;

    /** The most pages a pass reads: a thousand conversations. */
    public const PAGES = 40;

    /**
     * Brings the index up to date with what changed in Chatwoot since the last pass, with some overlap.
     *
     * @param int $max The furthest back it goes, in seconds.
     * @param int|null $now The time, for the tests.
     * @return int How many conversations changed.
     */
    public static function sync(int $max = self::FIRST_WINDOW, ?int $now = null): int {
        $now = $now ?? time();
        $last = (int)get_config('local_oksigeniamarandu', 'chatsynced');
        $window = $last ? min($max, $now - $last + 60) : $max;
        $client = directory::client();
        $changed = 0;
        $inboxes = directory::inbox_ids();
        // Page by page: Chatwoot gives 25 at a time.
        for ($page = 1; $page <= self::PAGES; $page++) {
            $answer = $client->call('GET', '/conversations', [
                'status' => 'all',
                'assignee_type' => 'all',
                'updated_within' => max(60, $window),
                'page' => $page,
            ]);
            $payload = $answer['data']['payload'] ?? [];
            foreach ($payload as $conversation) {
                if ($inboxes && !in_array((int)($conversation['inbox_id'] ?? 0), $inboxes)) {
                    continue;
                }
                $changed += self::store($conversation) ? 1 : 0;
            }
            if (count($payload) < self::PAGE_SIZE) {
                break;
            }
        }
        set_config('chatsynced', $now, 'local_oksigeniamarandu');
        return $changed;
    }

    /**
     * Fills the index with every conversation of the inboxes the site attends, whenever it was: what was brought into
     * Chatwoot directly (the chats of the app of a phone, its history) never comes by the webhook or as a change.
     *
     * @param bool $rebuild Every row written again from Chatwoot, also what changed there without moving its time.
     * @return array [how many it kept new or changed, how many of those inboxes the index has].
     */
    public static function sync_all(bool $rebuild = false): array {
        $client = directory::client();
        $kept = 0;
        $seen = [];
        foreach (directory::inbox_ids() ?: [0] as $inbox) {
            for ($page = 1; $page <= 10 * self::PAGES; $page++) {
                $query = ['status' => 'all', 'assignee_type' => 'all', 'page' => $page];
                if ($inbox) {
                    $query['inbox_id'] = $inbox;
                }
                $payload = $client->call('GET', '/conversations', $query)['data']['payload'] ?? [];
                foreach ($payload as $conversation) {
                    if ($inbox && (int)($conversation['inbox_id'] ?? 0) !== (int)$inbox) {
                        continue;
                    }
                    // Rebuilt, each row is written again from what Chatwoot says (its department, its state, its 24
                    // hours), also what changed there without moving its time, and nobody hears of it.
                    $kept += self::store($conversation, $rebuild, true, $rebuild) ? 1 : 0;
                    $seen[(int)($conversation['id'] ?? 0)] = true;
                }
                if (count($payload) < self::PAGE_SIZE) {
                    break;
                }
            }
        }
        return [$kept, count(array_filter(array_keys($seen)))];
    }

    /**
     * A pass from the page, if the last one is a few seconds old and nobody else is doing it; less often while the
     * webhook of Chatwoot comes.
     *
     * @return bool Whether it made one.
     */
    public static function sync_from_page(): bool {
        $now = time();
        $last = (int)get_config('local_oksigeniamarandu', 'chatsynced');
        $hooked = (int)get_config('local_oksigeniamarandu', 'chatwebhookat') > $now - 10 * MINSECS;
        if ($now - $last < ($hooked ? MINSECS : self::PAGE_PASS)) {
            return false;
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_oksigeniamarandu_chat')->get_lock('sync', 0);
        if (!$lock) {
            return false;
        }
        try {
            self::sync(self::PAGE_WINDOW, $now);
        } finally {
            $lock->release();
        }
        return true;
    }

    /**
     * Asks Chatwoot for one conversation and keeps it.
     *
     * @param int $id The number of the conversation in the account.
     * @param bool $messages Whether its messages may have changed; after an assignment or a change of state they have
     *                       not, and the wait and the last message stay as they were (one request less).
     * @return \stdClass|null Its row, or null if Chatwoot does not have it.
     */
    public static function refresh(int $id, bool $messages = true): ?\stdClass {
        global $DB;
        try {
            $conversation = directory::client()->call('GET', '/conversations/' . $id);
        } catch (\moodle_exception $e) {
            return null;
        }
        $inboxes = directory::inbox_ids();
        if ($inboxes && !in_array((int)($conversation['inbox_id'] ?? 0), $inboxes)) {
            return null;
        }
        self::store($conversation, true, $messages);
        return $DB->get_record(self::TABLE, ['cwid' => $id]) ?: null;
    }

    /**
     * Keeps a conversation as Chatwoot gives it, with what its last messages say: only if something changed.
     *
     * @param array $conversation The conversation, as the API of Chatwoot gives it.
     * @param bool $force Whether to look at it even if Chatwoot says it did not change.
     * @param bool $messages Whether to ask for its messages too.
     * @param bool $fresh Written again from what Chatwoot says, without keeping what the row had, and without notices.
     * @return bool Whether its row changed.
     */
    public static function store(array $conversation, bool $force = false, bool $messages = true, bool $fresh = false): bool {
        global $DB;
        $id = (int)($conversation['id'] ?? 0);
        if (!$id) {
            return false;
        }
        $old = $DB->get_record(self::TABLE, ['cwid' => $id]);
        $updated = (string)($conversation['updated_at'] ?? '');
        if ($old && !$force && $updated !== '' && $old->cwupdated === $updated) {
            return false;
        }
        $contact = $conversation['meta']['sender'] ?? [];
        $row = (object)([
            'cwid' => $id,
            'inboxid' => (int)($conversation['inbox_id'] ?? 0),
            'teamid' => (int)($conversation['meta']['team']['id'] ?? 0),
            'assigneeid' => ($conversation['meta']['assignee_type'] ?? '') === 'User'
                && !in_array((int)($conversation['meta']['assignee']['id'] ?? 0), directory::automatic_ids())
                ? (int)($conversation['meta']['assignee']['id'] ?? 0) : 0,
            'status' => substr((string)($conversation['status'] ?? 'open'), 0, 10),
            'contactid' => (int)($contact['id'] ?? 0),
            'phone' => substr(preg_replace('/[^0-9]/', '', (string)($contact['phone_number'] ?? '')), 0, 32),
            'name' => \core_text::substr(trim((string)($contact['name'] ?? '')), 0, 255),
        ] + marks::for_index((array)($contact['custom_attributes'] ?? [])) + [
            'lastactivity' => (int)($conversation['last_activity_at'] ?? $conversation['timestamp'] ?? 0),
            'cwupdated' => substr($updated, 0, 20),
        ]);
        $list = $messages || !$old ? self::latest_messages($id) : [];
        if ($list === null) {
            // Without its messages, what the conversation itself says.
            $list = array_filter([$conversation['last_non_activity_message'] ?? null]);
        }
        $row = (object)((array)$row + self::from_messages($list, $old ?: null, $fresh));
        if ($old) {
            $row->id = $old->id;
            // The line «they passed it to you» stops once it is someone else's or another department's.
            if ((int)$old->passedto && (int)$row->assigneeid !== (int)$old->passedto) {
                $row->passedto = 0;
            }
            if ((int)$old->passedteam && (int)$row->teamid !== (int)$old->passedteam) {
                $row->passedteam = 0;
            }
            $same = true;
            foreach ((array)$row as $field => $value) {
                if ($field !== 'cwupdated' && (string)$old->$field !== (string)$value) {
                    $same = false;
                    break;
                }
            }
            if ($same) {
                $DB->set_field(self::TABLE, 'cwupdated', $row->cwupdated, ['id' => $old->id]);
                return false;
            }
            $row->timemodified = time();
            $DB->update_record(self::TABLE, $row);
            if (!$fresh) {
                notify::changed($old, $row, $list);
            }
        } else {
            $row->passedby = 0;
            $row->passedto = 0;
            $row->passedteam = 0;
            $row->passedat = 0;
            $row->timecreated = time();
            $row->timemodified = $row->timecreated;
            $DB->insert_record(self::TABLE, $row);
            notify::changed(null, $row, $list);
        }
        return true;
    }

    /**
     * The last messages of a conversation, from Chatwoot.
     *
     * @param int $id The conversation.
     * @return array|null The messages in order, or null if they did not come.
     */
    protected static function latest_messages(int $id): ?array {
        try {
            $answer = directory::client()->call('GET', '/conversations/' . $id . '/messages');
        } catch (\moodle_exception $e) {
            return null;
        }
        return array_values($answer['payload'] ?? []);
    }

    /**
     * What the last messages of a conversation say: since when the contact waits, its last message and the last one
     * of the conversation.
     *
     * @param array $messages The messages, in order, as Chatwoot gives them.
     * @param \stdClass|null $old The row it had, if any.
     * @param bool $fresh Without keeping the 24 hours the row had: only what these messages say.
     * @return array lastmsgid, waitsince, lastincoming, preview, previewkind and previewat.
     */
    public static function from_messages(array $messages, ?\stdClass $old = null, bool $fresh = false): array {
        $wait = 0;
        $answered = false;
        $lastincoming = 0;
        $preview = null;
        $lastid = $old ? (int)$old->lastmsgid : 0;
        foreach ($messages as $message) {
            $lastid = max($lastid, (int)($message['id'] ?? 0));
            $kind = message::kind($message);
            $at = (int)($message['created_at'] ?? 0);
            if ($kind === 'incoming') {
                $wait = $wait ?: $at;
                // What came to the phone before the number was on the API (a history brought in) opens no 24 hours:
                // Meta counts only what reaches the API.
                if (empty($message['content_attributes']['imported'])) {
                    $lastincoming = max($lastincoming, $at);
                }
            } else if ($kind === 'outgoing') {
                $wait = 0;
                $answered = true;
            }
            // The list shows what a person or the contact said: an automatic message or a bot only when there is nothing else.
            if ($kind !== 'activity' && ($kind !== 'bot' || !$preview || message::kind($preview) === 'bot')) {
                $preview = $message;
            }
        }
        if ($wait && !$answered && $old && $old->waitsince && $old->waitsince < $wait) {
            // The wait started before the messages it read: it keeps the start it knew.
            $wait = (int)$old->waitsince;
        }
        $result = [
            'lastmsgid' => $lastid,
            'waitsince' => $wait,
            'lastincoming' => $fresh ? $lastincoming : max($lastincoming, $old ? (int)$old->lastincoming : 0),
            'preview' => $old ? (string)$old->preview : '',
            'previewkind' => $old ? (string)$old->previewkind : '',
            'previewat' => $old ? (int)$old->previewat : 0,
        ];
        if ($preview) {
            // Without the signature on top of what a person sent: the list shows what was said.
            $text = message::plain_text(preg_replace('/^\*[^*\n]{1,80}\*\n/u', '', (string)($preview['content'] ?? '')));
            if ($text === '' && !empty($preview['attachments'])) {
                $text = get_string('chat:attachment', 'local_oksigeniamarandu');
            }
            $result['preview'] = \core_text::substr($text, 0, 255);
            $result['previewkind'] = message::kind($preview);
            $result['previewat'] = (int)($preview['created_at'] ?? 0);
        }
        return $result;
    }

    /**
     * Notes that a person passed a conversation to another person or to a department.
     *
     * @param int $id The conversation.
     * @param int $userid Who passed it.
     * @param int $agentid To whom, or 0.
     * @param int $teamid To which department, or 0.
     */
    public static function passed(int $id, int $userid, int $agentid, int $teamid): void {
        global $DB;
        $row = $DB->get_record(self::TABLE, ['cwid' => $id]);
        if (!$row) {
            return;
        }
        $row->passedby = $userid;
        $row->passedto = $agentid;
        $row->passedteam = $teamid;
        $row->passedat = time();
        $row->timemodified = time();
        $DB->update_record(self::TABLE, $row);
        notify::passed($row, $userid);
    }

    /**
     * Whether a webhook of Chatwoot is genuine: signed with the secret of the webhook (HMAC-SHA256 of its time, a dot
     * and its body) and sent less than five minutes ago.
     *
     * @param string $raw The body.
     * @param string $time The header X-Chatwoot-Timestamp.
     * @param string $signature The header X-Chatwoot-Signature.
     * @param string $secret The secret.
     * @param int|null $now The time, for the tests.
     * @return bool
     */
    public static function verify(string $raw, string $time, string $signature, string $secret, ?int $now = null): bool {
        if ($secret === '' || !ctype_digit($time) || abs(($now ?? time()) - (int)$time) > 5 * MINSECS) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $time . '.' . $raw, $secret);
        return hash_equals($expected, trim($signature));
    }

    /**
     * Who a contact is, as its custom attributes say, in every conversation of it the index has.
     *
     * @param int $contactid The contact of Chatwoot.
     * @param array $attributes Its custom attributes.
     * @return int How many conversations changed.
     */
    public static function contact_marks(int $contactid, array $attributes): int {
        global $DB;
        if (!$contactid) {
            return 0;
        }
        $said = marks::for_index($attributes);
        $changed = 0;
        $fields = 'id, kind, kindface, kindnote, kindnotes';
        foreach ($DB->get_records(self::TABLE, ['contactid' => $contactid], '', $fields) as $row) {
            if (
                $row->kind === $said['kind'] && (string)$row->kindface === $said['kindface']
                    && (string)$row->kindnote === $said['kindnote'] && (string)$row->kindnotes === $said['kindnotes']
            ) {
                continue;
            }
            $DB->update_record(self::TABLE, (object)(['id' => $row->id, 'timemodified' => time()] + $said));
            $changed++;
        }
        return $changed;
    }

    /**
     * What a webhook of Chatwoot says: the conversation it speaks of is asked for again.
     *
     * @param array $payload The body.
     * @return int The conversation it refreshed, or 0.
     */
    public static function webhook(array $payload): int {
        $event = (string)($payload['event'] ?? '');
        $account = (int)($payload['account']['id'] ?? 0);
        if ($account && $account !== (int)get_config('local_oksigeniamarandu', 'chatwootaccount')) {
            return 0;
        }
        if ($event === 'contact_updated') {
            // Who the contact is, as the team marked it, maybe from Chatwoot itself: every conversation of it.
            self::contact_marks((int)($payload['id'] ?? 0), (array)($payload['custom_attributes'] ?? []));
            return 0;
        }
        $id = strpos($event, 'message_') === 0 ? (int)($payload['conversation']['id'] ?? 0)
            : (strpos($event, 'conversation_') === 0 ? (int)($payload['id'] ?? 0) : 0);
        if (!$id) {
            return 0;
        }
        set_config('chatwebhookat', time(), 'local_oksigeniamarandu');
        return self::refresh($id) ? $id : 0;
    }
}
