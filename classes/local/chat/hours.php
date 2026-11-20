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
 * The hours the team attends, as the inbox of Chatwoot has them (also with its own message out of hours switched off),
 * less the closed days of the calendar of the site: whether it is open now, when it opens, and how long a family has
 * waited counting only those hours, so that what came at night does not look late at the first hour.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hours {
    /** @var bool Whether there are hours at all; without them, always open. */
    protected $enabled;

    /** @var array The closed days, as keys (Y-m-d). */
    protected $closed = [];

    /** @var \DateTimeZone The time zone of the hours. */
    protected $zone;

    /** @var array By day of the week (0 Sunday to 6 Saturday): [open, close] in minutes of the day, or null if closed. */
    protected $week = [];

    /**
     * Hours from the schedule of an inbox of Chatwoot.
     *
     * @param string $timezone Its time zone; the one of the site if empty or unknown.
     * @param array $schedule Its working hours: day_of_week, closed_all_day, open_all_day, open_hour, open_minutes,
     *                        close_hour and close_minutes.
     * @param string[] $closed The closed days (Y-m-d).
     */
    public function __construct(string $timezone, array $schedule, array $closed = []) {
        $this->closed = array_fill_keys($closed, true);
        try {
            $this->zone = new \DateTimeZone($timezone !== '' ? $timezone : \core_date::get_server_timezone());
        } catch (\Exception $e) {
            $this->zone = \core_date::get_server_timezone_object();
        }
        for ($day = 0; $day < 7; $day++) {
            $this->week[$day] = null;
        }
        foreach ($schedule as $row) {
            $day = (int)($row['day_of_week'] ?? -1);
            if ($day < 0 || $day > 6 || !empty($row['closed_all_day'])) {
                continue;
            }
            if (!empty($row['open_all_day'])) {
                $this->week[$day] = [0, 24 * 60];
                continue;
            }
            $open = (int)($row['open_hour'] ?? 0) * 60 + (int)($row['open_minutes'] ?? 0);
            $close = (int)($row['close_hour'] ?? 0) * 60 + (int)($row['close_minutes'] ?? 0);
            if ($close > $open) {
                $this->week[$day] = [$open, $close];
            }
        }
        if (!array_filter($this->week)) {
            // Without a day with hours, every day is open, all of it; only the closed days are not.
            $this->week = array_fill(0, 7, [0, 24 * 60]);
        }
        $always = array_filter($this->week, function ($span) {
            return $span === [0, 24 * 60];
        });
        $this->enabled = count($always) < 7 || $this->closed;
    }

    /**
     * The hours of the inbox the site attends (the first one, if there are several).
     *
     * @return self
     */
    public static function of_site(): self {
        $inboxes = directory::inboxes();
        $inbox = reset($inboxes);
        if (!$inbox) {
            return new self('', [], closed::days());
        }
        return new self($inbox['timezone'], $inbox['hours'], closed::days());
    }

    /**
     * The hours for the page: whether there are any, the time zone and the seven days.
     *
     * @return array enabled, timezone, week (day, open, close, closed, allday) and closed (the closed days, Y-m-d).
     */
    public function export(): array {
        $week = [];
        foreach ($this->week as $day => $span) {
            $week[] = [
                'day' => $day,
                'open' => $span ? sprintf('%02d:%02d', intdiv($span[0], 60), $span[0] % 60) : '',
                'close' => $span ? sprintf('%02d:%02d', intdiv($span[1], 60), $span[1] % 60) : '',
                'closed' => $span === null,
                'allday' => $span !== null && $span[0] === 0 && $span[1] === 24 * 60,
            ];
        }
        $closed = array_keys($this->closed);
        sort($closed);
        return ['enabled' => (bool)$this->enabled, 'timezone' => $this->zone->getName(), 'week' => $week, 'closed' => $closed];
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
        [$day, $minute, $ymd] = $this->local($time);
        $span = isset($this->closed[$ymd]) ? null : $this->week[$day];
        return $span !== null && $minute >= $span[0] && $minute < $span[1];
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
            $day = (int)$date->format('w');
            $span = isset($this->closed[$date->format('Y-m-d')]) ? null : $this->week[$day];
            if ($span !== null) {
                $open = $date->setTime(intdiv($span[0], 60), $span[0] % 60);
                if ($open->getTimestamp() > $time) {
                    return $open->getTimestamp();
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
            $span = isset($this->closed[$date->format('Y-m-d')]) ? null : $this->week[(int)$date->format('w')];
            if ($span !== null) {
                $start = $date->setTime(intdiv($span[0], 60), $span[0] % 60)->getTimestamp();
                $end = $span[1] === 24 * 60 ? $date->modify('+1 day')->getTimestamp()
                    : $date->setTime(intdiv($span[1], 60), $span[1] % 60)->getTimestamp();
                $total += max(0, min($end, $to) - max($start, $from));
            }
            $date = $date->modify('+1 day')->setTime(0, 0);
        }
        return $total;
    }

    /**
     * The day of the week, the minute of the day and the date of a moment, in the zone of the hours.
     *
     * @param int $time The moment.
     * @return array [day, minute, Y-m-d].
     */
    protected function local(int $time): array {
        $date = (new \DateTimeImmutable('@' . $time))->setTimezone($this->zone);
        return [(int)$date->format('w'), (int)$date->format('G') * 60 + (int)$date->format('i'), $date->format('Y-m-d')];
    }
}
