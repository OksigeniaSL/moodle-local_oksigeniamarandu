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

namespace local_oksigeniamarandu\local\chat;

/**
 * The way into the conversations from the main navigation, for whoever is a person of the team: before, only a notice or
 * the card of «Your work» took them there.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class entry {
    /**
     * Adds «Conversations» to the main navigation of whoever attends them.
     *
     * @param \core\hook\navigation\primary_extend $hook The hook.
     */
    public static function primary(\core\hook\navigation\primary_extend $hook): void {
        global $USER;
        if (during_initial_install() || empty($USER->id) || isguestuser() || !chatwoot::is_configured()) {
            return;
        }
        try {
            $viewer = viewer::for_user();
        } catch (\moodle_exception $e) {
            // Chatwoot down: no way in today, and the page of the site goes on.
            return;
        }
        if (!$viewer || $viewer->readonly) {
            return;
        }
        $hook->get_primaryview()->add(
            get_string('chat:title', 'local_oksigeniamarandu'),
            new \moodle_url('/local/oksigeniamarandu/chat.php'),
            \navigation_node::TYPE_CUSTOM,
            null,
            'local_oksigeniamarandu_chat'
        );
    }
}
