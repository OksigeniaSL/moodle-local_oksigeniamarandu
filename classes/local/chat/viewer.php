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
 * A person of the team at the page of the conversations: their user of Moodle, the person of Chatwoot with the same
 * email, their departments and what they see. The administrators of the account of Chatwoot and whoever has the
 * capability to see everything see every conversation; the rest, those of their departments and those they have.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class viewer {
    /** The table of what each person read. */
    public const SEEN = 'local_oksigeniamarandu_convseen';

    /** @var int The user of Moodle. */
    public $userid;

    /** @var string Their first name. */
    public $firstname;

    /** @var array The person of Chatwoot: id, name, email and role. */
    public $agent;

    /** @var bool Whether they see every conversation. */
    public $seesall;

    /** @var int[] Their departments. */
    public $teams;

    /** @var bool Whether they only look: whoever sees everything without being a person of the account. */
    public $readonly;

    /**
     * The person.
     *
     * @param int $userid The user of Moodle.
     * @param string $firstname Their first name.
     * @param array $agent The person of Chatwoot.
     * @param bool $seesall Whether they see everything.
     * @param int[] $teams Their departments.
     * @param bool $readonly Whether they only look.
     */
    public function __construct(
        int $userid,
        string $firstname,
        array $agent,
        bool $seesall,
        array $teams,
        bool $readonly = false
    ) {
        $this->readonly = $readonly;
        $this->userid = $userid;
        $this->firstname = $firstname;
        $this->agent = $agent;
        $this->seesall = $seesall;
        $this->teams = array_values(array_map('intval', $teams));
    }

    /**
     * The person a user of Moodle is at the conversations, if they are one.
     *
     * @param \stdClass|null $user The user; the current one if null.
     * @return self|null Null if they cannot use the conversations or are not a person of the account; whoever sees
     *                   everything without being one only looks.
     */
    public static function for_user(?\stdClass $user = null): ?self {
        global $USER;
        $user = $user ?? $USER;
        if (empty($user->id) || isguestuser($user) || !chatwoot::is_configured()) {
            return null;
        }
        $context = \context_system::instance();
        if (!has_capability('local/oksigeniamarandu:chat', $context, $user)) {
            return null;
        }
        $agent = directory::agent_by_email((string)$user->email);
        $seesall = has_capability('local/oksigeniamarandu:chatall', $context, $user);
        if (!$agent) {
            if (!$seesall) {
                return null;
            }
            $agent = ['id' => 0, 'name' => fullname($user), 'email' => '', 'role' => ''];
            return new self((int)$user->id, (string)$user->firstname, $agent, true, [], true);
        }
        $seesall = $seesall || $agent['role'] === 'administrator';
        return new self((int)$user->id, (string)$user->firstname, $agent, $seesall, directory::teams_of($agent['id']));
    }

    /**
     * The condition of the conversations this person sees, on the table with the alias c. What is with the bot of
     * the menu (pending) is not in the lists.
     *
     * @return array [sql, params].
     */
    public function where(): array {
        global $DB;
        $sql = "c.status <> 'pending'";
        $params = [];
        if ($this->seesall) {
            return [$sql, $params];
        }
        $or = 'c.assigneeid = :meagent OR c.passedto = :meagent2';
        $params = ['meagent' => $this->agent['id'], 'meagent2' => $this->agent['id']];
        if ($this->teams) {
            [$in, $inparams] = $DB->get_in_or_equal($this->teams, SQL_PARAMS_NAMED, 'myteam');
            $or .= " OR c.teamid $in";
            $params += $inparams;
        }
        return ["$sql AND ($or)", $params];
    }

    /**
     * Whether this person sees a conversation.
     *
     * @param \stdClass $row Its row.
     * @return bool
     */
    public function sees(\stdClass $row): bool {
        if ($row->status === 'pending') {
            return false;
        }
        return $this->seesall || (int)$row->assigneeid === $this->agent['id'] || (int)$row->passedto === $this->agent['id']
            || in_array((int)$row->teamid, $this->teams);
    }

    /**
     * When this person last read each conversation.
     *
     * @param int[] $ids The conversations; all if empty.
     * @return array By conversation.
     */
    public function seen(array $ids = []): array {
        global $DB;
        $params = ['userid' => $this->userid];
        $sql = 'userid = :userid';
        if ($ids) {
            [$in, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
            $sql .= " AND convid $in";
            $params += $inparams;
        }
        return $DB->get_records_select_menu(self::SEEN, $sql, $params, '', 'convid, timeseen');
    }

    /**
     * Notes that this person read a conversation now.
     *
     * @param int $id The conversation.
     */
    public function read(int $id): void {
        global $DB;
        $row = $DB->get_record(self::SEEN, ['userid' => $this->userid, 'convid' => $id]);
        if ($row) {
            $DB->set_field(self::SEEN, 'timeseen', time(), ['id' => $row->id]);
        } else {
            $DB->insert_record(self::SEEN, (object)['userid' => $this->userid, 'convid' => $id, 'timeseen' => time()]);
        }
    }

    /**
     * A conversation for the page.
     *
     * @param \stdClass $row Its row.
     * @param array $seen When this person read each conversation.
     * @param int $now The time.
     * @return array As the contract of the page says.
     */
    public function export(\stdClass $row, array $seen, int $now): array {
        $assignee = null;
        if ($row->assigneeid) {
            $agent = directory::agent((int)$row->assigneeid);
            $name = $agent ? $agent['name'] : '';
            $assignee = ['agentid' => (int)$row->assigneeid, 'name' => $name, 'firstname' => (string)strtok($name, ' '),
                'me' => $this->agent['id'] && (int)$row->assigneeid === $this->agent['id']];
        }
        $passed = null;
        $tome = $this->agent['id'] && (int)$row->passedto === $this->agent['id'];
        $tomyteam = !$row->passedto && in_array((int)$row->passedteam, $this->teams);
        if ($row->passedat && $row->passedby != $this->userid && ($tome || $tomyteam)) {
            $by = \core_user::get_user((int)$row->passedby);
            $passed = ['by' => $by ? (string)$by->firstname : '', 'at' => (int)$row->passedat, 'toteam' => !$tome];
        }
        $kind = $row->kind !== '' ? (string)$row->kind : 'unknown';
        $line = '';
        $rings = [];
        if ($kind === 'unknown' && ($family = family::of_phone((string)$row->phone))) {
            $kind = 'family';
            $line = $family['line'];
            foreach ($family['children'] as $child) {
                if ($child['stage'] && $child['stage']['color'] !== '') {
                    $rings[] = ['color' => $child['stage']['color'], 'dark' => $child['stage']['dark'], 'dashed' => false,
                        'name' => $child['name']];
                }
            }
        }
        $open = $row->status !== 'resolved';
        $replyuntil = $row->lastincoming ? (int)$row->lastincoming + DAYSECS : 0;
        return [
            'id' => (int)$row->cwid,
            'status' => (string)$row->status,
            'section' => !$open ? 'resolved' : ($row->waitsince ? 'reply' : 'waiting'),
            'teamid' => (int)$row->teamid,
            'assignee' => $assignee,
            'contact' => ['id' => (int)$row->contactid, 'name' => (string)$row->name, 'phone' => (string)$row->phone,
                'kind' => $kind, 'line' => $line, 'rings' => $rings],
            'preview' => ['text' => (string)$row->preview, 'kind' => (string)$row->previewkind,
                'incoming' => $row->previewkind === 'incoming', 'private' => $row->previewkind === 'note',
                'at' => (int)$row->previewat],
            'waitsince' => $open ? (int)$row->waitsince : 0,
            'lastincoming' => (int)$row->lastincoming,
            'canreply' => $replyuntil > $now,
            'replyuntil' => $replyuntil,
            'unread' => $open && $row->lastincoming > (int)($seen[$row->cwid] ?? 0),
            'passed' => $passed,
            'lastactivity' => (int)$row->lastactivity,
            'rev' => (int)$row->timemodified,
        ];
    }

    /**
     * How many conversations wait in each tray this person has: theirs, those nobody has in their departments, and
     * each department they see.
     *
     * @return array mine, unassigned and teams, as the contract of the page says.
     */
    public function inboxes(): array {
        global $DB;
        [$where, $params] = $this->where();
        $rows = $DB->get_records_sql("SELECT c.id, c.teamid, c.assigneeid, c.waitsince
                                        FROM {" . mirror::TABLE . "} c
                                       WHERE $where AND c.status <> 'resolved'", $params);
        $teams = directory::teams();
        $counts = [];
        $mine = ['reply' => 0, 'total' => 0];
        $unassigned = ['reply' => 0, 'total' => 0];
        foreach ($rows as $row) {
            $teamid = (int)$row->teamid;
            $counts[$teamid] = $counts[$teamid] ?? ['reply' => 0, 'unassigned' => 0, 'total' => 0];
            $counts[$teamid]['total']++;
            $counts[$teamid]['reply'] += $row->waitsince ? 1 : 0;
            if ($this->agent['id'] && (int)$row->assigneeid === $this->agent['id']) {
                $mine['total']++;
                $mine['reply'] += $row->waitsince ? 1 : 0;
            }
            if (!$row->assigneeid) {
                $counts[$teamid]['unassigned']++;
                // Those nobody has: in their departments; for whoever has none, in all of them.
                if (!$this->teams || in_array($teamid, $this->teams)) {
                    $unassigned['total']++;
                    $unassigned['reply'] += $row->waitsince ? 1 : 0;
                }
            }
        }
        $list = [];
        foreach ($teams as $id => $team) {
            $ismine = in_array($id, $this->teams);
            if (!$this->seesall && !$ismine && empty($counts[$id])) {
                continue;
            }
            $count = $counts[$id] ?? ['reply' => 0, 'unassigned' => 0, 'total' => 0];
            $list[] = ['id' => $id, 'name' => $team['name'], 'mine' => $ismine, 'internal' => $team['internal']] + $count;
        }
        return ['mine' => $mine, 'unassigned' => $unassigned, 'teams' => $list];
    }
}
