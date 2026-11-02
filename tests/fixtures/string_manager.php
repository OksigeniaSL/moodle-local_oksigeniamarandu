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

/**
 * The strings of Moodle plus some of the tests, as a plugin's language pack would bring them: Moodle keeps only the keys
 * of the English pack, so a test cannot add them in a local customisation.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_oksigeniamarandu_test_string_manager extends core_string_manager_standard {
    /** @var array Strings of the tests, by component. */
    public static $extra = [];

    /**
     * The strings of a component, with those of the tests.
     *
     * @param string $component The component.
     * @param string $lang The language.
     * @param bool $disablecache Do not use caches.
     * @param bool $disablelocal Do not use local customisations.
     * @return array
     */
    public function load_component_strings($component, $lang, $disablecache = false, $disablelocal = false) {
        return (self::$extra[$component] ?? []) + parent::load_component_strings($component, $lang, $disablecache, $disablelocal);
    }

    /**
     * Uses this manager, with these strings, until use_standard().
     *
     * @param array $extra Strings by component.
     */
    public static function use_with(array $extra): void {
        global $CFG;
        self::$extra = $extra;
        $CFG->config_php_settings['customstringmanager'] = self::class;
        get_string_manager(true);
    }

    /**
     * Back to the manager of Moodle.
     */
    public static function use_standard(): void {
        global $CFG;
        self::$extra = [];
        unset($CFG->config_php_settings['customstringmanager']);
        get_string_manager(true);
    }
}
