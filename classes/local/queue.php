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
 * The queue of notices: each one waits for its template, the quiet hours and the limits of the site, and then goes out.
 *
 * Inside the 24 hours after a family last wrote, the notice goes as plain text, which is free; outside, with its
 * template, which Meta charges. Nothing goes to a number that has not opted in, nor after the monthly limit.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class queue {
    /** Table of the queue. */
    public const TABLE = 'local_oksigeniamarandu_queue';

    /** What a template costs when the site does not say, in euros: a guess, to be checked with the prices of Meta. */
    public const DEFAULT_COST = 0.02;

    /** Free replies are possible until 24 hours after the family last wrote; a margin, so as not to arrive late. */
    private const WINDOW = DAYSECS - 30 * MINSECS;

    /**
     * Queues a notice for one number.
     *
     * @param int $contactid The contact.
     * @param \stdClass $notice The notice.
     * @param string[] $params Values of the template.
     * @param string|null $url Where the button goes, on this site.
     * @param bool $urgent Whether it goes out in the quiet hours too.
     * @param int $validfor Seconds after which it is no longer worth sending.
     * @param int|null $now The time, for the tests.
     * @return int The id in the queue.
     */
    public static function add(
        int $contactid,
        \stdClass $notice,
        array $params,
        ?string $url,
        bool $urgent,
        int $validfor,
        ?int $now = null
    ): int {
        global $DB;
        do {
            $token = random_string(10);
        } while ($DB->record_exists(self::TABLE, ['token' => $token]));
        $now = $now ?? time();
        return $DB->insert_record(self::TABLE, (object)[
            'contactid' => $contactid,
            'component' => $notice->component,
            'notice' => $notice->notice,
            'params' => json_encode(array_values($params)),
            'url' => $url,
            'token' => $token,
            'urgent' => (int)$urgent,
            'status' => 'queued',
            'timequeued' => $now,
            'timeexpires' => $now + $validfor,
        ]);
    }

    /**
     * Sends what can go out now.
     *
     * @param cloud_api $api The client.
     * @param int|null $now The time, for the tests.
     * @return array Counts by what happened: sent, waiting, failed, expired, skipped.
     */
    public static function process(cloud_api $api, ?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        $counts = ['sent' => 0, 'waiting' => 0, 'failed' => 0, 'expired' => 0, 'skipped' => 0];
        $config = get_config('local_oksigeniamarandu');
        $cost = self::cost();
        $cap = (float)($config->capmonth ?? 0);
        $perday = (int)($config->capfamily ?? 0);
        $quiet = self::is_quiet($now);
        $enabled = notices::enabled();
        $urgent = notices::urgent();
        $spent = self::spent($now);

        $counts['expired'] = $DB->count_records_select(self::TABLE, 'status = ? AND timeexpires < ?', ['queued', $now]);
        $DB->set_field_select(self::TABLE, 'status', 'expired', 'status = ? AND timeexpires < ?', ['queued', $now]);

        foreach ($DB->get_records(self::TABLE, ['status' => 'queued'], 'id ASC', '*', 0, 200) as $row) {
            $key = $row->component . '/' . $row->notice;
            $notice = notices::get($row->component, $row->notice);
            $contact = $DB->get_record(consent::CONTACTS, ['id' => $row->contactid]);
            $active = $contact && $contact->status === 'active';
            if (!$notice || !$active || !in_array($key, $enabled, true)) {
                $why = !$notice ? 'undeclared' : (!$active ? 'notactive' : 'disabled');
                self::finish($row, $now, 'skipped', null, null, $why);
                $counts['skipped']++;
                continue;
            }
            if ($quiet && !$row->urgent && !in_array($key, $urgent, true)) {
                $counts['waiting']++;
                continue;
            }
            if ($perday > 0 && self::sent_today($contact->id, $now) >= $perday) {
                $counts['waiting']++;
                continue;
            }
            $params = json_decode($row->params, true) ?: [];
            $free = !empty($contact->lastinbound) && $contact->lastinbound > $now - self::WINDOW;
            $template = $free ? null : templates::approved($notice, $contact->lang ?: null);
            if (!$free && !$template) {
                $counts['waiting']++;
                continue;
            }
            if (!$free && $cap > 0 && $spent + $cost > $cap + 0.00001) {
                self::warn_cap($now);
                $counts['waiting']++;
                continue;
            }
            try {
                if ($free) {
                    $text = self::render($notice, $params, $contact->lang ?: null);
                    if ($row->url) {
                        $text .= "\n" . templates::button_url($row->token);
                    }
                    $wamid = $api->send_text($contact->phone, $text);
                    self::finish($row, $now, 'sent', 'text', $wamid);
                } else {
                    $wamid = $api->send_template($contact->phone, $template->name, notices::metalang($template->lang), [
                        ['type' => 'body', 'parameters' => array_map(function ($value) {
                            return ['type' => 'text', 'text' => $value];
                        }, $params)],
                        ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [
                            ['type' => 'text', 'text' => $row->token],
                        ]],
                    ]);
                    self::finish($row, $now, 'sent', 'template', $wamid);
                    $spent += $cost;
                }
                $counts['sent']++;
            } catch (api_exception $e) {
                self::finish($row, $now, 'failed', $free ? 'text' : 'template', null, $e->getMessage());
                $counts['failed']++;
            }
        }
        return $counts;
    }

    /**
     * A status Meta sends for a message of the queue.
     *
     * @param string $wamid The id of the message.
     * @param string $status sent, delivered, read or failed.
     * @param string $error What Meta says when it fails.
     */
    public static function status(string $wamid, string $status, string $error = ''): void {
        global $DB;
        if ($wamid === '' || !($row = $DB->get_record(self::TABLE, ['wamid' => $wamid]))) {
            return;
        }
        if (in_array($status, ['delivered', 'read'], true)) {
            $row->status = $status === 'read' || $row->status === 'read' ? 'read' : 'delivered';
            $row->timedelivered = $row->timedelivered ?: time();
        } else if ($status === 'failed') {
            $row->status = 'failed';
            $row->error = \core_text::substr($error === '' ? 'failed' : $error, 0, 255);
        } else {
            return;
        }
        $DB->update_record(self::TABLE, $row);
    }

    /**
     * The text of a notice with its values, for a free message inside the 24 hours.
     *
     * @param \stdClass $notice The notice.
     * @param string[] $params Values.
     * @param string|null $lang Language.
     * @return string
     */
    public static function render(\stdClass $notice, array $params, ?string $lang = null): string {
        $text = notices::text($notice, 'template', $lang);
        foreach (array_values($params) as $i => $value) {
            $text = str_replace('{{' . ($i + 1) . '}}', $value, $text);
        }
        return $text;
    }

    /**
     * Whether a time falls in the quiet hours or the quiet days of the site, in its time zone.
     *
     * @param int $time The time.
     * @return bool
     */
    public static function is_quiet(int $time): bool {
        $config = get_config('local_oksigeniamarandu');
        $date = new \DateTime('@' . $time);
        $date->setTimezone(\core_date::get_server_timezone_object());
        $days = array_filter(explode(',', (string)($config->quietdays ?? '')), 'strlen');
        if (in_array($date->format('w'), $days, true)) {
            return true;
        }
        $from = (int)str_replace(':', '', (string)($config->quietfrom ?? ''));
        $to = (int)str_replace(':', '', (string)($config->quietto ?? ''));
        if ($from === $to) {
            return false;
        }
        $now = (int)$date->format('Hi');
        return $from > $to ? ($now >= $from || $now < $to) : ($now >= $from && $now < $to);
    }

    /**
     * What the templates sent this month cost, roughly: free messages are not counted.
     *
     * @param int $now The time.
     * @return float Euros.
     */
    public static function spent(int $now): float {
        global $DB;
        $start = new \DateTime('@' . $now);
        $start->setTimezone(\core_date::get_server_timezone_object());
        $start->modify('first day of this month midnight');
        $count = $DB->count_records_select(
            self::TABLE,
            "kind = 'template' AND timesent >= ? AND status <> 'failed'",
            [$start->getTimestamp()]
        );
        return $count * self::cost();
    }

    /**
     * The price of one template, as the site set it.
     *
     * @return float Euros.
     */
    public static function cost(): float {
        $cost = get_config('local_oksigeniamarandu', 'costpermessage');
        return $cost === false || $cost === '' ? self::DEFAULT_COST : (float)$cost;
    }

    /**
     * How many notices a number has received today.
     *
     * @param int $contactid The contact.
     * @param int $now The time.
     * @return int
     */
    private static function sent_today(int $contactid, int $now): int {
        global $DB;
        $start = new \DateTime('@' . $now);
        $start->setTimezone(\core_date::get_server_timezone_object());
        $start->modify('midnight');
        return $DB->count_records_select(
            self::TABLE,
            "contactid = ? AND timesent >= ? AND status <> 'failed'",
            [$contactid, $start->getTimestamp()]
        );
    }

    /**
     * Closes a row of the queue.
     *
     * @param \stdClass $row The row.
     * @param int $now The time.
     * @param string $status sent, failed or skipped.
     * @param string|null $kind template or text.
     * @param string|null $wamid The id of the message.
     * @param string|null $error Why, if it did not go.
     */
    private static function finish(
        \stdClass $row,
        int $now,
        string $status,
        ?string $kind,
        ?string $wamid,
        ?string $error = null
    ): void {
        global $DB;
        $row->status = $status;
        $row->kind = $kind;
        $row->wamid = $wamid;
        $row->error = $error === null ? null : \core_text::substr(strip_tags($error), 0, 255);
        $row->timesent = $status === 'sent' ? $now : null;
        $DB->update_record(self::TABLE, $row);
    }

    /**
     * Writes once a month to whom the site said that the limit of spending has been reached.
     *
     * @param int $now The time.
     */
    private static function warn_cap(int $now): void {
        $month = userdate($now, '%Y-%m', \core_date::get_server_timezone());
        $email = (string)get_config('local_oksigeniamarandu', 'capemail');
        if ($email === '' || get_config('local_oksigeniamarandu', 'capwarned') === $month || !validate_email($email)) {
            return;
        }
        set_config('capwarned', $month, 'local_oksigeniamarandu');
        $to = \core_user::get_noreply_user();
        $to->email = $email;
        $to->emailstop = 0;
        $site = format_string(get_site()->fullname, true, ['context' => \context_system::instance(), 'escape' => false]);
        $a = (object)['site' => $site, 'cap' => format_float((float)get_config('local_oksigeniamarandu', 'capmonth'), 2)];
        email_to_user(
            $to,
            \core_user::get_noreply_user(),
            get_string('capmail:subject', 'local_oksigeniamarandu', $a),
            get_string('capmail:body', 'local_oksigeniamarandu', $a)
        );
    }
}
