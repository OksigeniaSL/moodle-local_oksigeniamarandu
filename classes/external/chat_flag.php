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

namespace local_oksigeniamarandu\external;

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * A small choice of a person on the page of the conversations, kept for the next time: the tour they already saw, the
 * sound of the notices, and whether they want notices on this computer.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_flag extends chat_base {
    /** The choices there are, with their value by default. */
    public const FLAGS = ['tour' => '', 'sound' => 'on', 'desktop' => ''];

    /**
     * The parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'name' => new external_value(PARAM_ALPHA, 'tour, sound or desktop'),
            'value' => new external_value(PARAM_ALPHANUMEXT, 'Its value: the tour seen, on or off, yes or no'),
        ]);
    }

    /**
     * Keeps it.
     *
     * @param string $name The choice.
     * @param string $value Its value.
     * @return array
     */
    public static function execute(string $name, string $value): array {
        $params = self::validate_parameters(self::execute_parameters(), ['name' => $name, 'value' => $value]);
        $viewer = self::viewer();
        if (!array_key_exists($params['name'], self::FLAGS)) {
            throw new \invalid_parameter_exception('Unknown choice');
        }
        set_user_preference('local_oksigeniamarandu_chat' . $params['name'], substr($params['value'], 0, 30), $viewer->userid);
        return ['ok' => true];
    }

    /**
     * The choices of the person who looks.
     *
     * @return array By name.
     */
    public static function current(): array {
        $flags = [];
        foreach (self::FLAGS as $name => $default) {
            $flags[$name] = (string)get_user_preferences('local_oksigeniamarandu_chat' . $name, $default);
        }
        return $flags;
    }

    /**
     * What it returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['ok' => new external_value(PARAM_BOOL, 'Whether it was kept')]);
    }
}
