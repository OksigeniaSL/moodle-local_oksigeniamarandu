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
 * Who of the team hears of what happens in a conversation, on the phone and in the bell of the site: whoever has it,
 * when the contact writes; the people of its department, when nobody has it and the contact starts waiting; whoever it
 * is passed to; whoever is named in a note. Only in the hours of the team, but what is passed or named; never to who
 * did it; once every ten minutes at most for the same conversation.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notify {
    /** What came longer ago than this is not news. */
    private const FRESH = 15 * MINSECS;

    /** How long the same person does not hear twice of messages of the same conversation. */
    private const QUIET = 10 * MINSECS;

    /**
     * What changed in a conversation, as the index sees it.
     *
     * @param \stdClass|null $old Its row before, if it had one.
     * @param \stdClass $new Its row now.
     * @param array $messages Its last messages, as Chatwoot gives them, if they were read.
     * @param int|null $now The time, for the tests.
     */
    public static function changed(?\stdClass $old, \stdClass $new, array $messages, ?int $now = null): void {
        $now = $now ?? time();
        if ($new->status === 'pending' || $new->status === 'resolved') {
            return;
        }
        $open = self::hours()->is_open($now);
        $name = $new->name !== '' ? $new->name : '+' . $new->phone;
        $news = [];
        $wrote = $new->lastincoming > ($old->lastincoming ?? 0) && $new->lastincoming >= $now - self::FRESH;
        if ($open && $wrote) {
            $text = self::text($new->preview, $new->previewkind === 'incoming');
            if ($new->assigneeid) {
                $news[] = [(int)$new->assigneeid, 'incoming', ['name' => $name, 'text' => $text], true];
            } else if ($new->teamid && empty($old->waitsince) && $new->waitsince) {
                $team = directory::teams()[(int)$new->teamid]['name'] ?? '';
                foreach (directory::members_of((int)$new->teamid) as $agentid) {
                    $news[] = [$agentid, 'waiting', ['name' => $name, 'text' => $text, 'team' => $team], true];
                }
            }
        }
        // The notes that name someone, newer than the last message it looked at.
        foreach ($messages as $message) {
            if ((int)($message['id'] ?? 0) <= (int)($old->lastmsgid ?? PHP_INT_MAX) || message::kind($message) !== 'note') {
                continue;
            }
            $content = (string)($message['content'] ?? '');
            preg_match_all('~mention://user/(\d+)/~', $content, $mentions);
            preg_match_all('~mention://team/(\d+)/~', $content, $teams);
            $by = (int)($message['sender']['id'] ?? 0);
            $author = directory::agent($by);
            // A bot (such as the one that warns of long waits) is named whole; a person, by their first name.
            $byname = !$author ? '' : (in_array($by, directory::automatic_ids()) ? $author['name'] : strtok($author['name'], ' '));
            $values = ['name' => $name, 'by' => $byname, 'text' => self::text(message::plain_text($content), true)];
            foreach (array_unique(array_map('intval', $mentions[1])) as $agentid) {
                if ($agentid !== $by) {
                    $news[] = [$agentid, 'mention', $values, false];
                }
            }
            // A department named in a note: everyone in it hears.
            $allteams = directory::teams();
            foreach (array_unique(array_map('intval', $teams[1])) as $teamid) {
                foreach (directory::members_of($teamid) as $agentid) {
                    if ($agentid !== $by) {
                        $news[] = [$agentid, 'teammention', $values + ['team' => $allteams[$teamid]['name'] ?? ''], false];
                    }
                }
            }
        }
        self::queue((int)$new->cwid, $news, $now);
    }

    /**
     * A person passed a conversation to another person.
     *
     * @param \stdClass $row The conversation, already passed.
     * @param int $byuserid Who passed it.
     */
    public static function passed(\stdClass $row, int $byuserid): void {
        if (!$row->passedto) {
            return;
        }
        $by = \core_user::get_user($byuserid);
        $team = directory::teams()[(int)$row->teamid]['name'] ?? '';
        self::queue((int)$row->cwid, [[(int)$row->passedto, 'passed', ['name' => $row->name !== '' ? $row->name : '+' . $row->phone,
            'by' => $by ? $by->firstname : '', 'team' => $team], false]], time(), $byuserid);
    }

    /**
     * Sends what there is to tell, in a task, so the page and the webhook do not wait for it.
     *
     * @param int $conversation The conversation.
     * @param array $news Each [agent id, kind, values, whether it is held for ten minutes].
     * @param int $now The time.
     * @param int $skipuserid A user who should not hear of it (who did it).
     */
    protected static function queue(int $conversation, array $news, int $now, int $skipuserid = 0): void {
        if (!$news) {
            return;
        }
        $cache = \cache::make('local_oksigeniamarandu', 'chat');
        $people = [];
        foreach ($news as [$agentid, $kind, $values, $held]) {
            $agent = directory::agent($agentid);
            $user = $agent ? self::user_of($agent) : null;
            if (!$user || (int)$user->id === $skipuserid || isset($people[$user->id])) {
                continue;
            }
            $key = 'notified_' . $user->id . '_' . $conversation;
            if ($held && (int)$cache->get($key) > $now - self::QUIET) {
                continue;
            }
            $cache->set($key, $now);
            $people[$user->id] = ['userid' => (int)$user->id, 'kind' => $kind, 'values' => $values];
        }
        if ($people) {
            $task = new \local_oksigeniamarandu\task\chat_notify();
            $task->set_custom_data(['conversation' => $conversation, 'people' => array_values($people)]);
            \core\task\manager::queue_adhoc_task($task);
        }
    }

    /**
     * Sends one notice.
     *
     * @param int $conversation The conversation.
     * @param int $userid To whom.
     * @param string $kind incoming, waiting, passed, mention or teammention.
     * @param array $values What the text takes.
     */
    public static function send(int $conversation, int $userid, string $kind, array $values): void {
        $user = \core_user::get_user($userid);
        if (!$user || $user->deleted || $user->suspended) {
            return;
        }
        $url = new \moodle_url('/local/oksigeniamarandu/chat.php', ['c' => $conversation]);
        $text = get_string('chat:notify:' . $kind, 'local_oksigeniamarandu', (object)$values);
        $message = new \core\message\message();
        $message->component = 'local_oksigeniamarandu';
        $message->name = 'chat';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = $text;
        $message->fullmessage = $text;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $text;
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = get_string('chat:title', 'local_oksigeniamarandu');
        message_send($message);
        // And by Telegram, to whoever linked theirs, if the site switched the notice on there: with the quiet hours of every
        // notice, a button that opens the conversation and others to keep it, resolve it or pass it from there.
        try {
            \local_oksigeniamarandu\api::ask(
                'local_oksigeniamarandu',
                'chat',
                [$text],
                telegram_actions::REF . $conversation,
                0,
                (int)$user->id,
                $url
            );
        } catch (\Throwable $e) {
            debugging('Marandu: the notice of the conversation did not go to Telegram: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * The user of Moodle of a person of the account, by their email.
     *
     * @param array $agent The person.
     * @return \stdClass|null
     */
    protected static function user_of(array $agent): ?\stdClass {
        global $DB;
        if ($agent['email'] === '' || in_array($agent['id'], directory::automatic_ids())) {
            return null;
        }
        $user = $DB->get_record_select(
            'user',
            'deleted = 0 AND suspended = 0 AND ' . $DB->sql_equal('email', ':email', false),
            ['email' => $agent['email']],
            '*',
            IGNORE_MULTIPLE
        );
        return $user ?: null;
    }

    /**
     * A short text for the notice.
     *
     * @param string $text The text.
     * @param bool $show Whether it may be shown (only what the contact wrote, or a note).
     * @return string
     */
    protected static function text(string $text, bool $show): string {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if (!$show || $text === '') {
            return '';
        }
        return \core_text::strlen($text) > 120 ? \core_text::substr($text, 0, 117) . '…' : $text;
    }

    /**
     * The hours of the team, or always open if Chatwoot does not answer.
     *
     * @return hours
     */
    protected static function hours(): hours {
        try {
            return hours::of_site();
        } catch (\moodle_exception $e) {
            return new hours('', []);
        }
    }
}
