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

namespace local_oksigeniamarandu\output;

/**
 * What the Moodle app asks the plugin for.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mobile {
    /**
     * At the start of the app: the handler of the links of the notices of the conversations, and no entry in the menu
     * (the app runs the script before it looks at disabled).
     *
     * @param array $args What the app sends.
     * @return array
     */
    public static function mobile_init(array $args): array {
        global $CFG;
        return [
            'templates' => [],
            'javascript' => (string)file_get_contents($CFG->dirroot . '/local/oksigeniamarandu/app/mobile-links.js'),
            'otherdata' => '',
            'disabled' => true,
        ];
    }
}
