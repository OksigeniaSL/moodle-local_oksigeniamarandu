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

use local_oksigeniamarandu\local\proposals;

/**
 * A proposal of improvement that just came: its line and its tool by the text generation of the site, if there is one,
 * and the notice to the people who receive them.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class proposal_received extends \core\task\adhoc_task {
    /**
     * Puts it in a line and tells.
     */
    public function execute() {
        global $DB;
        $id = (int)($this->get_custom_data()->id ?? 0);
        $record = $DB->get_record(proposals::TABLE, ['id' => $id]);
        if (!$record || $record->timenotified) {
            return;
        }
        if (proposals::summarise($record)) {
            $record = $DB->get_record(proposals::TABLE, ['id' => $id]);
        }
        mtrace('Proposal ' . $id . ': told to ' . proposals::notify($record) . ' chats');
    }
}
