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
 * What Marandu listens to of itself: the buttons of the notice of a conversation, pressed on Telegram.
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
