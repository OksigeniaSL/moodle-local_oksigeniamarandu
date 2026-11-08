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
use local_oksigeniamarandu\local\templates;

/**
 * Sends to Meta the templates of the notices a plugin brings in a new version, and the new texts of those that changed,
 * without going back to the wizard. Only on a site that has WhatsApp and already sent its texts once from the wizard:
 * until then, they go from there.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_templates extends \core\task\scheduled_task {
    /**
     * Name of the task, for the administration.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:synctemplates', 'local_oksigeniamarandu');
    }

    /**
     * Sends them.
     */
    public function execute() {
        global $DB;
        $wabaid = (string)get_config('local_oksigeniamarandu', 'wabaid');
        if ($wabaid === '' || !cloud_api::is_configured() || !$DB->record_exists(templates::TABLE, [])) {
            return;
        }
        try {
            $counts = templates::sync(cloud_api::from_config(), $wabaid);
        } catch (api_exception $e) {
            mtrace('Marandu, the templates: ' . $e->getMessage());
            return;
        }
        if ($counts['created'] || $counts['edited'] || $counts['failed'] || $counts['wrong']) {
            mtrace('Marandu, the templates: ' . json_encode($counts));
        }
    }
}
