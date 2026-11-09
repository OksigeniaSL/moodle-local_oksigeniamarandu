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

use local_oksigeniamarandu\local\channel;
use local_oksigeniamarandu\local\telegram_api;

/**
 * After an update of the plugin, puts the animation in place of the file that reviewers got of the posts still to go.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class refresh_reviews extends \core\task\adhoc_task {
    /**
     * Changes the reviews; one that Telegram refuses stays as it was.
     */
    public function execute() {
        if (!telegram_api::is_configured()) {
            return;
        }
        mtrace('Reviews of the channel changed: ' . channel::refresh_reviews(telegram_api::from_config()));
    }
}
