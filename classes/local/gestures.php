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
 * The gestures of the settings without the look of a form (Jujol): an hour as a plate with half an hour less and more,
 * a number with one less and one more, and where a span of the day falls on the ruler of the 24 hours. The page of
 * the channel and the quiet hours and limits use them (templates channel_plate, channel_stepper and channel_day).
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gestures {
    /**
     * The minutes of an hour written «08:30».
     *
     * @param string $time
     * @return int
     */
    public static function minutes(string $time): int {
        return (int)substr($time, 0, 2) * 60 + (int)substr($time, 3, 2);
    }

    /**
     * An hour written «08:30», from its minutes.
     *
     * @param int $minutes
     * @return string
     */
    public static function clock(int $minutes): string {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * An hour as a plate: the hour, and half an hour less and more, never past midnight.
     *
     * @param string $name The setting.
     * @param string $value The hour it has.
     * @param string $label What it is, for a screen reader.
     * @param bool $caption Whether the label also goes above the plate.
     * @return array The context of local_oksigeniamarandu/channel_plate.
     */
    public static function plate(string $name, string $value, string $label, bool $caption = false): array {
        $at = self::minutes($value);
        return ['name' => $name, 'value' => $value, 'label' => $label, 'caption' => $caption ? $label : '',
            'less' => self::clock(max(0, intdiv($at - 1, 30) * 30)), 'noless' => $at === 0,
            'more' => self::clock($at >= 1410 ? 1439 : intdiv($at, 30) * 30 + 30), 'nomore' => $at === 1439];
    }

    /**
     * A number with one less and one more (or a step of more), from 0, where 0 is without a limit.
     *
     * @param string $name The setting.
     * @param int $value The number it has.
     * @param int $most The largest it may be.
     * @param string $unit What it counts.
     * @param int $step How much one touch changes it.
     * @return array The context of local_oksigeniamarandu/channel_stepper.
     */
    public static function stepper(string $name, int $value, int $most, string $unit, int $step = 1): array {
        return ['name' => $name, 'value' => $value, 'most' => $most, 'unit' => $unit,
            'less' => max(0, $value - $step), 'noless' => $value <= 0, 'more' => min($most, $value + $step),
            'nomore' => $value >= $most, 'nocap' => $value === 0];
    }

    /**
     * Where a span of the day falls on the ruler, in hundredths of the day; past midnight, in two.
     *
     * @param string $from
     * @param string $to
     * @return array[] Each with a (where it starts) and w (how wide).
     */
    public static function spans(string $from, string $to): array {
        [$a, $b] = [self::minutes($from), self::minutes($to)];
        $list = $a < $b ? [[$a, $b]] : ($a > $b ? [[$a, 1440], [0, $b]] : [[0, 1440]]);
        return array_map(function (array $span): array {
            return ['a' => round($span[0] / 14.4, 3), 'w' => round(($span[1] - $span[0]) / 14.4, 3)];
        }, $list);
    }

    /**
     * The hours written on the ruler, every three; those between the sixes go away on a phone.
     *
     * @return array[] Each with x (where), label and odd.
     */
    public static function marks(): array {
        return array_map(function (int $hour): array {
            return ['x' => round($hour / 0.24, 3), 'label' => $hour, 'odd' => $hour % 6 !== 0];
        }, range(0, 24, 3));
    }
}
