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
use local_oksigeniamarandu\local\channel;
use local_oksigeniamarandu\local\cloud_api;
use local_oksigeniamarandu\local\queue;
use local_oksigeniamarandu\local\telegram_api;

/**
 * Sends the notices of the queue that can go out now, by WhatsApp and by Telegram, and what goes to the channel.
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
        $whatsapp = $telegram = null;
        try {
            $whatsapp = cloud_api::is_configured() ? cloud_api::from_config() : null;
        } catch (api_exception $e) {
            mtrace('Marandu, WhatsApp: ' . $e->getMessage());
        }
        try {
            $telegram = telegram_api::is_configured() ? telegram_api::from_config() : null;
        } catch (api_exception $e) {
            mtrace('Marandu, Telegram: ' . $e->getMessage());
        }
        if (!$whatsapp && !$telegram) {
            return;
        }
        $counts = queue::process($whatsapp, null, $telegram);
        if (array_sum($counts)) {
            mtrace('Marandu: ' . json_encode($counts));
        }
        // The channel of Telegram: its reviews, its posts and what was taken back.
        if ($telegram && channel::current()) {
            $counts = channel::process($telegram);
            if (array_sum($counts)) {
                mtrace('Marandu, the channel: ' . json_encode($counts));
            }
        }
    }
}
