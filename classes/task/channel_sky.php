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

use local_oksigeniamarandu\local\sky;

/**
 * Prepares the sky over the place of the site for the channel of Telegram: the station, the full moon and the meteor
 * showers.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class channel_sky extends \core\task\scheduled_task {
    /**
     * Name of the task, for the administration.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:channelsky', 'local_oksigeniamarandu');
    }

    /**
     * Prepares them; without a channel or the place of the site, nothing.
     */
    public function execute() {
        $counts = sky::prepare();
        if (array_sum($counts)) {
            mtrace('Marandu, the sky of the channel: ' . json_encode($counts));
        }
    }
}
