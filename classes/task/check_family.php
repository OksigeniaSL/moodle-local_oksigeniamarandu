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

namespace local_oksigeniamarandu\task;

use local_oksigeniamarandu\local\family;

/**
 * Checks the numbers that stand for adults of the family records, besides the events that already do it: a phone that
 * changed or an adult who left, so that no notice about a student reaches a number that is not their family's any more.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_family extends \core\task\scheduled_task {
    /**
     * Name of the task, for the administration.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:checkfamily', 'local_oksigeniamarandu');
    }

    /**
     * Drops the links of the adults that no longer have that number.
     */
    public function execute() {
        $dropped = family::check();
        if ($dropped) {
            mtrace("Links of the family records dropped: $dropped");
        }
    }
}
