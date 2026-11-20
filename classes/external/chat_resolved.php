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
        ]);
    }

    /**
     * The resolved conversations.
     *
     * @param int $before Only those whose last activity was before this time; 0 for the newest.
     * @return array
     */
    public static function execute(int $before = 0): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['before' => $before]);
        $viewer = self::viewer();
        $now = time();
        [$where, $wparams] = $viewer->where();
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
