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
 * The words a family writes to opt in or out, as the site sets them.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class keywords {
    /** Opt-in words when the site sets none. */
    public const DEFAULT_OPTIN = 'ALTA, JOIN';

    /** Opt-out words when the site sets none. */
    public const DEFAULT_OPTOUT = 'BAJA, STOP';

    /** Letters of the codes: no 0, O, 1 or I, which people mix up. */
    public const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Length of the codes. */
    public const CODE_LENGTH = 8;

    /**
     * Upper case, without accents, punctuation or extra spaces, so that «Baja.» and «baja» match «BAJA».
     *
     * @param string $text What the family wrote.
     * @return string
     */
    public static function normalise(string $text): string {
        $text = \core_text::strtoupper(\core_text::specialtoascii(trim($text)));
        $text = preg_replace('/[^A-Z0-9 ]+/', ' ', $text);
        return trim(preg_replace('/ +/', ' ', $text));
    }

    /**
     * The words of a setting, normalised.
     *
     * @param string $name optinkeywords or optoutkeywords.
     * @return string[]
     */
    public static function list(string $name): array {
        $value = get_config('local_oksigeniamarandu', $name);
        if ($value === false || trim($value) === '') {
            $value = $name === 'optinkeywords' ? self::DEFAULT_OPTIN : self::DEFAULT_OPTOUT;
        }
        $words = array_map([self::class, 'normalise'], explode(',', $value));
        return array_values(array_filter($words, function ($word) {
            return $word !== '' && strpos($word, ' ') === false;
        }));
    }

    /**
     * The first word of a setting, the one that goes in links and replies.
     *
     * @param string $name optinkeywords or optoutkeywords.
     * @return string
     */
    public static function first(string $name): string {
        $words = self::list($name);
        return $words[0] ?? ($name === 'optinkeywords' ? 'ALTA' : 'BAJA');
    }

    /**
     * What a message asks for: opt out (the whole message is an opt-out word), opt in (an opt-in word, maybe followed
     * by a code), or nothing: «Alta comedor» is a message like any other, for whoever answers the number.
     *
     * @param string $text What the family wrote.
     * @return array|null ['action' => 'optin', 'code' => string|null] or ['action' => 'optout'], or null.
     */
    public static function match(string $text): ?array {
        $words = explode(' ', self::normalise($text));
        if (count($words) === 1 && in_array($words[0], self::list('optoutkeywords'), true)) {
            return ['action' => 'optout'];
        }
        // With a code of eight, maybe in two halves as a letter may print it: «ALTA K7M2 Q9XA» or «ALTA K7M2-Q9XA».
        if (count($words) <= 3 && in_array($words[0], self::list('optinkeywords'), true)) {
            $code = implode('', array_slice($words, 1));
            if ($code === '' || self::looks_like_code($code)) {
                return ['action' => 'optin', 'code' => $code === '' ? null : $code];
            }
        }
        // A greeting with the code between brackets at its end, as the links write it: «Hola, somos la familia de Leo.
        // (K7M2Q9XA)».
        $letter = '[' . self::CODE_ALPHABET . ']';
        $pattern = '/\(\s*(' . $letter . '{4})[\s-]?(' . $letter . '{4})\s*\)\W*$/u';
        if (preg_match($pattern, \core_text::strtoupper(trim($text)), $parts)) {
            return ['action' => 'optin', 'code' => $parts[1] . $parts[2]];
        }
        return null;
    }

    /**
     * Whether a word may be a code: eight characters, of the letters of the codes, or letters and figures (somebody who
     * wrote a 0 where there was a Q, say, hears that the code does not work).
     *
     * @param string $word The word, normalised.
     * @return bool
     */
    private static function looks_like_code(string $word): bool {
        return strlen($word) === self::CODE_LENGTH
            && (strspn($word, self::CODE_ALPHABET) === self::CODE_LENGTH || preg_match('/[0-9].*[A-Z]|[A-Z].*[0-9]/', $word));
    }

    /**
     * The message that joins with a code, as a link writes it for the person: a greeting and the code between brackets.
     *
     * @param string $code The code.
     * @param string $greeting What the person says, in their words, such as «Hola, somos la familia de Leo.»; the
     *                         greeting of Marandu if empty.
     * @return string
     */
    public static function join_text(string $code, string $greeting = ''): string {
        $greeting = trim(preg_replace('/\s+/u', ' ', $greeting));
        if ($greeting === '') {
            $lang = current_language();
            $greeting = get_string_manager()->get_string('join:greeting', 'local_oksigeniamarandu', confirm::of($lang), $lang);
        }
        return $greeting . ' (' . $code . ')';
    }

    /**
     * A new random code.
     *
     * @return string
     */
    public static function new_code(): string {
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }
        return $code;
    }
}
