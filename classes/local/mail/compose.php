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

use local_oksigeniamarandu\local\cards;

/**
 * The common mail of the tools, filled from the site: its name, its colour and its logo (the brand of the cards), the
 * words that are always the same in the language of the reader, and the pieces a plugin gives (letter, and the
 * templates letter/mail.html.mst and letter/mail.txt.mst). The pictures are kept by what they are, so that a notice of Moodle marked for the
 * way of Marandu, which comes with its HTML only, finds them again when it goes out.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class compose {
    /** The plugin. */
    private const C = 'local_oksigeniamarandu';

    /**
     * A mail from its pieces, in the brand of the site.
     *
     * @param array $pieces heading, and as the template takes them: subject, preheader, hello, blocks, signature
     *     (signer, signrole; the farewell is said by Marandu), contact, reason, unsub (unsuburl; the words are said by
     *     Marandu), hero; and unit, who writes inside the organization («Office»).
     * @param string $lang The language of the reader; that of the site if empty.
     * @param bool $keep Kept, with its pictures, for a notice of Moodle that will carry it (for_notice()).
     * @return array subject, html, text, images (cid, name, mime, data), unsubscribe (its address, or empty) and id
     *     (what finds it again, when kept).
     */
    public static function build(array $pieces, string $lang = '', bool $keep = false): array {
        global $CFG;
        $lang = $lang !== '' ? $lang : (string)$CFG->lang;
        $manager = get_string_manager();
        $say = fn(string $key) => $manager->get_string('mail:letter:' . $key, self::C, null, $lang);
        if (!empty($pieces['signature']['signer'])) {
            $pieces['signature'] += ['signoff' => $say('signoff'), 'signrole' => ''];
        }
        if (!empty($pieces['unsub']['unsuburl'])) {
            $pieces['unsub'] += ['unsubtext' => $say('unsubtext'), 'unsubhint' => $say('unsubhint')];
        }
        $pieces['lang'] = $lang;
        $pieces['dir'] = $manager->get_string('thisdirection', 'langconfig', null, $lang) === 'rtl' ? 'rtl' : 'ltr';
        $built = letter::build(self::brand((string)($pieces['unit'] ?? '')), $pieces);
        $html = self::render('mail.html', $built['context']);
        $text = trim(html_entity_decode(self::render('mail.txt', $built['context']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $images = [];
        foreach ($built['images'] as $image) {
            // Named by what it is: the same picture, the same name, in every mail that carries it.
            $hash = sha1($image['bytes']);
            $html = str_replace('cid:' . $image['cid'] . '"', 'cid:' . $hash . '@marandu"', $html);
            $images[] = ['cid' => $hash . '@marandu', 'name' => $image['name'], 'mime' => $image['mime'],
                'data' => $image['bytes']];
            if ($keep) {
                mailer::keep_file($image['bytes'], $hash . '.' . pathinfo($image['name'], PATHINFO_EXTENSION));
            }
        }
        $id = '';
        if ($keep) {
            $id = sha1($html);
            // Both found again by the hash of the HTML, the one the mark of the notice carries.
            mailer::keep_file($html, $id . '.html', true);
            mailer::keep_file($text, $id . '.txt', true);
        }
        return ['subject' => (string)($pieces['subject'] ?? $pieces['heading']), 'html' => $html, 'text' => $text,
            'images' => $images, 'unsubscribe' => (string)($pieces['unsub']['unsuburl'] ?? ''), 'id' => $id];
    }

    /**
     * Renders a template of the mail (letter/), with the Mustache of Moodle but not its renderer: a mail keeps the
     * attributes of tables that old mail programs read, which the templates of the pages of Moodle may not have.
     *
     * @param string $name mail.html or mail.txt (letter/<name>.mst: not .mustache, which the checks of Moodle take as a
     *     template of a page wherever it is).
     * @param array $context
     * @return string
     */
    private static function render(string $name, array $context): string {
        global $CFG;
        static $engine = null;
        if ($engine === null) {
            $options = ['escape' => fn($value) => s($value)];
            if (class_exists(\Mustache\Engine::class)) {
                $engine = new \Mustache\Engine($options);
            } else {
                if (!class_exists(\Mustache_Engine::class)) {
                    require_once($CFG->libdir . '/mustache/src/Mustache/Autoloader.php');
                    \Mustache_Autoloader::register();
                }
                $engine = new \Mustache_Engine($options);
            }
        }
        return $engine->render((string)file_get_contents(__DIR__ . '/../../../letter/' . $name . '.mst'), $context);
    }

    /**
     * Gives a notice of Moodle its mail in the common look, for when Moodle turns it into a mail: it is kept, and the
     * sender of the notice is marked so that the way of Marandu takes it out with its pictures (Moodle 5.3 and later).
     * What the notice shows in the bell does not change. Without pieces, the mail says the subject of the notice, its
     * text and a button to its page.
     *
     * @param \core\message\message $message Before message_send().
     * @param array $pieces As build() takes them; empty for those of the notice.
     * @param bool $routine Daily and repeated: by the mail of the site once the month is near its quota.
     */
    public static function for_notice(\core\message\message $message, array $pieces = [], bool $routine = false): void {
        $to = is_object($message->userto) ? $message->userto : \core_user::get_user((int)$message->userto);
        $lang = $to && !empty($to->lang) ? (string)$to->lang : '';
        if (!$pieces) {
            $text = trim((string)$message->fullmessage);
            $pieces = self::from_text((string)$message->subject, $text === trim((string)$message->subject) ? '' : $text, '', $lang);
            $url = $message->contexturl;
            if (!empty($url)) {
                $pieces['blocks'][] = ['action' => ['actionurl' => $url instanceof \moodle_url ? $url->out(false) : (string)$url,
                    'actiontext' => (string)($message->contexturlname ?: get_string_manager()->get_string(
                        'mail:letter:open',
                        self::C,
                        null,
                        $lang ?: null
                    ))]];
            }
            $pieces['reason'] = get_string_manager()->get_string(
                'mail:letter:reason:notice',
                self::C,
                cards::org(),
                $lang ?: null
            );
        }
        $built = self::build($pieces, $lang, true);
        $from = $message->userfrom;
        if (!is_object($from)) {
            $from = \core_user::get_user((int)$from) ?: \core_user::get_noreply_user();
        }
        $message->userfrom = hook::sender($from, (string)$message->component, $routine, $built['id']);
    }

    /**
     * A mail of a few lines, as the warnings to whoever administers are written: the subject as its title, a paragraph
     * for each block of lines and, for a line that ends in an address of the site, a button to it.
     *
     * @param string $subject
     * @param string $text
     * @param string $reason Why it arrives.
     * @param string $lang
     * @param string $action What its button says, naming what it does; the words before the address if empty.
     * @return array The pieces.
     */
    public static function from_text(
        string $subject,
        string $text,
        string $reason = '',
        string $lang = '',
        string $action = ''
    ): array {
        global $CFG;
        $blocks = [];
        foreach ($text === '' ? [] : preg_split('/\R{2,}/', trim($text)) as $part) {
            if (preg_match('~^(.*?):?\s*(' . preg_quote($CFG->wwwroot, '~') . '\S*)$~s', trim($part), $match)) {
                $before = rtrim(trim($match[1]), ':');
                // With a button that says what it does, the sentence before the address stays, unless it only pointed
                // to it («To look at it»).
                if ($action !== '' && count(preg_split('/\s+/u', $before)) > 4) {
                    $blocks[] = ['paragraph' => $before];
                }
                $label = $action !== '' ? $action : $before;
                $blocks[] = ['action' => ['actionurl' => $match[2], 'actiontext' => $label !== '' ? $label
                    : get_string_manager()->get_string('mail:letter:open', self::C, null, $lang ?: $CFG->lang)]];
                continue;
            }
            $blocks[] = ['paragraph' => trim($part)];
        }
        // The line a mail program shows before opening it: the first paragraph, which adds to the subject.
        $first = '';
        foreach ($blocks as $block) {
            if (isset($block['paragraph'])) {
                $first = \core_text::substr(preg_replace('/\s+/', ' ', $block['paragraph']), 0, 140);
                break;
            }
        }
        return ['subject' => $subject, 'heading' => $subject, 'preheader' => $first, 'blocks' => $blocks, 'reason' => $reason];
    }

    /**
     * A mail kept with build(), with the pictures it shows.
     *
     * @param string $id
     * @return array|null html, text and images (cid, name, mime, data).
     */
    public static function kept(string $id): ?array {
        if (!preg_match('/^[a-f0-9]{40}$/', $id)) {
            return null;
        }
        $html = mailer::find_file($id . '.html');
        if (!$html) {
            return null;
        }
        $text = mailer::find_file($id . '.txt');
        $images = [];
        preg_match_all('/cid:([a-f0-9]{40})@marandu/', $html['data'], $matches);
        foreach (array_unique($matches[1]) as $hash) {
            $picture = mailer::find_file($hash);
            if ($picture) {
                $images[] = ['cid' => $hash . '@marandu'] + $picture;
            }
        }
        return ['html' => $html['data'], 'text' => $text ? $text['data'] : '', 'images' => $images];
    }

    /**
     * The brand of the site for a mail: the name of its cards, its colour and its logo.
     *
     * @param string $unit Who writes inside the organization.
     * @return array As letter::build() takes it.
     */
    private static function brand(string $unit): array {
        global $CFG;
        $brand = ['name' => cards::org(), 'unit' => $unit, 'site' => (string)parse_url($CFG->wwwroot, PHP_URL_HOST),
            'siteurl' => $CFG->wwwroot, 'colours' => [cards::brand()]];
        if (function_exists('imagecreatefromstring')) {
            // The wide logo when it carries the name, else the compact one, with the name beside it.
            $wide = cards::logo_bytes('logo');
            $size = $wide ? @getimagesizefromstring($wide) : false;
            $aspect = $size && $size[1] > 0 ? $size[0] / $size[1] : 0;
            if ($wide !== null && $aspect >= 1.6 && $aspect <= 4.5) {
                $brand['logo'] = $wide;
                $brand['wordmark'] = true;
            } else if (($compact = cards::logo_bytes('logocompact') ?? $wide) !== null) {
                $brand['logo'] = $compact;
            }
        }
        return $brand;
    }
}
