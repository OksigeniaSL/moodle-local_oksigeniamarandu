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
 * Who of the team already attends a family: the other conversations still alive (open, with the menu or put off
 * until a day) of the adults of the same household, by the common record of families, that another person has.
 * Whoever opens one of them sees who has the other, and can pass theirs to that person or keep it.
 *
 * Only with the record of families, and only when the centre checked the number: a number someone just typed tells
 * nothing about who the family is.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class held {
    /** How many it says at most. */
    public const LIMIT = 3;

    /**
     * The conversations of the same family that another person has, the latest first.
     *
     * @param viewer $viewer Who looks.
     * @param \stdClass $row The conversation open.
     * @return array Each with conversation, status, teamid, teamname, agentid, name, firstname (who has it), adult (with
     *               whom), phone, preview and at (only if whoever looks sees it, else empty and 0) and sees.
     */
    public static function elsewhere(viewer $viewer, \stdClass $row): array {
        global $DB;
        $phone = (string)($row->phone ?? '');
        $family = $phone !== '' ? family::of_phone($phone) : null;
        if (!$family || empty($family['verified'])) {
            return [];
        }
        // The numbers of the family the centre knows: its adults, and the students of age if the site writes to them.
        $who = [$phone => ''];
        foreach ($family['adults'] as $adult) {
            if ($adult['phone'] !== '' && !empty($adult['verified'])) {
                $who[$adult['phone']] = $adult['name'];
            }
        }
        foreach ($family['students'] ?? [] as $student) {
            $who[$student['phone']] = $who[$student['phone']] ?? $student['name'];
        }
        [$in, $params] = $DB->get_in_or_equal(array_map('strval', array_keys($who)), SQL_PARAMS_NAMED, 'kin');
        $rows = $DB->get_records_select(
            mirror::TABLE,
            "phone $in AND cwid <> :cwid AND assigneeid <> 0 AND status IN ('open', 'pending', 'snoozed')",
            $params + ['cwid' => (int)$row->cwid],
            'lastactivity DESC'
        );
        $teams = directory::teams();
        $list = [];
        foreach ($rows as $other) {
            $agentid = (int)$other->assigneeid;
            // Theirs, or the same person who has this one: nobody else attends the family.
            if ($agentid === (int)$viewer->agent['id'] || $agentid === (int)$row->assigneeid) {
                continue;
            }
            $agent = directory::agent($agentid);
            if (!$agent) {
                continue;
            }
            $sees = $viewer->sees($other);
            $list[] = [
                'conversation' => (int)$other->cwid,
                'status' => (string)$other->status,
                'teamid' => (int)$other->teamid,
                'teamname' => (string)($teams[(int)$other->teamid]['name'] ?? ''),
                'agentid' => $agentid,
                'name' => (string)$agent['name'],
                'firstname' => (string)strtok((string)$agent['name'], ' '),
                'adult' => (string)($who[(string)$other->phone] ?: $other->name),
                'phone' => (string)$other->phone,
                // What was said, only to whoever may open it.
                'preview' => $sees ? (string)$other->preview : '',
                'at' => $sees ? (int)($other->previewat ?: $other->lastactivity) : 0,
                'sees' => $sees,
            ];
            if (count($list) >= self::LIMIT) {
                break;
            }
        }
        return $list;
    }
}
