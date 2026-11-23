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
        // The conversations of the team, to whoever attends them and linked their Telegram: only by Telegram (a notice of a
        // WhatsApp conversation by WhatsApp says nothing new), with a button that opens the conversation.
        'chat' => [
            'examples' => ['Lucía Ferrer: ¿Me confirman el recibo de diciembre?'],
            'perweek' => 0,
            'channels' => ['telegram'],
            // From Telegram itself: keep it, resolve it or pass it to a department; a reply to the notice is a note.
            'options' => ['take', 'resolve', 'pass'],
            // Its own brake (one a conversation every ten minutes, in the hours of the team): the notices a day are for
            // the families.
            'uncapped' => true,
        ],
        'board' => [
            'examples' => ['Héctor Ruiz: El lunes 23 no hay comedor: arreglan la cocina.'],
            'perweek' => 0,
            'channels' => ['telegram'],
            // The «Seen» of the board, pressed on Telegram itself.
            'options' => ['seen'],
            'uncapped' => true,
        ],
        // The messages of the team, between two people or in a department: only by Telegram, with a button that opens
        // the conversation; switched on wherever the notice of the conversations is.
        'team' => [
            'examples' => ['Ana, en privado: ¿Me pasas el certificado de Leo?'],
            'perweek' => 0,
            'channels' => ['telegram'],
            'uncapped' => true,
            'follows' => 'chat',
        ],
        // A proposal of improvement of a tool, with the picture of its screen, to the people the site chose to receive them:
        // only by Telegram; switched on wherever the notice of the conversations is.
        'proposal' => [
            'examples' => ['Oksigenia Meals: que se vea el menú de toda la semana de una vez'],
            'perweek' => 0,
            'channels' => ['telegram'],
            'uncapped' => true,
            'follows' => 'chat',
        ],
        // A post of the channel of Telegram, for whoever publishes it in the channel of WhatsApp too: on WhatsApp, inside the
        // 24 hours, the post itself (its video or its image with its text) ready to forward, and this notice with the link
        // to its page after it; outside them, the template with its button; on Telegram, a card with the button. It
        // comes when the post goes out: neither the quiet hours nor the notices a day hold it back.
        'channel_whatsapp' => [
            'examples' => ['Colegio Ejemplo', 'Jornada de puertas abiertas'],
            'perweek' => 0,
            'askedfor' => true,
            'uncapped' => true,
        ],
        // A send to many that waits for approval, to whoever may approve it: «Approve» and «Reject» from Telegram itself.
        'bulk' => [
            'examples' => ['Inés: Invitación a la jornada de puertas abiertas, 142 por WhatsApp, 8,52 EUR'],
            'perweek' => 0,
            'channels' => ['telegram'],
            'options' => ['approve', 'reject'],
            'uncapped' => true,
        ],
    ];
}

/**
 * What Marandu posts by itself in the channel of Telegram of the site: the announcements of the front page, written by a
 * person already, so they go without review unless the site says otherwise; from the calendar of the site, «This
 * week» on Monday and «No class tomorrow» on the eve of a day without class; the official weather warnings of the place
 * of the site; and its sky: the passes of the International Space Station, the full moon and the meteor showers; reviewed
 * first.
 *
 * @return array By kind of post.
 */
function local_oksigeniamarandu_oksigeniamarandu_posts(): array {
    return [
        'news' => ['review' => false],
        'week' => ['card' => 'this_week'],
        'dayoff' => ['card' => 'no_class'],
        'weather' => ['card' => 'weather'],
        'sky_iss' => ['card' => 'sky'],
        'sky_moon' => ['card' => 'sky'],
        'sky_meteors' => ['card' => 'sky'],
        // Work of a class: always reviewed, whatever the site says.
        'classwork' => ['always' => true, 'card' => 'classwork'],
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
 * Serves the cards Marandu made for the posts of the channel, and the photos of the work of a class sent to it. They
 * are for everyone, as the channel: no login.
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
    global $USER;
    $areas = [\local_oksigeniamarandu\local\cards::AREA, \local_oksigeniamarandu\local\classwork::AREA,
        \local_oksigeniamarandu\local\proposals::AREA];
    if ($context->contextlevel != CONTEXT_SYSTEM || !in_array($filearea, $areas, true) || count($args) < 2) {
        return false;
    }
    if ($filearea === \local_oksigeniamarandu\local\proposals::AREA) {
        // The picture of a proposal: only for whoever reads them.
        require_login();
        if (!\local_oksigeniamarandu\local\proposals::can_read((int)$USER->id)) {
            return false;
        }
        $options['cacheability'] = 'private';
    }
    $itemid = (int)array_shift($args);
    $file = get_file_storage()->get_file($context->id, 'local_oksigeniamarandu', $filearea, $itemid, '/', array_pop($args));
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, DAYSECS, 0, $forcedownload, $options);
}

/**
 * In a course, for whoever teaches it: «To the channel», to send a piece of work of the class to the review of the channel
 * of Telegram of the site.
 *
 * @param navigation_node $navigation The navigation of the course.
 * @param stdClass $course The course.
 * @param context_course $context Its context.
 */
function local_oksigeniamarandu_extend_navigation_course(navigation_node $navigation, stdClass $course, context_course $context) {
    if (!\local_oksigeniamarandu\local\classwork::can_send($context)) {
        return;
    }
    $navigation->add(
        get_string('classwork:nav', 'local_oksigeniamarandu'),
        new moodle_url('/local/oksigeniamarandu/share.php', ['id' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_oksigeniamarandu_share',
        new pix_icon('t/share', '')
    );
}
