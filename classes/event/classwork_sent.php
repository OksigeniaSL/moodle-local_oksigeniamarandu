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

namespace local_oksigeniamarandu\event;

/**
 * Whoever teaches a course sent a piece of work of the class to the review of the channel: the log says who and from
 * which course; the post does not.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class classwork_sent extends \core\event\base {
    /**
     * What it is.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_oksigeniamarandu_post';
    }

    /**
     * Its name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:classworksent', 'local_oksigeniamarandu');
    }

    /**
     * What happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' sent the work of class with the post id '{$this->objectid}' to the review "
            . "of the channel, from the course with id '{$this->courseid}'.";
    }

    /**
     * Where to see it.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/oksigeniamarandu/channel.php', [], 'mrd-review');
    }

    /**
     * How its object is restored.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'local_oksigeniamarandu_post', 'restore' => \core\event\base::NOT_MAPPED];
    }
}
