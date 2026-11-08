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

namespace local_oksigeniamarandu\local;

/**
 * Work of a class for the channel of Telegram: whoever teaches a course sends from it a photo of a piece of work (a
 * drawing, a model, a screen), with the group and without names, and it reaches whoever reviews the channel. Only the
 * work: never a person. That is why this kind of post always waits for a review, whatever the site says, and why the
 * photo is drawn again before it is kept: without what the camera writes inside it (the place, the device), straight and
 * no larger than it needs.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class classwork {
    /** The kind of post. */
    public const KIND = 'classwork';

    /** The area of its photos. */
    public const AREA = 'classwork';

    /** The largest photo it takes, bytes. */
    public const MAXBYTES = 15 * 1024 * 1024;

    /** The longest side of the photo it keeps, pixels. */
    private const SIDE = 1600;

    /** The longest title, group and text. */
    public const LIMITS = ['title' => 80, 'group' => 80, 'text' => 400];

    /** The plugin. */
    private const COMPONENT = 'local_oksigeniamarandu';

    /**
     * Whether a user may send work of a course to the channel: the site has a channel, it takes this kind of post and
     * the user teaches the course.
     *
     * @param \context_course $context The course.
     * @param int|null $userid The user; the current one if null.
     * @return bool
     */
    public static function can_send(\context_course $context, ?int $userid = null): bool {
        return channel::is_ready() && channel::policy(self::COMPONENT . '/' . self::KIND) !== 'off'
            && has_capability('local/oksigeniamarandu:share', $context, $userid);
    }

    /**
     * Sends a piece of work to review: its photo, drawn again, kept for the channel; and the post, waiting.
     *
     * @param \stdClass $course The course.
     * @param string $photo The photo, as it was uploaded.
     * @param string $title What the work is called.
     * @param string $group The group or the subject, without names.
     * @param string $text A few words, if any.
     * @param int|null $userid Who sends it; the current user if null.
     * @return int The id of the post; 0 if it does not go (no channel).
     * @throws \moodle_exception If the photo is not a photo, or the title is missing.
     */
    public static function send(
        \stdClass $course,
        string $photo,
        string $title,
        string $group,
        string $text = '',
        ?int $userid = null
    ): int {
        global $USER;
        $title = self::cut($title, 'title');
        $group = self::cut($group, 'group');
        $text = self::cut($text, 'text');
        if ($title === '') {
            throw new \moodle_exception('classwork:notitle', self::COMPONENT);
        }
        $jpeg = self::photo($photo);
        if ($jpeg === null) {
            throw new \moodle_exception('classwork:notphoto', self::COMPONENT);
        }
        $context = \context_system::instance();
        $fs = get_file_storage();
        do {
            $itemid = random_int(100000000, 2000000000);
        } while (!$fs->is_area_empty($context->id, self::COMPONENT, self::AREA, $itemid, false));
        $fs->create_file_from_string(['contextid' => $context->id, 'component' => self::COMPONENT, 'filearea' => self::AREA,
            'itemid' => $itemid, 'filepath' => '/', 'filename' => 'work.jpg'], $jpeg);
        $url = \moodle_url::make_pluginfile_url($context->id, self::COMPONENT, self::AREA, $itemid, '/', 'work.jpg');
        $content = ['title' => $title, 'image' => $url->out(false)];
        if ($group !== '') {
            $content['lines'] = [$group];
        }
        if ($text !== '') {
            $content['text'] = $text;
        }
        $id = channel::post(self::COMPONENT, self::KIND, $content, null, false, self::KIND . '-' . $itemid);
        if (!$id) {
            $fs->delete_area_files($context->id, self::COMPONENT, self::AREA, $itemid);
            return 0;
        }
        \local_oksigeniamarandu\event\classwork_sent::create([
            'context' => \context_course::instance($course->id),
            'objectid' => $id,
            'userid' => $userid ?? $USER->id,
        ])->trigger();
        return $id;
    }

    /**
     * A photo drawn again as a JPEG: straight as the camera held it, no larger than it needs, and without anything the
     * camera wrote inside it.
     *
     * @param string $bytes The photo.
     * @return string|null Null if it is not a JPEG, a PNG or a WebP the server can read, or it is too large.
     */
    public static function photo(string $bytes): ?string {
        if ($bytes === '' || strlen($bytes) > self::MAXBYTES) {
            return null;
        }
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }
        $image = @imagecreatefromstring($bytes);
        if (!$image) {
            return null;
        }
        if ($type === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
            $turns = [3 => 180, 6 => -90, 8 => 90];
            $orientation = (int)($exif['Orientation'] ?? 1);
            if (isset($turns[$orientation])) {
                $image = imagerotate($image, $turns[$orientation], 0);
            }
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, self::SIDE / max($width, $height));
        $newwidth = max(1, (int)round($width * $scale));
        $newheight = max(1, (int)round($height * $scale));
        // On white: what is transparent in a PNG would be black in a JPEG.
        $out = imagecreatetruecolor($newwidth, $newheight);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $image, 0, 0, 0, 0, $newwidth, $newheight, $width, $height);
        ob_start();
        imagejpeg($out, null, 85);
        return (string)ob_get_clean();
    }

    /**
     * A text of the form, plain and no longer than it may be.
     *
     * @param string $text The text.
     * @param string $field title, group or text.
     * @return string
     */
    private static function cut(string $text, string $field): string {
        $text = trim(preg_replace('/[ \t]+/', ' ', strip_tags($text)));
        return \core_text::substr($text, 0, self::LIMITS[$field]);
    }
}
