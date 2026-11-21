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
 * The address book of the conversations: the favourites of each person (a star they put on whoever they write to
 * often) and the contacts of the centre by what they are (suppliers, staff, former students), as anyone of the team
 * marks them in their file. Each with the latest conversation the person sees, to open it, or none, to write first.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agenda {
    /** The user preference with the favourites of a person: contact ids, comma separated. */
    public const PREF = 'local_oksigeniamarandu_chatfavs';

    /** The kinds of contact the centre keeps in its address book, in their order. */
    public const KINDS = ['supplier', 'staff', 'alumni', 'other'];

    /** How many favourites a person keeps, at most. */
    public const MAX = 200;

    /**
     * The favourites of a person.
     *
     * @param int $userid The person.
     * @return int[] Contact ids, the last starred first.
     */
    public static function favourites(int $userid): array {
        $value = (string)get_user_preferences(self::PREF, '', $userid);
        return array_values(array_unique(array_filter(array_map('intval', explode(',', $value)))));
    }

    /**
     * Puts or takes away the star of a person on a contact.
     *
     * @param int $userid The person.
     * @param int $contactid The contact, in Chatwoot.
     * @param bool $on With the star.
     */
    public static function star(int $userid, int $contactid, bool $on): void {
        $list = array_diff(self::favourites($userid), [$contactid]);
        if ($on) {
            array_unshift($list, $contactid);
        }
        $list = array_slice(array_values($list), 0, self::MAX);
        set_user_preference(self::PREF, $list ? implode(',', $list) : null, $userid);
    }

    /**
     * The address book as a person sees it.
     *
     * @param viewer $viewer Who looks.
     * @return array favourites (contacts) and groups (kind and contacts), each contact with contactid, name, phone,
     *               kind, line and rings (as the trays show it), conversation (the latest one the person sees, or 0),
     *               lastactivity and favourite.
     */
    public static function for_viewer(viewer $viewer): array {
        global $DB;
        $now = time();
        $favs = self::favourites($viewer->userid);
        // Whatever the team marked, also with family: a contact goes in the group of each of its marks.
        $params = [];
        $where = "c.kind <> ''";
        if ($favs) {
            [$infavs, $favparams] = $DB->get_in_or_equal($favs, SQL_PARAMS_NAMED, 'fav');
            $where = "($where OR c.contactid $infavs)";
            $params += $favparams;
        }
        $rows = $DB->get_records_sql(
            "SELECT c.* FROM {" . mirror::TABLE . "} c WHERE c.contactid > 0 AND $where ORDER BY c.lastactivity DESC, c.cwid DESC",
            $params
        );
        $contacts = [];
        $marked = [];
        foreach ($rows as $row) {
            $id = (int)$row->contactid;
            if (!isset($contacts[$id])) {
                // The same face and line as in the trays, from its newest conversation.
                $shown = $viewer->export($row, [], $now)['contact'];
                $contacts[$id] = ['contactid' => $id, 'name' => (string)$row->name, 'phone' => (string)$row->phone,
                    'kind' => (string)$shown['kind'], 'marks' => $shown['marks'], 'note' => (string)$shown['note'],
                    'line' => (string)$shown['line'], 'rings' => $shown['rings'],
                    'conversation' => 0, 'lastactivity' => (int)$row->lastactivity, 'favourite' => in_array($id, $favs, true)];
            }
            // What it is, from its newest conversation that says it; the newest conversation the person can open.
            if (!isset($marked[$id]) && array_intersect(marks::parse((string)$row->kind), self::KINDS)) {
                $marked[$id] = marks::parse((string)$row->kind);
            }
            if (!$contacts[$id]['conversation'] && $viewer->sees($row)) {
                $contacts[$id]['conversation'] = (int)$row->cwid;
            }
        }
        // A favourite only stays while the person sees a conversation of it, unless the team marked what it is.
        foreach ($contacts as $id => $contact) {
            if (!$contact['conversation'] && !isset($marked[$id])) {
                unset($contacts[$id]);
            }
        }
        $byname = function (array $a, array $b): int {
            return \core_text::strtolower($a['name']) <=> \core_text::strtolower($b['name']);
        };
        $favourites = [];
        foreach ($favs as $id) {
            if (isset($contacts[$id])) {
                $favourites[] = $contacts[$id];
            }
        }
        $groups = [];
        foreach (self::KINDS as $kind) {
            $list = array_values(array_filter($contacts, function ($contact) use ($kind, $marked) {
                return in_array($kind, $marked[$contact['contactid']] ?? [], true);
            }));
            usort($list, $byname);
            if ($list) {
                $groups[] = ['kind' => $kind, 'contacts' => $list];
            }
        }
        return ['favourites' => $favourites, 'groups' => $groups];
    }
}
