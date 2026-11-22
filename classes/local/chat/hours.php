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

/**
 * The hours the team attends: those of the inbox of Chatwoot (also with its own message out of hours switched off), or
 * those of a department that has its own (team_hours), less the closed days of the calendar of the site, and with the
 * days the calendar gives a department other hours. Whether it is open now, when it opens, and how long a family has
 * waited counting only those hours, so that what came at night does not look late at the first hour.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hours {
    /** A whole day, in minutes. */
    private const DAY = 24 * 60;

    /** @var bool Whether there are hours at all; without them, always open. */
    protected $enabled;

    /** @var array The closed days, as keys (Y-m-d). */
    protected $closed = [];

    /** @var array The days with hours of their own (Y-m-d): their spans, none if closed that day. */
    protected $special = [];

    /** @var \DateTimeZone The time zone of the hours. */
    protected $zone;

    /** @var array By day of the week (0 Sunday to 6 Saturday): its spans [open, close] in minutes, none if closed. */
    protected $week = [];

    /** @var bool Whether they are the hours of a department with something of its own: its week, or days of the calendar. */
    public $own = false;

    /**
     * Hours from the schedule of an inbox of Chatwoot, or from a week of their own.
     *
     * @param string $timezone Its time zone; the one of the site if empty or unknown.
     * @param array $schedule Its working hours: day_of_week, closed_all_day, open_all_day, open_hour, open_minutes,
     *                        close_hour and close_minutes.
     * @param string[] $closed The closed days (Y-m-d).
     * @param array $special The days with hours of their own, over the closed days: by Y-m-d, the spans [open, close] in
     *                       minutes, or none if closed that day.
     * @param array|null $week A week of its own instead of the schedule: by day of the week, its spans in minutes.
     */
    public function __construct(
        string $timezone,
        array $schedule,
        array $closed = [],
        array $special = [],
        ?array $week = null
    ) {
        $this->closed = array_fill_keys($closed, true);
        try {
            $this->zone = new \DateTimeZone($timezone !== '' ? $timezone : \core_date::get_server_timezone());
        } catch (\Exception $e) {
            $this->zone = \core_date::get_server_timezone_object();
        }
        for ($day = 0; $day < 7; $day++) {
            $this->week[$day] = $week === null ? [] : self::clean($week[$day] ?? []);
        }
        foreach ($week === null ? $schedule : [] as $row) {
            $day = (int)($row['day_of_week'] ?? -1);
            if ($day < 0 || $day > 6 || !empty($row['closed_all_day'])) {
                continue;
            }
            if (!empty($row['open_all_day'])) {
                $this->week[$day] = [[0, self::DAY]];
                continue;
            }
            $open = (int)($row['open_hour'] ?? 0) * 60 + (int)($row['open_minutes'] ?? 0);
            $close = (int)($row['close_hour'] ?? 0) * 60 + (int)($row['close_minutes'] ?? 0);
            if ($close > $open) {
                $this->week[$day] = [[$open, min(self::DAY, $close)]];
            }
        }
        if (!array_filter($this->week)) {
            // Without a day with hours, every day is open, all of it; only the closed days are not.
            $this->week = array_fill(0, 7, [[0, self::DAY]]);
        }
        foreach ($special as $ymd => $spans) {
            $this->special[(string)$ymd] = self::clean(is_array($spans) ? $spans : []);
        }
        $always = array_filter($this->week, function ($spans) {
            return $spans === [[0, self::DAY]];
        });
        $this->enabled = count($always) < 7 || $this->closed || $this->special;
    }

    /**
     * The hours of the inbox the site attends (the first one, if there are several).
     *
     * @param int|null $now The time the calendar is read around, for the tests.
     * @return self
     */
    public static function of_site(?int $now = null): self {
        $inboxes = directory::inboxes();
        $inbox = reset($inboxes);
        if (!$inbox) {
            return new self('', [], closed::days($now));
        }
        return new self($inbox['timezone'], $inbox['hours'], closed::days($now));
    }

    /**
     * The hours of a department: its own week if it has one, or those of the inbox; the closed days of all, and the
     * days the calendar closes it or gives it other hours. Those of the inbox for no department, or one not known.
     *
     * @param int $teamid The department, or 0.
     * @param int|null $now The time the calendar is read around, for the tests.
     * @return self
     */
    public static function of_team(int $teamid, ?int $now = null): self {
        if ($teamid <= 0) {
            return self::of_site($now);
        }
        return self::by_team($now)[$teamid] ?? self::of_site($now);
    }

    /**
     * The hours of every department of the account.
     *
     * @param int|null $now The time the calendar is read around, for the tests.
     * @return self[] By id of department.
     */
    public static function by_team(?int $now = null): array {
        $inboxes = directory::inboxes();
        $inbox = reset($inboxes);
        $timezone = $inbox ? (string)$inbox['timezone'] : '';
        $schedule = $inbox ? (array)$inbox['hours'] : [];
        $closed = closed::days($now);
        $marks = closed::teams($now);
        $weeks = team_hours::get();
        $all = [];
        foreach (directory::teams() as $id => $team) {
            $special = [];
            $shut = [];
            foreach (team_hours::names_of($team) as $name) {
                $special = array_merge($special, $marks[$name]['hours'] ?? []);
                $shut = array_merge($shut, $marks[$name]['closed'] ?? []);
            }
            // Closed that day wins over other hours that day; either wins over the closed days of all.
            foreach ($shut as $ymd) {
                $special[$ymd] = [];
            }
            $hours = new self($timezone, $schedule, $closed, $special, $weeks[$id] ?? null);
            $hours->own = isset($weeks[$id]) || $special;
            $all[(int)$id] = $hours;
        }
        return $all;
    }

    /**
     * The hours of the departments a person attends, for what is said of them as a whole («out of hours»): theirs;
     * without any, every department and the inbox for whoever sees everything, the inbox for the rest.
     *
     * @param viewer $viewer The person.
     * @param int|null $now The time the calendar is read around, for the tests.
     * @return self[]
     */
    public static function of_viewer(viewer $viewer, ?int $now = null): array {
        $byteam = self::by_team($now);
        $mine = array_values(array_intersect_key($byteam, array_flip($viewer->teams)));
        if ($mine) {
            return $mine;
        }
        $site = self::of_site($now);
        return $viewer->seesall ? array_merge([$site], array_values($byteam)) : [$site];
    }

    /**
     * Whether a group of hours is closed now, all of them, and when the first opens.
     *
     * @param self[] $list The hours.
     * @param int $now The time.
     * @return int 0 if one of them is open now; otherwise when the first one opens, or -1 if none ever does.
     */
    public static function first_opening(array $list, int $now): int {
        $first = -1;
        foreach ($list as $hours) {
            if ($hours->is_open($now)) {
                return 0;
            }
            $next = $hours->next_open($now);
            if ($next && ($first < 0 || $next < $first)) {
                $first = $next;
            }
        }
        return $first;
    }

    /**
     * The departments as the bot of the menu tells the families: whether each one attends now, and if not, when it
     * opens, in the language of the site.
     *
     * @param int|null $now The time, for the tests.
     * @return array Each with id, name, internal, open, opensat (0 if open, or if it never opens) and opensattext.
     */
    public static function teams_now(?int $now = null): array {
        global $CFG;
        $byteam = self::by_team($now);
        $now = $now ?? time();
        $list = [];
        foreach (directory::teams() as $id => $team) {
            $hours = $byteam[(int)$id] ?? self::of_site($now);
            $open = $hours->is_open($now);
            $opens = $open ? 0 : $hours->next_open($now);
            $list[] = [
                'id' => (int)$id,
                'name' => (string)$team['name'],
                'internal' => !empty($team['internal']),
                'open' => $open,
                'opensat' => $opens,
                'opensattext' => $opens ? $hours->say_when($opens, $now, (string)$CFG->lang) : '',
            ];
        }
        usort($list, function ($a, $b) {
            return strcmp(closed::plain($a['name']), closed::plain($b['name']));
        });
        return $list;
    }

    /**
     * The hours for the page: whether there are any, the time zone, the seven days and the days apart.
     *
     * @return array enabled, timezone, week (day, open, close, closed, allday and spans, each with open and close; open
     *               and close are the first opening and the last closing of the day), closed (the days closed, Y-m-d) and
     *               special (the days with hours of their own: day, Y-m-d, and spans).
     */
    public function export(): array {
        $week = [];
        foreach ($this->week as $day => $spans) {
            $week[] = [
                'day' => $day,
                'open' => $spans ? self::time_text($spans[0][0]) : '',
                'close' => $spans ? self::time_text($spans[count($spans) - 1][1]) : '',
                'closed' => !$spans,
                'allday' => $spans === [[0, self::DAY]],
                'spans' => self::spans_text($spans),
            ];
        }
        $closed = array_keys($this->closed);
        $special = [];
        foreach ($this->special as $ymd => $spans) {
            if ($spans) {
                $special[] = ['day' => (string)$ymd, 'spans' => self::spans_text($spans)];
            } else {
                $closed[] = (string)$ymd;
            }
        }
        $closed = array_values(array_unique(array_diff($closed, array_column($special, 'day'))));
        sort($closed);
        usort($special, function ($a, $b) {
            return strcmp($a['day'], $b['day']);
        });
        return ['enabled' => (bool)$this->enabled, 'timezone' => $this->zone->getName(), 'week' => $week, 'closed' => $closed,
            'special' => $special];
    }

    /**
     * The week as the command line says it: «Mon-Fri 07:30-15:30».
     *
     * @return string
     */
    public function describe(): string {
        return $this->enabled ? team_hours::describe($this->week) : 'always open';
    }

    /**
     * The days ahead that are closed, or have hours of their own, from a day on.
     *
     * @param string $from The first day, Y-m-d.
     * @return array closed (how many days are closed) and special (Y-m-d => «08:00-12:00»).
     */
    public function days_ahead(string $from): array {
        $closed = [];
        foreach (array_keys($this->closed) as $ymd) {
            if ((string)$ymd >= $from && !array_key_exists($ymd, $this->special)) {
                $closed[] = (string)$ymd;
            }
        }
        $special = [];
        foreach ($this->special as $ymd => $spans) {
            if ((string)$ymd < $from) {
                continue;
            }
            if ($spans) {
                $special[(string)$ymd] = implode(',', array_map([team_hours::class, 'span_text'], $spans));
            } else {
                $closed[] = (string)$ymd;
            }
        }
        ksort($special);
        return ['closed' => count(array_unique($closed)), 'special' => $special];
    }

    /**
     * When the team starts a day: its opening, or 8:00 if that day has no hours of its own (all day, or no hours at all).
     *
     * @param string $ymd The day, Y-m-d.
     * @return int The moment; 0 if the day is not a date.
     */
    public function opening(string $ymd): int {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, $this->zone);
        if (!$date || $date->format('Y-m-d') !== $ymd) {
            return 0;
        }
        $spans = $this->spans_of($date);
        $minute = $spans && $spans[0][0] > 0 ? $spans[0][0] : 8 * 60;
        return $date->setTime(intdiv($minute, 60), $minute % 60)->getTimestamp();
    }

    /**
     * Whether the team attends at that moment.
     *
     * @param int $time The moment.
     * @return bool
     */
    public function is_open(int $time): bool {
        if (!$this->enabled) {
            return true;
        }
        $date = (new \DateTimeImmutable('@' . $time))->setTimezone($this->zone);
        $minute = (int)$date->format('G') * 60 + (int)$date->format('i');
        foreach ($this->spans_of($date) as [$open, $close]) {
            if ($minute >= $open && $minute < $close) {
                return true;
            }
        }
        return false;
    }

    /**
     * When it opens next, from that moment; the moment itself if it is open.
     *
     * @param int $time The moment.
     * @return int The moment it opens, or 0 if it never does.
     */
    public function next_open(int $time): int {
        if ($this->is_open($time)) {
            return $time;
        }
        $date = (new \DateTimeImmutable('@' . $time))->setTimezone($this->zone);
        // Closed days can make a long run, like a summer: a few months at most.
        for ($i = 0; $i < 120; $i++) {
            foreach ($this->spans_of($date) as [$open]) {
                $start = $date->setTime(intdiv($open, 60), $open % 60)->getTimestamp();
                if ($start > $time) {
                    return $start;
                }
            }
            $date = $date->modify('+1 day')->setTime(0, 0);
        }
        return 0;
    }

    /**
     * How long it has been, counting only the hours the team attends.
     *
     * @param int $from Since when.
     * @param int $to Until when.
     * @return int Seconds.
     */
    public function waited(int $from, int $to): int {
        if ($to <= $from) {
            return 0;
        }
        if (!$this->enabled) {
            return $to - $from;
        }
        $total = 0;
        $date = (new \DateTimeImmutable('@' . $from))->setTimezone($this->zone)->setTime(0, 0);
        // Two months back at most: older waits are long anyway.
        for ($i = 0; $i < 60 && $date->getTimestamp() < $to; $i++) {
            foreach ($this->spans_of($date) as [$open, $close]) {
                $start = $date->setTime(intdiv($open, 60), $open % 60)->getTimestamp();
                $end = $close === self::DAY ? $date->modify('+1 day')->getTimestamp()
                    : $date->setTime(intdiv($close, 60), $close % 60)->getTimestamp();
                $total += max(0, min($end, $to) - max($start, $from));
            }
            $date = $date->modify('+1 day')->setTime(0, 0);
        }
        return $total;
    }

    /**
     * When the team opens, as it is said, in the time zone of the hours: «today at 16:00», «tomorrow at 7:30», «on
     * Monday at 9:00», or the date beyond a week.
     *
     * @param int $time When it opens.
     * @param int $now Now.
     * @param string $lang The language; the current one if empty.
     * @return string
     */
    public function say_when(int $time, int $now, string $lang = ''): string {
        $previous = $lang !== '' ? force_current_language($lang) : null;
        try {
            $component = 'local_oksigeniamarandu';
            $date = (new \DateTimeImmutable('@' . $time))->setTimezone($this->zone);
            $today = (new \DateTimeImmutable('@' . $now))->setTimezone($this->zone)->setTime(0, 0);
            $clock = $date->format(get_string('chat:when:timeformat', $component));
            $ymd = $date->format('Y-m-d');
            if ($ymd === $today->format('Y-m-d')) {
                return get_string('chat:when:today', $component, $clock);
            }
            if ($ymd === $today->modify('+1 day')->format('Y-m-d')) {
                return get_string('chat:when:tomorrow', $component, $clock);
            }
            $near = $ymd < $today->modify('+7 days')->format('Y-m-d');
            return get_string('chat:when:day', $component, (object)[
                'day' => userdate($time, $near ? '%A' : get_string('chat:when:dateformat', $component), $this->zone->getName()),
                'time' => $clock,
            ]);
        } finally {
            if ($previous !== null) {
                force_current_language($previous);
            }
        }
    }

    /**
     * The spans of a day: those it has of its own, none if it is closed, or those of its day of the week.
     *
     * @param \DateTimeImmutable $date The day, in the zone of the hours.
     * @return array The spans [open, close] in minutes.
     */
    protected function spans_of(\DateTimeImmutable $date): array {
        $ymd = $date->format('Y-m-d');
        if (array_key_exists($ymd, $this->special)) {
            return $this->special[$ymd];
        }
        return isset($this->closed[$ymd]) ? [] : $this->week[(int)$date->format('w')];
    }

    /**
     * Spans in order, inside the day, each of some length.
     *
     * @param array $spans The spans [open, close] in minutes.
     * @return array
     */
    private static function clean(array $spans): array {
        $clean = [];
        foreach ($spans as $span) {
            $open = max(0, (int)($span[0] ?? 0));
            $close = min(self::DAY, (int)($span[1] ?? 0));
            if ($close > $open) {
                $clean[] = [$open, $close];
            }
        }
        usort($clean, function ($a, $b) {
            return $a[0] <=> $b[0];
        });
        return $clean;
    }

    /**
     * Spans for the page.
     *
     * @param array $spans The spans [open, close] in minutes.
     * @return array Each with open and close, «HH:MM» (the end of the day, «24:00»).
     */
    private static function spans_text(array $spans): array {
        return array_map(function ($span) {
            return ['open' => self::time_text($span[0]), 'close' => self::time_text($span[1])];
        }, $spans);
    }

    /**
     * A minute of the day as «HH:MM».
     *
     * @param int $minute The minute.
     * @return string
     */
    private static function time_text(int $minute): string {
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }
}
