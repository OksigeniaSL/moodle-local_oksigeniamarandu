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
use local_oksigeniamarandu\local\spend;

/**
 * What WhatsApp costs this month, read again from Meta every hour, with its word at 80 % and 100 % of the budget.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class spend_refresh extends \core\task\scheduled_task {
    /**
     * The name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:spendrefresh', 'local_oksigeniamarandu');
    }

    /**
     * Reads the month; if Meta does not answer, the last reading stays.
     */
    public function execute() {
        $wabaid = (string)get_config('local_oksigeniamarandu', 'wabaid');
        if ($wabaid === '' || !cloud_api::is_configured()) {
            return;
        }
        try {
            $month = spend::refresh($this->api(), $wabaid);
            mtrace('Marandu: WhatsApp this month, ' . $month['count'] . ' messages, ' . $month['cost'] . ' ' . $month['currency']);
        } catch (api_exception $e) {
            mtrace('Marandu: what WhatsApp costs was not read: ' . $e->getMessage());
        }
    }

    /**
     * The client of the API. Kept apart so that the tests can answer for Meta.
     *
     * @return cloud_api
     */
    protected function api(): cloud_api {
        return cloud_api::from_config();
    }
}
