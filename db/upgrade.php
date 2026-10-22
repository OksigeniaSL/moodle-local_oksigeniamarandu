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
 * Upgrade steps for local_oksigeniamarandu.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrades the plugin from an older version.
 *
 * @param int $oldversion The version installed before.
 * @return bool
 */
function xmldb_local_oksigeniamarandu_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026102201) {
        // 0.2.0: contacts, the links that tell whom they stand for, and the record of consent.
        $file = __DIR__ . '/install.xml';
        foreach (['local_oksigeniamarandu_contact', 'local_oksigeniamarandu_link', 'local_oksigeniamarandu_consent'] as $table) {
            if (!$dbman->table_exists($table)) {
                $dbman->install_one_table_from_xmldb_file($file, $table);
            }
        }
        if (!get_config('local_oksigeniamarandu', 'verifytoken')) {
            set_config('verifytoken', random_string(32), 'local_oksigeniamarandu');
        }
        upgrade_plugin_savepoint(true, 2026102201, 'local', 'oksigeniamarandu');
    }

    return true;
}
