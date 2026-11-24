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

use local_oksigeniamarandu\local\chat\chatwoot;
use local_oksigeniamarandu\local\chat\mirror;

/**
 * Brings the index of the conversations of the team up to date with Chatwoot, every minute.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_sync extends \core\task\scheduled_task {
    /**
     * Its name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:chatsync', 'local_oksigeniamarandu');
    }

    /**
     * Does it, if the site has a Chatwoot.
     */
    public function execute() {
        if (!chatwoot::is_configured()) {
            return;
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_oksigeniamarandu_chat')->get_lock('sync', 30);
        if (!$lock) {
            return;
        }
        try {
            try {
                $changed = mirror::sync();
                mtrace('Conversations changed: ' . $changed);
            } finally {
                // The conversations whose day came, back to whoever asked: also when the list of Chatwoot failed,
                // since each one is asked for by itself.
                $sent = \local_oksigeniamarandu\local\chat\scheduled::due();
                if ($sent) {
                    mtrace('Messages sent at their hour: ' . $sent);
                }
                $back = \local_oksigeniamarandu\local\chat\reminders::due();
                if ($back) {
                    mtrace('Conversations back on their day: ' . $back);
                }
                // Whoever joined the account gets the role of the chat; whoever left it, loses the one given here.
                $access = \local_oksigeniamarandu\local\chat\access::sync();
                if ($access['given'] || $access['taken']) {
                    mtrace('Access to the chat given: ' . $access['given'] . '; taken: ' . $access['taken']);
                }
            }
        } finally {
            $lock->release();
        }
    }
}
