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
 * A plugin that hears what became of its notices, and is asked about each item of a send to many, for the tests.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class listening_plugin {
    /** @var array What it heard: the notice, the item, the channel, what became of it and why. */
    public static $heard = [];

    /** @var callable|null What it says of each item of a send; null to say nothing of it. */
    public static $item = null;

    /**
     * Forgets everything.
     */
    public static function forget(): void {
        self::$heard = [];
        self::$item = null;
    }
}
