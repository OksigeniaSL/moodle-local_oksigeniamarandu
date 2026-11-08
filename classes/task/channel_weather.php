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

use local_oksigeniamarandu\local\weather;

/**
 * Prepares the official weather warnings of the place of the site for the channel of Telegram.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class channel_weather extends \core\task\scheduled_task {
    /**
     * Name of the task, for the administration.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:channelweather', 'local_oksigeniamarandu');
    }

    /**
     * Prepares them; without a channel or a source, nothing.
     */
    public function execute() {
        $counts = weather::prepare();
        if ($counts['posted'] || $counts['withdrawn']) {
            mtrace('Marandu, the weather warnings of the channel: ' . json_encode($counts));
        }
    }
}
