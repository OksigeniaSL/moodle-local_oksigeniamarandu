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

use local_oksigeniamarandu\local\proposals;

/**
 * The hourly round of the suggestions: what waits to go to Oksigenia, what Oksigenia has, and the summary of the ideas
 * of the day.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class proposal_round extends \core\task\scheduled_task {
    /**
     * Its name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:proposalround', 'local_oksigeniamarandu');
    }

    /**
     * Does it.
     */
    public function execute() {
        $counts = proposals::round();
        mtrace('Suggestions: ' . $counts['sent'] . ' to Oksigenia, ' . $counts['asked'] . ' asked, ' . $counts['ideas']
            . ' ideas in the summary');
    }
}
