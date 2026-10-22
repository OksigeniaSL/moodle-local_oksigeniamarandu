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
 * A request to the WhatsApp Cloud API that did not go through, with a message the administrator can act on.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_exception extends \moodle_exception {
    /** @var int|null The error code Meta returned, if it returned one. */
    public $metacode;

    /**
     * Builds the exception from a string of the plugin.
     *
     * @param string $errorcode The language string of the plugin that explains the error.
     * @param mixed $a Values for the string.
     * @param int|null $metacode The error code Meta returned, if any.
     * @param string|null $debuginfo What helps a developer, never shown to users.
     */
    public function __construct(string $errorcode, $a = null, ?int $metacode = null, ?string $debuginfo = null) {
        $this->metacode = $metacode;
        parent::__construct($errorcode, 'local_oksigeniamarandu', '', $a, $debuginfo);
    }
}
