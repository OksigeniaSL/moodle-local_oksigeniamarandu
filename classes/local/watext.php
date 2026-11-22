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
 * The text of a post of the channel as WhatsApp writes it: *bold*, _italic_, ~struck~ and `code`, and nothing of the
 * richer marks of Telegram. Pure functions, without the database: what comes in is what goes out.
 *
 * WhatsApp has no way to escape a mark. A mark in the text itself (an asterisk, an underscore, a tilde or a back quote)
 * that could open or close a format, at the edge of a word, is changed for the character that looks like it and
 * formats nothing; between two letters or digits (2*3, file_name) it never formats, so it stays. Links, addresses of
 * email and names after an at sign are never touched.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class watext {
    /** The mark of each format of Telegram that WhatsApp has too. */
    public const MARKS = ['bold' => '*', 'italic' => '_', 'strikethrough' => '~', 'code' => self::QUOTE];

    /** Each mark of WhatsApp, and the character that looks like it and formats nothing. */
    public const SAFE = ['*' => "\u{2217}", '_' => "\u{02CD}", '~' => "\u{223C}", self::QUOTE => "\u{02CB}"];

    /** The back quote, the mark of code. */
    private const QUOTE = "\x60";

    /** What Telegram marks as a piece that must stay as it is: a link, an address, a name, a tag or a number. */
    private const VERBATIM = ['url', 'email', 'mention', 'hashtag', 'cashtag', 'bot_command', 'phone_number'];

    /** Links, addresses of email and names after an at sign, which plain text keeps as they are. */
    private const UNTOUCHED = '~((?:https?://|www\.)[^\s<>"]+|[\w.+-]+@[\w-]+(?:\.[\w-]+)+|@\w+)~iu';

    /**
     * A text with the entities of Telegram (as the Bot API gives them: type, offset and length in UTF-16 code units, and
     * url for a text_link), as WhatsApp writes it. Bold, italic, strikethrough, code and pre take their marks; a link in
     * a word goes after it in brackets; a quote, each line after «> »; everything else (underline, spoiler, custom
     * emoji…) stays as plain text. A format that spans several lines is marked line by line, and the spaces at its
     * edges go outside its marks, or WhatsApp would not format it. Entities that overlap without one inside the other
     * are left out.
     *
     * @param string $text The text.
     * @param array $entities The entities.
     * @return string
     */
    public static function from_entities(string $text, array $entities): string {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // Where each code unit of UTF-16 falls among the characters.
        $index = [];
        $unit = 0;
        foreach ($chars as $i => $char) {
            $index[$unit] = $i;
            $unit += strlen($char) === 4 ? 2 : 1;
        }
        $index[$unit] = count($chars);
        $at = function (int $offset) use ($index, $unit): int {
            $offset = max(0, min($offset, $unit));
            // Inside a character of two units, at its end.
            while (!isset($index[$offset])) {
                $offset++;
            }
            return $index[$offset];
        };
        $nodes = [];
        foreach ($entities as $entity) {
            $entity = (array)$entity;
            $start = $at((int)($entity['offset'] ?? 0));
            $end = $at((int)($entity['offset'] ?? 0) + (int)($entity['length'] ?? 0));
            if ($end > $start && !empty($entity['type'])) {
                $nodes[] = (object)['type' => (string)$entity['type'], 'start' => $start, 'end' => $end,
                    'url' => (string)($entity['url'] ?? ''), 'children' => []];
            }
        }
        // The outer ones first: by where they start, and the longest first.
        usort($nodes, function (\stdClass $a, \stdClass $b): int {
            return [$a->start, -$a->end] <=> [$b->start, -$b->end];
        });
        $root = (object)['type' => 'root', 'start' => 0, 'end' => count($chars), 'url' => '', 'children' => []];
        $open = [$root];
        foreach ($nodes as $node) {
            while (count($open) > 1 && end($open)->end <= $node->start) {
                array_pop($open);
            }
            $parent = end($open);
            if ($node->end > $parent->end) {
                continue;
            }
            $parent->children[] = $node;
            $open[] = $node;
        }
        return self::render($root, $chars, []);
    }

    /**
     * The text of a post of the channel for WhatsApp: the title in bold, the lines as a list, the text and, at the end,
     * the link of its button after its label, which is how a button of Telegram goes in WhatsApp.
     *
     * @param array $content The content of the post: title, lines and text (as channel::clean() leaves them).
     * @param string $link Where its button goes; empty without a button.
     * @param string $label The label of the button.
     * @return string
     */
    public static function post(array $content, string $link = '', string $label = ''): string {
        $text = '';
        $entities = [];
        $add = function (string $section) use (&$text): int {
            $text .= ($text === '' ? '' : "\n\n");
            $offset = self::units($text);
            $text .= $section;
            return $offset;
        };
        $title = trim((string)($content['title'] ?? ''));
        if ($title !== '') {
            $entities[] = ['type' => 'bold', 'offset' => $add($title), 'length' => self::units($title)];
        }
        $lines = array_filter(array_map('trim', (array)($content['lines'] ?? [])), 'strlen');
        if ($lines) {
            $add(implode("\n", array_map(function (string $line): string {
                return "\u{2022} " . $line;
            }, $lines)));
        }
        $paragraphs = array_filter(array_map('trim', (array)($content['text'] ?? [])), 'strlen');
        if ($paragraphs) {
            $add(implode("\n", $paragraphs));
        }
        if ($link !== '') {
            $before = "\u{1F449} " . ($label !== '' ? $label . ': ' : '');
            $entities[] = ['type' => 'url', 'offset' => $add($before . $link) + self::units($before),
                'length' => self::units($link)];
        }
        return self::from_entities($text, $entities);
    }

    /**
     * Plain text, ready to go inside the marks of WhatsApp: each mark at the edge of a word is changed for the
     * character that looks like it; links, addresses of email and names after an at sign stay as they are.
     *
     * @param string $text The text.
     * @return string
     */
    public static function escape(string $text): string {
        $parts = preg_split(self::UNTOUCHED, $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                // A mark formats nothing between two letters or digits: only one at the edge of a word changes.
                $parts[$i] = preg_replace_callback(
                    '/(?<![\p{L}\p{N}])[*_~\x60]|[*_~\x60](?![\p{L}\p{N}])/u',
                    function ($mark) {
                        return self::SAFE[$mark[0]];
                    },
                    $part
                );
            }
        }
        return implode('', $parts);
    }

    /**
     * A text of WhatsApp as the page shows it before it is shared: escaped for HTML, with its bold, italic, struck and
     * code in their tags, its links that can be followed and its lines.
     *
     * @param string $text The text, with the marks of WhatsApp.
     * @return string HTML.
     */
    public static function html(string $text): string {
        $parts = preg_split(self::UNTOUCHED, $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $html = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                $href = preg_match('~^(?:https?://|www\.)~i', $part) ? (stripos($part, 'www.') === 0 ? 'https://' : '') . $part
                    : '';
                $html .= $href === '' ? s($part) : '<a href="' . s($href) . '" target="_blank" rel="noopener">' . s($part) . '</a>';
                continue;
            }
            $part = s($part);
            $tags = [self::QUOTE => 'code', '*' => 'strong', '_' => 'em', '~' => 's'];
            foreach ($tags as $mark => $tag) {
                $quoted = preg_quote($mark, '/');
                $part = preg_replace(
                    '/(^|[^\p{L}\p{N}])' . $quoted . '(?=\S)(.+?)(?<=\S)' . $quoted . '(?![\p{L}\p{N}])/mu',
                    '$1<' . $tag . '>$2</' . $tag . '>',
                    $part
                );
            }
            $html .= $part;
        }
        return nl2br($html, false);
    }

    /**
     * How long a text is in code units of UTF-16, as Telegram measures it.
     *
     * @param string $text The text.
     * @return int
     */
    public static function units(string $text): int {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * What a node has, with the marks of its children.
     *
     * @param \stdClass $node The node: start, end and children.
     * @param string[] $chars The characters of the text.
     * @param array $active The formats already open around it.
     * @return string
     */
    private static function render(\stdClass $node, array $chars, array $active): string {
        $out = '';
        $at = $node->start;
        foreach ($node->children as $child) {
            $out .= self::escape(implode('', array_slice($chars, $at, $child->start - $at)));
            $out .= self::piece($child, $chars, $active);
            $at = $child->end;
        }
        return $out . self::escape(implode('', array_slice($chars, $at, $node->end - $at)));
    }

    /**
     * One entity, as WhatsApp writes it.
     *
     * @param \stdClass $node The entity: type, start, end, url and children.
     * @param string[] $chars The characters of the text.
     * @param array $active The formats already open around it.
     * @return string
     */
    private static function piece(\stdClass $node, array $chars, array $active): string {
        $raw = implode('', array_slice($chars, $node->start, $node->end - $node->start));
        if (in_array($node->type, self::VERBATIM, true)) {
            return $raw;
        }
        switch ($node->type) {
            case 'bold':
            case 'italic':
            case 'strikethrough':
                $inner = self::render($node, $chars, $active + [$node->type => true]);
                return isset($active[$node->type]) ? $inner : self::wrap($inner, self::MARKS[$node->type]);
            case 'code':
                return self::wrap(self::escape($raw), self::MARKS['code']);
            case 'pre':
                return str_repeat(self::QUOTE, 3) . self::escape($raw) . str_repeat(self::QUOTE, 3);
            case 'text_link':
                $inner = self::render($node, $chars, $active);
                return $node->url === '' || trim($raw) === $node->url ? $inner : $inner . ' (' . $node->url . ')';
            case 'blockquote':
            case 'expandable_blockquote':
                $lines = explode("\n", self::render($node, $chars, $active));
                return implode("\n", array_map(function (string $line): string {
                    return '> ' . $line;
                }, $lines));
            default:
                return self::render($node, $chars, $active);
        }
    }

    /**
     * A piece between two marks, line by line, with the spaces at its edges outside them.
     *
     * @param string $inner What goes inside.
     * @param string $mark The mark.
     * @return string
     */
    private static function wrap(string $inner, string $mark): string {
        $lines = explode("\n", $inner);
        foreach ($lines as $i => $line) {
            if (trim($line) !== '' && preg_match('/^(\s*)(.*?)(\s*)$/su', $line, $parts)) {
                $lines[$i] = $parts[1] . $mark . $parts[2] . $mark . $parts[3];
            }
        }
        return implode("\n", $lines);
    }
}
