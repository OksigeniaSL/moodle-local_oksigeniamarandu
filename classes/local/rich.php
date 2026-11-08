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
 * The card of a notice on Telegram: a rich message with a title, an image, a list, some text and the button.
 *
 * A plugin asks for it with the last argument of api::notify() and api::notify_child(). WhatsApp ignores it and goes with
 * its template. The parts are plain text: Telegram receives them as blocks, so nothing has to be escaped.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rich {
    /** The styles of a button with a link that Telegram takes. */
    private const STYLES = ['primary', 'danger', 'success'];

    /** The images Telegram takes as a photo, by what they really are. */
    private const IMAGES = ['image/png', 'image/jpeg'];

    /** The largest photo Telegram takes, in bytes. */
    private const MAX_BYTES = 10485760;

    /** The biggest animation it sends (Telegram takes up to 50 MB from a bot). */
    private const MAX_VIDEO = 20971520;

    /** How many lines and paragraphs a card keeps, and how long each is: well within the limits of a rich message. */
    private const MAX_LINES = 30;

    /** How long a line of the list can be. */
    private const LINE_LENGTH = 500;

    /** How many paragraphs a card keeps. */
    private const MAX_PARAGRAPHS = 10;

    /** How long a paragraph can be. */
    private const PARAGRAPH_LENGTH = 1000;

    /**
     * Checks the parts of a card and leaves them ready to keep in the queue.
     *
     * @param array $rich Any of: title (text); image (a URL of this site with a PNG or a JPEG, which Marandu reads and
     *                    uploads, so that it works on a site Telegram cannot reach); lines (texts of a list); text (a text,
     *                    or several, each line a paragraph); style of the button (primary, danger or success).
     * @return array The clean parts; empty if nothing is left to show.
     * @throws \coding_exception With a part it does not know, an image of another site or a style it does not know.
     */
    public static function clean(array $rich): array {
        global $CFG;
        $unknown = array_diff(array_keys($rich), ['title', 'image', 'lines', 'text', 'style']);
        if ($unknown) {
            throw new \coding_exception('A rich message has no ' . implode(', ', $unknown));
        }
        $image = $rich['image'] ?? '';
        $image = $image instanceof \moodle_url ? $image->out(false) : (string)$image;
        if ($image !== '' && strpos($image, $CFG->wwwroot . '/') !== 0) {
            throw new \coding_exception('The image of a rich message has to be on this site');
        }
        $style = $rich['style'] ?? null;
        if ($style !== null && !in_array($style, self::STYLES, true)) {
            throw new \coding_exception('A button of a rich message cannot have the style ' . $style);
        }
        $paragraphs = [];
        foreach ((array)($rich['text'] ?? []) as $text) {
            $paragraphs = array_merge($paragraphs, preg_split('/\R+/u', (string)$text));
        }
        $clean = array_filter([
            'title' => self::plain($rich['title'] ?? '', 256),
            'image' => $image,
            'lines' => self::plain_list((array)($rich['lines'] ?? []), self::MAX_LINES, self::LINE_LENGTH),
            'text' => self::plain_list($paragraphs, self::MAX_PARAGRAPHS, self::PARAGRAPH_LENGTH),
        ]);
        if ($clean && $style !== null) {
            $clean['style'] = $style;
        }
        return $clean;
    }

    /**
     * The blocks of a card, in the order they are read: title, image, list, text and button.
     *
     * @param array $rich The clean parts.
     * @param string|null $media The photo as Telegram takes it (a file_id or attach://name); null without one.
     * @param string|null $url Where the button goes; null without a button.
     * @param string $label The label of the button.
     * @return array
     */
    public static function blocks(array $rich, ?string $media, ?string $url, string $label): array {
        $blocks = [];
        if (!empty($rich['title'])) {
            $blocks[] = ['type' => 'heading', 'text' => $rich['title'], 'size' => 2];
        }
        if ($media !== null) {
            $blocks[] = ['type' => 'photo', 'photo' => ['type' => 'photo', 'media' => $media]];
        }
        if (!empty($rich['lines'])) {
            $blocks[] = ['type' => 'list', 'items' => array_map(function ($line) {
                return ['blocks' => [['type' => 'paragraph', 'text' => $line]]];
            }, $rich['lines'])];
        }
        foreach ($rich['text'] ?? [] as $paragraph) {
            $blocks[] = ['type' => 'paragraph', 'text' => $paragraph];
        }
        if ($url !== null) {
            $button = ['text' => $label, 'url' => $url];
            if (!empty($rich['style'])) {
                $button['style'] = $rich['style'];
            }
            $blocks[] = ['type' => 'buttons', 'buttons' => [$button]];
        }
        return $blocks;
    }

    /**
     * The file of an animation of this site, an MP4 without sound, as image() reads an image.
     *
     * @param string $url A URL of this site.
     * @return string|null Its bytes; null if it is not an MP4 that Telegram would take.
     */
    public static function video(string $url): ?string {
        return self::file($url, '/\.mp4$/i', ['video/mp4'], self::MAX_VIDEO);
    }

    /**
     * The file of an image of this site: from the disk if it is a file of the code, which needs no address that works from
     * the server itself, or else from its address. Without following redirections: a page that asks to log in is no image.
     *
     * @param string $url A URL of this site.
     * @return string|null Its bytes; null if it is not a PNG or a JPEG that Telegram would take.
     */
    public static function image(string $url): ?string {
        return self::file($url, '/\.(png|jpe?g)$/i', self::IMAGES, self::MAX_BYTES);
    }

    /**
     * A file of this site, from the disk if it is a file of the code, or else from its address.
     *
     * @param string $url A URL of this site.
     * @param string $extensions The pattern of the names of the files of the code it may read.
     * @param string[] $types The types it takes.
     * @param int $max The most bytes it takes.
     * @return string|null Its bytes; null if it is not of those types or is too big.
     */
    private static function file(string $url, string $extensions, array $types, int $max): ?string {
        global $CFG;
        if (strpos($url, $CFG->wwwroot . '/') !== 0) {
            return null;
        }
        $path = rawurldecode((string)strtok(substr($url, strlen($CFG->wwwroot)), '?#'));
        $file = preg_match($extensions, $path) ? realpath($CFG->dirroot . $path) : false;
        $stored = self::card_file($url);
        if ($stored !== null) {
            $bytes = $stored;
        } else if ($file !== false && strpos($file, realpath($CFG->dirroot) . DIRECTORY_SEPARATOR) === 0) {
            $bytes = (string)file_get_contents($file);
        } else {
            require_once($CFG->libdir . '/filelib.php');
            $curl = new \curl(['ignoresecurity' => true]);
            $bytes = (string)$curl->get($url, [], ['CURLOPT_TIMEOUT' => 10, 'CURLOPT_FOLLOWLOCATION' => 0]);
        }
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($type, $types, true) || strlen($bytes) > $max) {
            debugging('Marandu: this file cannot be sent to Telegram: ' . s($url), DEBUG_DEVELOPER);
            return null;
        }
        return $bytes;
    }

    /**
     * A card Marandu made for a post, read from its files rather than from its address (a server does not always reach
     * itself).
     *
     * @param string $url A URL of this site.
     * @return string|null Its bytes; null if it is not a card of Marandu.
     */
    private static function card_file(string $url): ?string {
        global $CFG;
        $rest = rawurldecode(substr($url, strlen($CFG->wwwroot)));
        if (!preg_match('~^/pluginfile\.php(?:/|\?file=/)(\d+)/local_oksigeniamarandu/card/(\d+)/([^/?#&]+)~', $rest, $parts)) {
            return null;
        }
        $file = get_file_storage()->get_file(
            (int)$parts[1],
            'local_oksigeniamarandu',
            cards::AREA,
            (int)$parts[2],
            '/',
            $parts[3]
        );
        return $file ? $file->get_content() : null;
    }

    /**
     * A text without marks.
     *
     * @param mixed $text The text.
     * @param int $length How long it can be.
     * @return string
     */
    private static function plain($text, int $length): string {
        return \core_text::substr(trim(strip_tags((string)$text)), 0, $length);
    }

    /**
     * Some texts without marks, leaving out those that end up empty.
     *
     * @param array $texts The texts.
     * @param int $count How many it keeps.
     * @param int $length How long each can be.
     * @return string[]
     */
    private static function plain_list(array $texts, int $count, int $length): array {
        $plain = array_map(function ($text) use ($length) {
            return self::plain($text, $length);
        }, $texts);
        return array_slice(array_values(array_filter($plain, 'strlen')), 0, $count);
    }
}
