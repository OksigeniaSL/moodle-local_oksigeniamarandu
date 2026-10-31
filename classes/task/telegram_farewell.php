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
use local_oksigeniamarandu\local\consent;
use local_oksigeniamarandu\local\telegram;
use local_oksigeniamarandu\local\telegram_api;

/**
 * When the site changes to another bot: the old one tells the chats that had joined it where the notices come now,
 * with a button to open the new bot. Those who open it and start it get the notices again.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class telegram_farewell extends \core\task\adhoc_task {
    /**
     * Queues the message of the old bot.
     *
     * @param string $oldtoken The token of the old bot; kept encrypted until it is used.
     * @param string $newbot The username of the new bot.
     * @param int[] $contactids The chats that had joined.
     */
    public static function queue(string $oldtoken, string $newbot, array $contactids): void {
        $task = new static();
        $task->set_custom_data([
            'token' => \core\encryption::encrypt($oldtoken),
            'newbot' => $newbot,
            'contacts' => array_values($contactids),
        ]);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Sends it, at the pace Telegram allows; a chat that cannot get it is left as it is.
     */
    public function execute() {
        global $DB;
        $data = $this->get_custom_data();
        if (empty($data->token) || empty($data->newbot) || empty($data->contacts)) {
            return;
        }
        $api = $this->api(\core\encryption::decrypt($data->token));
        $a = (object)[
            'site' => format_string(get_site()->fullname, true, ['context' => \context_system::instance(), 'escape' => false]),
            'bot' => '@' . $data->newbot,
        ];
        $text = get_string('telegram:farewell:text', 'local_oksigeniamarandu', $a);
        $label = get_string('telegram:farewell:button', 'local_oksigeniamarandu');
        [$insql, $params] = $DB->get_in_or_equal($data->contacts);
        foreach ($DB->get_records_select(consent::CONTACTS, "id $insql", $params, 'id', 'id, phone') as $contact) {
            try {
                $api->send_text(telegram::chat($contact->phone), $text, 'https://t.me/' . $data->newbot, $label);
            } catch (api_exception $e) {
                mtrace('Marandu: the old bot could not tell a chat: ' . $e->getMessage());
            }
            // Well under the 30 messages a second of Telegram.
            usleep(50000);
        }
    }

    /**
     * The client of the old bot. Kept apart so that the tests can answer for Telegram.
     *
     * @param string $token The token of the old bot.
     * @return telegram_api
     */
    protected function api(string $token): telegram_api {
        return new telegram_api($token);
    }
}
