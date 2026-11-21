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
 * «Remind me»: a person asks to have a conversation back on a day, with a line of what for. Until then it is theirs and
 * waits apart (snoozed in Chatwoot, which opens it again on its own if the family writes before); that day, when the team
 * opens, it is open again and theirs, with the line as a note of the team, and a notice tells them.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reminders {
    /** The table. */
    public const TABLE = 'local_oksigeniamarandu_convrem';

    /** How long a reminder that does not come back (Chatwoot does not answer) is tried again. */
    public const RETRY = 6 * HOURSECS;

    /**
     * Asks to have a conversation back on a day: the one the person had is replaced.
     *
     * @param viewer $viewer Who asks, who can write.
     * @param \stdClass $row The conversation, one the person sees.
     * @param string $day The day, Y-m-d.
     * @param string $reason What for, in a line.
     * @param hours|null $hours The hours of the team; those of the site if null.
     * @param int|null $now The time, for the tests.
     * @return \stdClass The conversation as it is now.
     */
    public static function set(
        viewer $viewer,
        \stdClass $row,
        string $day,
        string $reason,
        ?hours $hours = null,
        ?int $now = null
    ): \stdClass {
        global $DB;
        $now = $now ?? time();
        $at = ($hours ?? hours::of_site())->opening($day);
        if ($at <= $now) {
            throw new \invalid_parameter_exception('The day must be ahead');
        }
        $reason = \core_text::substr(trim(preg_replace('/\s+/u', ' ', $reason)), 0, 200);
        $DB->delete_records_select(
            self::TABLE,
            'cwid = :cwid AND userid = :userid AND timedone = 0',
            ['cwid' => $row->cwid, 'userid' => $viewer->userid]
        );
        $DB->insert_record(self::TABLE, (object)['cwid' => (int)$row->cwid, 'userid' => $viewer->userid, 'remindat' => $at,
            'reason' => $reason, 'timecreated' => $now, 'timedone' => 0]);
        if ($row->status !== 'resolved') {
            // Theirs, and apart until that day.
            $path = '/conversations/' . (int)$row->cwid;
            directory::as_person($viewer, function (chatwoot $client) use ($path, $row, $viewer, $at) {
                if ((int)$row->assigneeid !== (int)$viewer->agent['id']) {
                    $client->call('POST', $path . '/assignments', ['assignee_id' => $viewer->agent['id']]);
                }
                $client->call('POST', $path . '/toggle_status', ['status' => 'snoozed', 'snoozed_until' => $at]);
            });
            return mirror::refresh((int)$row->cwid, false) ?: $row;
        }
        return $row;
    }

    /**
     * Takes away the reminder of a person on a conversation; if it was waiting apart, it is open again.
     *
     * @param viewer $viewer Who.
     * @param \stdClass $row The conversation.
     * @return \stdClass The conversation as it is now.
     */
    public static function remove(viewer $viewer, \stdClass $row): \stdClass {
        global $DB;
        $DB->delete_records_select(
            self::TABLE,
            'cwid = :cwid AND userid = :userid AND timedone = 0',
            ['cwid' => $row->cwid, 'userid' => $viewer->userid]
        );
        if ($row->status === 'snoozed') {
            return actions::run($viewer, $row, 'reopen') ?? $row;
        }
        return $row;
    }

    /**
     * The reminders a person is waiting for, by conversation.
     *
     * @param int $userid The person.
     * @return array By conversation: at and reason.
     */
    public static function of_user(int $userid): array {
        global $DB;
        $list = [];
        foreach ($DB->get_records(self::TABLE, ['userid' => $userid, 'timedone' => 0], 'remindat ASC') as $one) {
            $list[(int)$one->cwid] = ['at' => (int)$one->remindat, 'reason' => (string)$one->reason];
        }
        return $list;
    }

    /**
     * Brings back the conversations whose day came: open and theirs, with the line as a note, and a notice.
     *
     * @param int|null $now The time, for the tests.
     * @return int How many came back.
     */
    public static function due(?int $now = null): int {
        global $DB;
        $now = $now ?? time();
        $done = 0;
        $rows = $DB->get_records_select(
            self::TABLE,
            'timedone = 0 AND remindat <= :now',
            ['now' => $now],
            'remindat ASC',
            '*',
            0,
            50
        );
        foreach ($rows as $one) {
            try {
                self::bring_back($one);
                $done++;
            } catch (\moodle_exception $e) {
                debugging('Marandu: a reminder did not come back: ' . $e->getMessage(), DEBUG_DEVELOPER);
                if ($one->remindat > $now - self::RETRY) {
                    continue;
                }
            }
            $DB->set_field(self::TABLE, 'timedone', $now, ['id' => $one->id]);
        }
        return $done;
    }

    /**
     * Brings one back.
     *
     * @param \stdClass $one The reminder.
     */
    protected static function bring_back(\stdClass $one): void {
        global $DB;
        $user = \core_user::get_user((int)$one->userid);
        $viewer = $user && !$user->deleted && !$user->suspended ? viewer::for_user($user) : null;
        $row = $DB->get_record(mirror::TABLE, ['cwid' => $one->cwid]);
        if (!$viewer || $viewer->readonly || !$viewer->agent['id'] || !$row) {
            return;
        }
        if (!$viewer->sees($row)) {
            // It went to another department or to someone else meanwhile: the reminder does not take it from them.
            return;
        }
        if ($row->status === 'snoozed' || $row->status === 'resolved') {
            $row = actions::run($viewer, $row, 'reopen') ?? $row;
        }
        if ((int)$row->assigneeid !== (int)$viewer->agent['id']) {
            $row = actions::run($viewer, $row, 'take') ?? $row;
        }
        $reason = (string)$one->reason;
        actions::note($viewer, $row, $reason !== '' ? get_string('chat:remind:note', 'local_oksigeniamarandu', $reason)
            : get_string('chat:remind:noteempty', 'local_oksigeniamarandu'));
        $name = $row->name !== '' ? $row->name : '+' . $row->phone;
        notify::send(
            (int)$row->cwid,
            (int)$user->id,
            $reason !== '' ? 'reminder' : 'remindernoreason',
            ['name' => $name, 'reason' => $reason]
        );
    }
}
