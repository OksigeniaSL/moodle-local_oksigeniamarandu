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

use local_oksigeniamarandu\local\forward;

/**
 * What the other address of the webhook did not take at once, tried again: Moodle runs it later, waiting longer each time.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forward_webhook extends \core\task\adhoc_task {
    /**
     * Passes the body on; if the address still does not take it, the task fails and Moodle tries it again.
     *
     * @throws \moodle_exception If the address does not take it.
     */
    public function execute() {
        $url = forward::url();
        if ($url === '') {
            // The site stopped passing things on meanwhile.
            return;
        }
        $data = $this->get_custom_data();
        if (!(new forward())->send($url, (string)($data->body ?? ''), (string)($data->signature ?? ''))) {
            throw new \moodle_exception('error:forward', 'local_oksigeniamarandu', '', $url);
        }
    }
}
