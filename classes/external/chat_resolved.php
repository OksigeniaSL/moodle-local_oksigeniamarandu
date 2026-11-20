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

namespace local_oksigeniamarandu\external;

use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_oksigeniamarandu\local\chat\mirror;

/**
 * The conversations resolved in the last days that a person sees, the newest first: the page only keeps what is open
 * and what was resolved today, and this brings back what someone resolved yesterday or last week.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_resolved extends chat_base {
    /** How many days back, at most. */
    public const DAYS = 30;

    /** How many at most in one answer. */
    public const LIMIT = 200;

    /**
     * The parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'before' => new external_value(PARAM_INT, 'Only those resolved before this time; 0 for the newest', VALUE_DEFAULT, 0),
            'id' => new external_value(PARAM_INT, 'One conversation, whenever it was, if the person sees it', VALUE_DEFAULT, 0),
            'query' => new external_value(PARAM_TEXT, 'Every conversation of whom this finds, whenever it was', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * The resolved conversations.
     *
     * @param int $before Only those whose last activity was before this time; 0 for the newest.
     * @param int $id One conversation only, whenever it was (an old one the card of the contact lists).
     * @param string $query Every conversation of whom this finds (a name, a phone, a child of the family), whenever.
     * @return array
     */
    public static function execute(int $before = 0, int $id = 0, string $query = ''): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['before' => $before, 'id' => $id, 'query' => $query]);
        $viewer = self::viewer();
        $now = time();
        if ($params['id']) {
            $row = $DB->get_record(mirror::TABLE, ['cwid' => $params['id']]);
            $list = $row && $viewer->sees($row) ? [self::clean($viewer->export($row, $viewer->seen([(int)$row->cwid]), $now))] : [];
            return ['conversations' => $list, 'more' => false, 'days' => self::DAYS];
        }
        [$where, $wparams] = $viewer->where();
        $query = trim($params['query']);
        if (\core_text::strlen($query) >= 2) {
            return ['conversations' => self::found($viewer, $where, $wparams, $query, $now), 'more' => false, 'days' => self::DAYS];
        }
        $sql = "c.status = 'resolved' AND c.lastactivity >= :since";
        $wparams['since'] = $now - self::DAYS * DAYSECS;
        if ($params['before'] > 0) {
            $sql .= ' AND c.lastactivity < :before';
            $wparams['before'] = $params['before'];
        }
        $rows = $DB->get_records_sql(
            "SELECT c.* FROM {" . mirror::TABLE . "} c WHERE $where AND $sql ORDER BY c.lastactivity DESC, c.cwid DESC",
            $wparams,
            0,
            self::LIMIT + 1
        );
        $more = count($rows) > self::LIMIT;
        $rows = array_slice($rows, 0, self::LIMIT);
        $seen = $viewer->seen(array_map('intval', array_column($rows, 'cwid')));
        $list = [];
        foreach ($rows as $row) {
            $list[] = self::clean($viewer->export($row, $seen, $now));
        }
        return ['conversations' => $list, 'more' => $more, 'days' => self::DAYS];
    }

    /**
     * Every conversation the person sees of whom a search finds, whenever it was: by the name of the contact, by
     * their phone, or by any name of their family in the common record (a child, the other adult).
     *
     * @param \local_oksigeniamarandu\local\chat\viewer $viewer Who looks.
     * @param string $where What they see, as SQL.
     * @param array $wparams Its parameters.
     * @param string $query What was typed.
     * @param int $now The time.
     * @return array The conversations, the newest first.
     */
    protected static function found($viewer, string $where, array $wparams, string $query, int $now): array {
        global $DB;
        $conditions = [$DB->sql_like('c.name', ':qname', false, false)];
        $params = ['qname' => '%' . $DB->sql_like_escape($query) . '%'];
        $digits = preg_replace('/\D/', '', $query);
        if (strlen($digits) >= 3) {
            $conditions[] = $DB->sql_like('c.phone', ':qphone');
            $params['qphone'] = '%' . $digits . '%';
        }
        $phones = [];
        foreach (\local_oksigeniamarandu\local\chat\family::search($query, 10) as $family) {
            foreach ($family['adults'] as $adult) {
                if ($adult['phone'] !== '') {
                    $phones[] = $adult['phone'];
                }
            }
        }
        if ($phones) {
            [$in, $inparams] = $DB->get_in_or_equal(array_values(array_unique($phones)), SQL_PARAMS_NAMED, 'fphone');
            $conditions[] = "c.phone $in";
            $params += $inparams;
        }
        $rows = $DB->get_records_sql(
            "SELECT c.* FROM {" . mirror::TABLE . "} c WHERE $where AND (" . implode(' OR ', $conditions) . ")
          ORDER BY c.lastactivity DESC, c.cwid DESC",
            $wparams + $params,
            0,
            self::LIMIT
        );
        $seen = $viewer->seen(array_map('intval', array_column($rows, 'cwid')));
        $list = [];
        foreach ($rows as $row) {
            $list[] = self::clean($viewer->export($row, $seen, $now));
        }
        return $list;
    }

    /**
     * What it returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'conversations' => new external_multiple_structure(self::conversation_structure()),
            'more' => new external_value(PARAM_BOOL, 'Whether there are older ones in those days'),
            'days' => new external_value(PARAM_INT, 'How many days back it looks'),
        ]);
    }
}
