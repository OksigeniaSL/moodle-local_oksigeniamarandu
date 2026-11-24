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
    global $CFG, $DB;
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

    if ($oldversion < 2026110606) {
        // 0.13.6. The daily check of the family records only looks at the links that stand because of the number; those
        // that joined with the adult's own code stay. The former were created already claimed.
        $table = new xmldb_table('local_oksigeniamarandu_link');
        $field = new xmldb_field('bynumber', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'code');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $DB->set_field_select(
            'local_oksigeniamarandu_link',
            'bynumber',
            1,
            'component = ? AND timeclaimed = timecreated',
            ['local_oksigeniafamily']
        );
        upgrade_plugin_savepoint(true, 2026110606, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026110700) {
        // 0.14.0: a notice can have a card on Telegram.
        $table = new xmldb_table('local_oksigeniamarandu_queue');
        $field = new xmldb_field('rich', XMLDB_TYPE_TEXT, null, null, null, null, null, 'params');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026110700, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026110800) {
        // 0.15.0: questions with buttons, and their answers.
        $table = new xmldb_table('local_oksigeniamarandu_queue');
        $fields = [
            new xmldb_field('ref', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'rich'),
            new xmldb_field('itemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'ref'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $table = new xmldb_table('local_oksigeniamarandu_answer');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('queueid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('contactid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('component', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('notice', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('ref', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('choice', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('channel', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('messageid', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null);
        $table->add_field('contextid', XMLDB_TYPE_CHAR, '128', null, null, null, null);
        $table->add_field('timeanswered', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('queueid', XMLDB_KEY_FOREIGN, ['queueid'], 'local_oksigeniamarandu_queue', ['id']);
        $table->add_key('contactid', XMLDB_KEY_FOREIGN, ['contactid'], 'local_oksigeniamarandu_contact', ['id']);
        $table->add_index('messageid', XMLDB_INDEX_NOTUNIQUE, ['messageid']);
        $table->add_index('componentref', XMLDB_INDEX_NOTUNIQUE, ['component', 'ref']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026110800, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026111000) {
        // 0.17.0: the kind of item of a link, such as the kitchen beside the diners of the same plugin.
        $table = new xmldb_table('local_oksigeniamarandu_link');
        $field = new xmldb_field('area', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null, 'component');
        $old = new xmldb_index('componentitem', XMLDB_INDEX_NOTUNIQUE, ['component', 'itemid']);
        if ($dbman->index_exists($table, $old)) {
            $dbman->drop_index($table, $old);
        }
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('componentareaitem', XMLDB_INDEX_NOTUNIQUE, ['component', 'area', 'itemid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        $table = new xmldb_table('local_oksigeniamarandu_queue');
        $field = new xmldb_field('area', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null, 'itemid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026111000, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026111100) {
        // 0.18.0: what goes to the channel of Telegram of the site, and how many people it had each day.
        $table = new xmldb_table('local_oksigeniamarandu_post');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('component', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('kind', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sourcekey', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('content', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('urgent', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'review');
        $table->add_field('token', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timeplanned', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timeexpires', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('reviews', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timereview', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('decidedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timedecided', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('messageid', XMLDB_TYPE_CHAR, '40', null, null, null, null);
        $table->add_field('attempts', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('error', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('clicks', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timepublished', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('decidedby', XMLDB_KEY_FOREIGN, ['decidedby'], 'user', ['id']);
        $table->add_index('token', XMLDB_INDEX_UNIQUE, ['token']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('source', XMLDB_INDEX_NOTUNIQUE, ['component', 'kind', 'sourcekey']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        $table = new xmldb_table('local_oksigeniamarandu_chstat');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('timeday', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('members', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('timeday', XMLDB_INDEX_UNIQUE, ['timeday']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026111100, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026111900) {
        // The language a plugin asks for a notice in.
        $table = new xmldb_table('local_oksigeniamarandu_queue');
        $field = new xmldb_field('lang', XMLDB_TYPE_CHAR, '30', null, null, null, null, 'area');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026111900, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026112200) {
        // The reactions to the posts of the channel; the bot asks Telegram for them from now on.
        $table = new xmldb_table('local_oksigeniamarandu_post');
        $field = new xmldb_field('reactions', XMLDB_TYPE_TEXT, null, null, null, null, null, 'clicks');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        \core\task\manager::queue_adhoc_task(new \local_oksigeniamarandu\task\telegram_refresh());
        upgrade_plugin_savepoint(true, 2026112200, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026112302) {
        // The animations of the reviews still waiting, which reached Telegram as files to download.
        \core\task\manager::queue_adhoc_task(new \local_oksigeniamarandu\task\refresh_reviews());
        upgrade_plugin_savepoint(true, 2026112302, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026112600) {
        // A template Meta moved to another category cannot change it: a new text goes as a new template, beside it.
        $table = new xmldb_table('local_oksigeniamarandu_tpl');
        $fields = [
            new xmldb_field('nextname', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'timemodified'),
            new xmldb_field('nextmetaid', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'nextname'),
            new xmldb_field('nextstatus', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'nextmetaid'),
            new xmldb_field('nextcategory', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'nextstatus'),
            new xmldb_field('nextreason', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'nextcategory'),
            new xmldb_field('nextbody', XMLDB_TYPE_TEXT, null, null, null, null, null, 'nextreason'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026112600, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026113000) {
        // The conversations of the team, over Chatwoot: their index, what each person read and who wrote what.
        $xml = __DIR__ . '/install.xml';
        foreach (['local_oksigeniamarandu_conv', 'local_oksigeniamarandu_convseen', 'local_oksigeniamarandu_convmsg'] as $name) {
            if (!$dbman->table_exists($name)) {
                $dbman->install_one_table_from_xmldb_file($xml, $name);
            }
        }
        upgrade_plugin_savepoint(true, 2026113000, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026113100) {
        // The chats of a number that comes from the app of WhatsApp Business, kept for the shared inbox.
        if (!$dbman->table_exists('local_oksigeniamarandu_hist')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_oksigeniamarandu_hist');
        }
        upgrade_plugin_savepoint(true, 2026113100, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026120100) {
        // The last message the index looked at, to tell the people of the team of the new ones.
        $table = new xmldb_table('local_oksigeniamarandu_conv');
        $field = new xmldb_field('lastmsgid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'previewat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026120100, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026120600) {
        // The day a person asked to be reminded of a conversation.
        if (!$dbman->table_exists('local_oksigeniamarandu_convrem')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_oksigeniamarandu_convrem');
        }
        upgrade_plugin_savepoint(true, 2026120600, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026120800) {
        // The board of the team: its notices and who saw each.
        foreach (['local_oksigeniamarandu_board', 'local_oksigeniamarandu_boardto'] as $name) {
            if (!$dbman->table_exists($name)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', $name);
            }
        }
        upgrade_plugin_savepoint(true, 2026120800, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026120900) {
        // What a number said no to, short of everything (the notices of one plugin).
        if (!$dbman->table_exists('local_oksigeniamarandu_optout')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_oksigeniamarandu_optout');
        }
        upgrade_plugin_savepoint(true, 2026120900, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026121000) {
        // The menu of the bot speaks to the family as the confirmations do: Telegram gets it again.
        if (\local_oksigeniamarandu\local\telegram_api::is_configured()) {
            \core\task\manager::queue_adhoc_task(new \local_oksigeniamarandu\task\telegram_refresh());
        }
        upgrade_plugin_savepoint(true, 2026121000, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026121100) {
        // The sends to many, with their cost and their approval.
        foreach (['local_oksigeniamarandu_bulk', 'local_oksigeniamarandu_bulkitem'] as $name) {
            if (!$dbman->table_exists($name)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', $name);
            }
        }
        upgrade_plugin_savepoint(true, 2026121100, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026121200) {
        // Several marks of who a contact is, with the one whose face it has and the lines of each.
        $table = new xmldb_table('local_oksigeniamarandu_conv');
        $field = new xmldb_field('kind', XMLDB_TYPE_CHAR, '60', null, XMLDB_NOTNULL, null, null, 'name');
        $dbman->change_field_precision($table, $field);
        foreach ([['kindface', '20', 'kind'], ['kindnote', '255', 'kindface']] as [$name, $length, $after]) {
            $field = new xmldb_field($name, XMLDB_TYPE_CHAR, $length, null, null, null, null, $after);
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $field = new xmldb_field('kindnotes', XMLDB_TYPE_TEXT, null, null, null, null, null, 'kindnote');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026121200, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026121300) {
        // When a question stops taking answers. Those already out count as closed, so that their messages are not
        // rewritten all at once.
        $table = new xmldb_table('local_oksigeniamarandu_queue');
        $field = new xmldb_field('timeclosed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timeclicked');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $DB->set_field_select('local_oksigeniamarandu_queue', 'timeclosed', time(), "ref IS NOT NULL
            AND status IN ('sent', 'delivered', 'read')");
        upgrade_plugin_savepoint(true, 2026121300, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026121301) {
        // The update to 0.51.0 closed also the questions that still waited in the queue: they would go out with buttons
        // that answer nothing. A question waiting is never closed (closing one that waits makes it expired).
        $DB->set_field_select('local_oksigeniamarandu_queue', 'timeclosed', 0, "status = 'queued' AND timeclosed > 0");
        // What a site with a year of notices and thousands of conversations looks for every minute.
        $indexes = [
            'local_oksigeniamarandu_queue' => [
                ['timesent', ['timesent']],
                ['timeclosed-timeexpires', ['timeclosed', 'timeexpires']],
            ],
            'local_oksigeniamarandu_conv' => [['phone', ['phone']], ['status-lastactivity', ['status', 'lastactivity']]],
        ];
        foreach ($indexes as $name => $list) {
            $table = new xmldb_table($name);
            foreach ($list as [$indexname, $fields]) {
                $index = new xmldb_index($indexname, XMLDB_INDEX_NOTUNIQUE, $fields);
                if (!$dbman->index_exists($table, $index)) {
                    $dbman->add_index($table, $index);
                }
            }
        }
        // Codes that can only be claimed for a while, such as the one that joins a chat to approve a login.
        $table = new xmldb_table('local_oksigeniamarandu_link');
        $field = new xmldb_field('timeexpires', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timecreated');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026121301, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026121500) {
        // The messages of the team: between two people and of each department.
        $tables = ['local_oksigeniamarandu_teamconv', 'local_oksigeniamarandu_teammsg', 'local_oksigeniamarandu_teamread'];
        foreach ($tables as $name) {
            if (!$dbman->table_exists($name)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', $name);
            }
        }
        upgrade_plugin_savepoint(true, 2026121500, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026122200) {
        // What Meta counts as marketing stops going by WhatsApp: the rule applies once to what is in marketing now. Those
        // on by WhatsApp go off, and those already taken off it by hand (off by WhatsApp, on by Telegram) are written down
        // too, so that all of them go again by themselves when a text of theirs is approved as utility. An exception is
        // switched on again by hand (cli/setup.php --notices=<component/notice> --channels=whatsapp).
        $split = function ($value): array {
            return array_values(array_filter(array_map('trim', explode(',', (string)$value))));
        };
        $whatsapp = $split(get_config('local_oksigeniamarandu', 'enablednotices'));
        $telegram = $split(get_config('local_oksigeniamarandu', 'telegramnotices'));
        $off = [];
        $records = $DB->get_records('local_oksigeniamarandu_tpl', ['category' => 'marketing', 'status' => 'approved',
            'lang' => $CFG->lang]);
        foreach ($records as $record) {
            $key = $record->component . '/' . $record->notice;
            if (in_array($key, $whatsapp, true) || in_array($key, $telegram, true)) {
                $off[$key] = time();
            }
        }
        if ($off && get_config('local_oksigeniamarandu', 'marketingoffnotices') === false) {
            set_config('enablednotices', implode(',', array_diff($whatsapp, array_keys($off))), 'local_oksigeniamarandu');
            set_config('marketingoffnotices', json_encode($off), 'local_oksigeniamarandu');
        }
        upgrade_plugin_savepoint(true, 2026122200, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026122300) {
        // A WhatsApp that did not arrive, in sight in the lists (when and why), and the calls of the team written down.
        $table = new xmldb_table('local_oksigeniamarandu_conv');
        $fields = [
            new xmldb_field('failedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'lastmsgid'),
            new xmldb_field('failerror', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'failedat'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $table = new xmldb_table('local_oksigeniamarandu_convmsg');
        $field = new xmldb_field('callresult', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'timecreated');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026122300, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026122500) {
        // The channel of WhatsApp: the posts of the channel of Telegram that wait for it, published there by a person.
        if (!$dbman->table_exists('local_oksigeniamarandu_wapost')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_oksigeniamarandu_wapost');
        }
        upgrade_plugin_savepoint(true, 2026122500, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026122900) {
        // Proposals of improvement, with a picture of the screen.
        if (!$dbman->table_exists('local_oksigeniamarandu_proposal')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_oksigeniamarandu_proposal');
        }
        // The exceptions of the rule of marketing: what is on for WhatsApp while Meta counts it as marketing was switched on
        // by hand (the rule had switched it off), and is kept so from now on.
        $off = \local_oksigeniamarandu\local\notices::marketing_off();
        foreach (\local_oksigeniamarandu\local\notices::enabled('whatsapp') as $key) {
            [$component, $notice] = explode('/', $key, 2) + [1 => ''];
            if (!isset($off[$key]) && \local_oksigeniamarandu\local\templates::is_marketing($component, $notice)) {
                \local_oksigeniamarandu\local\notices::keep($key, true);
            }
        }
        upgrade_plugin_savepoint(true, 2026122900, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026122901) {
        // The approved message each message sent from the conversations was: Chatwoot does not keep it, and the page needs
        // it to know that the family has to answer before the team can write freely.
        $table = new xmldb_table('local_oksigeniamarandu_convmsg');
        $field = new xmldb_field('template', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'callresult');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026122901, 'local', 'oksigeniamarandu');
    }

    if ($oldversion < 2026122910) {
        // The messages of the team that go out later, at the hour a person chose.
        if (!$dbman->table_exists('local_oksigeniamarandu_convsched')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'local_oksigeniamarandu_convsched');
        }
        upgrade_plugin_savepoint(true, 2026122910, 'local', 'oksigeniamarandu');
    }

    // Every setting with its value, also those a new version brings: Moodle does not stop the update on them.
    \local_oksigeniamarandu\local\sitesettings::settle();

    return true;
}
