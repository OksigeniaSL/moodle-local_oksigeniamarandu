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
 * The week of each department that does not keep the hours of the inbox: a canteen does not open when the office does.
 * Written as people write it, one department after another:
 *
 *     Canteen=mon-fri 7:30-15:30; Admissions=mon-fri 9:00-14:00,16:00-18:00; Office=mon-thu 8:00-14:00, fri 8:00-13:00
 *
 * A department by its name in Chatwoot (or the name it is shown with) or its number; days by their names in English,
 * Spanish, Portuguese or Arabic, whole or short, alone or as a range; then the hours of those days, one span or several.
 * The days not written are closed. Kept as minutes of the day, by department and day of the week (0 Sunday to 6 Saturday).
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class team_hours {
    /** The setting with the weeks, as JSON. */
    public const SETTING = 'chatteamhours';

    /** The names of the days, without accents or capitals, by day of the week (0 Sunday). */
    private const DAYS = [
        0 => ['sun', 'sunday', 'dom', 'domingo'],
        1 => ['mon', 'monday', 'lun', 'lunes', 'seg', 'segunda'],
        2 => ['tue', 'tues', 'tuesday', 'mar', 'martes', 'ter', 'terca'],
        3 => ['wed', 'wednesday', 'mie', 'miercoles', 'qua', 'quarta'],
        4 => ['thu', 'thur', 'thurs', 'thursday', 'jue', 'jueves', 'qui', 'quinta'],
        5 => ['fri', 'friday', 'vie', 'viernes', 'sex', 'sexta'],
        6 => ['sat', 'saturday', 'sab', 'sabado'],
    ];

    /** The names of the days in Arabic, without the article and with a plain alef, by day of the week. */
    private const ARABIC = [
        0 => 'احد',
        1 => 'اثنين',
        2 => 'ثلاثاء',
        3 => 'اربعاء',
        4 => 'خميس',
        5 => 'جمعة',
        6 => 'سبت',
    ];

    /** The short names of the days for the command line, by day of the week. */
    private const SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    /**
     * The weeks of the departments that have their own.
     *
     * @return array By id of department: by day of the week, the spans [open, close] in minutes of the day.
     */
    public static function get(): array {
        $kept = json_decode((string)get_config('local_oksigeniamarandu', self::SETTING), true);
        if (!is_array($kept)) {
            return [];
        }
        $weeks = [];
        foreach ($kept as $teamid => $week) {
            if ((int)$teamid <= 0 || !is_array($week)) {
                continue;
            }
            $clean = [];
            for ($day = 0; $day < 7; $day++) {
                $clean[$day] = [];
                foreach (is_array($week[$day] ?? null) ? $week[$day] : [] as $span) {
                    if (is_array($span) && count($span) === 2 && (int)$span[0] < (int)$span[1]) {
                        $clean[$day][] = [max(0, (int)$span[0]), min(24 * 60, (int)$span[1])];
                    }
                }
            }
            $weeks[(int)$teamid] = $clean;
        }
        return $weeks;
    }

    /**
     * Sets the weeks of the departments, all at once: those not written keep the hours of the inbox.
     *
     * @param string $text As the command line takes it; empty for none.
     * @return array By id of department, its week.
     * @throws \invalid_parameter_exception If it cannot be read or names a department the account does not have.
     */
    public static function set(string $text): array {
        if (trim($text) === '') {
            set_config(self::SETTING, '', 'local_oksigeniamarandu');
            return [];
        }
        $teams = directory::teams();
        $weeks = [];
        foreach (self::parse($text) as [$name, $week]) {
            $id = self::find($name, $teams);
            if ($id === null) {
                throw new \invalid_parameter_exception('no department called «' . $name . '»; there are: ' .
                    implode(', ', array_column($teams, 'name')));
            }
            if (isset($weeks[$id])) {
                throw new \invalid_parameter_exception('«' . $name . '» is written twice');
            }
            $weeks[$id] = $week;
        }
        ksort($weeks);
        set_config(self::SETTING, json_encode($weeks), 'local_oksigeniamarandu');
        return $weeks;
    }

    /**
     * The departments and their weeks, as written.
     *
     * @param string $text Departments separated by «;» or new lines, each «name=days hours».
     * @return array Each [name, week].
     * @throws \invalid_parameter_exception If something cannot be read.
     */
    public static function parse(string $text): array {
        $all = [];
        foreach (preg_split('/[;\r\n]+/u', $text) as $part) {
            if (trim($part) === '') {
                continue;
            }
            $at = strpos($part, '=');
            $name = $at === false ? '' : trim(substr($part, 0, $at));
            if ($name === '') {
                throw new \invalid_parameter_exception('«' . trim($part) . '» is not «department=days hours»');
            }
            try {
                $all[] = [$name, self::parse_week(substr($part, $at + 1))];
            } catch (\invalid_parameter_exception $e) {
                throw new \invalid_parameter_exception($name . ': ' . $e->debuginfo);
            }
        }
        if (!$all) {
            throw new \invalid_parameter_exception('no department');
        }
        return $all;
    }

    /**
     * One week, as written: days, then their hours, and again.
     *
     * @param string $text Such as «mon-thu 8:00-14:00, fri 8:00-13:00».
     * @return array By day of the week (0 Sunday), the spans [open, close] in minutes, in order.
     * @throws \invalid_parameter_exception If something cannot be read.
     */
    public static function parse_week(string $text): array {
        $week = array_fill(0, 7, []);
        $days = [];
        $spans = [];
        $close = function () use (&$days, &$spans, &$week) {
            foreach ($days as $day) {
                $week[$day] = array_merge($week[$day], $spans);
            }
        };
        $tokens = self::tokens($text);
        if (!$tokens) {
            throw new \invalid_parameter_exception('no days and hours');
        }
        foreach ($tokens as $token) {
            $span = self::span($token);
            if ($span !== null) {
                if (!$days) {
                    throw new \invalid_parameter_exception('the hours «' . $token . '» have no days before them');
                }
                $spans[] = $span;
                continue;
            }
            $these = self::days($token);
            if ($these === null) {
                throw new \invalid_parameter_exception('«' . $token . '» is neither a day nor hours (such as mon-fri 9:00-14:00)');
            }
            if ($spans) {
                // Days after hours: the days before are done.
                $close();
                $days = [];
                $spans = [];
            }
            $days = array_values(array_unique(array_merge($days, $these)));
        }
        if (!$spans) {
            throw new \invalid_parameter_exception('the days «' . end($tokens) . '» have no hours after them');
        }
        $close();
        foreach ($week as $day => $list) {
            usort($list, function ($a, $b) {
                return $a[0] <=> $b[0];
            });
            for ($i = 1; $i < count($list); $i++) {
                if ($list[$i][0] < $list[$i - 1][1]) {
                    throw new \invalid_parameter_exception('the hours of ' . self::SHORT[$day] . ' overlap');
                }
            }
            $week[$day] = $list;
        }
        return $week;
    }

    /**
     * The spans of hours a text starts with, such as the name of an event after «Office:».
     *
     * @param string $text Such as «8:00-12:00» or «9:00-14:00 and 16:00-18:00 (meeting)».
     * @return array|null The spans [open, close] in minutes, in order; null if it does not start with hours, or they overlap.
     */
    public static function parse_spans(string $text): ?array {
        $spans = [];
        foreach (self::tokens($text) as $token) {
            $span = self::span($token);
            if ($span === null) {
                break;
            }
            $spans[] = $span;
        }
        if (!$spans) {
            return null;
        }
        usort($spans, function ($a, $b) {
            return $a[0] <=> $b[0];
        });
        for ($i = 1; $i < count($spans); $i++) {
            if ($spans[$i][0] < $spans[$i - 1][1]) {
                return null;
            }
        }
        return $spans;
    }

    /**
     * The department a name or a number stands for.
     *
     * @param string $given The name (as in Chatwoot or as shown, without minding accents or capitals) or the number.
     * @param array $teams The departments, by id.
     * @return int|null
     */
    public static function find(string $given, array $teams): ?int {
        $given = trim($given);
        if (preg_match('/^\d+$/', $given) && isset($teams[(int)$given])) {
            return (int)$given;
        }
        $plain = closed::plain($given);
        foreach ($teams as $id => $team) {
            if ($plain !== '' && in_array($plain, self::names_of($team), true)) {
                return (int)$id;
            }
        }
        return null;
    }

    /**
     * The names a department answers to, without accents or capitals: the one in Chatwoot and the one it is shown with.
     *
     * @param array $team The department, as the directory gives it.
     * @return string[]
     */
    public static function names_of(array $team): array {
        return array_values(array_unique(array_filter([
            closed::plain((string)($team['name'] ?? '')),
            closed::plain((string)($team['raw'] ?? '')),
        ], 'strlen')));
    }

    /**
     * A week as the command line says it: «Mon-Fri 07:30-15:30; Sat 09:00-13:00».
     *
     * @param array $week By day of the week, its spans in minutes.
     * @return string
     */
    public static function describe(array $week): string {
        $parts = [];
        $order = [1, 2, 3, 4, 5, 6, 0];
        for ($i = 0; $i < 7; $i++) {
            $spans = $week[$order[$i]] ?? [];
            if (!$spans) {
                continue;
            }
            $j = $i;
            while ($j + 1 < 7 && ($week[$order[$j + 1]] ?? []) === $spans) {
                $j++;
            }
            $days = self::SHORT[$order[$i]] . ($j > $i ? '-' . self::SHORT[$order[$j]] : '');
            $parts[] = $days . ' ' . implode(',', array_map([self::class, 'span_text'], $spans));
            $i = $j;
        }
        return $parts ? implode('; ', $parts) : 'closed every day';
    }

    /**
     * A span as people read it.
     *
     * @param array $span [open, close] in minutes.
     * @return string Such as «07:30-15:30».
     */
    public static function span_text(array $span): string {
        return sprintf('%02d:%02d-%02d:%02d', intdiv($span[0], 60), $span[0] % 60, intdiv($span[1], 60), $span[1] % 60);
    }

    /**
     * The words of a week: days or ranges of days, and spans of hours.
     *
     * @param string $text The text.
     * @return string[]
     */
    private static function tokens(string $text): array {
        $text = \core_text::strtolower(trim($text));
        // Dashes of any kind; the Portuguese «-feira» of the days; spaces around a dash; «and» between spans.
        $text = str_replace(["\u{2013}", "\u{2014}", "\u{2212}"], '-', $text);
        $text = preg_replace('/-feira\b/u', '', $text);
        $text = preg_replace('/\s*-\s*/u', '-', $text);
        $text = preg_replace('/\s+(?:y|and|e|و)\s+/u', ',', $text);
        return array_values(array_filter(preg_split('/[\s,]+/u', $text), 'strlen'));
    }

    /**
     * A span of hours: «8:00-14:00», «8-14», «8.30-14.00», «8h-14h»; a day ends at 24:00.
     *
     * @param string $token The word.
     * @return array|null [open, close] in minutes, or null if it is not one.
     */
    private static function span(string $token): ?array {
        if (!preg_match('/^(\d{1,2})(?:[:.h](\d{2}))?h?-(\d{1,2})(?:[:.h](\d{2}))?h?$/u', $token, $m)) {
            return null;
        }
        $open = (int)$m[1] * 60 + (int)($m[2] ?? 0);
        $close = (int)$m[3] * 60 + (int)($m[4] ?? 0);
        if ((int)($m[2] ?? 0) > 59 || (int)($m[4] ?? 0) > 59 || $open >= 24 * 60 || $close > 24 * 60 || $close <= $open) {
            return null;
        }
        return [$open, $close];
    }

    /**
     * A day or a range of days: «mon», «lunes», «seg», «mon-fri», «vie-lun» (round the weekend).
     *
     * @param string $token The word.
     * @return int[]|null The days of the week (0 Sunday), or null if it is not one.
     */
    private static function days(string $token): ?array {
        $ends = explode('-', $token);
        if (count($ends) > 2) {
            return null;
        }
        $first = self::day($ends[0]);
        $last = count($ends) === 2 ? self::day($ends[1]) : $first;
        if ($first === null || $last === null) {
            return null;
        }
        $days = [$first];
        for ($day = $first; $day !== $last;) {
            $day = ($day + 1) % 7;
            $days[] = $day;
        }
        return $days;
    }

    /**
     * One day by its name.
     *
     * @param string $word The name, whole or short, in any of the languages.
     * @return int|null The day of the week (0 Sunday), or null.
     */
    private static function day(string $word): ?int {
        $arabic = preg_replace('/^ال/u', '', str_replace(['أ', 'إ', 'آ'], 'ا', trim($word)));
        $found = array_search($arabic, self::ARABIC, true);
        if ($found !== false) {
            return (int)$found;
        }
        $plain = rtrim(\core_text::specialtoascii(\core_text::strtolower(trim($word))), '.');
        foreach (self::DAYS as $day => $names) {
            if (in_array($plain, $names, true)) {
                return $day;
            }
        }
        return null;
    }
}
