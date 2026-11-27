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
 * The callbacks of a plugin that hears what became of its notices, for the tests.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_oksigeniamarandu\local\listening_plugin;

/**
 * What became of a notice.
 *
 * @param string $notice The notice.
 * @param int $itemid The item.
 * @param string $channel The channel.
 * @param string $status What became of it.
 * @param string $why Why it did not go.
 * @param int $queued When it was queued.
 * @param string $area The kind of item.
 */
function local_oksigeniamarandu_marandu_notice_done(
    string $notice,
    int $itemid,
    string $channel,
    string $status,
    string $why,
    int $queued,
    string $area
): void {
    listening_plugin::$heard[] = [$notice, $itemid, $channel, $status, $why];
}

/**
 * Whether an item of a send to many still goes.
 *
 * @param int $bulkid The send.
 * @param string $notice The notice.
 * @param int $itemid The item.
 * @return bool|array
 */
function local_oksigeniamarandu_marandu_bulk_item(int $bulkid, string $notice, int $itemid): bool|array {
    return listening_plugin::$item ? (listening_plugin::$item)($itemid) : true;
}

/**
 * Hears an answer to one of its sends, and thanks for it.
 *
 * @param int $bulkid The send.
 * @param int $itemid The item.
 * @param string $answer no.
 * @param int $time When.
 * @return string|null
 */
function local_oksigeniamarandu_marandu_bulk_answer(int $bulkid, int $itemid, string $answer, int $time): ?string {
    listening_plugin::$answers[] = [$bulkid, $itemid, $answer];
    return 'Thanks for telling us.';
}

/**
 * Hears what a contact wrote in a conversation of the team.
 *
 * @param string $phone The number.
 * @param string $text What it wrote.
 * @param int $time When.
 * @param string $url The conversation.
 * @param int $messageid The message.
 */
function local_oksigeniamarandu_marandu_chat_in(string $phone, string $text, int $time, string $url, int $messageid): void {
    if (listening_plugin::$chatfails) {
        throw new \coding_exception('It fails on purpose');
    }
    listening_plugin::$chat[] = [$phone, $text, $time, $url, $messageid];
}

/**
 * What became of a mail.
 *
 * @param string $email The address.
 * @param string $event bounce, blocked, spam or failed.
 * @param string $why What the other side said.
 * @param string $ref What the plugin calls the mail.
 * @param int $time When.
 */
function local_oksigeniamarandu_marandu_mail_event(string $email, string $event, string $why, string $ref, int $time): void {
    listening_plugin::$mail[] = [$email, $event, $why, $ref, $time];
}
