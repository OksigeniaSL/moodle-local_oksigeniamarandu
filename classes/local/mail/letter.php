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

namespace local_oksigeniamarandu\local\mail;

/**
 * The common e-mail of the Oksigenia plugins: from the brand of a centre and the pieces of one mail, the context of
 * letter/mail.html.mst and letter/mail.txt.mst, the pictures that go inside the mail and the headers it needs.
 *
 * No dependency on Moodle: PHP 8 with GD. Marandu fills it from the site in api::mail_html().
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class letter {
    /** The display width of the mail, and of a picture inside the letter. */
    public const WIDTH = 600;
    /** The widest a picture inside the letter goes (the width less its margins). */
    public const PICTURE = 520;
    /** The height of the logo's plate in the band, at 1x. */
    public const PLATE = 48;

    /**
     * Everything one mail needs.
     *
     * The brand: name; unit (who writes inside the centre: «Secretaría», «Comedor»); site and siteurl; colours, the
     * colours of the brand with the main one first (one is enough); logo, the bytes of a PNG or JPEG; wordmark, true
     * when the logo already says the name.
     *
     * The mail: lang; dir (ltr or rtl); subject; preheader; heading; hello; blocks (as in mail.mustache; a picture
     * brings picturebytes, picturealt, picturecaption and, if smaller, picturewidth, in place of its cid); signature
     * (signoff, signer, signrole); contact; reason; unsub (unsuburl, unsubtext, unsubhint: only in mails to many);
     * hero (bytes, alt and brand: a picture across the top, over the band; with brand, the picture already carries
     * the brand, and the band is left out); sample.
     *
     * @param array $brand
     * @param array $mail
     * @return array context (for both templates); images (cid, bytes, mime, name: to embed, each with its cid);
     *     headers (name => value: List-Unsubscribe and List-Unsubscribe-Post when the mail has a way out).
     */
    public static function build(array $brand, array $mail): array {
        $name = trim((string)($brand['name'] ?? ''));
        $heading = trim((string)($mail['heading'] ?? ''));
        if ($name === '' || $heading === '') {
            throw new \InvalidArgumentException('A mail needs the name of its centre and a heading.');
        }
        $colours = self::colours((array)($brand['colours'] ?? []));
        $images = [];

        $logo = false;
        if (!empty($brand['logo'])) {
            $plate = self::logo_plate((string)$brand['logo']);
            $images[] = ['cid' => 'logo', 'bytes' => $plate['bytes'], 'mime' => 'image/png', 'name' => 'logo.png'];
            $logo = ['logocid' => 'logo', 'logowidth' => $plate['width'], 'logoheight' => $plate['height'],
                'wordmark' => !empty($brand['wordmark'])];
        }

        $hero = false;
        if (!empty($mail['hero']['bytes'])) {
            [$w, $h, $mime] = self::size((string)$mail['hero']['bytes']);
            $images[] = ['cid' => 'hero', 'bytes' => (string)$mail['hero']['bytes'], 'mime' => $mime,
                'name' => 'hero.' . self::extension($mime)];
            $hero = ['herocid' => 'hero', 'herowidth' => self::WIDTH, 'heroheight' => (int)round(self::WIDTH * $h / $w),
                'heroalt' => (string)($mail['hero']['alt'] ?? $name), 'herobrand' => !empty($mail['hero']['brand'])];
        }

        $blocks = [];
        $n = 0;
        foreach ((array)($mail['blocks'] ?? []) as $block) {
            if (isset($block['picture'])) {
                $p = $block['picture'];
                $bytes = (string)($p['picturebytes'] ?? '');
                if ($bytes === '') {
                    continue;
                }
                [$w, $h, $mime] = self::size($bytes);
                $cid = 'picture' . (++$n);
                $width = min((int)($p['picturewidth'] ?? self::PICTURE), self::PICTURE, $w);
                $images[] = ['cid' => $cid, 'bytes' => $bytes, 'mime' => $mime, 'name' => $cid . '.' . self::extension($mime)];
                $block = ['picture' => ['picturecid' => $cid, 'picturewidth' => $width,
                    'pictureheight' => (int)round($width * $h / $w), 'picturealt' => (string)($p['picturealt'] ?? ''),
                    'picturecaption' => (string)($p['picturecaption'] ?? '')]];
            }
            $blocks[] = $block;
        }

        $rtl = ($mail['dir'] ?? 'ltr') === 'rtl';
        $unsub = !empty($mail['unsub']['unsuburl']) ? $mail['unsub'] : false;
        $context = [
            'lang' => (string)($mail['lang'] ?? 'en'),
            'dir' => $rtl ? 'rtl' : 'ltr',
            'start' => $rtl ? 'right' : 'left',
            'end' => $rtl ? 'left' : 'right',
            'subject' => (string)($mail['subject'] ?? $heading),
            'preheader' => (string)($mail['preheader'] ?? ''),
            'brand' => [
                'name' => $name,
                'unit' => (string)($brand['unit'] ?? ''),
                'site' => (string)($brand['site'] ?? ''),
                'siteurl' => (string)($brand['siteurl'] ?? ''),
            ],
            'logo' => $logo,
            'hero' => $hero,
            'colours' => $colours['colours'],
            'dark' => $colours['dark'],
            'hasstripe' => count($colours['stripe']) > 1,
            'stripe' => $colours['stripe'],
            'stripetop' => $colours['stripetop'],
            'heading' => $heading,
            'hello' => (string)($mail['hello'] ?? ''),
            'blocks' => $blocks,
            'signature' => !empty($mail['signature']['signer']) ? $mail['signature'] : false,
            'contact' => (string)($mail['contact'] ?? ''),
            'reason' => (string)($mail['reason'] ?? ''),
            'unsub' => $unsub,
            'sample' => (string)($mail['sample'] ?? ''),
        ];
        $headers = [];
        if ($unsub) {
            // RFC 8058: the address takes a POST with «List-Unsubscribe=One-Click» and stops the mails, without asking.
            $headers['List-Unsubscribe'] = '<' . $unsub['unsuburl'] . '>';
            $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }
        return ['context' => $context, 'images' => $images, 'headers' => $headers];
    }

    /**
     * The colours of the mail from the colours of the brand, by day and at night.
     *
     * By day the paper is white and the band is the main colour as it is, with white or black letters, whichever reads.
     * The title and the links take the first colour of the brand that reads on the paper (4.5:1), or the main one
     * darkened until it does; the bullets and the rule of a note, the next colour of the brand that shows (3:1). The
     * page around the card is a neutral grey in the elements' own styles (a program that darkens the mail by itself
     * turns a tinted paper into mud) and the wall of the brand, a white with a tenth of its colour, for the programs
     * that say they are light.
     *
     * At night the band, the logo's plate and the stripe stay: they are the brand, and they read on any ground. The paper
     * is a dark with a third of the main colour; the title, a white tinted with it (7:1); the links and the button, the
     * main colour lightened until it shows; a stripe colour that would vanish, lightened until it shows (2:1).
     *
     * @param string[] $palette #rrggbb, the main one first.
     * @return array colours, dark (name => #RRGGBB), stripe and stripetop (n, colour, night).
     */
    public static function colours(array $palette): array {
        $palette = array_values(array_unique(array_filter(array_map([self::class, 'hex'], $palette))));
        if (!$palette) {
            $palette = ['#3A4A5C'];
        }
        $main = $palette[0];
        $others = array_slice($palette, 1);

        // By day.
        $card = '#FFFFFF';
        $soft = self::mix($card, $main, 0.07);
        $grounds = [$card, $soft];
        // A main colour that shows on white (3:1) is darkened a little until its letters read; a pale one (a yellow)
        // would turn to mud, so its titles go in a dark ink with a touch of it, and its links in it, darkened.
        $darkened = self::towards($main, '#000000', $grounds, 4.5);
        $pale = self::contrast($main, $card) < 3;
        $title = self::first($palette, $grounds, 4.5) ?? ($pale ? self::mix('#1C1C21', $main, 0.12) : $darkened);
        $accent = self::first($others, $grounds, 3) ?? $main;
        $ink2 = self::towards(self::mix('#5F6670', $title, 0.12), '#000000', $grounds, 4.5);
        $day = [
            'page' => '#F3F4F6',
            'wall' => self::mix('#FFFFFF', $main, 0.10),
            'card' => $card,
            'band' => $main,
            'bandink' => self::on($main),
            'soft' => $soft,
            'ink' => self::mix('#1C1C21', $title, 0.12),
            'ink2' => $ink2,
            'title' => $title,
            'link' => $pale && !self::first($palette, $grounds, 4.5) ? $darkened : $title,
            'accent' => $accent,
            'button' => $main,
            'buttonink' => self::on($main),
            'line' => self::mix($card, $title, 0.16),
            'border' => self::mix($card, $ink2, 0.45),
        ];

        // At night.
        $deep = self::towards($main, '#000000', ['#FFFFFF'], 7);
        $ncard = self::mix('#17171C', $deep, 0.24);
        $nsoft = self::mix($ncard, '#FFFFFF', 0.06);
        $ngrounds = [$ncard, $nsoft];
        $button = self::button($main, $ncard);
        $nink2 = self::towards(self::mix('#B3B3BB', $deep, 0.10), '#FFFFFF', $ngrounds, 4.5);
        $night = [
            'page' => self::mix('#0C0C10', $deep, 0.18),
            'wall' => self::mix('#0C0C10', $deep, 0.18),
            'card' => $ncard,
            'band' => $main,
            'bandink' => self::on($main),
            'soft' => $nsoft,
            'ink' => self::mix('#F1F1F3', $deep, 0.05),
            'ink2' => $nink2,
            'title' => self::towards(self::mix('#FFFFFF', $main, 0.25), '#FFFFFF', $ngrounds, 7),
            'link' => self::towards($main, '#FFFFFF', $ngrounds, 4.5),
            'accent' => self::towards($accent, '#FFFFFF', $ngrounds, 3),
            'button' => $button,
            'buttonink' => self::on($button),
            'line' => self::mix($ncard, '#FFFFFF', 0.14),
            'border' => self::mix($ncard, $nink2, 0.45),
        ];

        $stripe = [];
        foreach (array_slice($palette, 0, 6) as $i => $colour) {
            $stripe[] = ['n' => $i + 1, 'colour' => $colour, 'night' => self::towards($colour, '#FFFFFF', [$ncard], 2)];
        }
        // Under the band, the stripe without the main colour, which is the band's; at the foot, the whole stripe.
        return ['colours' => $day, 'dark' => $night, 'stripe' => $stripe, 'stripetop' => array_slice($stripe, 1)];
    }

    /**
     * The logo on its white plate with rounded corners, as PNG at twice its size. A dark logo with a transparent ground
     * would vanish in a dark or inverted mail; on its plate it reads on any ground, and an image is never inverted.
     *
     * @param string $bytes PNG, JPEG, GIF or WebP.
     * @param int $height of the plate at 1x.
     * @param int $scale
     * @return array bytes, width and height (at 1x, for the img).
     */
    public static function logo_plate(string $bytes, int $height = self::PLATE, int $scale = 2): array {
        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            throw new \InvalidArgumentException('The logo must be a PNG, JPEG, GIF or WebP.');
        }
        $pad = 7 * $scale;
        $radius = 10 * $scale;
        $h = $height * $scale;
        $lh = $h - 2 * $pad;
        $lw = (int)round(imagesx($src) * $lh / imagesy($src));
        // A wide logo keeps its height up to 260 px of plate; wider, it shrinks.
        $maxw = 260 * $scale - 2 * $pad;
        if ($lw > $maxw) {
            $lh = (int)round($lh * $maxw / $lw);
            $lw = $maxw;
        }
        $w = $lw + 2 * $pad;
        // The plate drawn four times larger and scaled down, so its corners are smooth.
        $ss = 4;
        $big = imagecreatetruecolor($w * $ss, $h * $ss);
        imagealphablending($big, false);
        imagesavealpha($big, true);
        imagefill($big, 0, 0, imagecolorallocatealpha($big, 0, 0, 0, 127));
        $white = imagecolorallocatealpha($big, 255, 255, 255, 0);
        $rr = $radius * $ss;
        imagefilledrectangle($big, $rr, 0, $w * $ss - $rr - 1, $h * $ss - 1, $white);
        imagefilledrectangle($big, 0, $rr, $w * $ss - 1, $h * $ss - $rr - 1, $white);
        $corners = [[$rr, $rr], [$w * $ss - $rr - 1, $rr], [$rr, $h * $ss - $rr - 1], [$w * $ss - $rr - 1, $h * $ss - $rr - 1]];
        foreach ($corners as [$x, $y]) {
            imagefilledellipse($big, $x, $y, 2 * $rr, 2 * $rr, $white);
        }
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagecopyresampled($im, $big, 0, 0, 0, 0, $w, $h, $w * $ss, $h * $ss);
        imagealphablending($im, true);
        imagecopyresampled($im, $src, $pad, (int)round(($h - $lh) / 2), 0, 0, $lw, $lh, imagesx($src), imagesy($src));
        ob_start();
        imagepng($im, null, 9);
        $png = (string)ob_get_clean();
        imagedestroy($im);
        imagedestroy($big);
        imagedestroy($src);
        return ['bytes' => $png, 'width' => (int)round($w / $scale), 'height' => $height];
    }

    /**
     * The contrast between two colours (WCAG 2), from 1 to 21.
     *
     * @param string $a
     * @param string $b
     * @return float
     */
    public static function contrast(string $a, string $b): float {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * White or a near black, whichever reads better on a colour (white when both read).
     *
     * @param string $ground
     * @return string
     */
    public static function on(string $ground): string {
        $white = self::contrast('#FFFFFF', $ground);
        return ($white >= 4.5 || $white >= self::contrast('#16161A', $ground)) ? '#FFFFFF' : '#16161A';
    }

    /**
     * A button at night: the colour as it is if it shows on the ground (3:1), else lightened until it does, and
     * lightened further while its letters would not read (4.5:1).
     *
     * @param string $colour
     * @param string $ground
     * @return string
     */
    private static function button(string $colour, string $ground): string {
        $c = self::towards($colour, '#FFFFFF', [$ground], 3);
        for ($i = 0; $i < 40 && self::contrast(self::on($c), $c) < 4.5; $i++) {
            $c = self::mix($c, '#FFFFFF', 0.05);
        }
        return $c;
    }

    /**
     * The first colour that reads on every ground given.
     *
     * @param string[] $colours
     * @param string[] $grounds
     * @param float $min
     * @return string|null
     */
    private static function first(array $colours, array $grounds, float $min): ?string {
        foreach ($colours as $c) {
            if (self::reads($c, $grounds, $min)) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Whether a colour reads on every ground given.
     *
     * @param string $colour
     * @param string[] $grounds
     * @param float $min
     * @return bool
     */
    private static function reads(string $colour, array $grounds, float $min): bool {
        foreach ($grounds as $g) {
            if (self::contrast($colour, $g) < $min) {
                return false;
            }
        }
        return true;
    }

    /**
     * A colour moved towards a target (black or white) in small steps until it reads on every ground given.
     *
     * @param string $colour
     * @param string $target
     * @param string[] $grounds
     * @param float $min
     * @return string
     */
    private static function towards(string $colour, string $target, array $grounds, float $min): string {
        for ($t = 0.0; $t <= 1.0; $t += 0.02) {
            $c = self::mix($colour, $target, $t);
            if (self::reads($c, $grounds, $min)) {
                return $c;
            }
        }
        return strtoupper($target);
    }

    /**
     * Two colours mixed: $t of the second.
     *
     * @param string $a
     * @param string $b
     * @param float $t
     * @return string
     */
    private static function mix(string $a, string $b, float $t): string {
        [$ar, $ag, $ab] = self::rgb($a);
        [$br, $bg, $bb] = self::rgb($b);
        return sprintf(
            '#%02X%02X%02X',
            (int)round($ar + ($br - $ar) * $t),
            (int)round($ag + ($bg - $ag) * $t),
            (int)round($ab + ($bb - $ab) * $t)
        );
    }

    /**
     * The relative luminance of a colour (WCAG 2).
     *
     * @param string $hex
     * @return float
     */
    private static function luminance(string $hex): float {
        $l = array_map(function (int $v): float {
            $c = $v / 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));
        return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
    }

    /**
     * #rgb or #rrggbb to its three channels.
     *
     * @param string $hex
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
     * A colour as #RRGGBB, or '' when it is not one.
     *
     * @param mixed $colour
     * @return string
     */
    private static function hex($colour): string {
        $colour = ltrim(trim((string)$colour), '#');
        if (!preg_match('/^([0-9a-f]{3}|[0-9a-f]{6})$/i', $colour)) {
            return '';
        }
        [$r, $g, $b] = self::rgb($colour);
        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    /**
     * The size and type of a picture.
     *
     * @param string $bytes
     * @return array width, height, mime
     */
    private static function size(string $bytes): array {
        $info = @getimagesizefromstring($bytes);
        if (!$info || empty($info[0]) || empty($info[1])) {
            throw new \InvalidArgumentException('A picture of the mail must be a PNG, JPEG or GIF.');
        }
        return [(int)$info[0], (int)$info[1], (string)$info['mime']];
    }

    /**
     * The file extension of a picture type.
     *
     * @param string $mime
     * @return string
     */
    private static function extension(string $mime): string {
        return ['image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime] ?? 'png';
    }
}
