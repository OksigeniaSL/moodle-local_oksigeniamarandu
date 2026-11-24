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

use local_oksigeniamarandu\local\api_exception;

/**
 * The messages that go out later: a person writes now and it goes at the hour they choose, as if they sent it then. If the
 * family writes first, it does not go by itself: it waits for whoever wrote it to look at it again. If it cannot go, its
 * author hears why. A message goes once: what has no certain answer is looked for in the conversation before trying again.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scheduled {
    /** The table. */
    public const TABLE = 'local_oksigeniamarandu_convsched';

    /** How far ahead a message may go. */
    public const AHEAD = 60 * DAYSECS;

    /** How many messages of a conversation may wait at once. */
    public const MOST = 10;

    /** The longest delay of a message of an hour chosen: after it, the one who wrote it decides. */
    public const LATE = HOURSECS;

    /** How long what went out is kept. */
    public const KEEP = 90 * DAYSECS;

    /**
     * Writes a message to go out later.
     *
     * @param viewer $viewer Who writes it, who can write.
     * @param \stdClass $row The conversation.
     * @param int $at When it goes.
     * @param string $text What it says; not used with an approved message.
     * @param array|null $template An approved message, as templates::find() gives it.
     * @param array $values What goes in its gaps, by gap.
     * @param int|null $now The time, for the tests.
     * @return int Its id.
     */
    public static function add(
        viewer $viewer,
        \stdClass $row,
        int $at,
        string $text,
        ?array $template = null,
        array $values = [],
        ?int $now = null
    ): int {
        global $DB;
        $now = $now ?? time();
        if ($at <= $now + MINSECS || $at > $now + self::AHEAD) {
            throw new \invalid_parameter_exception('The time must be ahead, and not too far');
        }
        if ($template === null) {
            $text = trim($text);
            if ($text === '') {
                throw new \invalid_parameter_exception('Empty message');
            }
            if ((int)$row->lastincoming + DAYSECS <= $at) {
                // By then one cannot write freely: an approved message, or before.
                throw new \moodle_exception('chat:schedwindow', 'local_oksigeniamarandu');
            }
        }
        if ($DB->count_records(self::TABLE, ['cwid' => $row->cwid, 'timedone' => 0]) >= self::MOST) {
            throw new \moodle_exception('chat:schedtoomany', 'local_oksigeniamarandu');
        }
        $id = (int)$DB->insert_record(self::TABLE, (object)['cwid' => (int)$row->cwid, 'userid' => $viewer->userid,
            'sendat' => $at, 'body' => $template === null ? $text : json_encode($values, JSON_UNESCAPED_UNICODE),
            'template' => $template['name'] ?? null, 'language' => $template['language'] ?? null,
            'status' => 'waiting', 'reason' => '', 'timecreated' => $now, 'timemodified' => $now, 'timedone' => 0]);
        self::moved((int)$row->cwid);
        return $id;
    }

    /**
     * Takes a message back.
     *
     * @param \stdClass $row The conversation.
     * @param int $id The message.
     * @return bool Whether it was there to take back: one that is going out is not.
     */
    public static function remove(\stdClass $row, int $id): bool {
        global $DB;
        $one = self::get($row, $id);
        if (!$one || $one->status === 'sending') {
            return false;
        }
        $DB->delete_records(self::TABLE, ['id' => $one->id]);
        self::moved((int)$row->cwid);
        return true;
    }

    /**
     * Sends a message of the list now, signed by whoever presses, and takes it off the list.
     *
     * @param viewer $viewer Who sends it.
     * @param \stdClass $row The conversation.
     * @param int $id The message.
     * @param int|null $now The time, for the tests.
     * @return array The message, as Chatwoot gives it.
     */
    public static function now(viewer $viewer, \stdClass $row, int $id, ?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        $one = self::get($row, $id);
        if (!$one || !self::claim($one, $now)) {
            throw new \moodle_exception('chat:schedgone', 'local_oksigeniamarandu');
        }
        try {
            $sent = self::go($viewer, $row, $one, $now);
        } catch (\moodle_exception $e) {
            // Without a certain answer it may have gone out: the person looks before sending it again.
            $code = $e instanceof api_exception ? (int)$e->a : 400;
            $sure = $code >= 400 && $code < 500;
            self::state($one, 'held', $sure ? 'refused' : 'unsure', $now, $sure ? self::why($e) : '');
            throw $e;
        }
        self::done($one, $now);
        return $sent;
    }

    /**
     * The messages of a conversation that are on the list.
     *
     * @param int $cwid The conversation.
     * @param int $userid Whoever looks.
     * @return array Each with id, at, text, by, mine, status, reason and title (of the approved message).
     */
    public static function of(int $cwid, int $userid): array {
        global $DB;
        return self::export($DB->get_records(self::TABLE, ['cwid' => $cwid, 'timedone' => 0], 'sendat ASC, id ASC'), $userid);
    }

    /**
     * The messages of every conversation that are on the list, by conversation.
     *
     * @param int $userid Whoever looks.
     * @return array
     */
    public static function by_conversation(int $userid): array {
        global $DB;
        $list = [];
        foreach (self::export($DB->get_records(self::TABLE, ['timedone' => 0], 'sendat ASC, id ASC'), $userid, true) as $one) {
            $list[$one['cwid']][] = array_diff_key($one, ['cwid' => 0]);
        }
        return $list;
    }

    /**
     * Sends what is due.
     *
     * @param int|null $now The time, for the tests.
     * @return int How many went out.
     */
    public static function due(?int $now = null): int {
        global $DB;
        $now = $now ?? time();
        $sent = 0;
        $rows = $DB->get_records_select(
            self::TABLE,
            "timedone = 0 AND status IN ('waiting', 'sending') AND sendat <= :now",
            ['now' => $now],
            'sendat ASC, id ASC',
            '*',
            0,
            50
        );
        foreach ($rows as $one) {
            $sent += (int)self::attempt($one, $now);
        }
        $DB->delete_records_select(
            self::TABLE,
            'timedone > 0 AND timedone < :old',
            ['old' => $now - self::KEEP]
        );
        return $sent;
    }

    /**
     * Tries to send one that is due.
     *
     * @param \stdClass $one The message.
     * @param int $now The time.
     * @return bool Whether it went out.
     */
    protected static function attempt(\stdClass $one, int $now): bool {
        global $DB;
        $user = \core_user::get_user((int)$one->userid);
        $viewer = $user && !$user->deleted && !$user->suspended ? viewer::for_user($user) : null;
        $row = $DB->get_record(mirror::TABLE, ['cwid' => $one->cwid]);
        if (!$viewer || $viewer->readonly || !$viewer->agent['id'] || !$row || !$viewer->sees($row)) {
            self::hold($one, 'access', $row ?: null, $now);
            return false;
        }
        if ($one->status === 'sending') {
            // An answer that never came: it may have gone out. Looked for in the conversation.
            $found = self::found($viewer, $row, $one);
            if ($found === null) {
                return false;
            }
            if ($found) {
                message::remember($found, (int)$row->cwid, (int)$one->userid, '', (string)$one->template);
                self::done($one, $now);
                mirror::refresh((int)$row->cwid);
                return true;
            }
            $DB->set_field(self::TABLE, 'status', 'waiting', ['id' => $one->id]);
            $one->status = 'waiting';
        }
        if ($one->sendat < $now - self::LATE) {
            self::hold($one, 'late', $row, $now);
            return false;
        }
        try {
            // As it is now: the list may be a minute behind.
            $row = mirror::refresh((int)$row->cwid, false) ?? $row;
        } catch (\moodle_exception $e) {
            return false;
        }
        if ((int)$row->lastincoming > (int)$one->timecreated) {
            self::hold($one, 'wrote', $row, $now);
            return false;
        }
        if ($one->template === null && (int)$row->lastincoming + DAYSECS <= $now) {
            self::hold($one, 'window', $row, $now);
            return false;
        }
        if (!self::claim($one, $now)) {
            return false;
        }
        try {
            self::go($viewer, $row, $one, $now);
        } catch (api_exception $e) {
            $code = (int)$e->a;
            if ($code >= 400 && $code < 500) {
                self::hold($one, 'refused', $row, $now, self::why($e));
            }
            // Anything else has no certain answer: the next pass looks for it before trying again.
            return false;
        } catch (\moodle_exception $e) {
            self::hold($one, 'refused', $row, $now, self::why($e));
            return false;
        }
        self::done($one, $now);
        return true;
    }

    /**
     * Sends it.
     *
     * @param viewer $viewer Who sends it.
     * @param \stdClass $row The conversation.
     * @param \stdClass $one The message.
     * @param int $now The time.
     * @return array The message, as Chatwoot gives it.
     */
    protected static function go(viewer $viewer, \stdClass $row, \stdClass $one, int $now): array {
        if ($one->template !== null) {
            $template = templates::find((string)$one->template, (string)$one->language);
            if (!$template) {
                throw new \moodle_exception('chat:notemplate', 'local_oksigeniamarandu');
            }
            $sent = sender::template($viewer, $row, $template, json_decode($one->body, true) ?: []);
        } else {
            if ((int)$row->lastincoming + DAYSECS <= $now) {
                throw new \moodle_exception('chat:windowclosed', 'local_oksigeniamarandu');
            }
            $sent = sender::text($viewer, $row, (string)$one->body);
        }
        mirror::refresh((int)$row->cwid);
        return $sent;
    }

    /**
     * The message of a person that went out without an answer, if it is in the conversation.
     *
     * @param viewer $viewer Who wrote it.
     * @param \stdClass $row The conversation.
     * @param \stdClass $one The message.
     * @return int|null Its id in Chatwoot; 0 if it is not there; null if it could not be looked for.
     */
    protected static function found(viewer $viewer, \stdClass $row, \stdClass $one): ?int {
        try {
            $answer = directory::client()->call('GET', '/conversations/' . (int)$row->cwid . '/messages');
        } catch (\moodle_exception $e) {
            return null;
        }
        $template = $one->template === null ? null : templates::find((string)$one->template, (string)$one->language);
        $content = $one->template === null ? sender::signed($viewer, (string)$one->body) :
            ($template ? templates::render($template, json_decode($one->body, true) ?: []) : '');
        foreach (array_reverse($answer['payload'] ?? []) as $message) {
            if (
                (int)($message['message_type'] ?? -1) === 1 && empty($message['private']) &&
                (string)($message['content'] ?? '') === $content &&
                (int)($message['created_at'] ?? 0) >= (int)$one->timemodified - MINSECS
            ) {
                return (int)($message['id'] ?? 0);
            }
        }
        return 0;
    }

    /**
     * Takes a message for itself, so that only one process sends it.
     *
     * @param \stdClass $one The message.
     * @param int $now The time.
     * @return bool Whether it was free.
     */
    protected static function claim(\stdClass $one, int $now): bool {
        global $DB;
        $lock = \core\lock\lock_config::get_lock_factory('local_oksigeniamarandu_sched')->get_lock('m' . $one->id, 10);
        if (!$lock) {
            return false;
        }
        try {
            $fresh = $DB->get_record(self::TABLE, ['id' => $one->id]);
            if (!$fresh || $fresh->timedone || $fresh->status === 'sending') {
                return false;
            }
            $DB->update_record(self::TABLE, (object)['id' => $one->id, 'status' => 'sending', 'timemodified' => $now]);
            $one->status = 'sending';
            $one->timemodified = $now;
        } finally {
            $lock->release();
        }
        return true;
    }

    /**
     * A message that cannot go by itself: it stays, with why, and its author hears of it.
     *
     * @param \stdClass $one The message.
     * @param string $reason wrote, window, late, access, refused or unsure.
     * @param \stdClass|null $row The conversation.
     * @param int $now The time.
     * @param string $why What was said when it was refused, as the code of a text of the plugin or a text.
     */
    protected static function hold(\stdClass $one, string $reason, ?\stdClass $row, int $now, string $why = ''): void {
        self::state($one, 'held', $reason, $now, $why);
        if (!$row) {
            return;
        }
        $name = $row->name !== '' ? $row->name : '+' . $row->phone;
        notify::send((int)$one->cwid, (int)$one->userid, 'sched' . $reason, ['name' => $name]);
    }

    /**
     * Changes the state of a message.
     *
     * @param \stdClass $one The message.
     * @param string $status Its state.
     * @param string $reason Why.
     * @param int $now The time.
     * @param string $why What was said when it was refused.
     */
    protected static function state(\stdClass $one, string $status, string $reason, int $now, string $why = ''): void {
        global $DB;
        $DB->update_record(self::TABLE, (object)['id' => $one->id, 'status' => $status, 'reason' => $reason,
            'why' => $why === '' ? null : \core_text::substr($why, 0, 255), 'timemodified' => $now]);
        self::moved((int)$one->cwid);
    }

    /**
     * What an error says to whoever wrote the message: the code of a text of the plugin, or what Chatwoot said.
     *
     * @param \moodle_exception $e The error.
     * @return string
     */
    protected static function why(\moodle_exception $e): string {
        if ($e->module === 'local_oksigeniamarandu' && $e->errorcode !== 'error:chatwoot') {
            return $e->errorcode;
        }
        return (string)$e->debuginfo;
    }

    /**
     * A message went out: it is off the list, and kept for a while.
     *
     * @param \stdClass $one The message.
     * @param int $now The time.
     */
    protected static function done(\stdClass $one, int $now): void {
        global $DB;
        $DB->update_record(self::TABLE, (object)['id' => $one->id, 'status' => 'sent', 'timedone' => $now]);
        self::moved((int)$one->cwid);
    }

    /**
     * A message of a conversation, if it is on the list.
     *
     * @param \stdClass $row The conversation.
     * @param int $id The message.
     * @return \stdClass|null
     */
    protected static function get(\stdClass $row, int $id): ?\stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['id' => $id, 'cwid' => $row->cwid, 'timedone' => 0]) ?: null;
    }

    /**
     * Tells the pages that show the conversation that something of it changed.
     *
     * @param int $cwid The conversation.
     */
    protected static function moved(int $cwid): void {
        global $DB;
        $DB->set_field(mirror::TABLE, 'timemodified', time(), ['cwid' => $cwid]);
    }

    /**
     * What was said when a message was refused, for whoever wrote it: what this plugin says. What Chatwoot says, in its own
     * language and for whoever manages it, stays in the table.
     *
     * @param string $why As it is kept.
     * @return string
     */
    protected static function said(string $why): string {
        return strpos($why, 'chat:') === 0 && get_string_manager()->string_exists($why, 'local_oksigeniamarandu')
            ? get_string($why, 'local_oksigeniamarandu') : '';
    }

    /**
     * The messages as the page shows them.
     *
     * @param \stdClass[] $rows The rows.
     * @param int $userid Whoever looks.
     * @param bool $with Whether each says its conversation.
     * @return array
     */
    protected static function export(array $rows, int $userid, bool $with = false): array {
        $users = [];
        $info = $rows ? templates::info() : [];
        $list = [];
        foreach ($rows as $one) {
            if (!isset($users[$one->userid])) {
                $user = \core_user::get_user((int)$one->userid);
                $users[$one->userid] = $user ? trim((string)strtok((string)$user->firstname, ' ')) : '';
            }
            $text = (string)$one->body;
            $title = '';
            if ($one->template !== null) {
                $values = json_decode($one->body, true) ?: [];
                $template = templates::find((string)$one->template, (string)$one->language);
                $text = $template ? templates::render($template, $values) : implode(' · ', $values);
                $title = (string)($info[$one->template]['title'] ?? '');
            }
            $list[] = ['id' => (int)$one->id, 'at' => (int)$one->sendat, 'text' => $text, 'by' => $users[$one->userid],
                'mine' => (int)$one->userid === $userid, 'status' => (string)$one->status, 'reason' => (string)$one->reason,
                'why' => self::said((string)($one->why ?? '')), 'approved' => $one->template !== null, 'title' => $title,
                'body' => (string)$one->body,
                'template' => (string)$one->template, 'language' => (string)$one->language] +
                ($with ? ['cwid' => (int)$one->cwid] : []);
        }
        return $list;
    }
}
