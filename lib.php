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
 * Callbacks of local_oksigeniamarandu.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The notices of this plugin, declared as any other plugin declares its own (see \local_oksigeniamarandu\local\notices).
 *
 * The general notice: «you have a new notice from the site», with its subject and a button to read it. Any plugin can
 * use it while it has no notices of its own, and so will the channel for the notifications of Moodle.
 *
 * @return array
 */
function local_oksigeniamarandu_oksigeniamarandu_notices(): array {
    return [
        'notification' => [
            'examples' => ['Colegio Ejemplo', 'Reunión de familias el jueves a las 17:00'],
            'perweek' => 1,
        ],
    ];
}

/**
 * What Marandu posts by itself in the channel of Telegram of the site: the announcements of the front page, written by a
 * person already, so they go without review unless the site says otherwise; and, from the calendar of the site, «This
 * week» on Monday and «No class tomorrow» on the eve of a day without class, reviewed first.
 *
 * @return array By kind of post.
 */
function local_oksigeniamarandu_oksigeniamarandu_posts(): array {
    return [
        'news' => ['review' => false],
        'week' => [],
        'dayoff' => [],
    ];
}

/**
 * What Marandu has for a user on their dashboard («Your work», of the Oksigenia plugins): the channel of Telegram, for
 * whoever may review it, with its figures and what is waiting for them. Kept for a minute; nothing without the block.
 *
 * @param int $userid The user.
 * @return array The cards: key, title, url, status, alerts (text, url, level urgent or today, and due, the time of its
 *               deadline, if it has one) and actions (label, url).
 */
function local_oksigeniamarandu_oksigenia_work(int $userid): array {
    return \local_oksigeniamarandu\local\work::for_user($userid);
}

/**
 * Serves the cards Marandu made for the posts of the channel. They are for everyone, as the channel: no login.
 *
 * @param stdClass $course The course (the site).
 * @param stdClass|null $cm Not used.
 * @param context $context The context: the system.
 * @param string $filearea card.
 * @param array $args The post and the name of the file.
 * @param bool $forcedownload Whether to download it.
 * @param array $options More options.
 * @return bool False if it is not a card.
 */
function local_oksigeniamarandu_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_SYSTEM || $filearea !== \local_oksigeniamarandu\local\cards::AREA || count($args) < 2) {
        return false;
    }
    $itemid = (int)array_shift($args);
    $file = get_file_storage()->get_file($context->id, 'local_oksigeniamarandu', $filearea, $itemid, '/', array_pop($args));
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, DAYSECS, 0, $forcedownload, $options);
}
