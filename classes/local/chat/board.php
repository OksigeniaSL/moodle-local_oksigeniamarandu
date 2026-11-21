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

use local_oksigeniamarandu\external\chat_extras;

/**
 * The board of the team: notices for some departments, or the whole team, that each person marks seen with one press,
 * until everyone has seen them. Who they are for is fixed when they are put; whoever puts one sees who is missing and
 * can tell them again. Not a message: nobody answers on the board.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class board {
    /** The notices. */
    public const TABLE = 'local_oksigeniamarandu_board';

    /** Who each one is for, and when they saw it. */
    public const TO = 'local_oksigeniamarandu_boardto';

    /** The reference of a notice of the board on Telegram: b: and its number. */
    public const REF = 'b:';

    /** How long what everyone saw stays in «Seen by everyone». */
    public const KEEP = 30 * DAYSECS;

    /** The longest text. */
    public const MAX = 2000;

    /**
     * The people a notice is for: the users of the people of those departments, or of the whole team.
     *
     * @param int[] $teams The departments.
     * @param bool $all The whole team.
     * @return int[] User ids.
     */
    public static function audience(array $teams, bool $all): array {
        $agents = [];
        if ($all) {
            $agents = array_column(directory::agents(), 'id');
        } else {
            foreach ($teams as $teamid) {
                $agents = array_merge($agents, directory::members_of((int)$teamid));
            }
        }
        $users = [];
        foreach (array_unique(array_map('intval', $agents)) as $agentid) {
            $agent = directory::agent($agentid);
            $user = $agent ? notify::user_of($agent) : null;
            if ($user) {
                $users[(int)$user->id] = (int)$user->id;
            }
        }
        return array_values($users);
    }

    /**
     * Puts a notice on the board, and tells whoever it is for.
     *
     * @param viewer $viewer Who puts it, who can write.
     * @param string $text The notice.
     * @param int[] $teams For these departments…
     * @param bool $all …or for the whole team.
     * @param int|null $now The time, for the tests.
     * @return int The notice.
     */
    public static function post(viewer $viewer, string $text, array $teams, bool $all, ?int $now = null): int {
        global $DB;
        $now = $now ?? time();
        $text = trim($text);
        $known = directory::teams();
        $teams = array_values(array_unique(array_filter(array_map('intval', $teams), function ($id) use ($known) {
            return isset($known[$id]);
        })));
        if ($text === '' || \core_text::strlen($text) > self::MAX || (!$all && !$teams)) {
            throw new \invalid_parameter_exception('A notice needs its text and whom it is for');
        }
        $id = (int)$DB->insert_record(self::TABLE, (object)['authorid' => $viewer->userid, 'text' => $text,
            'audience' => $all ? 'all' : implode(',', $teams), 'timecreated' => $now, 'timemodified' => $now, 'timeedited' => 0,
            'timeremoved' => 0]);
        $people = self::audience($teams, $all);
        $people[] = $viewer->userid;
        foreach (array_unique($people) as $userid) {
            // Whoever puts it has seen it.
            $DB->insert_record(self::TO, (object)['boardid' => $id, 'userid' => $userid,
                'timeseen' => $userid === $viewer->userid ? $now : 0, 'timereminded' => 0]);
            if ($userid !== $viewer->userid) {
                self::tell($id, $userid, $viewer, $text);
            }
        }
        return $id;
    }

    /**
     * A person marks a notice seen.
     *
     * @param int $userid The person.
     * @param int $id The notice.
     * @param int|null $now The time, for the tests.
     * @return bool Whether it was for them and not seen yet.
     */
    public static function seen(int $userid, int $id, ?int $now = null): bool {
        global $DB;
        $now = $now ?? time();
        $row = $DB->get_record(self::TO, ['boardid' => $id, 'userid' => $userid]);
        if (!$row || $row->timeseen) {
            return false;
        }
        $DB->set_field(self::TO, 'timeseen', $now, ['id' => $row->id]);
        $DB->set_field(self::TABLE, 'timemodified', $now, ['id' => $id]);
        return true;
    }

    /**
     * Whoever put a notice tells again someone who has not seen it.
     *
     * @param viewer $viewer Who put it.
     * @param int $id The notice.
     * @param int $userid Whom.
     * @param int|null $now The time, for the tests.
     */
    public static function remind(viewer $viewer, int $id, int $userid, ?int $now = null): void {
        global $DB;
        $now = $now ?? time();
        $notice = self::own($viewer, $id);
        $row = $DB->get_record(self::TO, ['boardid' => $id, 'userid' => $userid]);
        if (!$row || $row->timeseen) {
            return;
        }
        $DB->set_field(self::TO, 'timereminded', $now, ['id' => $row->id]);
        self::tell($id, $userid, $viewer, (string)$notice->text, true);
    }

    /**
     * Whoever put a notice changes its text, or takes it off the board.
     *
     * @param viewer $viewer Who put it.
     * @param int $id The notice.
     * @param string|null $text The new text; null to take it off.
     * @param int|null $now The time, for the tests.
     */
    public static function change(viewer $viewer, int $id, ?string $text, ?int $now = null): void {
        global $DB;
        $now = $now ?? time();
        self::own($viewer, $id);
        if ($text === null) {
            $DB->update_record(self::TABLE, (object)['id' => $id, 'timeremoved' => $now, 'timemodified' => $now]);
            return;
        }
        $text = trim($text);
        if ($text === '' || \core_text::strlen($text) > self::MAX) {
            throw new \invalid_parameter_exception('A notice needs its text');
        }
        $DB->update_record(self::TABLE, (object)['id' => $id, 'text' => $text, 'timemodified' => $now, 'timeedited' => $now]);
    }

    /**
     * The notices a person has on the board: for them or put by them, on the board, and what everyone saw in the last
     * days; the newest first.
     *
     * @param viewer $viewer Who looks.
     * @param int|null $now The time, for the tests.
     * @return array Each as the page takes it.
     */
    public static function for_viewer(viewer $viewer, ?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        $rows = $DB->get_records_sql(
            "SELECT b.* FROM {" . self::TABLE . "} b
               JOIN {" . self::TO . "} t ON t.boardid = b.id AND t.userid = :userid
              WHERE b.timeremoved = 0 AND b.timecreated > :since
           ORDER BY b.timecreated DESC, b.id DESC",
            ['userid' => $viewer->userid, 'since' => $now - 120 * DAYSECS]
        );
        if (!$rows) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal(array_keys($rows), SQL_PARAMS_NAMED);
        $to = [];
        foreach ($DB->get_records_select(self::TO, "boardid $in", $params, 'timeseen ASC, id ASC') as $one) {
            $to[(int)$one->boardid][] = $one;
        }
        $users = [];
        $list = [];
        foreach ($rows as $row) {
            $item = self::export($row, $to[(int)$row->id] ?? [], $viewer, $users);
            // What everyone saw goes after some days.
            if ($item['seencount'] === $item['total'] && (int)$row->timemodified < $now - self::KEEP) {
                continue;
            }
            $list[] = $item;
        }
        return $list;
    }

    /**
     * How many notices a person has to see, and the last time anything of theirs on the board moved.
     *
     * @param int $userid The person.
     * @return array tosee and rev.
     */
    public static function counts(int $userid): array {
        global $DB;
        $row = $DB->get_record_sql(
            "SELECT SUM(CASE WHEN t.timeseen = 0 THEN 1 ELSE 0 END) AS tosee, MAX(b.timemodified) AS rev
               FROM {" . self::TABLE . "} b
               JOIN {" . self::TO . "} t ON t.boardid = b.id AND t.userid = :userid
              WHERE b.timeremoved = 0",
            ['userid' => $userid]
        );
        return ['tosee' => (int)($row->tosee ?? 0), 'rev' => (int)($row->rev ?? 0)];
    }

    /**
     * A notice as the page takes it.
     *
     * @param \stdClass $row The notice.
     * @param array $to Who it is for.
     * @param viewer $viewer Who looks.
     * @param array $users The people already read, by id.
     * @return array
     */
    protected static function export(\stdClass $row, array $to, viewer $viewer, array &$users): array {
        $person = function (int $userid) use (&$users): array {
            if (!isset($users[$userid])) {
                $user = \core_user::get_user($userid);
                $agent = $user && $user->email !== '' ? directory::agent_by_email((string)$user->email) : null;
                $users[$userid] = ['userid' => $userid, 'name' => $user ? fullname($user) : '',
                    'firstname' => $user ? (string)$user->firstname : '', 'agentid' => $agent ? (int)$agent['id'] : 0,
                    'picture' => $user ? chat_extras::picture($user) : ''];
            }
            return $users[$userid];
        };
        $teams = directory::teams();
        $people = [];
        $mine = null;
        foreach ($to as $one) {
            $people[] = $person((int)$one->userid) + ['seenat' => (int)$one->timeseen, 'remindedat' => (int)$one->timereminded];
            if ((int)$one->userid === $viewer->userid) {
                $mine = $one;
            }
        }
        $names = [];
        if ($row->audience !== 'all') {
            foreach (explode(',', (string)$row->audience) as $teamid) {
                if (isset($teams[(int)$teamid])) {
                    $names[] = $teams[(int)$teamid]['name'];
                }
            }
        }
        return [
            'id' => (int)$row->id,
            'text' => (string)$row->text,
            'author' => $person((int)$row->authorid),
            'mine' => (int)$row->authorid === $viewer->userid,
            'all' => $row->audience === 'all',
            'teams' => $names,
            'at' => (int)$row->timecreated,
            'edited' => !empty($row->timeedited),
            'seen' => $mine && $mine->timeseen ? (int)$mine->timeseen : 0,
            'total' => count($people),
            'seencount' => count(array_filter($people, function ($one) {
                return $one['seenat'] > 0;
            })),
            'people' => $people,
        ];
    }

    /**
     * A notice of the board, by its id, put by this person.
     *
     * @param viewer $viewer Who.
     * @param int $id The notice.
     * @return \stdClass
     */
    protected static function own(viewer $viewer, int $id): \stdClass {
        global $DB;
        $notice = $DB->get_record(self::TABLE, ['id' => $id, 'timeremoved' => 0]);
        if (!$notice || (int)$notice->authorid !== $viewer->userid) {
            throw new \moodle_exception('chat:board:notyours', 'local_oksigeniamarandu');
        }
        return $notice;
    }

    /**
     * Tells a person of a notice of the board: the bell, the desktop, the app and, to whoever linked theirs and if the
     * site switched it on, Telegram with «Seen».
     *
     * @param int $id The notice.
     * @param int $userid Whom.
     * @param viewer $by Who put it.
     * @param string $text The notice.
     * @param bool $again Whether it is a second time.
     */
    protected static function tell(int $id, int $userid, viewer $by, string $text, bool $again = false): void {
        $user = \core_user::get_user($userid);
        if (!$user || $user->deleted || $user->suspended) {
            return;
        }
        $short = \core_text::strlen($text) > 160 ? \core_text::substr($text, 0, 157) . '…' : $text;
        $values = (object)['by' => $by->firstname, 'text' => directory::one_line($short)];
        $subject = get_string($again ? 'chat:notify:boardagain' : 'chat:notify:board', 'local_oksigeniamarandu', $values);
        $url = new \moodle_url('/local/oksigeniamarandu/chat.php', ['b' => 'board', 'aviso' => $id]);
        $message = new \core\message\message();
        $message->component = 'local_oksigeniamarandu';
        $message->name = 'chat';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = $subject;
        $message->fullmessage = $subject;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = get_string('chat:title', 'local_oksigeniamarandu');
        message_send($message);
        try {
            $line = get_string($again ? 'chat:board:againline' : 'chat:board:line', 'local_oksigeniamarandu', $values);
            \local_oksigeniamarandu\api::ask('local_oksigeniamarandu', 'board', [$line], self::REF . $id, 0, $userid, $url);
        } catch (\Throwable $e) {
            debugging('Marandu: the notice of the board did not go to Telegram: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
