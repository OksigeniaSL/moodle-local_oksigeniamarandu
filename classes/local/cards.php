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
 * The cards of the posts of the channel, made on the server from the templates of the house (cards/<type>/, by Gaudí):
 * a still one with GD and an animated one with ffmpeg, in the colour and with the name of the organization. The text
 * never goes over the drawing; it shrinks to fit and ends in «…» if it still does not.
 *
 * A post asks for one with the part card of its content: ['type' => 'open_day', 'zones' => ['kicker' => …, 'title' =>
 * …, 'date' => …, 'detail' => …]]. The zones of each type are in its plantilla.json; org is the name of the
 * organization unless the post says another. The card is made before the post goes to review, so whoever reviews sees
 * it as it will go.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cards {
    /** The types of card, as the folders of the templates. */
    public const TYPES = ['open_day', 'outing', 'this_week', 'no_class', 'book', 'big_number', 'sky'];

    /** The file area of the cards made, by post. */
    public const AREA = 'card';

    /** The colour of the organization if neither the site nor its theme say one: the blue of Boost. */
    public const DEFAULTCOLOR = '#0f6cbf';

    /** GD draws text in points at 96 dpi: the size in points is the size in pixels by this. */
    private const PT = 0.75;

    /** The plugin. */
    private const COMPONENT = 'local_oksigeniamarandu';

    /** A change here makes every card again. */
    private const VERSION = 1;

    /** The most cards made in one run of the task, so that it does not take long. */
    private const PERRUN = 3;

    /** @var int Cards made in this run. */
    private static $made = 0;

    /** @var array The ffmpeg found for the paths the site has, once per request. */
    private static $ffmpeg = [];

    /**
     * A template, with the folder of its files.
     *
     * @param string $type One of TYPES.
     * @return array
     */
    public static function template(string $type): array {
        if (!in_array($type, self::TYPES, true)) {
            throw new \coding_exception('There is no card ' . $type);
        }
        $dir = self::root() . '/cards/' . $type;
        $template = json_decode((string)file_get_contents($dir . '/plantilla.json'), true);
        $template['dir'] = $dir;
        return $template;
    }

    /**
     * The part card of the content of a post, checked.
     *
     * @param array $card The type and the texts of its zones; items for a list, as label and text; cover, a URL of
     *                    this site with a PNG or a JPEG; moon, with its phase from 0 to 7.
     * @return array
     * @throws \coding_exception With a type or a zone it does not know.
     */
    public static function clean(array $card): array {
        global $CFG;
        $template = self::template((string)($card['type'] ?? ''));
        $known = array_column($template['zones'], 'type', 'id');
        $zones = [];
        foreach ((array)($card['zones'] ?? []) as $id => $value) {
            if (!isset($known[$id])) {
                throw new \coding_exception("The card {$card['type']} has no zone {$id}");
            }
            if ($known[$id] === 'list') {
                $zones[$id] = array_values(array_map(function ($item) {
                    return ['label' => self::plain($item['label'] ?? ''), 'text' => self::plain($item['text'] ?? '')];
                }, (array)$value));
            } else if ($id === 'moon') {
                $zones[$id] = ['phase' => ((int)($value['phase'] ?? 0) % 8 + 8) % 8];
            } else if ($id === 'cover') {
                $url = $value instanceof \moodle_url ? $value->out(false) : (string)$value;
                if ($url !== '' && strpos($url, $CFG->wwwroot . '/') !== 0) {
                    throw new \coding_exception('The cover of a card has to be on this site');
                }
                $zones[$id] = $url;
            } else {
                $zones[$id] = self::plain($value);
            }
        }
        return ['type' => $card['type'], 'zones' => $zones];
    }

    /**
     * The colour of the organization: the one the site set for its cards, or the brand colour of its theme.
     *
     * @return string As #rrggbb.
     */
    public static function brand(): string {
        global $CFG;
        foreach (
            [get_config(self::COMPONENT, 'cardcolor'), get_config('theme_' . $CFG->theme, 'brandcolor'),
                get_config('theme_boost', 'brandcolor')] as $colour
        ) {
            if (is_string($colour) && preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($colour))) {
                return '#' . ltrim(trim($colour), '#');
            }
        }
        return self::DEFAULTCOLOR;
    }

    /**
     * The name of the organization on the cards: the one the site set, or the name of the site.
     *
     * @return string
     */
    public static function org(): string {
        global $SITE;
        $name = trim((string)get_config(self::COMPONENT, 'cardorg'));
        return $name !== '' ? $name : self::plain(format_string($SITE->fullname, true, ['context' => \context_system::instance()]));
    }

    /**
     * Whether the server can make cards: GD with its fonts.
     *
     * @return bool
     */
    public static function can_draw(): bool {
        return function_exists('imagettftext') && function_exists('imagecreatefrompng');
    }

    /**
     * The ffmpeg of the site, if it can make the animated cards (6.1 or later, with drawtext and libx264): the one the
     * site set, or that of Oksigenia Hechauka.
     *
     * @return string|null Its path.
     */
    public static function ffmpeg(): ?string {
        $paths = [
            trim((string)get_config(self::COMPONENT, 'ffmpeg')),
            trim((string)get_config('local_oksigeniahechauka', 'ffmpeg')),
        ];
        $key = implode('|', $paths);
        if (array_key_exists($key, self::$ffmpeg)) {
            return self::$ffmpeg[$key];
        }
        self::$ffmpeg[$key] = null;
        foreach ($paths as $path) {
            if ($path === '' || !is_executable($path)) {
                continue;
            }
            $version = (string)shell_exec(escapeshellarg($path) . ' -hide_banner -version 2>&1');
            if (preg_match('/version n?(\d+)\.(\d+)/', $version, $parts) && [(int)$parts[1], (int)$parts[2]] >= [6, 1]) {
                self::$ffmpeg[$key] = $path;
                break;
            }
        }
        return self::$ffmpeg[$key];
    }

    /**
     * Makes the card of a post, if it asks for one and it is not made yet or changed: the still one and, if the site
     * has ffmpeg and wants them, the animated one, kept in the files of the plugin and put in its content as its image
     * and its animation.
     *
     * @param \stdClass $post The post.
     * @return \stdClass The post, with its content changed if the card was made.
     */
    public static function for_post(\stdClass $post): \stdClass {
        global $DB;
        $content = json_decode($post->content, true) ?: [];
        if (!self::pending($post) || self::$made >= self::PERRUN) {
            return $post;
        }
        $animated = self::animate();
        $hash = self::hash($content['card']);
        self::$made++;
        $context = \context_system::instance();
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, self::COMPONENT, self::AREA, $post->id);
        unset($content['image'], $content['animation']);
        try {
            $store = function (string $name, string $bytes) use ($fs, $context, $post): string {
                $fs->create_file_from_string(['contextid' => $context->id, 'component' => self::COMPONENT,
                    'filearea' => self::AREA, 'itemid' => $post->id, 'filepath' => '/', 'filename' => $name], $bytes);
                return \moodle_url::make_pluginfile_url($context->id, self::COMPONENT, self::AREA, $post->id, '/', $name)
                    ->out(false);
            };
            $content['image'] = $store('card.jpg', self::still($content['card']));
            if ($animated) {
                $video = self::animated($content['card'], self::ffmpeg());
                if ($video !== null) {
                    $content['animation'] = $store('card.mp4', $video);
                }
            }
        } catch (\Throwable $e) {
            debugging('Marandu: the card of a post was not made: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        $content['cardhash'] = $hash;
        $post->content = json_encode($content);
        $DB->set_field(channel::TABLE, 'content', $post->content, ['id' => $post->id]);
        return $post;
    }

    /**
     * Whether a post asks for a card that is not made yet, or changed: then it waits for it.
     *
     * @param \stdClass $post The post.
     * @return bool
     */
    public static function pending(\stdClass $post): bool {
        $content = json_decode($post->content, true) ?: [];
        return !empty($content['card']) && self::can_draw() && ($content['cardhash'] ?? '') !== self::hash($content['card']);
    }

    /**
     * Whether the cards go animated: the site wants it and has an ffmpeg that can.
     *
     * @return bool
     */
    private static function animate(): bool {
        return get_config(self::COMPONENT, 'cardanimated') !== '0' && self::ffmpeg() !== null;
    }

    /**
     * What a card is made of, to know when to make it again.
     *
     * @param array $card The card.
     * @return string
     */
    private static function hash(array $card): string {
        return sha1(json_encode([self::VERSION, $card, self::brand(), self::org(), self::animate()]));
    }

    /**
     * The still card, as a JPEG.
     *
     * @param array $card The type and the texts of its zones.
     * @param string|null $brand The colour of the organization; that of the site if null.
     * @return string
     */
    public static function still(array $card, ?string $brand = null): string {
        $template = self::template($card['type']);
        $data = self::data($card);
        $colours = self::colours($template, $brand ?? self::brand());
        [$width, $height] = $template['size'] ?? [1280, 720];
        $image = imagecreatefrompng($template['dir'] . '/' . $template['files']['background']);
        imagealphablending($image, true);
        $accent = imagecreatefrompng($template['dir'] . '/' . $template['files']['accent']);
        imagealphablending($accent, false);
        imagesavealpha($accent, true);
        [$r, $g, $b] = $colours['accent'];
        // COLORIZE adds to every channel: to a white layer, adding c - 255 leaves c, and the alpha stays.
        imagefilter($accent, IMG_FILTER_COLORIZE, $r - 255, $g - 255, $b - 255);
        imagecopy($image, $accent, 0, 0, 0, 0, $width, $height);
        $cover = self::draw_images($image, $template, $data, $colours);
        self::draw_lines($image, self::layout($template, $data, $colours, $cover));
        ob_start();
        imagejpeg($image, null, 90);
        return (string)ob_get_clean();
    }

    /**
     * The animated card, as an MP4 without sound: two turns of the background in a loop, with the texts coming in as
     * the template says and going out at the end, so that it starts again clean.
     *
     * @param array $card The type and the texts of its zones.
     * @param string $ffmpeg The path of ffmpeg.
     * @param string|null $brand The colour of the organization; that of the site if null.
     * @return string|null Null if ffmpeg failed.
     */
    public static function animated(array $card, string $ffmpeg, ?string $brand = null): ?string {
        $template = self::template($card['type']);
        $data = self::data($card);
        $colours = self::colours($template, $brand ?? self::brand());
        [$width, $height] = $template['size'] ?? [1280, 720];
        $video = $template['video'];
        $fps = (int)$video['fps'];
        $loops = (int)$video['compose']['loops'];
        $duration = $loops * (float)$video['duration'];
        $job = make_request_directory();

        $layer = imagecreatetruecolor($width, $height);
        imagealphablending($layer, false);
        imagefill($layer, 0, 0, imagecolorallocatealpha($layer, 0, 0, 0, 127));
        imagesavealpha($layer, true);
        $cover = self::draw_images($layer, $template, $data, $colours);
        imagepng($layer, $job . '/images.png');
        $lines = self::layout($template, $data, $colours, $cover);

        $inputs = [
            ['-stream_loop', (string)($loops - 1), '-i', $template['dir'] . '/' . $template['files']['background_video']],
            ['-stream_loop', (string)($loops - 1), '-i', $template['dir'] . '/' . $template['files']['accent_video']],
            ['-f', 'lavfi', '-i', sprintf('color=c=0x%s:s=%dx%d:r=%d', self::hex($colours['accent']), $width, $height, $fps)],
            ['-loop', '1', '-framerate', (string)$fps, '-i', $job . '/images.png'],
        ];
        // The accent: a solid colour with the video of its alpha (full range) as alpha, over the background.
        $filters = ['[1:v]format=gray[a]', '[2:v][a]alphamerge[tint]', '[0:v][tint]overlay=format=auto[c0]',
            '[c0][3:v]overlay=format=auto[c1]'];
        $texts = [];
        $vars = ['{D}' => sprintf('%.2f', $duration)];
        foreach ($lines as $i => $line) {
            // The text goes in a file: nothing to escape, and expansion=none keeps any % as it is.
            $file = sprintf('%s/line-%02d.txt', $job, $i);
            file_put_contents($file, $line['text']);
            $enter = self::entrance($line['enter'], (float)$line['delay']);
            $at = $vars + ['{x}' => (string)$line['x'], '{y}' => (string)$line['y']];
            $texts[] = sprintf(
                "drawtext=fontfile='%s':textfile='%s':expansion=none:fontsize=%d:fontcolor=0x%s:y_align=baseline:"
                    . "x='%s':y='%s':alpha='%s'",
                $line['font'],
                $file,
                $line['px'],
                self::hex($line['color']),
                strtr($enter['x'], $at),
                strtr($enter['y'], $at),
                strtr($enter['alpha'], $at)
            );
        }
        $filters[] = '[c1]' . ($texts ? implode(',', $texts) . ',' : '') . 'format=yuv420p[v]';
        $out = $job . '/card.mp4';
        $command = [$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-threads', '2'];
        foreach ($inputs as $input) {
            $command = array_merge($command, $input);
        }
        $command = array_merge($command, ['-filter_complex', implode(';', $filters), '-map', '[v]', '-t',
            sprintf('%.2f', $duration), '-r', (string)$fps, '-c:v', 'libx264', '-preset', 'medium', '-crf', '23',
            '-pix_fmt', 'yuv420p', '-movflags', '+faststart', '-an', $out]);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return null;
        }
        $errors = stream_get_contents($pipes[2]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_file($out)) {
            debugging('Marandu: ffmpeg did not make the card: ' . s(\core_text::substr((string)$errors, 0, 500)), DEBUG_DEVELOPER);
            return null;
        }
        return (string)file_get_contents($out);
    }

    /**
     * The data of a card for its zones, with the name of the organization unless it says another.
     *
     * @param array $card The card.
     * @return array
     */
    private static function data(array $card): array {
        $data = (array)($card['zones'] ?? []);
        if (!isset($data['org']) || $data['org'] === '') {
            $data['org'] = self::org();
        }
        return $data;
    }

    /**
     * The folder of the plugin.
     *
     * @return string
     */
    private static function root(): string {
        return dirname(__DIR__, 2);
    }

    /**
     * A text without marks, runs of spaces or emoji: the font has none, and GD would draw their bytes as letters. The
     * message of Telegram keeps them.
     *
     * @param mixed $text The text.
     * @return string
     */
    private static function plain($text): string {
        $text = preg_replace(
            '/[\x{10000}-\x{10FFFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}]/u',
            '',
            strip_tags((string)$text)
        );
        return trim(preg_replace('/[ \t]+/u', ' ', (string)$text));
    }

    /**
     * A colour #rrggbb as its three channels.
     *
     * @param string $hex The colour.
     * @return int[]
     */
    private static function rgb(string $hex): array {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /**
     * Three channels as rrggbb.
     *
     * @param array $colour The channels.
     * @return string
     */
    private static function hex(array $colour): string {
        return sprintf('%02x%02x%02x', (int)$colour[0], (int)$colour[1], (int)$colour[2]);
    }

    /**
     * The relative luminance of a colour, as WCAG measures it.
     *
     * @param array $colour The channels.
     * @return float
     */
    private static function luminance(array $colour): float {
        $sum = 0.0;
        foreach ([0.2126, 0.7152, 0.0722] as $i => $weight) {
            $v = $colour[$i] / 255;
            $sum += $weight * ($v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4);
        }
        return $sum;
    }

    /**
     * The contrast between two colours.
     *
     * @param array $a One.
     * @param array $b The other.
     * @return float
     */
    public static function contrast(array $a, array $b): float {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * Whether a colour meets the rules of contrast of a template.
     *
     * @param array $colour The channels.
     * @param array $rules The rules.
     * @return bool
     */
    private static function rules_ok(array $colour, array $rules): bool {
        foreach ($rules as $rule) {
            if ($rule['type'] === 'text_on_accent') {
                $best = 0.0;
                foreach ($rule['candidates'] as $candidate) {
                    $best = max($best, self::contrast($colour, self::rgb($candidate)));
                }
                if ($best < $rule['min_contrast']) {
                    return false;
                }
            } else if (
                $rule['type'] === 'contrast_with'
                && self::contrast($colour, self::rgb($rule['color'])) < $rule['min_contrast']
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * The accent of a card: the colour of the organization as it is, or the smallest step towards white or black (of
     * 1 %) that meets every rule of the template.
     *
     * @param array $brand The channels of the colour.
     * @param array $rules The rules.
     * @return int[]
     */
    public static function fit_accent(array $brand, array $rules): array {
        for ($step = 0; $step <= 100; $step++) {
            foreach ([[255, 255, 255], [0, 0, 0]] as $target) {
                $colour = [];
                for ($i = 0; $i < 3; $i++) {
                    $colour[$i] = (int)floor($brand[$i] + ($target[$i] - $brand[$i]) * $step / 100 + 0.5);
                }
                if (self::rules_ok($colour, $rules)) {
                    return $colour;
                }
            }
        }
        return $brand;
    }

    /**
     * Every colour of a template, with the accent and the text over it worked out.
     *
     * @param array $template The template.
     * @param string $brand The colour of the organization.
     * @return array By name, the channels.
     */
    public static function colours(array $template, string $brand): array {
        $colours = [];
        foreach ($template['palette'] as $name => $hex) {
            $colours[$name] = self::rgb($hex);
        }
        $accent = self::fit_accent(self::rgb($brand), $template['accent']['rules']);
        $colours['accent'] = $accent;
        $candidates = ['#ffffff', $template['palette']['ink'] ?? '#000000'];
        foreach ($template['accent']['rules'] as $rule) {
            if ($rule['type'] === 'text_on_accent') {
                $candidates = $rule['candidates'];
            }
        }
        $best = null;
        foreach ($candidates as $candidate) {
            $colour = self::rgb($candidate);
            if ($best === null || self::contrast($accent, $colour) > self::contrast($accent, $best)) {
                $best = $colour;
            }
        }
        $colours['on_accent'] = $best;
        return $colours;
    }

    /**
     * The file of a font of a template.
     *
     * @param array $template The template.
     * @param string $role Its role, such as bold.
     * @return string
     */
    private static function font(array $template, string $role): string {
        return self::root() . '/fonts/' . $template['fonts'][$role];
    }

    /**
     * The width of a line, from the pen to its right edge, in pixels.
     *
     * @param string $font The font.
     * @param float $px The size.
     * @param string $text The line.
     * @return float
     */
    private static function width(string $font, float $px, string $text): float {
        if ($text === '') {
            return 0.0;
        }
        $box = imagettfbbox($px * self::PT, 0, $font, $text);
        return (float)max($box[2], $box[4]);
    }

    /**
     * The lines of a text in a width, word by word; its line breaks stay, and a word longer than the line is cut.
     *
     * @param string $font The font.
     * @param float $px The size.
     * @param string $text The text.
     * @param float $max The width.
     * @return string[]
     */
    private static function wrap(string $font, float $px, string $text, float $max): array {
        $lines = [];
        foreach (preg_split('/\R/u', trim($text)) as $paragraph) {
            $current = '';
            foreach (preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                $try = $current === '' ? $word : $current . ' ' . $word;
                if (self::width($font, $px, $try) <= $max) {
                    $current = $try;
                    continue;
                }
                if ($current !== '') {
                    $lines[] = $current;
                }
                while (self::width($font, $px, $word) > $max && \core_text::strlen($word) > 1) {
                    $part = '';
                    foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) as $char) {
                        if ($part !== '' && self::width($font, $px, $part . $char) > $max) {
                            break;
                        }
                        $part .= $char;
                    }
                    $lines[] = $part;
                    $word = \core_text::substr($word, \core_text::strlen($part));
                }
                $current = $word;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
        }
        return $lines;
    }

    /**
     * The same number of lines in the narrowest width, so that the last line is not left with one word.
     *
     * @param string $font The font.
     * @param float $px The size.
     * @param string $text The text.
     * @param float $max The width.
     * @param int $count The lines.
     * @return string[]
     */
    private static function balance(string $font, float $px, string $text, float $max, int $count): array {
        $low = $max * 0.4;
        $high = $max;
        for ($i = 0; $i < 10; $i++) {
            $mid = ($low + $high) / 2;
            if (count(self::wrap($font, $px, $text, $mid)) <= $count) {
                $high = $mid;
            } else {
                $low = $mid;
            }
        }
        return self::wrap($font, $px, $text, $high);
    }

    /**
     * A line ending in «…», shortened until it fits.
     *
     * @param string $font The font.
     * @param float $px The size.
     * @param string $line The line.
     * @param float $max The width.
     * @return string
     */
    private static function ellipsis(string $font, float $px, string $line, float $max): string {
        $trim = " ,.;:·-–—";
        $short = rtrim($line, $trim);
        while ($short !== '' && self::width($font, $px, $short . '…') > $max) {
            $short = rtrim(\core_text::substr($short, 0, \core_text::strlen($short) - 1), $trim);
        }
        return $short . '…';
    }

    /**
     * The size and the lines of a text in a zone: from its size down to its smallest one, the first whose lines fit; at
     * the smallest, the last line that fits ends in «…».
     *
     * @param array $template The template.
     * @param array $zone The zone.
     * @param string $font The font.
     * @param string $text The text.
     * @return array The size in pixels and the lines.
     */
    private static function fit(array $template, array $zone, string $font, string $text): array {
        $metrics = $zone['metrics'] ?? $template['metrics'];
        $step = $zone['shrink']['step'] ?? 2;
        for ($px = $zone['size']; $px >= $zone['min_size']; $px -= $step) {
            $lines = self::wrap($font, $px, $text, $zone['w']);
            $height = (count($lines) - 1) * $px * $zone['line_height'] + ($metrics['ascent'] + $metrics['descent']) * $px;
            if (count($lines) <= $zone['max_lines'] && $height <= $zone['h']) {
                if (!empty($zone['balance']) && count($lines) > 1) {
                    $lines = self::balance($font, $px, $text, $zone['w'], count($lines));
                }
                return [$px, $lines];
            }
        }
        $px = $zone['min_size'];
        $lines = self::wrap($font, $px, $text, $zone['w']);
        $room = (int)floor(($zone['h'] - ($metrics['ascent'] + $metrics['descent']) * $px) / ($px * $zone['line_height'])) + 1;
        $keep = max(1, min($zone['max_lines'], $room));
        if (count($lines) > $keep) {
            $lines = array_slice($lines, 0, $keep);
            $lines[$keep - 1] = self::ellipsis($font, $px, $lines[$keep - 1], $zone['w']);
        } else if ($lines && self::width($font, $px, $lines[0]) > $zone['w']) {
            $lines[0] = self::ellipsis($font, $px, $lines[0], $zone['w']);
        }
        return [$px, $lines];
    }

    /**
     * The lines of a text zone, each with where its pen goes (x and the baseline).
     *
     * @param array $template The template.
     * @param array $zone The zone.
     * @param string $text The text.
     * @param array $colours The colours.
     * @return array
     */
    private static function place_text(array $template, array $zone, string $text, array $colours): array {
        if (trim($text) === '') {
            return [];
        }
        $font = self::font($template, $zone['font']);
        [$px, $lines] = self::fit($template, $zone, $font, $text);
        $metrics = $zone['metrics'] ?? $template['metrics'];
        $lineheight = $px * $zone['line_height'];
        $height = (count($lines) - 1) * $lineheight + ($metrics['ascent'] + $metrics['descent']) * $px;
        $top = $zone['y'];
        if ($zone['valign'] === 'middle') {
            $top = $zone['y'] + ($zone['h'] - $height) / 2;
        } else if ($zone['valign'] === 'bottom') {
            $top = $zone['y'] + $zone['h'] - $height;
        }
        $out = [];
        foreach ($lines as $i => $line) {
            $width = self::width($font, $px, $line);
            $x = $zone['x'];
            if ($zone['align'] === 'center') {
                $x = $zone['x'] + ($zone['w'] - $width) / 2;
            } else if ($zone['align'] === 'right') {
                $x = $zone['x'] + $zone['w'] - $width;
            }
            $out[] = ['text' => $line, 'x' => (int)round($x), 'y' => (int)round($top + $metrics['ascent'] * $px + $i * $lineheight),
                'px' => $px, 'font' => $font, 'color' => $colours[$zone['color']], 'enter' => $zone['enter'] ?? null,
                'delay' => 0.0];
        }
        return $out;
    }

    /**
     * The rows of a list zone (items with label and text), all at the same size, stacked from the top; with few items
     * a text may take two lines.
     *
     * @param array $template The template.
     * @param array $zone The zone.
     * @param array $items The items.
     * @param array $colours The colours.
     * @return array
     */
    private static function place_list(array $template, array $zone, array $items, array $colours): array {
        $items = array_values(array_slice($items, 0, $zone['max_items']));
        $count = count($items);
        if (!$count) {
            return [];
        }
        $labelfont = self::font($template, $zone['label']['font']);
        $textfont = self::font($template, $zone['text']['font']);
        $metrics = $zone['metrics'] ?? $template['metrics'];
        $step = $zone['shrink']['step'] ?? 2;
        $start = $zone['size_by_count'][(string)$count];
        $floor = max($zone['min_size'], (int)round($start * ($zone['shrink']['floor'] ?? 0)));
        $maxlines = (int)($zone['lines_by_count'][(string)$count] ?? 1);
        [$gapmin, $gapmax] = $zone['row_gap_em'];
        $try = function (
            int $px,
            bool $force
        ) use (
            $items,
            $labelfont,
            $textfont,
            $zone,
            $metrics,
            $maxlines,
            $gapmin,
            $gapmax,
            $count
): ?array {
            $labelwidth = $zone['label']['w_em'] * $px;
            $textwidth = $zone['w'] - $labelwidth - $zone['gap'];
            $rows = [];
            $total = 0.0;
            foreach ($items as $item) {
                $label = (string)($item['label'] ?? '');
                $lines = self::wrap($textfont, $px, (string)($item['text'] ?? ''), $textwidth);
                if (count($lines) > 1 && count($lines) <= $maxlines) {
                    $lines = self::balance($textfont, $px, (string)$item['text'], $textwidth, count($lines));
                }
                $fits = self::width($labelfont, $px, $label) <= $labelwidth && count($lines) <= $maxlines;
                if (!$fits && !$force) {
                    return null;
                }
                if (self::width($labelfont, $px, $label) > $labelwidth) {
                    $label = self::ellipsis($labelfont, $px, $label, $labelwidth);
                }
                if (count($lines) > $maxlines) {
                    $lines = array_slice($lines, 0, $maxlines);
                    $lines[$maxlines - 1] = self::ellipsis($textfont, $px, $lines[$maxlines - 1], $textwidth);
                }
                $height = (max(count($lines), 1) - 1) * $px * $zone['line_height']
                    + ($metrics['ascent'] + $metrics['descent']) * $px;
                $rows[] = ['label' => $label, 'lines' => $lines ?: [''], 'h' => $height];
                $total += $height;
            }
            $gap = $count > 1 ? min($gapmax * $px, ($zone['h'] - $total) / ($count - 1)) : 0.0;
            if (($count > 1 && $gap < $gapmin * $px) || $total > $zone['h']) {
                if (!$force) {
                    return null;
                }
                $gap = $gapmin * $px;
            }
            return ['rows' => $rows, 'gap' => $gap, 'labelwidth' => $labelwidth];
        };
        $fit = null;
        for ($px = $start; $px >= $floor; $px -= $step) {
            if ($fit = $try($px, false)) {
                break;
            }
        }
        if (!$fit) {
            $px = $floor;
            $fit = $try($px, true);
        }
        $out = [];
        $y = (float)$zone['y'];
        foreach ($fit['rows'] as $i => $row) {
            $delay = $i * ($zone['enter']['stagger'] ?? 0);
            $base = (int)round($y + $metrics['ascent'] * $px);
            if ($row['label'] !== '') {
                $out[] = ['text' => $row['label'], 'x' => $zone['x'], 'y' => $base, 'px' => $px, 'font' => $labelfont,
                    'color' => $colours[$zone['label']['color']], 'enter' => $zone['enter'] ?? null, 'delay' => $delay];
            }
            foreach ($row['lines'] as $k => $line) {
                $out[] = ['text' => $line, 'x' => (int)round($zone['x'] + $fit['labelwidth'] + $zone['gap']),
                    'y' => (int)round($base + $k * $px * $zone['line_height']), 'px' => $px, 'font' => $textfont,
                    'color' => $colours[$zone['text']['color']], 'enter' => $zone['enter'] ?? null, 'delay' => $delay];
            }
            $y += $row['h'] + $fit['gap'];
        }
        return $out;
    }

    /**
     * Every line of a card. A zone that only stands in for a missing image (the title on the cover) is left out when
     * the image is there.
     *
     * @param array $template The template.
     * @param array $data The texts by zone.
     * @param array $colours The colours.
     * @param bool $cover Whether the cover was drawn.
     * @return array
     */
    private static function layout(array $template, array $data, array $colours, bool $cover): array {
        $standins = [];
        foreach ($template['zones'] as $zone) {
            if (isset($zone['fallback']['text_zone'])) {
                $standins[$zone['fallback']['text_zone']] = true;
            }
        }
        $lines = [];
        foreach ($template['zones'] as $zone) {
            if ($zone['type'] === 'list') {
                $lines = array_merge($lines, self::place_list($template, $zone, (array)($data[$zone['id']] ?? []), $colours));
            } else if ($zone['type'] === 'text') {
                $text = (string)($data[$zone['id']] ?? '');
                if (isset($standins[$zone['id']])) {
                    if ($cover) {
                        continue;
                    }
                    $text = (string)($data['title'] ?? '');
                }
                $lines = array_merge($lines, self::place_text($template, $zone, $text, $colours));
            }
        }
        return $lines;
    }

    /**
     * The images of a card: the cover cut from its centre to its zone, or the accent where it is missing; the phase of
     * the Moon, turned in the southern half of the world.
     *
     * @param \GdImage $image The card.
     * @param array $template The template.
     * @param array $data The data.
     * @param array $colours The colours.
     * @return bool Whether a cover was drawn.
     */
    private static function draw_images($image, array $template, array $data, array $colours): bool {
        $cover = false;
        imagealphablending($image, true);
        foreach ($template['zones'] as $zone) {
            if ($zone['type'] !== 'image') {
                continue;
            }
            if ($zone['id'] === 'moon') {
                if (!isset($data['moon']['phase'])) {
                    continue;
                }
                $phase = ((int)$data['moon']['phase']) % $zone['phases'];
                $moon = imagecreatefrompng($template['dir'] . '/' . str_replace('{phase}', (string)$phase, $zone['source']));
                if (!empty($zone['flip_south']) && (float)get_config(self::COMPONENT, 'latitude') < 0) {
                    imageflip($moon, IMG_FLIP_HORIZONTAL);
                }
                imagecopy($image, $moon, $zone['x'], $zone['y'], 0, 0, imagesx($moon), imagesy($moon));
                continue;
            }
            $bytes = empty($data[$zone['id']]) ? null : rich::image((string)$data[$zone['id']]);
            $source = $bytes === null ? false : @imagecreatefromstring($bytes);
            if ($source) {
                $width = imagesx($source);
                $height = imagesy($source);
                $scale = max($zone['w'] / $width, $zone['h'] / $height);
                $cutwidth = (int)round($zone['w'] / $scale);
                $cutheight = (int)round($zone['h'] / $scale);
                imagecopyresampled(
                    $image,
                    $source,
                    $zone['x'],
                    $zone['y'],
                    (int)(($width - $cutwidth) / 2),
                    (int)(($height - $cutheight) / 2),
                    $zone['w'],
                    $zone['h'],
                    $cutwidth,
                    $cutheight
                );
                if (!empty($zone['overlay'])) {
                    $overlay = imagecreatefrompng($template['dir'] . '/' . $zone['overlay']);
                    imagecopy($image, $overlay, $zone['x'], $zone['y'], 0, 0, imagesx($overlay), imagesy($overlay));
                }
                $cover = true;
            } else if (($zone['fallback']['fill'] ?? '') === 'accent') {
                [$r, $g, $b] = $colours['accent'];
                imagefilledrectangle(
                    $image,
                    $zone['x'],
                    $zone['y'],
                    $zone['x'] + $zone['w'] - 1,
                    $zone['y'] + $zone['h'] - 1,
                    imagecolorallocate($image, $r, $g, $b)
                );
            }
        }
        return $cover;
    }

    /**
     * Draws the lines with GD.
     *
     * @param \GdImage $image The card.
     * @param array $lines The lines placed.
     */
    private static function draw_lines($image, array $lines): void {
        imagealphablending($image, true);
        foreach ($lines as $line) {
            [$r, $g, $b] = $line['color'];
            imagettftext(
                $image,
                $line['px'] * self::PT,
                0,
                $line['x'],
                $line['y'],
                imagecolorallocatealpha($image, $r, $g, $b, 0),
                $line['font'],
                $line['text']
            );
        }
    }

    /**
     * How a line comes in, as drawtext expressions, moved by its delay (the rows of a list).
     *
     * @param array|null $enter The entrance of its zone.
     * @param float $delay Its delay.
     * @return array With alpha, x and y.
     */
    private static function entrance(?array $enter, float $delay): array {
        if (!$enter || ($enter['effect'] ?? 'none') === 'none' || !isset($enter['drawtext'])) {
            return ['alpha' => 'clip(({D}-t)/0.50,0,1)', 'x' => '{x}', 'y' => '{y}'];
        }
        $expressions = $enter['drawtext'];
        if ($delay > 0) {
            $from = sprintf('(t-%.2f)', $enter['start']);
            $to = sprintf('(t-%.2f)', $enter['start'] + $delay);
            foreach ($expressions as $name => $value) {
                $expressions[$name] = str_replace($from, $to, $value);
            }
        }
        return $expressions;
    }
}
