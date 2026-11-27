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

use local_oksigeniamarandu\local\mail\mailer;
use local_oksigeniamarandu\local\mail\quota;
use local_oksigeniamarandu\local\mail\settings;

/**
 * The mails that wait, each minute: those of a send to many, those Mailjet could not take for a while and, when the
 * month is past its quota, those a person let go. Once an hour, how many mails the account of Mailjet sent this month,
 * with its warnings.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_mail extends \core\task\scheduled_task {
    /**
     * The name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:sendmail', 'local_oksigeniamarandu');
    }

    /**
     * Sends what can go, and counts the month once an hour.
     */
    public function execute() {
        $now = time();
        if (settings::keys() !== null) {
            $counted = quota::counted($now);
            if (!$counted || (int)$counted['time'] < $now - HOURSECS) {
                $sent = quota::refresh($now);
                mtrace('Marandu: Mailjet this month, ' . ($sent === null ? 'not read' : $sent . ' mails'));
            }
            $warned = quota::warn($now);
            if ($warned) {
                mtrace('Marandu: the quota of the month, warned (' . $warned . ')');
            }
        }
        $counts = mailer::process($now);
        if (array_sum($counts)) {
            mtrace('Marandu: mails ' . json_encode($counts));
        }
    }
}
