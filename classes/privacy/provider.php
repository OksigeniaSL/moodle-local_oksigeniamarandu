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
use local_oksigeniamarandu\external\chat_flag;
use local_oksigeniamarandu\external\chat_prefs;
use local_oksigeniamarandu\local\answers;
use local_oksigeniamarandu\local\chat\agenda;
use local_oksigeniamarandu\local\bulk;
use local_oksigeniamarandu\local\chat\board;
use local_oksigeniamarandu\local\chat\team;
use local_oksigeniamarandu\local\chat\message;
use local_oksigeniamarandu\local\chat\mirror;
use local_oksigeniamarandu\local\chat\reminders;
use local_oksigeniamarandu\local\chat\viewer;
use local_oksigeniamarandu\local\channel;
use local_oksigeniamarandu\local\consent;
use local_oksigeniamarandu\local\history;
use local_oksigeniamarandu\local\telegram;

/**
 * The numbers, their consent and whom they stand for. A number is a user's when a link ties it to that user; numbers of
 * families without a Moodle account belong to the plugin that asked for them, which answers for them.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
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
            'lang' => 'privacy:metadata:contact:lang',
            'silent' => 'privacy:metadata:contact:silent',
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
        $collection->add_database_table(channel::TABLE, [
            'decidedby' => 'privacy:metadata:post:decidedby',
            'timedecided' => 'privacy:metadata:post:timedecided',
        ], 'privacy:metadata:post');
        $collection->add_database_table(consent::LOG, [
            'action' => 'privacy:metadata:consent:action',
            'source' => 'privacy:metadata:consent:source',
            'timecreated' => 'privacy:metadata:consent:timecreated',
        ], 'privacy:metadata:consent');
        $collection->add_database_table(consent::OPTOUTS, [
            'contactid' => 'privacy:metadata:optout:contactid',
            'scope' => 'privacy:metadata:optout:scope',
            'source' => 'privacy:metadata:optout:source',
            'timecreated' => 'privacy:metadata:optout:timecreated',
        ], 'privacy:metadata:optout');
        $collection->add_database_table('local_oksigeniamarandu_queue', [
            'contactid' => 'privacy:metadata:queue:contactid',
            'params' => 'privacy:metadata:queue:params',
            'rich' => 'privacy:metadata:queue:rich',
            'status' => 'privacy:metadata:queue:status',
            'timesent' => 'privacy:metadata:queue:timesent',
            'timeclicked' => 'privacy:metadata:queue:timeclicked',
        ], 'privacy:metadata:queue');
        $collection->add_database_table(answers::TABLE, [
            'contactid' => 'privacy:metadata:answer:contactid',
            'choice' => 'privacy:metadata:answer:choice',
            'timeanswered' => 'privacy:metadata:answer:timeanswered',
        ], 'privacy:metadata:answer');
        $collection->add_external_location_link('whatsapp', [
            'phone' => 'privacy:metadata:whatsapp:phone',
            'message' => 'privacy:metadata:whatsapp:message',
        ], 'privacy:metadata:whatsapp');
        $collection->add_external_location_link('telegram', [
            'chat' => 'privacy:metadata:telegram:chat',
            'message' => 'privacy:metadata:telegram:message',
        ], 'privacy:metadata:telegram');
        $collection->add_external_location_link('forward', [
            'phone' => 'privacy:metadata:forward:phone',
            'message' => 'privacy:metadata:forward:message',
        ], 'privacy:metadata:forward');
        $collection->add_database_table(viewer::SEEN, [
            'userid' => 'privacy:metadata:convseen:userid',
            'convid' => 'privacy:metadata:convseen:convid',
            'timeseen' => 'privacy:metadata:convseen:timeseen',
        ], 'privacy:metadata:convseen');
        $collection->add_database_table(board::TABLE, [
            'authorid' => 'privacy:metadata:board:authorid',
            'text' => 'privacy:metadata:board:text',
            'timecreated' => 'privacy:metadata:board:timecreated',
        ], 'privacy:metadata:board');
        $collection->add_database_table(board::TO, [
            'userid' => 'privacy:metadata:boardto:userid',
            'timeseen' => 'privacy:metadata:boardto:timeseen',
        ], 'privacy:metadata:boardto');
        $collection->add_database_table(team::CONVS, [
            'usera' => 'privacy:metadata:teamconv:usera',
            'userb' => 'privacy:metadata:teamconv:userb',
        ], 'privacy:metadata:teamconv');
        $collection->add_database_table(team::MESSAGES, [
            'userid' => 'privacy:metadata:teammsg:userid',
            'text' => 'privacy:metadata:teammsg:text',
            'timecreated' => 'privacy:metadata:teammsg:timecreated',
        ], 'privacy:metadata:teammsg');
        $collection->add_database_table(team::READS, [
            'userid' => 'privacy:metadata:teamread:userid',
            'lastread' => 'privacy:metadata:teamread:lastread',
            'timeread' => 'privacy:metadata:teamread:timeread',
        ], 'privacy:metadata:teamread');
        $collection->add_database_table(bulk::TABLE, [
            'userid' => 'privacy:metadata:bulk:userid',
            'decidedby' => 'privacy:metadata:bulk:decidedby',
            'label' => 'privacy:metadata:bulk:label',
            'timecreated' => 'privacy:metadata:bulk:timecreated',
        ], 'privacy:metadata:bulk');
        $collection->add_database_table(reminders::TABLE, [
            'userid' => 'privacy:metadata:convrem:userid',
            'cwid' => 'privacy:metadata:convrem:cwid',
            'remindat' => 'privacy:metadata:convrem:remindat',
            'reason' => 'privacy:metadata:convrem:reason',
        ], 'privacy:metadata:convrem');
        $collection->add_database_table(message::TABLE, [
            'userid' => 'privacy:metadata:convmsg:userid',
            'cwmsgid' => 'privacy:metadata:convmsg:cwmsgid',
            'timecreated' => 'privacy:metadata:convmsg:timecreated',
        ], 'privacy:metadata:convmsg');
        $collection->add_database_table(mirror::TABLE, [
            'phone' => 'privacy:metadata:conv:phone',
            'name' => 'privacy:metadata:conv:name',
            'kindnote' => 'privacy:metadata:conv:kindnote',
            'preview' => 'privacy:metadata:conv:preview',
            'passedby' => 'privacy:metadata:conv:passedby',
            'passedat' => 'privacy:metadata:conv:passedat',
        ], 'privacy:metadata:conv');
        $collection->add_database_table(history::TABLE, [
            'payload' => 'privacy:metadata:hist:payload',
        ], 'privacy:metadata:hist');
        $collection->add_external_location_link('chatwoot', [
            'userid' => 'privacy:metadata:chatwoot:userid',
            'message' => 'privacy:metadata:chatwoot:message',
        ], 'privacy:metadata:chatwoot');
        $collection->add_user_preference(chat_prefs::PREFERENCE, 'privacy:metadata:preference:chatbackgrounds');
        $collection->add_user_preference(agenda::PREF, 'privacy:metadata:preference:chatfavs');
        foreach (array_keys(chat_flag::FLAGS) as $name) {
            $collection->add_user_preference('local_oksigeniamarandu_chat' . $name, 'privacy:metadata:preference:chat' . $name);
        }
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
        if (
            $DB->record_exists(consent::LINKS, ['userid' => $userid])
            || $DB->record_exists(channel::TABLE, ['decidedby' => $userid])
            || $DB->record_exists(viewer::SEEN, ['userid' => $userid])
            || $DB->record_exists(reminders::TABLE, ['userid' => $userid])
            || $DB->record_exists(board::TABLE, ['authorid' => $userid])
            || $DB->record_exists(board::TO, ['userid' => $userid])
            || $DB->record_exists(bulk::TABLE, ['userid' => $userid])
            || $DB->record_exists(bulk::TABLE, ['decidedby' => $userid])
            || $DB->record_exists(message::TABLE, ['userid' => $userid])
            || $DB->record_exists(mirror::TABLE, ['passedby' => $userid])
            || $DB->record_exists(team::MESSAGES, ['userid' => $userid])
            || $DB->record_exists(team::READS, ['userid' => $userid])
            || $DB->record_exists_select(team::CONVS, 'usera = ? OR userb = ?', [$userid, $userid])
        ) {
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
        $userlist->add_from_sql('decidedby', 'SELECT decidedby FROM {' . channel::TABLE . '} WHERE decidedby IS NOT NULL', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {' . viewer::SEEN . '}', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {' . reminders::TABLE . '}', []);
        $userlist->add_from_sql('authorid', 'SELECT authorid FROM {' . board::TABLE . '}', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {' . board::TO . '}', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {' . bulk::TABLE . '}', []);
        $userlist->add_from_sql('decidedby', 'SELECT decidedby FROM {' . bulk::TABLE . '} WHERE decidedby > 0', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {' . message::TABLE . '}', []);
        $userlist->add_from_sql('passedby', 'SELECT passedby FROM {' . mirror::TABLE . '} WHERE passedby > 0', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {' . team::MESSAGES . '}', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {' . team::READS . '}', []);
        $userlist->add_from_sql('usera', 'SELECT usera FROM {' . team::CONVS . '} WHERE usera > 0', []);
        $userlist->add_from_sql('userb', 'SELECT userb FROM {' . team::CONVS . '} WHERE userb > 0', []);
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
                    'card' => empty($row->rich) ? null : json_decode($row->rich, true),
                    'answers' => array_values(array_map(function ($answer) {
                        return ['answer' => $answer->choice, 'channel' => $answer->channel,
                            'time' => transform::datetime($answer->timeanswered)];
                    }, $DB->get_records(answers::TABLE, ['queueid' => $row->id], 'id'))),
                    'status' => $row->status,
                    'sent' => $row->timesent ? transform::datetime($row->timesent) : null,
                ];
            }
            $numbers[] = (object)[
                'notices' => $notices,
                // A chat of Telegram has no phone: its address, as Marandu keeps it.
                'phone' => telegram::is_chat($contact->phone) ? $contact->phone : '+' . $contact->phone,
                'status' => $contact->status,
                'lang' => $contact->lang,
                'silent' => transform::yesno(!empty($contact->silent)),
                'consenttime' => $contact->consenttime ? transform::datetime($contact->consenttime) : null,
                'optouttime' => $contact->optouttime ? transform::datetime($contact->optouttime) : null,
                'linked' => transform::datetime($link->timeclaimed ?: $link->timecreated),
            ];
        }
        // What the user said of the posts of the channel they reviewed.
        $reviewed = array_values(array_map(function ($post) {
            return ['post' => $post->component . '/' . $post->kind, 'decision' => $post->status,
                'time' => transform::datetime($post->timedecided)];
        }, $DB->get_records(channel::TABLE, ['decidedby' => $contextlist->get_user()->id], 'id')));
        // What the user did in the conversations of the team: what they read, wrote and passed.
        $userid = $contextlist->get_user()->id;
        $conversations = [
            'read' => array_values(array_map(function ($seen) {
                return ['conversation' => (int)$seen->convid, 'time' => transform::datetime($seen->timeseen)];
            }, $DB->get_records(viewer::SEEN, ['userid' => $userid], 'id'))),
            'written' => array_values(array_map(function ($written) {
                return ['conversation' => (int)$written->convid, 'message' => (int)$written->cwmsgid,
                    'time' => transform::datetime($written->timecreated)];
            }, $DB->get_records(message::TABLE, ['userid' => $userid], 'id'))),
            'passed' => array_values(array_map(function ($passed) {
                return ['conversation' => (int)$passed->cwid, 'time' => transform::datetime($passed->passedat)];
            }, $DB->get_records(mirror::TABLE, ['passedby' => $userid], 'id'))),
            'board' => array_values(array_map(function ($one) {
                return ['text' => $one->text, 'time' => transform::datetime($one->timecreated)];
            }, $DB->get_records(board::TABLE, ['authorid' => $userid], 'id'))),
            'boardseen' => array_values(array_map(function ($one) {
                return ['notice' => (int)$one->boardid, 'seen' => $one->timeseen ? transform::datetime($one->timeseen) : ''];
            }, $DB->get_records(board::TO, ['userid' => $userid], 'id'))),
            'reminders' => array_values(array_map(function ($one) {
                return ['conversation' => (int)$one->cwid, 'day' => transform::datetime($one->remindat), 'reason' => $one->reason];
            }, $DB->get_records(reminders::TABLE, ['userid' => $userid], 'id'))),
            'sends' => array_values(array_map(function ($one) use ($userid) {
                return ['label' => $one->label, 'status' => $one->status, 'asked' => (int)$one->userid === (int)$userid,
                    'time' => transform::datetime($one->timecreated)];
            }, $DB->get_records_select(bulk::TABLE, 'userid = ? OR decidedby = ?', [$userid, $userid], 'id'))),
            'team' => array_values(array_map(function ($one) {
                return ['conversation' => (int)$one->convid, 'kind' => $one->kind, 'text' => (string)$one->text,
                    'time' => transform::datetime($one->timecreated)];
            }, $DB->get_records_select(team::MESSAGES, "userid = ? AND kind IN ('text', 'conv')", [$userid], 'id'))),
        ];
        if ($numbers || $reviewed || array_filter($conversations)) {
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_oksigeniamarandu')],
                (object)['numbers' => $numbers, 'reviewed' => $reviewed, 'conversations' => $conversations]
            );
        }
    }

    /**
     * Exports how the user wants the backgrounds of the conversations.
     *
     * @param int $userid The user.
     */
    public static function export_user_preferences(int $userid) {
        $value = get_user_preferences(chat_prefs::PREFERENCE, null, $userid);
        if ($value !== null) {
            writer::export_user_preference(
                'local_oksigeniamarandu',
                chat_prefs::PREFERENCE,
                $value,
                get_string('privacy:metadata:preference:chatbackgrounds', 'local_oksigeniamarandu')
            );
        }
        $value = get_user_preferences(agenda::PREF, null, $userid);
        if ($value !== null) {
            writer::export_user_preference(
                'local_oksigeniamarandu',
                agenda::PREF,
                $value,
                get_string('privacy:metadata:preference:chatfavs', 'local_oksigeniamarandu')
            );
        }
        foreach (array_keys(chat_flag::FLAGS) as $name) {
            $value = get_user_preferences('local_oksigeniamarandu_chat' . $name, null, $userid);
            if ($value !== null) {
                writer::export_user_preference(
                    'local_oksigeniamarandu',
                    'local_oksigeniamarandu_chat' . $name,
                    $value,
                    get_string('privacy:metadata:preference:chat' . $name, 'local_oksigeniamarandu')
                );
            }
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
        $DB->set_field_select(channel::TABLE, 'decidedby', null, 'decidedby IS NOT NULL');
        $DB->delete_records(viewer::SEEN);
        $DB->delete_records(reminders::TABLE);
        $DB->delete_records(board::TO);
        $DB->delete_records(board::TABLE);
        $DB->delete_records(bulk::ITEMS);
        $DB->delete_records(bulk::TABLE);
        $DB->delete_records(message::TABLE);
        $DB->set_field_select(mirror::TABLE, 'passedby', 0, 'passedby > 0');
        $DB->delete_records(team::READS);
        $DB->delete_records(team::MESSAGES);
        $DB->delete_records(team::CONVS);
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
     * Deletes the links of a user and the numbers no other link uses, with their record of consent; and what the
     * conversations keep of them. Also when the account passes to another person (api::account_reassigned()).
     *
     * @param int $userid The user.
     */
    public static function delete_user(int $userid): void {
        global $DB;
        $links = $DB->get_records(consent::LINKS, ['userid' => $userid]);
        $DB->delete_records(consent::LINKS, ['userid' => $userid]);
        // The posts the user reviewed stay, without who said it.
        $DB->set_field(channel::TABLE, 'decidedby', null, ['decidedby' => $userid]);
        // In the conversations, the messages stay in Chatwoot; here goes what tells who read, wrote or passed.
        $DB->delete_records(viewer::SEEN, ['userid' => $userid]);
        $DB->delete_records(reminders::TABLE, ['userid' => $userid]);
        // The board: what they put goes, with who saw it; and whether they saw the rest.
        foreach ($DB->get_fieldset_select(board::TABLE, 'id', 'authorid = ?', [$userid]) as $boardid) {
            $DB->delete_records(board::TO, ['boardid' => $boardid]);
        }
        $DB->delete_records(board::TABLE, ['authorid' => $userid]);
        $DB->delete_records(board::TO, ['userid' => $userid]);
        // The sends to many stay, for the account of what was spent, without who asked for them or approved them.
        $DB->set_field(bulk::TABLE, 'userid', 0, ['userid' => $userid]);
        $DB->set_field(bulk::TABLE, 'decidedby', 0, ['decidedby' => $userid]);
        $DB->delete_records(message::TABLE, ['userid' => $userid]);
        $DB->set_field(mirror::TABLE, 'passedby', 0, ['passedby' => $userid]);
        // The messages of the team: their conversations with another person, whole, and what they wrote or read.
        team::delete_user($userid);
        foreach ($links as $link) {
            if ($link->contactid && !$DB->record_exists(consent::LINKS, ['contactid' => $link->contactid])) {
                $DB->delete_records(consent::LOG, ['contactid' => $link->contactid]);
                $DB->delete_records(consent::OPTOUTS, ['contactid' => $link->contactid]);
                $DB->delete_records(answers::TABLE, ['contactid' => $link->contactid]);
                $DB->delete_records('local_oksigeniamarandu_queue', ['contactid' => $link->contactid]);
                // What the index of the conversations keeps of that number goes too; the conversations themselves
                // are in Chatwoot.
                $phone = (string)$DB->get_field(consent::CONTACTS, 'phone', ['id' => $link->contactid]);
                if ($phone !== '' && !telegram::is_chat($phone)) {
                    $DB->delete_records(mirror::TABLE, ['phone' => $phone]);
                }
                $DB->delete_records(consent::CONTACTS, ['id' => $link->contactid]);
            }
        }
    }
}
