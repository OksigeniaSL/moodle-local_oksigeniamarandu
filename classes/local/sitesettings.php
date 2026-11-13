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

namespace local_oksigeniamarandu\local;

/**
 * The settings of the administration, each with its value from the start: so Moodle does not stop whoever installs the
 * plugin on its page of new settings with the fields of Meta, which the wizard asks for in their moment.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sitesettings {
    /**
     * The value each setting of settings.php has until the site says otherwise.
     *
     * @return array By name.
     */
    public static function defaults(): array {
        return [
            'phonenumberid' => '',
            'wabaid' => '',
            'accesstoken' => '',
            'graphversion' => cloud_api::DEFAULT_GRAPH_VERSION,
            'displayphone' => '',
            'verifytoken' => random_string(32),
            'appsecret' => '',
            'optinkeywords' => keywords::DEFAULT_OPTIN,
            'optoutkeywords' => keywords::DEFAULT_OPTOUT,
            'usefamily' => 1,
        ];
    }

    /**
     * Gives the site the value of each setting it does not have yet, and leaves the rest as they are.
     */
    public static function settle(): void {
        foreach (self::defaults() as $name => $value) {
            if (get_config('local_oksigeniamarandu', $name) === false) {
                set_config($name, $value, 'local_oksigeniamarandu');
            }
        }
    }
}
