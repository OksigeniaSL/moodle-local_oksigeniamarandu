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

use local_oksigeniamarandu\local\api_exception;
use local_oksigeniamarandu\local\cloud_api;
use local_oksigeniamarandu\local\queue;

/**
 * Sends the notices of the queue that can go out now.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_queue extends \core\task\scheduled_task {
    /**
     * Name of the task, for the administration.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:sendqueue', 'local_oksigeniamarandu');
    }

    /**
     * Sends what can go out; without an account connected, nothing.
     */
    public function execute() {
        if (!cloud_api::is_configured()) {
            return;
        }
        try {
            $counts = queue::process(cloud_api::from_config());
        } catch (api_exception $e) {
            mtrace('Marandu: ' . $e->getMessage());
            return;
        }
        if (array_sum($counts)) {
            mtrace('Marandu: ' . json_encode($counts));
        }
    }
}
