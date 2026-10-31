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

use local_oksigeniamarandu\local\telegram;
use local_oksigeniamarandu\local\telegram_api;

/**
 * Tells Telegram again what the connected bot needs, after an update of the plugin: the kinds of update it reads (the
 * buttons of its menu, since 0.10.0) and its menu. It keeps the secret of the webhook.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class telegram_refresh extends \core\task\adhoc_task {
    /**
     * Refreshes the bot; if Telegram cannot be reached, it is tried again later, as any ad hoc task that fails.
     */
    public function execute() {
        if (!telegram_api::is_configured()) {
            return;
        }
        $api = $this->api();
        $secret = (string)get_config('local_oksigeniamarandu', 'telegramsecret');
        $api->set_webhook((new \moodle_url('/local/oksigeniamarandu/tgwebhook.php'))->out(false), $secret);
        telegram::menu($api);
    }

    /**
     * The bot of the site. Kept apart so that the tests can answer for Telegram.
     *
     * @return telegram_api
     */
    protected function api(): telegram_api {
        return telegram_api::from_config();
    }
}
