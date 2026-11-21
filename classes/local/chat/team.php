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
 * The messages of the team, among its people and never seen by a family: one conversation between two people (dm), and
 * one for each department (team), which whoever is in the department sees, as long as they are in it. A message is a
 * text or the card of a conversation with a family, which opens with the permissions of always. When somebody comes into
 * a department or leaves it, a line says so.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class team {
    /** The conversations. */
    public const CONVS = 'local_oksigeniamarandu_teamconv';

    /** Their messages. */
    public const MESSAGES = 'local_oksigeniamarandu_teammsg';

    /** What each person read of each, and when they were last told. */
    public const READS = 'local_oksigeniamarandu_teamread';

    /** The longest message. */
    public const MAX = 4000;

    /** The messages a page brings. */
    public const PAGE = 30;

    /** A second notice of the same conversation, if the person did not read it: not before this. */
    public const QUIET = 30 * MINSECS;

    /** @var array|null The people of the team as users, by agent, once read. */
    protected static $users = null;

    /** @var string What the people of the team were when they were read. */
    protected static $usersof = '';

    /**
     * Forgets what was read of the team, for the tests and when the team changes.
     */
    public static function forget(): void {
        self::$users = null;
        self::$usersof = '';
    }

    /**
     * The user of each person of the team: by agent, the user, or nothing where the person is not one user.
     *
     * @return array agent id => \stdClass user.
     */
    protected static function users(): array {
        $agents = directory::agents();
        $of = md5(json_encode($agents));
        if (self::$users === null || self::$usersof !== $of) {
            self::$usersof = $of;
            self::$users = [];
            foreach ($agents as $agent) {
                $user = notify::user_of($agent);
                if ($user) {
                    self::$users[(int)$agent['id']] = $user;
                }
            }
        }
        return self::$users;
    }

    /**
     * The users of a department, now.
     *
     * @param int $teamid The department.
     * @return int[]
     */
    public static function members(int $teamid): array {
        $users = self::users();
        $ids = [];
        foreach (directory::members_of($teamid) as $agentid) {
            if (isset($users[$agentid])) {
                $ids[] = (int)$users[$agentid]->id;
            }
        }
        sort($ids);
        return array_values(array_unique($ids));
    }

    /**
     * A person as the page takes them.
     *
     * @param int $userid The user.
     * @return array userid, name, firstname, agentid and picture.
     */
    public static function person(int $userid): array {
        $user = $userid ? \core_user::get_user($userid) : null;
        $agentid = 0;
        foreach (self::users() as $id => $one) {
            if ((int)$one->id === $userid) {
                $agentid = (int)$id;
            }
        }
        return ['userid' => $userid, 'name' => $user ? fullname($user) : '',
            'firstname' => $user ? (string)$user->firstname : '', 'agentid' => $agentid,
            'picture' => $user ? chat_extras::picture($user) : ''];
    }

    /**
     * The conversation of a department, made the first time; with the lines of who came in or left since last looked.
     *
     * @param int $teamid The department.
     * @param int|null $now The time, for the tests.
     * @return \stdClass
     */
    public static function of_team(int $teamid, ?int $now = null): \stdClass {
        global $DB;
        $now = $now ?? time();
        $members = self::members($teamid);
        $conv = $DB->get_record(self::CONVS, ['kind' => 'team', 'teamid' => $teamid, 'usera' => 0, 'userb' => 0]);
        if (!$conv) {
            $conv = (object)['kind' => 'team', 'teamid' => $teamid, 'usera' => 0, 'userb' => 0,
                'members' => implode(',', $members), 'timemodified' => 0, 'timecreated' => $now];
            $conv->id = $DB->insert_record(self::CONVS, $conv);
            return $conv;
        }
        $before = array_values(array_filter(array_map('intval', explode(',', (string)$conv->members))));
        $came = array_diff($members, $before);
        $left = array_diff($before, $members);
        if ($came || $left) {
            $last = (int)$DB->get_field(self::MESSAGES, 'MAX(id)', ['convid' => $conv->id]);
            foreach ($left as $userid) {
                self::line($conv, 'left', $userid, $now);
            }
            foreach ($came as $userid) {
                self::line($conv, 'joined', $userid, $now);
                // Whoever comes in reads everything, but it does not all wait as unread.
                self::mark($conv->id, $userid, $last, $now);
            }
            $conv->members = implode(',', $members);
            $DB->set_field(self::CONVS, 'members', $conv->members, ['id' => $conv->id]);
        }
        return $conv;
    }

    /**
     * The conversation between two people, if it exists, or made if asked.
     *
     * @param int $one A user.
     * @param int $other The other.
     * @param bool $make Whether to make it.
     * @param int|null $now The time, for the tests.
     * @return \stdClass|null
     */
    public static function between(int $one, int $other, bool $make, ?int $now = null): ?\stdClass {
        global $DB;
        [$usera, $userb] = [min($one, $other), max($one, $other)];
        $conv = $DB->get_record(self::CONVS, ['kind' => 'dm', 'teamid' => 0, 'usera' => $usera, 'userb' => $userb]);
        if ($conv || !$make) {
            return $conv ?: null;
        }
        $conv = (object)['kind' => 'dm', 'teamid' => 0, 'usera' => $usera, 'userb' => $userb, 'members' => null,
            'timemodified' => 0, 'timecreated' => $now ?? time()];
        $conv->id = $DB->insert_record(self::CONVS, $conv);
        return $conv;
    }

    /**
     * Whether a person of the team is in a conversation now.
     *
     * @param viewer $viewer The person.
     * @param \stdClass $conv The conversation.
     * @return bool
     */
    public static function sees(viewer $viewer, \stdClass $conv): bool {
        if ($conv->kind === 'dm') {
            return in_array($viewer->userid, [(int)$conv->usera, (int)$conv->userb], true);
        }
        return in_array($viewer->userid, self::members((int)$conv->teamid), true);
    }

    /**
     * A conversation the person is in, by its id.
     *
     * @param viewer $viewer The person.
     * @param int $id The conversation.
     * @return \stdClass
     */
    public static function get(viewer $viewer, int $id): \stdClass {
        global $DB;
        $conv = $DB->get_record(self::CONVS, ['id' => $id]);
        if ($conv && $conv->kind === 'team') {
            $conv = self::of_team((int)$conv->teamid);
        }
        if (!$conv || !self::sees($viewer, $conv)) {
            throw new \moodle_exception('chat:team:notyours', 'local_oksigeniamarandu');
        }
        return $conv;
    }

    /**
     * The person to write to, from the page: one of the team, not oneself.
     *
     * @param viewer $viewer Who writes.
     * @param int $userid To whom.
     * @return int
     */
    public static function other(viewer $viewer, int $userid): int {
        $ids = array_map(function ($user) {
            return (int)$user->id;
        }, self::users());
        if ($userid === $viewer->userid || !in_array($userid, $ids, true)) {
            throw new \moodle_exception('chat:team:notyours', 'local_oksigeniamarandu');
        }
        return $userid;
    }

    /**
     * Sends a message: a text, or the card of a conversation with a family the person sees.
     *
     * @param viewer $viewer Who writes, who can write.
     * @param int $id The conversation; 0 to start the one with a person, or to write in a department.
     * @param int $userid The person, with no conversation yet.
     * @param int $teamid The department.
     * @param string $text The text.
     * @param int $cwid The conversation with a family to pass, or 0.
     * @param int|null $now The time, for the tests.
     * @return array conversation (the row) and id (the message).
     */
    public static function send(
        viewer $viewer,
        int $id,
        int $userid,
        int $teamid,
        string $text,
        int $cwid = 0,
        ?int $now = null
    ): array {
        global $DB;
        $now = $now ?? time();
        $text = trim($text);
        if (\core_text::strlen($text) > self::MAX || ($text === '' && !$cwid)) {
            throw new \invalid_parameter_exception('A message needs its text, up to ' . self::MAX . ' characters');
        }
        if ($id) {
            $conv = self::get($viewer, $id);
        } else if ($teamid) {
            $conv = self::of_team($teamid, $now);
            if (!self::sees($viewer, $conv)) {
                throw new \moodle_exception('chat:team:notyours', 'local_oksigeniamarandu');
            }
        } else {
            $conv = self::between($viewer->userid, self::other($viewer, $userid), true, $now);
        }
        if ($cwid) {
            $row = $DB->get_record(mirror::TABLE, ['cwid' => $cwid]);
            if (!$row || !$viewer->sees($row)) {
                throw new \moodle_exception('chat:notyours', 'local_oksigeniamarandu');
            }
        }
        $msgid = (int)$DB->insert_record(self::MESSAGES, (object)['convid' => $conv->id, 'userid' => $viewer->userid,
            'kind' => $cwid ? 'conv' : 'text', 'text' => $text, 'cwid' => $cwid, 'timecreated' => $now]);
        $DB->set_field(self::CONVS, 'timemodified', $now, ['id' => $conv->id]);
        $conv->timemodified = $now;
        self::mark($conv->id, $viewer->userid, $msgid, $now);
        self::tell($conv, $viewer, $text !== '' ? $text : self::card_name($cwid), $now);
        return ['conversation' => $conv, 'id' => $msgid];
    }

    /**
     * Marks a conversation read, up to its last message.
     *
     * @param viewer $viewer The person.
     * @param \stdClass $conv The conversation.
     * @param int|null $now The time, for the tests.
     */
    public static function read(viewer $viewer, \stdClass $conv, ?int $now = null): void {
        global $DB;
        if (empty($conv->id)) {
            return;
        }
        self::mark(
            (int)$conv->id,
            $viewer->userid,
            (int)$DB->get_field(self::MESSAGES, 'MAX(id)', ['convid' => $conv->id]),
            $now ?? time()
        );
    }

    /**
     * What a person read of a conversation goes up to a message.
     *
     * @param int $convid The conversation.
     * @param int $userid The person.
     * @param int $msgid The last message read.
     * @param int $now The time.
     */
    protected static function mark(int $convid, int $userid, int $msgid, int $now): void {
        global $DB;
        $read = $DB->get_record(self::READS, ['convid' => $convid, 'userid' => $userid]);
        if (!$read) {
            $DB->insert_record(self::READS, (object)['convid' => $convid, 'userid' => $userid, 'lastread' => $msgid,
                'timeread' => $now, 'timenotified' => 0]);
        } else if ($msgid > (int)$read->lastread) {
            $DB->update_record(self::READS, (object)['id' => $read->id, 'lastread' => $msgid, 'timeread' => $now]);
        }
    }

    /**
     * A line of who came into a department or left it.
     *
     * @param \stdClass $conv The conversation of the department.
     * @param string $kind joined or left.
     * @param int $userid Who.
     * @param int $now The time.
     */
    protected static function line(\stdClass $conv, string $kind, int $userid, int $now): void {
        global $DB;
        $DB->insert_record(self::MESSAGES, (object)['convid' => $conv->id, 'userid' => $userid, 'kind' => $kind,
            'text' => '', 'cwid' => 0, 'timecreated' => $now]);
        $DB->set_field(self::CONVS, 'timemodified', $now, ['id' => $conv->id]);
        $conv->timemodified = $now;
    }

    /**
     * The conversations of a person: those of their departments, even empty, and those with another person that have
     * something; the newest first.
     *
     * @param viewer $viewer The person.
     * @return array As the page takes them.
     */
    public static function list(viewer $viewer): array {
        global $DB;
        $convs = [];
        foreach (self::teams_of($viewer) as $teamid) {
            $conv = self::of_team($teamid);
            $convs[(int)$conv->id] = $conv;
        }
        $dms = $DB->get_records_select(
            self::CONVS,
            "kind = 'dm' AND (usera = :me1 OR userb = :me2) AND timemodified > 0",
            ['me1' => $viewer->userid, 'me2' => $viewer->userid]
        );
        foreach ($dms as $conv) {
            $convs[(int)$conv->id] = $conv;
        }
        if (!$convs) {
            return [];
        }
        $unread = self::unread_by_conv($viewer->userid, array_keys($convs));
        $list = [];
        foreach ($convs as $conv) {
            $last = $DB->get_records(self::MESSAGES, ['convid' => $conv->id], 'id DESC', '*', 0, 1);
            $last = $last ? reset($last) : null;
            $list[] = self::head($conv, $viewer) + [
                'last' => $last ? ['text' => self::preview($last), 'kind' => (string)$last->kind, 'at' => (int)$last->timecreated,
                    'userid' => (int)$last->userid, 'firstname' => self::person((int)$last->userid)['firstname']] : null,
                'unread' => $unread[(int)$conv->id] ?? 0,
                'at' => (int)($conv->timemodified ?: $conv->timecreated),
            ];
        }
        usort($list, function ($a, $b) {
            return [$b['at'], $b['id']] <=> [$a['at'], $a['id']];
        });
        return $list;
    }

    /**
     * The departments of a person that have people of the team as users.
     *
     * @param viewer $viewer The person.
     * @return int[]
     */
    protected static function teams_of(viewer $viewer): array {
        $known = directory::teams();
        return array_values(array_filter($viewer->teams, function ($teamid) use ($known) {
            return isset($known[$teamid]);
        }));
    }

    /**
     * What a conversation is: who is in it.
     *
     * @param \stdClass $conv The conversation.
     * @param viewer $viewer Who looks.
     * @return array id, kind, teamid, teamname, other and people.
     */
    protected static function head(\stdClass $conv, viewer $viewer): array {
        $teams = directory::teams();
        if ($conv->kind === 'dm') {
            $other = (int)$conv->usera === $viewer->userid ? (int)$conv->userb : (int)$conv->usera;
            $people = [self::person($viewer->userid), self::person($other)];
            return ['id' => (int)($conv->id ?? 0), 'kind' => 'dm', 'teamid' => 0, 'teamname' => '',
                'other' => self::person($other), 'people' => $people];
        }
        return ['id' => (int)$conv->id, 'kind' => 'team', 'teamid' => (int)$conv->teamid,
            'teamname' => (string)($teams[(int)$conv->teamid]['name'] ?? ''), 'other' => null,
            'people' => array_map([self::class, 'person'], self::members((int)$conv->teamid))];
    }

    /**
     * A conversation with its last messages, or those before one.
     *
     * @param viewer $viewer Who looks.
     * @param \stdClass $conv The conversation (without id, one between two people that has nothing yet).
     * @param int $before Only those before this message; 0 for the last ones.
     * @return array As the page takes it.
     */
    public static function export(viewer $viewer, \stdClass $conv, int $before = 0): array {
        global $DB;
        $messages = [];
        $more = false;
        if (!empty($conv->id)) {
            $params = ['convid' => $conv->id];
            $where = 'convid = :convid';
            if ($before) {
                $where .= ' AND id < :before';
                $params['before'] = $before;
            }
            $rows = $DB->get_records_select(self::MESSAGES, $where, $params, 'id DESC', '*', 0, self::PAGE + 1);
            $more = count($rows) > self::PAGE;
            $rows = array_reverse(array_slice($rows, 0, self::PAGE));
            $cards = [];
            $cwids = array_filter(array_map(function ($row) {
                return (int)$row->cwid;
            }, $rows));
            if ($cwids) {
                [$in, $inparams] = $DB->get_in_or_equal(array_values(array_unique($cwids)), SQL_PARAMS_NAMED);
                foreach ($DB->get_records_select(mirror::TABLE, "cwid $in", $inparams) as $one) {
                    $cards[(int)$one->cwid] = $one;
                }
            }
            $teams = directory::teams();
            foreach ($rows as $row) {
                $card = null;
                if ($row->kind === 'conv') {
                    $one = $cards[(int)$row->cwid] ?? null;
                    $card = ['cwid' => (int)$row->cwid, 'name' => $one ? (string)$one->name : '',
                        'phone' => $one ? (string)$one->phone : '', 'kind' => $one ? (string)$one->kind : '',
                        'status' => $one ? (string)$one->status : '',
                        'teamname' => $one ? (string)($teams[(int)$one->teamid]['name'] ?? '') : ''];
                }
                $messages[] = ['id' => (int)$row->id, 'kind' => (string)$row->kind, 'text' => (string)$row->text,
                    'at' => (int)$row->timecreated, 'author' => self::person((int)$row->userid),
                    'mine' => (int)$row->userid === $viewer->userid && in_array($row->kind, ['text', 'conv']), 'conv' => $card];
            }
        }
        return self::head($conv, $viewer) + ['messages' => $messages, 'more' => $more];
    }

    /**
     * The whole team to write to: each person with their departments and the conversation with whoever looks.
     *
     * @param viewer $viewer Who looks.
     * @return array
     */
    public static function people(viewer $viewer): array {
        global $DB;
        $dms = [];
        foreach (
            $DB->get_records_select(self::CONVS, "kind = 'dm' AND (usera = :me1 OR userb = :me2)", ['me1' => $viewer->userid,
                'me2' => $viewer->userid]) as $conv
        ) {
            $dms[(int)$conv->usera === $viewer->userid ? (int)$conv->userb : (int)$conv->usera] = (int)$conv->id;
        }
        $people = [];
        foreach (self::users() as $agentid => $user) {
            if ((int)$user->id === $viewer->userid) {
                continue;
            }
            $people[] = self::person((int)$user->id) + ['teamids' => directory::teams_of($agentid),
                'dm' => $dms[(int)$user->id] ?? 0];
        }
        usort($people, function ($a, $b) {
            return strcmp(\core_text::strtolower($a['name']), \core_text::strtolower($b['name']));
        });
        return $people;
    }

    /**
     * The departments of whoever looks, with how many people each has.
     *
     * @param viewer $viewer Who looks.
     * @return array
     */
    public static function teams(viewer $viewer): array {
        $known = directory::teams();
        $list = [];
        foreach (self::teams_of($viewer) as $teamid) {
            $list[] = ['id' => $teamid, 'name' => (string)$known[$teamid]['name'], 'count' => count(self::members($teamid))];
        }
        return $list;
    }

    /**
     * How many messages wait unread, and the last time anything of theirs moved.
     *
     * @param viewer $viewer The person.
     * @return array unread and rev.
     */
    public static function counts(viewer $viewer): array {
        global $DB;
        $ids = [];
        $rev = 0;
        $teamids = self::teams_of($viewer);
        $where = "(kind = 'dm' AND (usera = :me1 OR userb = :me2))";
        $params = ['me1' => $viewer->userid, 'me2' => $viewer->userid];
        if ($teamids) {
            [$in, $inparams] = $DB->get_in_or_equal($teamids, SQL_PARAMS_NAMED, 't');
            $where .= " OR (kind = 'team' AND teamid $in)";
            $params += $inparams;
        }
        foreach ($DB->get_records_select(self::CONVS, $where, $params, '', 'id, timemodified') as $conv) {
            $ids[] = (int)$conv->id;
            $rev = max($rev, (int)$conv->timemodified);
        }
        return ['unread' => array_sum(self::unread_by_conv($viewer->userid, $ids)), 'rev' => $rev];
    }

    /**
     * The messages of others a person has not read, by conversation; the lines of who came or left do not count.
     *
     * @param int $userid The person.
     * @param int[] $ids The conversations.
     * @return array By conversation.
     */
    protected static function unread_by_conv(int $userid, array $ids): array {
        global $DB;
        if (!$ids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $rows = $DB->get_records_sql(
            "SELECT m.convid, COUNT(1) AS n
               FROM {" . self::MESSAGES . "} m
          LEFT JOIN {" . self::READS . "} r ON r.convid = m.convid AND r.userid = :me
              WHERE m.convid $in AND m.userid <> :me2 AND m.kind IN ('text', 'conv') AND m.id > COALESCE(r.lastread, 0)
           GROUP BY m.convid",
            $params + ['me' => $userid, 'me2' => $userid]
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int)$row->convid] = (int)$row->n;
        }
        return $counts;
    }

    /**
     * What the list shows of a message.
     *
     * @param \stdClass $message The message.
     * @return string
     */
    protected static function preview(\stdClass $message): string {
        $text = $message->kind === 'conv' && trim((string)$message->text) === ''
            ? self::card_name((int)$message->cwid) : (string)$message->text;
        return \core_text::substr(directory::one_line($text), 0, 160);
    }

    /**
     * The name of the conversation with a family passed as a card.
     *
     * @param int $cwid The conversation.
     * @return string
     */
    protected static function card_name(int $cwid): string {
        global $DB;
        $name = $cwid ? (string)$DB->get_field(mirror::TABLE, 'name', ['cwid' => $cwid]) : '';
        return get_string('chat:team:card', 'local_oksigeniamarandu', $name);
    }

    /**
     * Tells the others of the conversation: the bell (and with it the desktop and the app) and, to whoever linked their
     * Telegram, the notice of the team. One for each conversation until they read it or half an hour passes; not
     * looking at the hours, as a mention.
     *
     * @param \stdClass $conv The conversation.
     * @param viewer $by Who wrote.
     * @param string $text What they wrote.
     * @param int $now The time.
     */
    protected static function tell(\stdClass $conv, viewer $by, string $text, int $now): void {
        global $DB;
        $people = $conv->kind === 'dm' ? [(int)$conv->usera, (int)$conv->userb] : self::members((int)$conv->teamid);
        $short = \core_text::strlen($text) > 160 ? \core_text::substr($text, 0, 157) . '…' : $text;
        $teams = directory::teams();
        $values = (object)['by' => $by->firstname, 'text' => directory::one_line($short),
            'team' => (string)($teams[(int)$conv->teamid]['name'] ?? '')];
        $key = $conv->kind === 'dm' ? 'chat:notify:teamdm' : 'chat:notify:team';
        $url = new \moodle_url('/local/oksigeniamarandu/chat.php', ['eq' => $conv->id]);
        foreach (array_diff($people, [$by->userid]) as $userid) {
            $read = $DB->get_record(self::READS, ['convid' => $conv->id, 'userid' => $userid]);
            if ($read && $read->timenotified && $read->timeread < $read->timenotified && $now - $read->timenotified < self::QUIET) {
                continue;
            }
            $user = \core_user::get_user($userid);
            if (!$user || $user->deleted || $user->suspended) {
                continue;
            }
            if ($read) {
                $DB->set_field(self::READS, 'timenotified', $now, ['id' => $read->id]);
            } else {
                $DB->insert_record(self::READS, (object)['convid' => $conv->id, 'userid' => $userid, 'lastread' => 0,
                    'timeread' => 0, 'timenotified' => $now]);
            }
            $subject = get_string($key, 'local_oksigeniamarandu', $values);
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
            $message->customdata = \local_oksigeniamarandu\local\message_icon::customdata();
            $message->contexturl = $url->out(false);
            $message->contexturlname = get_string('chat:title', 'local_oksigeniamarandu');
            message_send($message);
            try {
                \local_oksigeniamarandu\api::notify('local_oksigeniamarandu', 'team', [$subject], $url, 0, $userid);
            } catch (\Throwable $e) {
                debugging('Marandu: the message of the team did not go to Telegram: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Takes away what the conversations of the team keep of a person: their conversations with another person, whole,
     * and their messages and reads in those of the departments. For the privacy of the site and when an account passes
     * to another person.
     *
     * @param int $userid The person.
     */
    public static function delete_user(int $userid): void {
        global $DB;
        $dms = $DB->get_fieldset_select(
            self::CONVS,
            'id',
            "kind = 'dm' AND (usera = :me1 OR userb = :me2)",
            ['me1' => $userid, 'me2' => $userid]
        );
        if ($dms) {
            [$in, $params] = $DB->get_in_or_equal($dms, SQL_PARAMS_NAMED);
            $DB->delete_records_select(self::MESSAGES, "convid $in", $params);
            $DB->delete_records_select(self::READS, "convid $in", $params);
            $DB->delete_records_select(self::CONVS, "id $in", $params);
        }
        $DB->delete_records(self::MESSAGES, ['userid' => $userid]);
        $DB->delete_records(self::READS, ['userid' => $userid]);
    }
}
