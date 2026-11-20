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
 * How a person wants the backgrounds of the conversations: one for each family, one each day or always the same.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_prefs extends chat_base {
    /** The name of the preference. */
    public const PREFERENCE = 'local_oksigeniamarandu_chatbackgrounds';

    /**
     * The parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'mode' => new external_value(PARAM_ALPHA, 'family, daily or fixed'),
            'fixed' => new external_value(PARAM_ALPHANUMEXT, 'The background when it is always the same', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Keeps it.
     *
     * @param string $mode How.
     * @param string $fixed Which one, if always the same.
     * @return array
     */
    public static function execute(string $mode, string $fixed = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), ['mode' => $mode, 'fixed' => $fixed]);
        $viewer = self::viewer();
        if (!in_array($params['mode'], ['family', 'daily', 'fixed'])) {
            throw new \invalid_parameter_exception('Unknown mode');
        }
        set_user_preference(
            self::PREFERENCE,
            json_encode(['mode' => $params['mode'], 'fixed' => $params['fixed']]),
            $viewer->userid
        );
        return ['ok' => true];
    }

    /**
     * The preference of the current user.
     *
     * @return array mode and fixed.
     */
    public static function current(): array {
        $value = json_decode((string)get_user_preferences(self::PREFERENCE, ''), true);
        return ['mode' => (string)($value['mode'] ?? 'family'), 'fixed' => (string)($value['fixed'] ?? '')];
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
