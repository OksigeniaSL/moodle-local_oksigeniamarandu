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
 * The notices the site sends to the people of the team: their conversations, what WhatsApp costs, a new chat joined to
 * their account and the posts for the channel of WhatsApp; on the phone (the app) and in the bell of the site.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$messageproviders = [
    // A family writes in a conversation of yours or of your department, someone passes you one, or names you in a note.
    'chat' => [
        'capability' => 'local/oksigeniamarandu:chat',
        'defaults' => [
            'airnotifier' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_DISALLOWED,
            // By Telegram it goes as a notice of its own, with its button (the notice «chat» of lib.php), not as a general one.
            'oksigeniamarandu' => MESSAGE_DISALLOWED,
        ],
    ],
    // What WhatsApp costs and the sends to many: one that waits for your approval, how the one you asked for ended, the
    // budget of the month passed. Without the capability of the chat: whoever asks for a send may not attend it.
    'spend' => [
        'defaults' => [
            'airnotifier' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED,
            // By Telegram the approval goes as a question of its own, with its buttons.
            'oksigeniamarandu' => MESSAGE_DISALLOWED,
        ],
    ],
    // A post of the channel of Telegram that waits for the channel of WhatsApp, for whoever publishes there and has no
    // WhatsApp nor Telegram joined; and a post taken out of Telegram after it went to WhatsApp. Whoever has them joined
    // gets it there, as the notice «channel_whatsapp» of lib.php, with the post ready to forward.
    'wachannel' => [
        'defaults' => [
            'airnotifier' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED,
            'oksigeniamarandu' => MESSAGE_DISALLOWED,
        ],
    ],
    // A number or a chat joined to your account: if it was not you, you know at once. Never by that same chat.
    'newchat' => [
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_FORCED,
            'oksigeniamarandu' => MESSAGE_DISALLOWED,
        ],
    ],
];
