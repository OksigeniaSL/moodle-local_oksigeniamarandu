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
 * Phone numbers in the shape the WhatsApp Cloud API takes: the country code and the number, digits only.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class phone {
    /**
     * Turns a number as people write it into digits with the country code, or null if it cannot be one.
     *
     * The number must start with + or 00 and the country code: nothing is guessed from the country of the site, so a
     * number without it never reaches somebody else abroad. Spaces, dots, dashes, slashes and brackets are dropped.
     *
     * @param string $raw The number as typed.
     * @return string|null From 8 to 15 digits (E.164), or null.
     */
    public static function normalise(string $raw): ?string {
        $clean = preg_replace('/[\s.\-\/()]/', '', trim($raw));
        if (strpos($clean, '+') === 0) {
            $clean = substr($clean, 1);
        } else if (strpos($clean, '00') === 0) {
            $clean = substr($clean, 2);
        } else {
            return null;
        }
        if (!preg_match('/^[1-9][0-9]{7,14}$/', $clean)) {
            return null;
        }
        return $clean;
    }
}
