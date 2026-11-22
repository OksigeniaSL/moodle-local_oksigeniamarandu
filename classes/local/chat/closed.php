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
 * so: an office often works on them. An event that starts with the name of a department and a colon is only for it:
 * «Canteen: closed» closes that department that day, and «Office: 8:00-12:00» gives it other hours that day. Read twice
 * a day.
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
        return self::read($now)['days'];
    }

    /**
     * What the calendar says of each department, by its name without accents or capitals: the days it is closed, and
     * the days it has other hours.
     *
     * @param int|null $now The time, for the tests.
     * @return array By name: closed (Y-m-d, in order) and hours (by Y-m-d, the spans [open, close] in minutes).
     */
    public static function teams(?int $now = null): array {
        return self::read($now)['teams'];
    }

    /**
     * The calendar, read: from the cache if it was read less than half a day ago.
     *
     * @param int|null $now The time, for the tests.
     * @return array days and teams.
     */
    protected static function read(?int $now): array {
        $url = trim((string)get_config('local_oksigeniamarandu', 'chatcalendar'));
        if (self::$fortests === null && $url === '') {
            return ['days' => [], 'teams' => []];
        }
        $cache = \cache::make('local_oksigeniamarandu', 'chatlong');
        $key = 'closedteams_' . sha1($url . '|' . self::words());
        $kept = self::$fortests === null ? $cache->get($key) : false;
        if ($kept !== false && $kept['time'] > time() - 12 * HOURSECS) {
            return $kept['read'];
        }
        $now = $now ?? time();
        $body = self::$fortests ?? calendar::fetch($url);
        if ($body === null) {
            // The calendar did not come: the last days read are better than none, and it is not asked for again in
            // the next half hour.
            $read = $kept === false ? ['days' => [], 'teams' => []] : $kept['read'];
            if (self::$fortests === null) {
                $cache->set($key, ['time' => time() - 12 * HOURSECS + 30 * MINSECS, 'read' => $read]);
            }
            return $read;
        }
        $days = [];
        $teams = [];
        foreach (calendar::parse($body, $now - 62 * DAYSECS, $now + 366 * DAYSECS) as $event) {
            // A closed day for all; or one only for a department, which leaves the rest as they are.
            $all = self::is_closed($event->name);
            $mark = $all ? null : self::team_mark($event->name);
            if (!$all && $mark === null) {
                continue;
            }
            for ($day = $event->first; $day <= $event->last; $day = date('Y-m-d', strtotime($day . ' +1 day'))) {
                if ($mark === null) {
                    $days[$day] = $day;
                } else if ($mark[1] === null) {
                    $teams[$mark[0]]['closed'][$day] = $day;
                } else {
                    $teams[$mark[0]]['hours'][$day] = $mark[1];
                }
            }
        }
        ksort($days);
        foreach ($teams as $name => $marks) {
            $closed = $marks['closed'] ?? [];
            ksort($closed);
            $hours = $marks['hours'] ?? [];
            ksort($hours);
            $teams[$name] = ['closed' => array_values($closed), 'hours' => $hours];
        }
        ksort($teams);
        $read = ['days' => array_values($days), 'teams' => $teams];
        if (self::$fortests === null) {
            $cache->set($key, ['time' => time(), 'read' => $read]);
        }
        return $read;
    }

    /**
     * Whether an event is only for a department: its name, a colon, and a word of the closed days or the hours of that
     * day («Canteen: closed», «Office: 8:00-12:00»). Which departments there are is not known here: any name will do.
     *
     * @param string $name The name of the event.
     * @return array|null [the name of the department without accents or capitals, null if closed or its spans], or null.
     */
    public static function team_mark(string $name): ?array {
        $at = strpos($name, ':');
        if ($at === false) {
            return null;
        }
        $team = self::plain(substr($name, 0, $at));
        $rest = trim(substr($name, $at + 1));
        if ($team === '' || $rest === '') {
            return null;
        }
        if (self::is_closed($rest)) {
            return [$team, null];
        }
        $spans = team_hours::parse_spans($rest);
        return $spans === null ? null : [$team, $spans];
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
    public static function plain(string $text): string {
        $text = \core_text::strtolower(trim($text));
        $text = \core_text::specialtoascii($text);
        return preg_replace('/^[^a-z0-9]+/', '', preg_replace('/\s+/', ' ', $text));
    }
}
