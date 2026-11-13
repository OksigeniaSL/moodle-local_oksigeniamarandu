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
 * The limits of the site, for every channel: the quiet hours and days, the notices a family gets a day at most, the
 * notices that go out in the quiet hours too, and, with WhatsApp, the money of a month. The wizard of WhatsApp and the
 * page of its own both use them.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class limits {
    /** What a site has until it says otherwise: no notice at night or at the weekend, three a day at most. */
    public const DEFAULTS = ['capfamily' => 3, 'quietfrom' => '20:00', 'quietto' => '08:00', 'quietdays' => '6,0'];

    /** The most notices a day a site may set for each person. */
    public const MOSTADAY = 20;

    /** The names of the days in the strings of the calendar, from Sunday. */
    private const DAYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    /**
     * Gives a site the defaults it has not set.
     */
    public static function defaults(): void {
        foreach (self::DEFAULTS as $name => $value) {
            if (get_config('local_oksigeniamarandu', $name) === false) {
                set_config($name, $value, 'local_oksigeniamarandu');
            }
        }
    }

    /** @var string[] The cards of the page, each saved on its own (Jujol): the quiet, the notices a day, the urgent, the money. */
    public const PARTS = ['quiet', 'perday', 'urgent', 'money'];

    /**
     * Saves what a card of the page sent, and only that: what does not come stays as it was. A day or a switch carries
     * what it has now as a hidden field and, after it, a button with the same name and the value it changes to; PHP keeps
     * the last one.
     *
     * @param bool $money Whether the page has the money of the month (only with WhatsApp).
     * @return array The errors, by field; empty if saved.
     */
    public static function save(bool $money): array {
        $component = 'local_oksigeniamarandu';
        $posted = function (string $name): ?string {
            return optional_param($name, null, PARAM_RAW_TRIMMED);
        };
        if ($money) {
            $email = $posted('capemail');
            if ($email !== null && $email !== '' && !validate_email($email)) {
                return ['capemail' => get_string('wizard:limits:bademail', $component)];
            }
            if ($posted('capmonth') !== null) {
                set_config('capmonth', max(0, unformat_float($posted('capmonth'), true) ?: 0), $component);
            }
            if ($email !== null) {
                set_config('capemail', $email, $component);
            }
        }
        if ($posted('capfamily') !== null) {
            set_config('capfamily', max(0, min(self::MOSTADAY, optional_param('capfamily', 0, PARAM_INT))), $component);
        }
        foreach (['quietfrom', 'quietto'] as $name) {
            $time = $posted($name);
            if ($time !== null && preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
                set_config($name, $time, $component);
            }
        }
        $days = optional_param_array('quietdays', null, PARAM_INT);
        if ($days !== null) {
            set_config('quietdays', implode(',', array_intersect(array_keys(array_filter($days)), range(0, 6))), $component);
        }
        // The notices come as values, each in its place: Moodle drops the keys of an array that have a slash, as
        // "component/notice" has; an empty value is one switched off.
        $urgent = optional_param_array('urgent', null, PARAM_RAW_TRIMMED);
        if ($urgent !== null) {
            set_config('urgentnotices', implode(',', array_intersect($urgent, self::switchedon())), $component);
        }
        return [];
    }

    /**
     * What the page shows: the week with its quiet, the notices a day, what does not wait and, with WhatsApp, the money.
     *
     * @param bool $money Whether to ask for the money of the month (only with WhatsApp).
     * @param array $errors The errors of a save, by field.
     * @param string $saved The card just saved, to come back with its «Saved».
     * @param \moodle_url|null $formurl Where the form goes: each card's buttons go there with their part, to come back to it.
     * @return array The context of local_oksigeniamarandu/limits_form.
     */
    public static function context(bool $money, array $errors = [], string $saved = '', ?\moodle_url $formurl = null): array {
        global $CFG;
        $component = 'local_oksigeniamarandu';
        $config = get_config($component);
        $days = array_filter(explode(',', (string)($config->quietdays ?? self::DEFAULTS['quietdays'])), 'strlen');
        $from = $config->quietfrom ?? self::DEFAULTS['quietfrom'];
        $to = $config->quietto ?? self::DEFAULTS['quietto'];
        $urgent = notices::urgent();
        $all = notices::all();
        // The week: a ruler of the 24 hours for each day, the quiet hours dark as the night; a day quiet all day, dark
        // all day. The name of each day is the switch that quiets it whole.
        $week = [];
        foreach ([1, 2, 3, 4, 5, 6, 0] as $day) {
            $allday = in_array((string)$day, $days, true);
            $week[] = [
                'group' => 'quiet',
                'value' => $day,
                'label' => get_string('wizard:limits:day' . $day, $component),
                'name' => get_string(self::DAYS[$day], 'calendar'),
                'on' => $allday,
                'spans' => $allday ? [['a' => 0, 'w' => 100]] : ($from === $to ? [] : gestures::spans($from, $to)),
            ];
        }
        $urgentrows = [];
        $askedfor = [];
        foreach (self::switchedon() as $index => $key) {
            if ($all[$key]->askedfor) {
                $askedfor[] = notices::text($all[$key]);
                continue;
            }
            $urgentrows[] = ['index' => $index, 'key' => $key, 'name' => notices::text($all[$key]),
                'on' => in_array($key, $urgent, true)];
        }
        $perday = (int)($config->capfamily ?? self::DEFAULTS['capfamily']);
        $capmonth = (int)round((float)($config->capmonth ?? 10));
        $action = function (string $part) use ($formurl): string {
            return $formurl ? (new \moodle_url($formurl, ['part' => $part]))->out(false) . '#mrd-' . $part : '#mrd-' . $part;
        };
        return [
            'saved' => in_array($saved, self::PARTS, true) ? $saved : '',
            'quietdays' => array_values(array_filter($week, function (array $day): bool {
                return $day['on'];
            })),
            'urgenton' => array_values(array_filter($urgentrows, function (array $row): bool {
                return $row['on'];
            })),
            'quiet' => [
                'saved' => $saved === 'quiet',
                'action' => $action('quiet'),
                'marks' => gestures::marks(),
                'week' => $week,
                'from' => gestures::plate('quietfrom', $from, get_string('limits:quiet:from', $component), true),
                'to' => gestures::plate('quietto', $to, get_string('limits:quiet:to', $component), true),
                'caption' => $from === $to ? get_string('limits:quiet:none', $component)
                    : get_string('limits:quiet:caption', $component, (object)['from' => $from, 'to' => $to]),
            ],
            'perday' => [
                'saved' => $saved === 'perday',
                'action' => $action('perday'),
                'stepper' => gestures::stepper('capfamily', $perday, self::MOSTADAY, get_string('limits:perday:unit', $component)),
                // As many bubbles as notices a day (six at most); without a limit, a phone full of them.
                'bubbles' => array_fill(0, $perday ? min($perday, 6) : 6, ['on' => true]),
                'waits' => $perday > 0,
                'caption' => get_string($perday ? 'limits:perday:caption' : 'limits:perday:nocap', $component),
            ],
            'urgent' => $urgentrows,
            'hasurgent' => !empty($urgentrows),
            'urgentsaved' => $saved === 'urgent',
            'urgentaction' => $action('urgent'),
            'askedfor' => $askedfor ? get_string('wizard:limits:askedfor', $component, implode(', ', $askedfor)) : '',
            'money' => $money ? [
                'saved' => $saved === 'money',
                'action' => $action('money'),
                'stepper' => gestures::stepper('capmonth', $capmonth, 10000, get_string('limits:money:unit', $component), 5),
                'caption' => $capmonth ? get_string('limits:money:caption', $component, $capmonth)
                    : get_string('limits:money:nocap', $component),
                'capemail' => $config->capemail ?? ($CFG->supportemail ?? ''),
                'capemailerror' => $errors['capemail'] ?? '',
                'tope' => drawings::svg('tope', 48),
            ] : false,
        ];
    }

    /**
     * The notices switched on for any channel, in the order of the site.
     *
     * @return string[] "component/notice" keys.
     */
    private static function switchedon(): array {
        $on = array_merge(notices::enabled('whatsapp'), notices::enabled('telegram'));
        return array_values(array_intersect(array_keys(notices::all()), $on));
    }
}
