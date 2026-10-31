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

    if ($oldversion < 2026102400) {
        // 0.4.0: the templates of the notices and the queue that sends them.
        $file = __DIR__ . '/install.xml';
        foreach (['local_oksigeniamarandu_tpl', 'local_oksigeniamarandu_queue'] as $table) {
            if (!$dbman->table_exists($table)) {
                $dbman->install_one_table_from_xmldb_file($file, $table);
            }
        }
        upgrade_plugin_savepoint(true, 2026102400, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026102402) {
        // 0.5.0: the queue tries again after errors that pass.
        $table = new xmldb_table('local_oksigeniamarandu_queue');
        $field = new xmldb_field('attempts', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0', 'error');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('timeretry', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timeexpires');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // The version of the API that was the default moves to the current one; a version the site chose stays.
        if (get_config('local_oksigeniamarandu', 'graphversion') === 'v23.0') {
            set_config('graphversion', \local_oksigeniamarandu\local\cloud_api::DEFAULT_GRAPH_VERSION, 'local_oksigeniamarandu');
        }
        upgrade_plugin_savepoint(true, 2026102402, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026102403) {
        // 0.6.0: the error code of Meta, for the panel to tell why a notice failed.
        $table = new xmldb_table('local_oksigeniamarandu_queue');
        $field = new xmldb_field('errorcode', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'error');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026102403, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026110100) {
        // 0.8.0: Telegram. A contact has its channel, and its address may be a chat of Telegram (tg: and the chat).
        $table = new xmldb_table('local_oksigeniamarandu_contact');
        $index = new xmldb_index('phone', XMLDB_INDEX_UNIQUE, ['phone']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        $field = new xmldb_field('phone', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null, 'id');
        $dbman->change_field_precision($table, $field);
        $dbman->add_index($table, $index);
        $field = new xmldb_field('channel', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'whatsapp', 'phone');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026110100, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026110200) {
        // 0.9.0: the bot is known by its number, so that a token renewed with /revoke is not taken for another bot.
        $token = (string)get_config('local_oksigeniamarandu', 'telegramtoken');
        if ($token !== '' && !get_config('local_oksigeniamarandu', 'telegrambotid')) {
            $id = \local_oksigeniamarandu\local\telegram::bot_id(\core\encryption::decrypt($token));
            set_config('telegrambotid', $id, 'local_oksigeniamarandu');
        }
        upgrade_plugin_savepoint(true, 2026110200, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026110300) {
        // 0.10.0: the menu of the bot of Telegram. A chat can ask for notices without sound.
        $table = new xmldb_table('local_oksigeniamarandu_contact');
        $field = new xmldb_field('silent', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'lang');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // A bot already connected learns its menu and its buttons, in a task: Telegram is not called from the upgrade.
        if (\local_oksigeniamarandu\local\telegram_api::is_configured()) {
            \core\task\manager::queue_adhoc_task(new \local_oksigeniamarandu\task\telegram_refresh());
        }
        upgrade_plugin_savepoint(true, 2026110300, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026110600) {
        // 0.11.0 and 0.13.0. Each channel has its own notices switched on: Telegram starts with those that were on for
        // all (0.11.0 shipped without this step). And the quiet hours and the limit by family hold for every channel: a
        // site that never set them (only the wizard of WhatsApp did) gets the ones the wizard proposes, no notice at
        // night or at the weekend and three a day at most.
        $on = get_config('local_oksigeniamarandu', 'enablednotices');
        if ($on !== false && get_config('local_oksigeniamarandu', 'telegramnotices') === false) {
            set_config('telegramnotices', $on, 'local_oksigeniamarandu');
        }
        \local_oksigeniamarandu\local\limits::defaults();
        upgrade_plugin_savepoint(true, 2026110600, 'local', 'oksigeniamarandu');
    }

    return true;
}
