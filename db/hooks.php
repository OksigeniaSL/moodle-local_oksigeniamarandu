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
 * What Marandu listens to: the buttons of the notice of a conversation, pressed on Telegram; the entry in the main
 * navigation; and the mails Moodle is about to send, for the notices marked for its way.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \local_oksigeniamarandu\hook\answer_received::class,
        'callback' => [\local_oksigeniamarandu\local\chat\telegram_actions::class, 'answered'],
    ],
];
// The entry to «Conversations» in the main navigation, where the version of Moodle has the hook.
if (class_exists(\core\hook\navigation\primary_extend::class)) {
    $callbacks[] = [
        'hook' => \core\hook\navigation\primary_extend::class,
        'callback' => [\local_oksigeniamarandu\local\chat\entry::class, 'primary'],
    ];
}
// The notices of the plugins that Moodle turns into a mail, marked for the way of Marandu (Moodle 5.3 and later).
if (class_exists(\core\hook\email\before_email_to_user::class)) {
    $callbacks[] = [
        'hook' => \core\hook\email\before_email_to_user::class,
        'callback' => [\local_oksigeniamarandu\local\mail\hook::class, 'before'],
    ];
}
