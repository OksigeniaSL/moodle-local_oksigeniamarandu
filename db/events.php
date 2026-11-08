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
 * What the plugin listens to: an adult of the family records who leaves (also when their family closes) or whose phone
 * changes, which never comes without local_oksigeniafamily; and the announcements forum of the front page, for the channel
 * of Telegram.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\mod_forum\event\discussion_created',
        'callback' => '\local_oksigeniamarandu\local\channel::forum_changed',
    ],
    [
        'eventname' => '\mod_forum\event\post_updated',
        'callback' => '\local_oksigeniamarandu\local\channel::forum_changed',
    ],
    [
        'eventname' => '\mod_forum\event\discussion_deleted',
        'callback' => '\local_oksigeniamarandu\local\channel::forum_changed',
    ],
    [
        'eventname' => '\local_oksigeniafamily\event\adult_removed',
        'callback' => '\local_oksigeniamarandu\local\family::adult_changed',
    ],
    [
        'eventname' => '\local_oksigeniafamily\event\adult_updated',
        'callback' => '\local_oksigeniamarandu\local\family::adult_changed',
    ],
];
