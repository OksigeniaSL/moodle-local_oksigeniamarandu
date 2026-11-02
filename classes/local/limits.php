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

    /**
     * Saves what the form sent.
     *
     * @param bool $money Whether the form had the money of the month (only with WhatsApp).
     * @return array The errors, by field; empty if saved.
     */
    public static function save(bool $money): array {
        $component = 'local_oksigeniamarandu';
        if ($money) {
            $email = trim(optional_param('capemail', '', PARAM_RAW_TRIMMED));
            if ($email !== '' && !validate_email($email)) {
                return ['capemail' => get_string('wizard:limits:bademail', $component)];
            }
            set_config(
                'capmonth',
                max(0, unformat_float(optional_param('capmonth', '0', PARAM_RAW_TRIMMED), true) ?: 0),
                $component
            );
            set_config('capemail', $email, $component);
        }
        set_config('capfamily', max(0, optional_param('capfamily', 0, PARAM_INT)), $component);
        foreach (['quietfrom', 'quietto'] as $name) {
            $time = optional_param($name, '', PARAM_RAW_TRIMMED);
            set_config($name, preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time) ? $time : '', $component);
        }
        $days = array_keys(array_filter(optional_param_array('quietdays', [], PARAM_INT)));
        set_config('quietdays', implode(',', array_intersect($days, range(0, 6))), $component);
        // The notices come as values: Moodle drops the keys of an array that have a slash, as "component/notice" has.
        $urgent = optional_param_array('urgent', [], PARAM_RAW_TRIMMED);
        set_config('urgentnotices', implode(',', array_intersect($urgent, self::switchedon())), $component);
        return [];
    }

    /**
     * What the form shows.
     *
     * @param bool $money Whether to ask for the money of the month (only with WhatsApp).
     * @param array $errors The errors of a save, by field.
     * @return array The context of local_oksigeniamarandu/limits_form.
     */
    public static function context(bool $money, array $errors = []): array {
        global $CFG;
        $component = 'local_oksigeniamarandu';
        $config = get_config($component);
        $days = array_filter(explode(',', (string)($config->quietdays ?? self::DEFAULTS['quietdays'])), 'strlen');
        $urgent = notices::urgent();
        $all = notices::all();
        $daynames = [];
        foreach ([1, 2, 3, 4, 5, 6, 0] as $day) {
            $daynames[] = [
                'value' => $day,
                'label' => get_string('wizard:limits:day' . $day, $component),
                'name' => get_string(self::DAYS[$day], 'calendar'),
                'on' => in_array((string)$day, $days, true),
            ];
        }
        $urgentrows = [];
        $askedfor = [];
        foreach (self::switchedon() as $key) {
            if ($all[$key]->askedfor) {
                $askedfor[] = notices::text($all[$key]);
                continue;
            }
            $urgentrows[] = ['key' => $key, 'name' => notices::text($all[$key]), 'on' => in_array($key, $urgent, true)];
        }
        return [
            'money' => $money,
            'capmonth' => format_float((float)($config->capmonth ?? 10), 0),
            'capemail' => $config->capemail ?? ($CFG->supportemail ?? ''),
            'capfamily' => (int)($config->capfamily ?? self::DEFAULTS['capfamily']),
            'quietfrom' => $config->quietfrom ?? self::DEFAULTS['quietfrom'],
            'quietto' => $config->quietto ?? self::DEFAULTS['quietto'],
            'days' => $daynames,
            'urgent' => $urgentrows,
            'hasurgent' => !empty($urgentrows),
            'askedfor' => $askedfor ? get_string('wizard:limits:askedfor', $component, implode(', ', $askedfor)) : '',
            'capemailerror' => $errors['capemail'] ?? '',
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
