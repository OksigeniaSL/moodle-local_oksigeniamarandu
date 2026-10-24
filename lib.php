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

/**
 * Callbacks of local_oksigeniamarandu.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The notices of this plugin, declared as any other plugin declares its own (see \local_oksigeniamarandu\local\notices).
 *
 * The general notice: «you have a new notice from the site», with its subject and a button to read it. Any plugin can
 * use it while it has no notices of its own, and so will the channel for the notifications of Moodle.
 *
 * @return array
 */
function local_oksigeniamarandu_oksigeniamarandu_notices(): array {
    return [
        'notification' => [
            'examples' => ['Colegio Ejemplo', 'Reunión de familias el jueves a las 17:00'],
            'perweek' => 1,
        ],
    ];
}
