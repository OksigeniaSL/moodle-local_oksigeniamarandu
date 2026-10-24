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
 * Privacy provider.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_oksigeniamarandu\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_oksigeniamarandu\local\consent;

/**
 * The numbers, their consent and whom they stand for. A number is a user's when a link ties it to that user; numbers of
 * families without a Moodle account belong to the plugin that asked for them, which answers for them.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * What is stored and what goes to WhatsApp.
     *
     * @param collection $collection The collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(consent::CONTACTS, [
            'phone' => 'privacy:metadata:contact:phone',
            'name' => 'privacy:metadata:contact:name',
            'status' => 'privacy:metadata:contact:status',
            'consenttime' => 'privacy:metadata:contact:consenttime',
            'optouttime' => 'privacy:metadata:contact:optouttime',
            'lastinbound' => 'privacy:metadata:contact:lastinbound',
        ], 'privacy:metadata:contact');
        $collection->add_database_table(consent::LINKS, [
            'userid' => 'privacy:metadata:link:userid',
            'contactid' => 'privacy:metadata:link:contactid',
            'timeclaimed' => 'privacy:metadata:link:timeclaimed',
        ], 'privacy:metadata:link');
        $collection->add_database_table(consent::LOG, [
            'action' => 'privacy:metadata:consent:action',
            'source' => 'privacy:metadata:consent:source',
            'timecreated' => 'privacy:metadata:consent:timecreated',
        ], 'privacy:metadata:consent');
        $collection->add_database_table('local_oksigeniamarandu_queue', [
            'contactid' => 'privacy:metadata:queue:contactid',
            'params' => 'privacy:metadata:queue:params',
            'status' => 'privacy:metadata:queue:status',
            'timesent' => 'privacy:metadata:queue:timesent',
            'timeclicked' => 'privacy:metadata:queue:timeclicked',
        ], 'privacy:metadata:queue');
        $collection->add_external_location_link('whatsapp', [
            'phone' => 'privacy:metadata:whatsapp:phone',
            'message' => 'privacy:metadata:whatsapp:message',
        ], 'privacy:metadata:whatsapp');
        return $collection;
    }

    /**
     * The system context, if a link ties a number to the user.
     *
     * @param int $userid The user.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();
        if ($DB->record_exists(consent::LINKS, ['userid' => $userid])) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * The users with a linked number, in the system context.
     *
     * @param userlist $userlist The list to fill.
     */
    public static function get_users_in_context(userlist $userlist) {
        if ($userlist->get_context()->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {' . consent::LINKS . '} WHERE userid IS NOT NULL', []);
    }

    /**
     * Exports the numbers of the user, with their consent.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $context = \context_system::instance();
        if (!in_array($context->id, $contextlist->get_contextids())) {
            return;
        }
        $numbers = [];
        foreach ($DB->get_records(consent::LINKS, ['userid' => $contextlist->get_user()->id]) as $link) {
            $contact = $link->contactid ? $DB->get_record(consent::CONTACTS, ['id' => $link->contactid]) : null;
            if (!$contact) {
                continue;
            }
            $notices = [];
            foreach ($DB->get_records('local_oksigeniamarandu_queue', ['contactid' => $contact->id], 'id') as $row) {
                $notices[] = (object)[
                    'notice' => $row->component . '/' . $row->notice,
                    'values' => json_decode($row->params, true),
                    'status' => $row->status,
                    'sent' => $row->timesent ? transform::datetime($row->timesent) : null,
                ];
            }
            $numbers[] = (object)[
                'notices' => $notices,
                'phone' => '+' . $contact->phone,
                'status' => $contact->status,
                'consenttime' => $contact->consenttime ? transform::datetime($contact->consenttime) : null,
                'optouttime' => $contact->optouttime ? transform::datetime($contact->optouttime) : null,
                'linked' => transform::datetime($link->timeclaimed ?: $link->timecreated),
            ];
        }
        if ($numbers) {
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_oksigeniamarandu')],
                (object)['numbers' => $numbers]
            );
        }
    }

    /**
     * Deletes the numbers of every user with a link, in the system context.
     *
     * @param \context $context The context.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        $userids = $DB->get_fieldset_select(consent::LINKS, 'DISTINCT userid', 'userid IS NOT NULL');
        foreach ($userids as $userid) {
            self::delete_user((int)$userid);
        }
    }

    /**
     * Deletes the numbers of one user.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        if (in_array(\context_system::instance()->id, $contextlist->get_contextids())) {
            self::delete_user($contextlist->get_user()->id);
        }
    }

    /**
     * Deletes the numbers of several users.
     *
     * @param approved_userlist $userlist The approved users.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        if ($userlist->get_context()->contextlevel != CONTEXT_SYSTEM) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            self::delete_user($userid);
        }
    }

    /**
     * Deletes the links of a user and the numbers no other link uses, with their record of consent.
     *
     * @param int $userid The user.
     */
    private static function delete_user(int $userid): void {
        global $DB;
        $links = $DB->get_records(consent::LINKS, ['userid' => $userid]);
        $DB->delete_records(consent::LINKS, ['userid' => $userid]);
        foreach ($links as $link) {
            if ($link->contactid && !$DB->record_exists(consent::LINKS, ['contactid' => $link->contactid])) {
                $DB->delete_records(consent::LOG, ['contactid' => $link->contactid]);
                $DB->delete_records('local_oksigeniamarandu_queue', ['contactid' => $link->contactid]);
                $DB->delete_records(consent::CONTACTS, ['id' => $link->contactid]);
            }
        }
    }
}
