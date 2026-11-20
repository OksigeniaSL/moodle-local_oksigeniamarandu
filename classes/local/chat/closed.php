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

namespace local_oksigeniamarandu\local\chat;

use local_oksigeniamarandu\local\calendar;

/**
 * The days the team does not attend at all, from a calendar of the site (an iCal): the events whose name starts with one
 * of its words, such as «Closed» or «Bank holiday». Holidays of the pupils are not closed days unless the calendar says
 * so: an office often works on them. Read twice a day.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class closed {
    /** @var string|null The iCal of the tests. */
    protected static $fortests = null;

    /**
     * The iCal the tests read instead of the one of the site; null to forget it.
     *
     * @param string|null $ical The calendar.
     */
    public static function fake_for_tests(?string $ical): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('Only for the tests');
        }
        self::$fortests = $ical;
    }

    /**
     * The closed days from two months back to a year ahead.
     *
     * @param int|null $now The time, for the tests.
     * @return string[] Y-m-d, in order.
     */
    public static function days(?int $now = null): array {
        $url = trim((string)get_config('local_oksigeniamarandu', 'chatcalendar'));
        if (self::$fortests === null && $url === '') {
            return [];
        }
        $cache = \cache::make('local_oksigeniamarandu', 'chat');
        $key = 'closed_' . sha1($url . '|' . self::words());
        $kept = self::$fortests === null ? $cache->get($key) : false;
        if ($kept !== false && $kept['time'] > time() - 12 * HOURSECS) {
            return $kept['days'];
        }
        $now = $now ?? time();
        $body = self::$fortests ?? calendar::fetch($url);
        if ($body === null) {
            // The calendar did not come: the last days read are better than none.
            return $kept === false ? [] : $kept['days'];
        }
        $days = [];
        foreach (calendar::parse($body, $now - 62 * DAYSECS, $now + 366 * DAYSECS) as $event) {
            if (!self::is_closed($event->name)) {
                continue;
            }
            for ($day = $event->first; $day <= $event->last; $day = date('Y-m-d', strtotime($day . ' +1 day'))) {
                $days[$day] = $day;
            }
        }
        ksort($days);
        $days = array_values($days);
        if (self::$fortests === null) {
            $cache->set($key, ['time' => time(), 'days' => $days]);
        }
        return $days;
    }

    /**
     * The words that make an event a closed day.
     *
     * @return string Separated by commas.
     */
    protected static function words(): string {
        $setting = get_config('local_oksigeniamarandu', 'chatclosedwords');
        return $setting === false || trim((string)$setting) === ''
            ? get_string('chat:closedwordsdefault', 'local_oksigeniamarandu') : (string)$setting;
    }

    /**
     * Whether an event is a closed day: its name starts with one of the words, with any case, accent or sign before.
     *
     * @param string $name The name of the event.
     * @return bool
     */
    public static function is_closed(string $name): bool {
        $plain = self::plain($name);
        foreach (explode(',', self::words()) as $word) {
            $word = self::plain($word);
            if ($word !== '' && strpos($plain, $word) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * A text without accents, case or signs at the start, to compare names.
     *
     * @param string $text The text.
     * @return string
     */
    protected static function plain(string $text): string {
        $text = \core_text::strtolower(trim($text));
        $text = \core_text::specialtoascii($text);
        return preg_replace('/^[^a-z0-9]+/', '', preg_replace('/\s+/', ' ', $text));
    }
}
