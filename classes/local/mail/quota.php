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

namespace local_oksigeniamarandu\local\mail;

use local_oksigeniamarandu\local\limits;

/**
 * The mails of the month by Mailjet against the quota of its plan: those of the whole account, as Mailjet counts them
 * once an hour (also what other tools send by it), or those Marandu sent, if they are more. Whoever is told of the limits
 * hears it at the share of the quota from which what is routine goes by the mail of the site, and at the quota.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quota {
    /** The plugin. */
    private const C = 'local_oksigeniamarandu';

    /**
     * The first moment of the month of a time, in the time zone of the site.
     *
     * @param int $now
     * @return int
     */
    public static function month_start(int $now): int {
        $date = new \DateTime('@' . $now);
        $date->setTimezone(\core_date::get_server_timezone_object());
        $date->modify('first day of this month midnight');
        return $date->getTimestamp();
    }

    /**
     * The mails Marandu sent by Mailjet this month.
     *
     * @param int $now
     * @return int
     */
    public static function own(int $now): int {
        global $DB;
        return $DB->count_records_select(
            mailer::TABLE,
            "way = 'mailjet' AND status = 'sent' AND timesent >= :start",
            ['start' => self::month_start($now)]
        );
    }

    /**
     * The mails of the month by Mailjet: what Mailjet counted for the whole account, or what Marandu sent, the more.
     *
     * @param int $now
     * @return int
     */
    public static function used(int $now): int {
        $counted = self::counted($now);
        return max(self::own($now), $counted ? $counted['sent'] : 0);
    }

    /**
     * What Mailjet counted for this month, the last time it was asked.
     *
     * @param int $now
     * @return array|null sent and time.
     */
    public static function counted(int $now): ?array {
        $month = json_decode((string)get_config(self::C, 'mailmonth'), true);
        return is_array($month) && ($month['month'] ?? '') === self::month_key($now) ? $month : null;
    }

    /**
     * Asks Mailjet how many mails the account sent this month.
     *
     * @param int $now
     * @param mailjet_api|null $api
     * @return int|null Null if it did not say.
     */
    public static function refresh(int $now, ?mailjet_api $api = null): ?int {
        $api = $api ?? mailjet_api::from_config();
        if (!$api) {
            return null;
        }
        $sent = $api->sent_between(self::month_start($now), $now);
        if ($sent !== null) {
            set_config('mailmonth', json_encode(['month' => self::month_key($now), 'sent' => $sent, 'time' => $now]), self::C);
        }
        return $sent;
    }

    /**
     * How the month goes, for the page and the warnings.
     *
     * @param int $now
     * @return array used, quota, share (a percentage, or null without a quota), routine (the mails from which what is
     *     routine goes by the mail of the site), counted (what Mailjet said, or null), waiting (what waits for the quota).
     */
    public static function state(int $now): array {
        global $DB;
        $used = self::used($now);
        $quota = settings::quota();
        return [
            'used' => $used,
            'quota' => $quota,
            'share' => $quota ? (int)floor($used * 100 / $quota) : null,
            'routine' => $quota ? (int)ceil($quota * settings::routine_share() / 100) : null,
            'counted' => self::counted($now),
            'waiting' => $DB->count_records(mailer::TABLE, ['status' => 'queued', 'waiting' => 'quota']),
        ];
    }

    /**
     * Tells whoever is told of the limits, once a month each: at the share from which what is routine goes by the mail
     * of the site, and at the quota.
     *
     * @param int $now
     * @return string|null The warning that went, if one did.
     */
    public static function warn(int $now): ?string {
        $state = self::state($now);
        if (!$state['quota'] || !settings::mailjet_chosen()) {
            return null;
        }
        $level = $state['used'] >= $state['quota'] ? 'full' : ($state['used'] >= $state['routine'] ? 'near' : null);
        $month = self::month_key($now);
        $warned = json_decode((string)get_config(self::C, 'mailwarned'), true);
        $warned = is_array($warned) && ($warned['month'] ?? '') === $month ? $warned : ['month' => $month];
        if ($level === null || !empty($warned[$level])) {
            return null;
        }
        $warned[$level] = $now;
        set_config('mailwarned', json_encode($warned), self::C);
        $a = (object)['site' => settings::from()[1], 'used' => $state['used'], 'quota' => $state['quota'],
            'share' => settings::routine_share(), 'waiting' => $state['waiting'],
            'url' => (new \moodle_url('/local/oksigeniamarandu/mail.php'))->out(false)];
        foreach (limits::emails() as $email) {
            mailer::warn(
                $email,
                get_string('mail:quota:' . $level . ':subject', self::C, $a),
                get_string('mail:quota:' . $level . ':body', self::C, $a),
                get_string('mail:action:month', self::C),
                $now
            );
        }
        return $level;
    }

    /**
     * The month of a time, as year and month.
     *
     * @param int $now
     * @return string
     */
    private static function month_key(int $now): string {
        $date = new \DateTime('@' . $now);
        $date->setTimezone(\core_date::get_server_timezone_object());
        return $date->format('Y-m');
    }
}
