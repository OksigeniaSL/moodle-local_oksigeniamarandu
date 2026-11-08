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
 * The calendar of the organization in the channel: «This week» on Monday morning, prepared on Friday, and «No class
 * tomorrow» on the eve of a day without class, with the day classes come back. From any iCal the site gives, read with
 * the parser of Moodle (bennu), and from the events of the site if it says so; nothing goes into the calendar of Moodle.
 *
 * A day without class is an event whose name starts with one of the words of the site («Holiday» counts for «Holiday -
 * All Saints», not for «Infants - Holiday party»), on a day with class.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class calendar {
    /** Days with class (Monday to Friday) and when each post goes, until the site says others. */
    public const DEFAULTS = ['classdays' => '1,2,3,4,5', 'weekhour' => '08:00', 'evehour' => '18:00'];

    /** The kinds of post of the calendar. */
    public const WEEK = 'week';

    /** The eve of a day without class. */
    public const DAYOFF = 'dayoff';

    /** The plugin. */
    private const COMPONENT = 'local_oksigeniamarandu';

    /** How many days ahead it looks for days without class. */
    private const HORIZON = 21;

    /** How long before its eve a «No class tomorrow» is prepared, so that its review falls in the hours of review. */
    private const AHEAD = 4 * DAYSECS;

    /** How long before Monday «This week» is prepared: from Friday. */
    private const WEEKAHEAD = 3 * DAYSECS;

    /** The most repetitions of an event it follows. */
    private const MAXREPEATS = 500;

    /** @var string|null The iCal of the tests. */
    private static $fortests = null;

    /**
     * Whether the site has a calendar for the channel.
     *
     * @return bool
     */
    public static function is_set(): bool {
        return self::$fortests !== null || trim((string)get_config(self::COMPONENT, 'calendarical')) !== ''
            || !empty(get_config(self::COMPONENT, 'calendarsite'));
    }

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
     * Prepares the posts of the calendar: «This week» from Friday, for Monday morning; «No class tomorrow» a few days
     * before its eve; and takes back those still waiting whose day has class again.
     *
     * @param int|null $now The time, for the tests.
     * @return array Counts: week, dayoff and withdrawn.
     */
    public static function prepare(?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        $counts = ['week' => 0, 'dayoff' => 0, 'withdrawn' => 0];
        if (!channel::is_ready() || !self::is_set()) {
            return $counts;
        }
        $today = self::date($now)->setTime(0, 0);
        $monday = clone $today;
        if ((int)$monday->format('N') !== 1) {
            $monday->modify('next monday');
        }
        if (self::at($monday, 'weekhour') <= $now) {
            $monday->modify('+7 days');
        }
        $events = self::events($today->getTimestamp(), $today->getTimestamp() + (self::HORIZON + 8) * DAYSECS);

        if ($now >= $monday->getTimestamp() - self::WEEKAHEAD) {
            $key = self::WEEK . '-' . $monday->format('Ymd');
            $lines = self::week($events, $monday->getTimestamp());
            if ($lines) {
                $link = trim((string)get_config(self::COMPONENT, 'calendarlink'))
                    ?: trim((string)get_config(self::COMPONENT, 'calendarical'));
                $content = ['title' => get_string('channel:cal:weektitle', self::COMPONENT), 'lines' => $lines,
                    'card' => ['type' => 'this_week', 'zones' => [
                        'kicker' => get_string('channel:cal:weekkicker', self::COMPONENT, (object)[
                            'from' => self::day_name($monday->format('Y-m-d'), 'channel:cal:daynumber', false),
                            'to' => self::day_name(
                                self::next_ymd($monday->format('Y-m-d'), 6),
                                'channel:cal:daymonthformat',
                                false
                            ),
                        ]),
                        'title' => get_string('channel:cal:weektitle', self::COMPONENT),
                        'items' => self::week_items($events, $monday->getTimestamp()),
                    ]]];
                if (preg_match('~^https?://~i', $link)) {
                    $content += ['url' => $link, 'label' => get_string('channel:cal:addcalendar', self::COMPONENT),
                        'utm' => false];
                }
                $counts['week'] += (int)(bool)channel::post(
                    self::COMPONENT,
                    self::WEEK,
                    $content,
                    self::at($monday, 'weekhour'),
                    false,
                    $key,
                    $monday->getTimestamp() + DAYSECS,
                    $now
                );
            } else {
                $counts['withdrawn'] += channel::cancel(self::COMPONENT, self::WEEK, $key, true);
            }
        }

        $announce = self::announcements($events, $now);
        foreach ($announce as $ymd => $day) {
            if ($now >= $day->eve || $day->eve - $now > self::AHEAD) {
                continue;
            }
            $back = self::day_name($day->back, 'channel:cal:dateformat', false);
            $counts['dayoff'] += (int)(bool)channel::post(self::COMPONENT, self::DAYOFF, [
                'title' => get_string('channel:cal:dayofftitle', self::COMPONENT),
                'text' => [implode(' · ', $day->names), get_string('channel:cal:back', self::COMPONENT, $back)],
                'card' => ['type' => 'no_class', 'zones' => [
                    'kicker' => self::day_name(self::ymd($day->eve), 'channel:cal:dateformat'),
                    'title' => get_string('channel:cal:dayofftitle', self::COMPONENT),
                    'back' => get_string('channel:cal:backcard', self::COMPONENT, $back),
                    'detail' => implode(' · ', $day->names),
                ]],
            ], $day->eve, false, self::DAYOFF . '-' . str_replace('-', '', $ymd), $day->start, $now);
        }
        $waiting = $DB->get_records_select(
            channel::TABLE,
            "component = :component AND kind = :kind AND status IN ('review', 'ready')",
            ['component' => self::COMPONENT, 'kind' => self::DAYOFF]
        );
        foreach ($waiting as $post) {
            $ymd = preg_replace('/^' . self::DAYOFF . '-(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', $post->sourcekey);
            if (!isset($announce[$ymd]) && $ymd >= $today->format('Y-m-d')) {
                $counts['withdrawn'] += channel::cancel(self::COMPONENT, self::DAYOFF, $post->sourcekey, true);
            }
        }
        return $counts;
    }

    /**
     * The events of the calendar between two times, from its iCal and, if the site says so, its own events.
     *
     * @param int $from From.
     * @param int $to To.
     * @return \stdClass[] Each with name, start, end, allday, first and last (the days it covers, Y-m-d), in order.
     */
    public static function events(int $from, int $to): array {
        $events = [];
        $url = trim((string)get_config(self::COMPONENT, 'calendarical'));
        $body = self::$fortests ?? ($url === '' ? null : self::fetch($url));
        if ($body !== null) {
            $events = self::parse($body, $from, $to);
        }
        if (!empty(get_config(self::COMPONENT, 'calendarsite'))) {
            $events = array_merge($events, self::site_events($from, $to));
        }
        $unique = [];
        foreach ($events as $event) {
            $unique[\core_text::strtolower($event->name) . '|' . $event->start] = $event;
        }
        usort($unique, function ($a, $b) {
            return [$a->start, $a->name] <=> [$b->start, $b->name];
        });
        return array_values($unique);
    }

    /**
     * The events of an iCal between two times, with their repetitions: daily, weekly (also on some days), monthly and
     * yearly, without the dates it leaves out or that another event replaces. Cancelled events do not count.
     *
     * @param string $body The iCal.
     * @param int $from From.
     * @param int $to To.
     * @return \stdClass[] As events() gives them.
     */
    public static function parse(string $body, int $from, int $to): array {
        global $CFG;
        require_once($CFG->libdir . '/bennu/bennu.inc.php');
        $ical = new \iCalendar();
        $ical->unserialize($body);
        $default = self::zone((string)($ical->properties['X-WR-TIMEZONE'][0]->value ?? ''));
        $vevents = $ical->components['VEVENT'] ?? [];
        $replaced = [];
        foreach ($vevents as $vevent) {
            if (!empty($vevent->properties['RECURRENCE-ID'][0])) {
                [$time] = self::time($vevent->properties['RECURRENCE-ID'][0], $default);
                if ($time !== null) {
                    $replaced[(string)($vevent->properties['UID'][0]->value ?? '')][self::ymd($time)] = true;
                }
            }
        }
        $events = [];
        foreach ($vevents as $vevent) {
            $p = $vevent->properties;
            if (empty($p['DTSTART'][0]) || empty($p['SUMMARY'][0])) {
                continue;
            }
            if (strtoupper(trim((string)($p['STATUS'][0]->value ?? ''))) === 'CANCELLED') {
                continue;
            }
            [$start, $allday] = self::time($p['DTSTART'][0], $default);
            if ($start === null) {
                continue;
            }
            $end = null;
            if (!empty($p['DTEND'][0])) {
                [$end] = self::time($p['DTEND'][0], $default);
            } else if (!empty($p['DURATION'][0])) {
                $end = self::add_duration($start, (string)$p['DURATION'][0]->value);
            }
            if ($end === null || $end < $start) {
                $end = $allday ? self::shift($start, 1) : $start;
            }
            $name = self::text((string)$p['SUMMARY'][0]->value);
            if ($name === '') {
                continue;
            }
            $override = !empty($p['RECURRENCE-ID'][0]);
            $skip = $override ? [] : ($replaced[(string)($p['UID'][0]->value ?? '')] ?? []);
            foreach ($p['EXDATE'] ?? [] as $exdate) {
                foreach (explode(',', (string)$exdate->value) as $value) {
                    $one = clone $exdate;
                    $one->value = trim($value);
                    [$time] = self::time($one, $default);
                    if ($time !== null) {
                        $skip[self::ymd($time)] = true;
                    }
                }
            }
            $days = $allday ? max(1, self::days_between($start, $end)) : 0;
            $starts = !$override && !empty($p['RRULE'][0])
                ? self::repeat($start, (string)$p['RRULE'][0]->value, $to)
                : [$start];
            foreach ($starts as $one) {
                if (isset($skip[self::ymd($one)])) {
                    continue;
                }
                $oneend = $allday ? self::shift($one, $days) : $one + ($end - $start);
                if ($one >= $to || ($oneend <= $from && !($oneend === $one && $one >= $from))) {
                    continue;
                }
                $events[] = self::event($name, $one, $oneend, $allday);
            }
        }
        return $events;
    }

    /**
     * The days without class in the events, by day, with the names of their events.
     *
     * @param \stdClass[] $events The events.
     * @return array By day (Y-m-d), the names.
     */
    public static function days_off(array $events): array {
        $off = [];
        foreach ($events as $event) {
            if (!self::is_dayoff($event->name)) {
                continue;
            }
            for ($day = $event->first; $day <= $event->last; $day = self::next_ymd($day)) {
                $off[$day][$event->name] = $event->name;
            }
        }
        return array_map('array_values', $off);
    }

    /**
     * Whether an event is of a day without class: its name starts with one of the words of the site, with any case,
     * accent, sign or emoji before.
     *
     * @param string $name The name of the event.
     * @return bool
     */
    public static function is_dayoff(string $name): bool {
        $setting = get_config(self::COMPONENT, 'calendarkeywords');
        $words = $setting === false ? get_string('channel:calendar:keywordsdefault', self::COMPONENT) : (string)$setting;
        $plain = self::plain($name);
        foreach (explode(',', $words) as $word) {
            $word = self::plain($word);
            if ($word !== '' && strpos($plain, $word) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * The days without class to tell the eve of: the first day of each run of them, with its eve, the day classes come
     * back and the names of its events.
     *
     * @param \stdClass[] $events The events.
     * @param int $now The time.
     * @return \stdClass[] By day (Y-m-d): eve (the time of the post), start (of the day), back (Y-m-d) and names.
     */
    public static function announcements(array $events, int $now): array {
        $off = self::days_off($events);
        $list = [];
        $day = self::ymd($now);
        for ($i = 0; $i <= self::HORIZON; $i++, $day = self::next_ymd($day)) {
            if (!isset($off[$day]) || !self::class_day($day)) {
                continue;
            }
            $before = self::next_ymd($day, -1);
            for ($j = 0; $j < 14 && !self::class_day($before); $j++) {
                $before = self::next_ymd($before, -1);
            }
            if (isset($off[$before])) {
                continue;
            }
            $back = self::next_ymd($day);
            for ($j = 0; $j < 120 && (isset($off[$back]) || !self::class_day($back)); $j++) {
                $back = self::next_ymd($back);
            }
            $date = self::date_of($day);
            $eve = (clone $date)->modify('-1 day');
            $list[$day] = (object)['eve' => self::at($eve, 'evehour'), 'start' => $date->getTimestamp(), 'back' => $back,
                'names' => $off[$day]];
        }
        return $list;
    }

    /**
     * The lines of «This week»: each event from Monday to Sunday, with its day and, if it has one, its time.
     *
     * @param \stdClass[] $events The events.
     * @param int $monday The start of Monday.
     * @return string[]
     */
    public static function week(array $events, int $monday): array {
        $first = self::ymd($monday);
        $last = self::ymd(self::shift($monday, 6));
        usort($events, function ($a, $b) {
            return [$a->start, $a->name] <=> [$b->start, $b->name];
        });
        $lines = [];
        foreach ($events as $event) {
            if ($event->last < $first || $event->first > $last) {
                continue;
            }
            if ($event->first !== $event->last) {
                $when = get_string('channel:cal:range', self::COMPONENT, (object)[
                    'from' => self::day_name($event->first, 'channel:cal:dayformat', false),
                    'to' => self::day_name($event->last, 'channel:cal:dayformat', false),
                ]);
            } else {
                $when = self::day_name($event->first, 'channel:cal:dayformat');
                if (!$event->allday && self::date($event->start)->format('H:i') !== '00:00') {
                    $when .= ', ' . self::date($event->start)->format('H:i');
                }
            }
            $lines[] = self::capital($when) . ' · ' . $event->name;
        }
        return $lines;
    }

    /**
     * The items of the card of «This week»: a short day and what happens, in order; the card shows the first ones.
     *
     * @param \stdClass[] $events The events.
     * @param int $monday The start of Monday.
     * @return array Each with label and text.
     */
    public static function week_items(array $events, int $monday): array {
        $first = self::ymd($monday);
        $last = self::ymd(self::shift($monday, 6));
        usort($events, function ($a, $b) {
            return [$a->start, $a->name] <=> [$b->start, $b->name];
        });
        $items = [];
        foreach ($events as $event) {
            if ($event->last < $first || $event->first > $last) {
                continue;
            }
            $items[] = ['label' => self::day_name(max($event->first, $first), 'channel:cal:shortdayformat'),
                'text' => $event->name];
        }
        return $items;
    }

    /**
     * The events of the site (eventtype site) between two times.
     *
     * @param int $from From.
     * @param int $to To.
     * @return \stdClass[]
     */
    private static function site_events(int $from, int $to): array {
        global $DB;
        $rows = $DB->get_records_select(
            'event',
            "eventtype = 'site' AND visible = 1 AND timestart < :to AND timestart + timeduration >= :from",
            ['from' => $from, 'to' => $to],
            'timestart',
            'id, name, timestart, timeduration'
        );
        $events = [];
        foreach ($rows as $row) {
            $start = (int)$row->timestart;
            $duration = (int)$row->timeduration;
            $midnight = self::date($start)->format('H:i') === '00:00';
            $allday = $midnight && ($duration === 0 || $duration % DAYSECS === 0);
            $end = $allday ? self::shift($start, max(1, intdiv($duration, DAYSECS))) : $start + $duration;
            $name = self::text(strip_tags((string)$row->name));
            if ($name !== '') {
                $events[] = self::event($name, $start, $end, $allday);
            }
        }
        return $events;
    }

    /**
     * An event, with the days it covers.
     *
     * @param string $name Its name.
     * @param int $start Its start.
     * @param int $end Its end (for a day without hours, the start of the day after it).
     * @param bool $allday Whether it has no hours.
     * @return \stdClass
     */
    private static function event(string $name, int $start, int $end, bool $allday): \stdClass {
        return (object)['name' => $name, 'start' => $start, 'end' => $end, 'allday' => $allday, 'first' => self::ymd($start),
            'last' => self::ymd(max($start, $end - 1))];
    }

    /**
     * Downloads the iCal of the site.
     *
     * @param string $url Its address.
     * @return string|null Null if it did not come.
     */
    private static function fetch(string $url): ?string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setopt(['CURLOPT_FOLLOWLOCATION' => 1, 'CURLOPT_MAXREDIRS' => 5, 'CURLOPT_TIMEOUT' => 30]);
        $body = $curl->get($url);
        $code = (int)($curl->get_info()['http_code'] ?? 0);
        if ($curl->get_errno() || $code !== 200 || strpos((string)$body, 'BEGIN:VCALENDAR') === false) {
            debugging('Marandu: the calendar did not come: ' . s($url), DEBUG_DEVELOPER);
            return null;
        }
        return (string)$body;
    }

    /**
     * The time of a date of an iCal: a day (VALUE=DATE), a time in UTC (Z), in its zone (TZID) or in the zone of the
     * calendar.
     *
     * @param object $property The property.
     * @param \DateTimeZone $default The zone of the calendar.
     * @return array The time (null if it cannot be read) and whether it is a day without hours.
     */
    private static function time($property, \DateTimeZone $default): array {
        $value = trim((string)$property->value);
        $params = (array)($property->parameters ?? []);
        if (preg_match('/^(\d{8})$/', $value) || strtoupper((string)($params['VALUE'] ?? '')) === 'DATE') {
            $date = \DateTime::createFromFormat('!Ymd', substr($value, 0, 8), \core_date::get_server_timezone_object());
            return [$date ? $date->getTimestamp() : null, true];
        }
        if (!preg_match('/^(\d{8}T\d{6})(Z?)$/i', $value, $parts)) {
            return [null, false];
        }
        $zone = $parts[2] !== '' ? new \DateTimeZone('UTC') : (isset($params['TZID']) ? self::zone((string)$params['TZID'])
            : $default);
        $date = \DateTime::createFromFormat('Ymd\THis', strtoupper($parts[1]), $zone);
        return [$date ? $date->getTimestamp() : null, false];
    }

    /**
     * The starts of an event that repeats, until a time.
     *
     * @param int $start The first start.
     * @param string $rule Its RRULE.
     * @param int $to Until this time.
     * @return int[]
     */
    private static function repeat(int $start, string $rule, int $to): array {
        $parts = [];
        foreach (explode(';', strtoupper($rule)) as $part) {
            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[$name] = $value;
        }
        $interval = max(1, (int)($parts['INTERVAL'] ?? 1));
        $count = isset($parts['COUNT']) ? max(1, (int)$parts['COUNT']) : null;
        $until = null;
        if (!empty($parts['UNTIL'])) {
            [$until, $day] = self::time((object)['value' => $parts['UNTIL'], 'parameters' => []], new \DateTimeZone('UTC'));
            $until = $until !== null && $day ? self::shift($until, 1) - 1 : $until;
        }
        $step = ['DAILY' => 'day', 'WEEKLY' => 'week', 'MONTHLY' => 'month', 'YEARLY' => 'year'][$parts['FREQ'] ?? ''] ?? null;
        if ($step === null) {
            return [$start];
        }
        $weekdays = [];
        if ($step === 'week' && !empty($parts['BYDAY'])) {
            $map = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
            foreach (explode(',', $parts['BYDAY']) as $byday) {
                if (isset($map[substr(trim($byday), -2)])) {
                    $weekdays[] = $map[substr(trim($byday), -2)];
                }
            }
            sort($weekdays);
        }
        $starts = [];
        $base = self::date($start);
        for ($n = 0; count($starts) < self::MAXREPEATS && $n < self::MAXREPEATS; $n++) {
            $period = (clone $base)->modify('+' . ($n * $interval) . ' ' . $step);
            $candidates = [$period];
            if ($weekdays) {
                $candidates = [];
                $monday = (clone $period)->modify('-' . ((int)$period->format('N') - 1) . ' days');
                foreach ($weekdays as $weekday) {
                    $candidates[] = (clone $monday)->modify('+' . ($weekday - 1) . ' days');
                }
            }
            foreach ($candidates as $candidate) {
                $time = $candidate->getTimestamp();
                if ($time < $start) {
                    continue;
                }
                if (($until !== null && $time > $until) || ($count !== null && count($starts) >= $count) || $time >= $to) {
                    return $starts;
                }
                $starts[] = $time;
            }
        }
        return $starts;
    }

    /**
     * The end of an event from its DURATION.
     *
     * @param int $start Its start.
     * @param string $duration As P1D or PT1H30M.
     * @return int|null
     */
    private static function add_duration(int $start, string $duration): ?int {
        try {
            return self::date($start)->add(new \DateInterval(ltrim(trim($duration), '+')))->getTimestamp();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * A text of an iCal, without its escapes, marks and runs of spaces.
     *
     * @param string $text The text.
     * @return string
     */
    private static function text(string $text): string {
        // As the calendar of Moodle reads them: line breaks as spaces, and no backslash left (bennu doubles them).
        $text = str_replace('\\', '', str_replace(['\\n', '\\N'], ' ', $text));
        return trim(preg_replace('/\s+/u', ' ', strip_tags($text)));
    }

    /**
     * A text to compare words: without what goes before its first letter, in small letters and without accents.
     *
     * @param string $text The text.
     * @return string
     */
    private static function plain(string $text): string {
        $text = preg_replace('/^[^\p{L}\p{N}]+/u', '', trim($text));
        $text = \core_text::strtolower(\core_text::specialtoascii($text));
        return trim(preg_replace('/^[^a-z0-9]+/', '', $text));
    }

    /**
     * Whether a day has class, as the site says.
     *
     * @param string $ymd The day.
     * @return bool
     */
    private static function class_day(string $ymd): bool {
        $setting = get_config(self::COMPONENT, 'classdays');
        $days = explode(',', $setting === false ? self::DEFAULTS['classdays'] : (string)$setting);
        return in_array(self::date_of($ymd)->format('N'), array_map('trim', $days), true);
    }

    /**
     * A time of a day: the hour the site says for weekhour or evehour.
     *
     * @param \DateTime $day The day.
     * @param string $setting weekhour or evehour.
     * @return int
     */
    private static function at(\DateTime $day, string $setting): int {
        $hour = (string)(get_config(self::COMPONENT, $setting) ?: self::DEFAULTS[$setting]);
        [$h, $m] = array_map('intval', array_pad(explode(':', $hour), 2, 0));
        return (clone $day)->setTime($h, $m)->getTimestamp();
    }

    /**
     * The name of a day, as its format string says, with a capital letter or not.
     *
     * @param string $ymd The day.
     * @param string $format The string of its format.
     * @param bool $capital Whether it starts with a capital.
     * @return string
     */
    private static function day_name(string $ymd, string $format, bool $capital = true): string {
        $name = trim(userdate(
            self::date_of($ymd)->getTimestamp(),
            get_string($format, self::COMPONENT),
            \core_date::get_server_timezone()
        ));
        return $capital ? self::capital($name) : $name;
    }

    /**
     * A text with its first letter in capitals.
     *
     * @param string $text The text.
     * @return string
     */
    private static function capital(string $text): string {
        return \core_text::strtoupper(\core_text::substr($text, 0, 1)) . \core_text::substr($text, 1);
    }

    /**
     * The zone of a name, or the one of the site if it does not know it.
     *
     * @param string $name The name.
     * @return \DateTimeZone
     */
    private static function zone(string $name): \DateTimeZone {
        try {
            return $name === '' ? \core_date::get_server_timezone_object()
                : new \DateTimeZone(\core_date::normalise_timezone($name));
        } catch (\Exception $e) {
            return \core_date::get_server_timezone_object();
        }
    }

    /**
     * A time as a date in the zone of the site.
     *
     * @param int $time The time.
     * @return \DateTime
     */
    private static function date(int $time): \DateTime {
        return (new \DateTime('@' . $time))->setTimezone(\core_date::get_server_timezone_object());
    }

    /**
     * The start of a day (Y-m-d) in the zone of the site.
     *
     * @param string $ymd The day.
     * @return \DateTime
     */
    private static function date_of(string $ymd): \DateTime {
        return \DateTime::createFromFormat('!Y-m-d', $ymd, \core_date::get_server_timezone_object());
    }

    /**
     * The day of a time, in the zone of the site.
     *
     * @param int $time The time.
     * @return string Y-m-d.
     */
    private static function ymd(int $time): string {
        return self::date($time)->format('Y-m-d');
    }

    /**
     * The day after or before a day.
     *
     * @param string $ymd The day.
     * @param int $days How many days, back if negative.
     * @return string Y-m-d.
     */
    private static function next_ymd(string $ymd, int $days = 1): string {
        return self::date_of($ymd)->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /**
     * A time some days later, at the same hour of the clock.
     *
     * @param int $time The time.
     * @param int $days The days.
     * @return int
     */
    private static function shift(int $time, int $days): int {
        return self::date($time)->modify('+' . $days . ' days')->getTimestamp();
    }

    /**
     * Whole days between two starts of day.
     *
     * @param int $from From.
     * @param int $to To.
     * @return int
     */
    private static function days_between(int $from, int $to): int {
        return (int)self::date_of(self::ymd($from))->diff(self::date_of(self::ymd($to)))->format('%r%a');
    }
}
