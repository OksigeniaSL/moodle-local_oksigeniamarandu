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
     * by a code), or nothing.
     *
     * @param string $text What the family wrote.
     * @return array|null ['action' => 'optin', 'code' => string|null] or ['action' => 'optout'], or null.
     */
    public static function match(string $text): ?array {
        $words = explode(' ', self::normalise($text));
        if (count($words) === 1 && in_array($words[0], self::list('optoutkeywords'), true)) {
            return ['action' => 'optout'];
        }
        if (count($words) <= 2 && in_array($words[0], self::list('optinkeywords'), true)) {
            return ['action' => 'optin', 'code' => $words[1] ?? null];
        }
        return null;
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
