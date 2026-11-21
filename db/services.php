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
 * Web services: what the site knows of a WhatsApp number, for the shared inbox that gets its messages too.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_oksigeniamarandu_whatsapp_context' => [
        'classname' => \local_oksigeniamarandu\external\whatsapp_context::class,
        'description' => 'What the site knows of a WhatsApp number: whom it joined with, the last notice it got and '
            . 'whether a plugin answered one of its messages.',
        'type' => 'read',
        'capabilities' => 'local/oksigeniamarandu:inbox',
    ],
    'local_oksigeniamarandu_chat_poll' => [
        'classname' => \local_oksigeniamarandu\external\chat_poll::class,
        'description' => 'What changed in the conversations of the team: trays, conversations and new messages.',
        'type' => 'read',
        'capabilities' => 'local/oksigeniamarandu:chat',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_oksigeniamarandu_chat_messages' => [
        'classname' => \local_oksigeniamarandu\external\chat_messages::class,
        'description' => 'Older messages of a conversation of the team.',
        'type' => 'read',
        'capabilities' => 'local/oksigeniamarandu:chat',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_oksigeniamarandu_chat_send' => [
        'classname' => \local_oksigeniamarandu\external\chat_send::class,
        'description' => 'Writes in a conversation of the team: an answer with its signature, or a note.',
        'type' => 'write',
        'capabilities' => 'local/oksigeniamarandu:chat',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_oksigeniamarandu_chat_template' => [
        'classname' => \local_oksigeniamarandu\external\chat_template::class,
        'description' => 'Sends an approved message of WhatsApp in a conversation of the team.',
        'type' => 'write',
        'capabilities' => 'local/oksigeniamarandu:chat',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_oksigeniamarandu_chat_flag' => [
        'classname' => \local_oksigeniamarandu\external\chat_flag::class,
        'description' => 'A small choice of a person on the page of the conversations: the tour seen, the sound, the notices.',
        'type' => 'write',
        'capabilities' => 'local/oksigeniamarandu:chat',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_oksigeniamarandu_chat_resolved' => [
        'classname' => \local_oksigeniamarandu\external\chat_resolved::class,
        'description' => 'The conversations resolved in the last days that a person of the team sees.',
        'type' => 'read',
        'capabilities' => 'local/oksigeniamarandu:chat',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_oksigeniamarandu_chat_find' => [
        'classname' => \local_oksigeniamarandu\external\chat_find::class,
        'description' => 'Whom a person of the team can start a conversation with: a number or a name.',
        'type' => 'read',
        'capabilities' => 'local/oksigeniamarandu:chat',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_oksigeniamarandu_chat_start' => [
        'classname' => \local_oksigeniamarandu\external\chat_start::class,
        'description' => 'Starts a conversation of the team with an approved message of WhatsApp.',
        'type' => 'write',
        'capabilities' => 'local/oksigeniamarandu:chat',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_oksigeniamarandu_chat_action' => [
        'classname' => \local_oksigeniamarandu\external\chat_action::class,
        'description' => 'Keeps, passes, resolves or reopens a conversation of the team, or says who its contact is.',
        'type' => 'write',
        'capabilities' => 'local/oksigeniamarandu:chat',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_oksigeniamarandu_chat_board' => [
        'classname' => \local_oksigeniamarandu\external\chat_board::class,
        'description' => 'The board of the team: its notices, and putting one, marking one seen or changing one.',
        'type' => 'write',
        'capabilities' => 'local/oksigeniamarandu:chat',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_oksigeniamarandu_chat_agenda' => [
        'classname' => \local_oksigeniamarandu\external\chat_agenda::class,
        'description' => 'The address book of the conversations: the favourites of the person and the contacts of the centre.',
        'type' => 'write',
        'capabilities' => 'local/oksigeniamarandu:chat',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_oksigeniamarandu_chat_remind' => [
        'classname' => \local_oksigeniamarandu\external\chat_remind::class,
        'description' => 'Asks to have a conversation of the team back on a day, with a line of what for, or takes it away.',
        'type' => 'write',
        'capabilities' => 'local/oksigeniamarandu:chat',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_oksigeniamarandu_chat_extras' => [
        'classname' => \local_oksigeniamarandu\external\chat_extras::class,
        'description' => 'Saved answers, approved messages, people and departments of the conversations of the team.',
        'type' => 'read',
        'capabilities' => 'local/oksigeniamarandu:chat',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_oksigeniamarandu_chat_contact' => [
        'classname' => \local_oksigeniamarandu\external\chat_contact::class,
        'description' => 'Who the contact of a conversation of the team is.',
        'type' => 'read',
        'capabilities' => 'local/oksigeniamarandu:chat',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_oksigeniamarandu_chat_prefs' => [
        'classname' => \local_oksigeniamarandu\external\chat_prefs::class,
        'description' => 'How a person wants the backgrounds of the conversations.',
        'type' => 'write',
        'capabilities' => 'local/oksigeniamarandu:chat',
        'ajax' => true,
        'loginrequired' => true,
    ],
];

$services = [
    'Oksigenia Marandu, shared inbox' => [
        'functions' => ['local_oksigeniamarandu_whatsapp_context'],
        'shortname' => 'local_oksigeniamarandu_inbox',
        'restrictedusers' => 1,
        'enabled' => 1,
    ],
];
