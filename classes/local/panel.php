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
 * What the panel tells at a glance: whether the notices go out, today, the month, what failed and who joined or left.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel {
    /** How many failed notices and moves of people the panel shows. */
    public const LAST = 5;

    /** Errors of the account, by what the site has to do in Meta: a new token, the restriction, the payment. */
    private const BLOCKED = [0 => 'token', 190 => 'token', 368 => 'restricted', 131031 => 'restricted', 131042 => 'payment'];

    /** Error codes of Meta, by the reason the panel gives in plain words; the rest get a general one. */
    private const WHY = [
        131026 => 'nowhatsapp',
        100 => 'invalid',
        131008 => 'invalid',
        131009 => 'invalid',
        131021 => 'invalid',
        131030 => 'notallowed',
        132001 => 'notemplate',
        132015 => 'paused',
        132016 => 'disabled',
    ];

    /**
     * Whether the notices go out and, if not, why and what to do.
     *
     * @param int $now The time.
     * @return \stdClass With kind (notready, blocked, cap or ok), what (for blocked: token, restricted, payment or
     *     account), since, message, waiting (how many) and next (the end of the quiet hours, if now is quiet).
     */
    public static function state(int $now): \stdClass {
        global $DB;
        $waiting = $DB->count_records(queue::TABLE, ['status' => 'queued']);
        $state = (object)['kind' => 'ok', 'what' => '', 'since' => 0, 'message' => '', 'waiting' => $waiting, 'next' => 0];
        if (!cloud_api::is_configured()) {
            $state->kind = 'notready';
            return $state;
        }
        if ($blocked = queue::blocked()) {
            $state->kind = 'blocked';
            $state->what = self::BLOCKED[(int)$blocked->code] ?? 'account';
            $state->since = (int)$blocked->time;
            $state->message = (string)$blocked->message;
            return $state;
        }
        if (self::capreached($now)) {
            $state->kind = 'cap';
            return $state;
        }
        if (queue::is_quiet($now)) {
            $state->next = queue::quiet_end($now);
        }
        return $state;
    }

    /**
     * What went out today, since midnight in the time zone of the site.
     *
     * @param int $now The time.
     * @return array sent, delivered, read and failed.
     */
    public static function today(int $now): array {
        global $DB;
        $params = ['start' => self::midnight($now)];
        $count = function (string $where) use ($DB, $params): int {
            return $DB->count_records_select(queue::TABLE, "timesent >= :start AND $where", $params);
        };
        return [
            // A notice that Meta could not deliver after taking it was sent all the same.
            'sent' => $count("(status IN ('sent', 'delivered', 'read') OR (status = 'failed' AND wamid IS NOT NULL))"),
            'delivered' => $count("status IN ('delivered', 'read')"),
            'read' => $count("status = 'read'"),
            'failed' => $count("status = 'failed'"),
        ];
    }

    /**
     * What is waiting to go out, grouped by why, in the order the queue would let it go.
     *
     * @param int $now The time.
     * @return \stdClass[] Each with reason (blocked, retry, quiet, template, cap or now), count, when (a time, or 0)
     *     and name (of the notice, for template).
     */
    public static function waiting(int $now): array {
        global $DB;
        $fields = 'id, contactid, component, notice, urgent, kind, timeretry';
        $rows = $DB->get_records(queue::TABLE, ['status' => 'queued'], 'id', $fields);
        if (!$rows) {
            return [];
        }
        if (queue::blocked()) {
            return [(object)['reason' => 'blocked', 'count' => count($rows), 'when' => 0, 'name' => '']];
        }
        $quiet = queue::is_quiet($now);
        $quietend = $quiet ? queue::quiet_end($now) : 0;
        $urgent = notices::urgent();
        $cap = self::capreached($now);
        [$insql, $params] = $DB->get_in_or_equal(array_unique(array_column($rows, 'contactid')));
        $contacts = $DB->get_records_select(consent::CONTACTS, "id $insql", $params, '', 'id, lang, lastinbound');
        $approved = [];
        $groups = [];
        $add = function (string $key, string $reason, int $when = 0, string $name = '') use (&$groups): void {
            if (!isset($groups[$key])) {
                $groups[$key] = (object)['reason' => $reason, 'count' => 0, 'when' => $when, 'name' => $name];
            }
            $groups[$key]->count++;
            if ($when && $when < $groups[$key]->when) {
                $groups[$key]->when = $when;
            }
        };
        foreach ($rows as $row) {
            $key = $row->component . '/' . $row->notice;
            if ($row->timeretry > $now) {
                $add('retry', 'retry', (int)$row->timeretry);
                continue;
            }
            if ($quiet && !$row->urgent && !in_array($key, $urgent, true)) {
                $add('quiet', 'quiet', $quietend);
                continue;
            }
            $contact = $contacts[$row->contactid] ?? null;
            $free = $contact && $row->kind !== 'template' && $contact->lastinbound > $now - DAYSECS + 30 * MINSECS;
            if ($free) {
                $add('now', 'now');
                continue;
            }
            $notice = notices::get($row->component, $row->notice);
            $lang = $contact->lang ?? '';
            if (!isset($approved[$key . '|' . $lang])) {
                $approved[$key . '|' . $lang] = $notice && templates::approved($notice, $lang ?: null);
            }
            if (!$approved[$key . '|' . $lang]) {
                $add('template:' . $key, 'template', 0, $notice ? notices::text($notice) : $key);
            } else if ($cap) {
                $add('cap', 'cap', self::nextmonth($now));
            } else {
                $add('now', 'now');
            }
        }
        $order = ['now' => 0, 'retry' => 1, 'quiet' => 2, 'template' => 3, 'cap' => 4];
        uasort($groups, function ($a, $b) use ($order) {
            return [$order[$a->reason], -$a->count] <=> [$order[$b->reason], -$b->count];
        });
        return array_values($groups);
    }

    /**
     * What the month costs, roughly, against the limit of the site.
     *
     * @param int $now The time.
     * @return \stdClass With spent, cap (0 for none), cost (of a template), templates and free (how many went out each
     *     way), likely (the end of the month at this pace) and full (whether the limit is reached).
     */
    public static function month(int $now): \stdClass {
        global $DB;
        $start = self::monthstart($now);
        $count = function (string $kind) use ($DB, $start): int {
            return $DB->count_records_select(
                queue::TABLE,
                "kind = :kind AND timesent >= :start AND status <> 'failed'",
                ['kind' => $kind, 'start' => $start]
            );
        };
        $spent = queue::spent($now);
        $days = (int)userdate($now, '%d', \core_date::get_server_timezone());
        $length = (int)userdate(self::nextmonth($now) - DAYSECS, '%d', \core_date::get_server_timezone());
        $elapsed = max(1.0, ($now - $start) / DAYSECS);
        return (object)[
            'spent' => $spent,
            'cap' => (float)get_config('local_oksigeniamarandu', 'capmonth'),
            'cost' => queue::cost(),
            'templates' => $count('template'),
            'free' => $count('text'),
            'likely' => $days >= $length ? $spent : $spent / $elapsed * $length,
            'full' => self::capreached($now),
        ];
    }

    /**
     * The last notices that failed or expired, newest first.
     *
     * @param int $limit How many.
     * @return \stdClass[] Each with time, name (of the notice), phone (masked) and why (a reason in plain words).
     */
    public static function failed(int $limit = self::LAST): array {
        global $DB;
        $rows = $DB->get_records_sql("SELECT q.id, q.component, q.notice, q.status, q.errorcode, q.attempts, q.timesent,
                                             q.timeexpires, c.phone
                                        FROM {" . queue::TABLE . "} q
                                        JOIN {" . consent::CONTACTS . "} c ON c.id = q.contactid
                                       WHERE q.status = 'failed'
                                    ORDER BY q.timesent DESC, q.id DESC", [], 0, $limit);
        $rows += $DB->get_records_sql("SELECT q.id, q.component, q.notice, q.status, q.errorcode, q.attempts, q.timesent,
                                              q.timeexpires, c.phone
                                         FROM {" . queue::TABLE . "} q
                                         JOIN {" . consent::CONTACTS . "} c ON c.id = q.contactid
                                        WHERE q.status = 'expired'
                                     ORDER BY q.timeexpires DESC, q.id DESC", [], 0, $limit);
        $failed = [];
        foreach ($rows as $row) {
            $notice = notices::get($row->component, $row->notice);
            $failed[] = (object)[
                'time' => (int)($row->status === 'expired' ? $row->timeexpires : $row->timesent),
                'name' => $notice ? notices::text($notice) : $row->component . '/' . $row->notice,
                'phone' => self::mask($row->phone),
                'why' => self::why($row),
            ];
        }
        usort($failed, function ($a, $b) {
            return $b->time <=> $a->time;
        });
        return array_slice($failed, 0, $limit);
    }

    /**
     * Who receives the notices, who wrote without joining, who left, and the last moves.
     *
     * @param int $limit How many moves.
     * @return \stdClass With active, pending, optedout and moves (each with time, action, who and how).
     */
    public static function people(int $limit = self::LAST): \stdClass {
        global $DB;
        $counts = $DB->get_records_sql_menu('SELECT status, COUNT(1) FROM {' . consent::CONTACTS . '} GROUP BY status');
        $moves = [];
        $log = $DB->get_records(consent::LOG, null, 'timecreated DESC, id DESC', '*', 0, $limit);
        $contacts = $log ? $DB->get_records_list(consent::CONTACTS, 'id', array_column($log, 'contactid')) : [];
        $whom = self::standsfor(array_keys($contacts));
        foreach ($log as $entry) {
            $contact = $contacts[$entry->contactid] ?? null;
            $who = $whom[$entry->contactid][0]->name ?? '';
            $moves[] = (object)[
                'time' => (int)$entry->timecreated,
                'action' => $entry->action,
                'who' => $who !== '' ? $who : ($contact ? self::mask($contact->phone) : ''),
                'how' => self::how($entry),
            ];
        }
        return (object)[
            'active' => (int)($counts['active'] ?? 0),
            'pending' => (int)($counts['pending'] ?? 0),
            'optedout' => (int)($counts['optedout'] ?? 0),
            'moves' => $moves,
        ];
    }

    /**
     * How many notices of each kind went out this month, the switched-on ones too when none did.
     *
     * @param int $now The time.
     * @return \stdClass[] Each with name, label and count; the most first.
     */
    public static function bynotice(int $now): array {
        global $DB;
        $counts = $DB->get_records_sql("SELECT " . $DB->sql_concat('component', "'/'", 'notice') . " AS notice, COUNT(1) AS total
                                          FROM {" . queue::TABLE . "}
                                         WHERE timesent >= :start AND status IN ('sent', 'delivered', 'read')
                                      GROUP BY component, notice", ['start' => self::monthstart($now)]);
        $keys = array_unique(array_merge(notices::enabled(), array_keys($counts)));
        $list = [];
        foreach ($keys as $key) {
            [$component, $name] = array_pad(explode('/', $key, 2), 2, '');
            $notice = notices::get($component, $name);
            if (!$notice) {
                continue;
            }
            $list[] = (object)[
                'name' => notices::text($notice),
                'label' => notices::label($notice),
                'count' => (int)($counts[$key]->total ?? 0),
            ];
        }
        usort($list, function ($a, $b) {
            return [$b->count, $a->name] <=> [$a->count, $b->name];
        });
        return $list;
    }

    /**
     * Whom each number stands for: users, and items of plugins with what they are.
     *
     * @param int[] $contactids The contacts.
     * @return array By contact, a list of objects with name (empty if the plugin does not say it) and kind (what it is: a user,
     *     a family of the canteen…).
     */
    public static function standsfor(array $contactids): array {
        global $DB;
        if (!$contactids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($contactids);
        $links = $DB->get_records_select(consent::LINKS, "contactid $insql", $params, 'id');
        $userids = array_filter(array_column($links, 'userid'));
        $users = $userids ? $DB->get_records_list('user', 'id', $userids) : [];
        $whom = [];
        foreach ($links as $link) {
            if ($link->userid && isset($users[$link->userid])) {
                $whom[$link->contactid][] = (object)[
                    'name' => fullname($users[$link->userid]),
                    'kind' => get_string('panel:moodleuser', 'local_oksigeniamarandu'),
                ];
            } else if (!$link->userid && $link->component !== 'core') {
                // The plugin names its item, if it can (the family of Ana); if not, only what it is.
                $whom[$link->contactid][] = (object)[
                    'name' => (string)notices::item_label($link->component, (int)$link->itemid),
                    'kind' => notices::item($link->component),
                ];
            }
        }
        return $whom;
    }

    /**
     * How a move of the record of consent happened, in a few words.
     *
     * @param \stdClass $entry The entry of the record.
     * @return string
     */
    public static function how(\stdClass $entry): string {
        $component = 'local_oksigeniamarandu';
        if ($entry->source === 'admin') {
            // Who did it and why, as the list keeps it: "<userid>:<reason>".
            [$userid, $reason] = array_pad(explode(':', (string)$entry->detail, 2), 2, '');
            $user = ctype_digit($userid) ? \core_user::get_user((int)$userid) : null;
            $why = trim($reason);
            // After a colon, in lower case: «a mano, por Elena: lo pidió por teléfono».
            $why = \core_text::strtolower(\core_text::substr($why, 0, 1)) . \core_text::substr($why, 1);
            $a = (object)['who' => $user ? fullname($user) : '?', 'why' => $why];
            return get_string($a->why === '' ? 'panel:how:admin' : 'panel:how:adminwhy', $component, $a);
        }
        if ($entry->source === 'keyword') {
            $word = keywords::first($entry->action === 'optout' ? 'optoutkeywords' : 'optinkeywords');
            return get_string('panel:how:wrote', $component, $word);
        }
        return get_string('panel:how:other', $component);
    }

    /**
     * A number with only its start and its end in sight, for a screen others may see: +34 6•• •• •• 18.
     *
     * @param string $phone Digits with the country code.
     * @return string
     */
    public static function mask(string $phone): string {
        if (strlen($phone) < 7) {
            return '+' . $phone;
        }
        return '+' . substr($phone, 0, 2) . ' ' . substr($phone, 2, 1) . '•• •• •• ' . substr($phone, -2);
    }

    /**
     * A number written in groups, to read it: +34 600 23 86 70.
     *
     * @param string $phone Digits with the country code.
     * @return string
     */
    public static function spaced(string $phone): string {
        if (strlen($phone) !== 11) {
            return '+' . $phone;
        }
        // The common case of a two-digit country and nine digits; the rest stay as they are.
        return '+' . substr($phone, 0, 2) . ' ' . substr($phone, 2, 3) . ' ' . substr($phone, 5, 2) . ' '
            . substr($phone, 7, 2) . ' ' . substr($phone, 9, 2);
    }

    /**
     * Why a notice did not go, as the panel says it.
     *
     * @param \stdClass $row The row of the queue, with status, errorcode and attempts.
     * @return string The key of the reason: nowhatsapp, invalid, notallowed, notemplate, paused, disabled, noanswer,
     *     expired or generic.
     */
    public static function why(\stdClass $row): string {
        if ($row->status === 'expired') {
            return 'expired';
        }
        if (isset(self::WHY[(int)$row->errorcode]) && $row->errorcode !== null) {
            return self::WHY[(int)$row->errorcode];
        }
        if ((int)$row->attempts > 0) {
            return 'noanswer';
        }
        return 'generic';
    }

    /**
     * A time as people say it: «today, 13:56», «yesterday, 09:41», «tomorrow, 08:00», «Saturday, 08:00» or «22 Oct».
     *
     * @param int $time The time.
     * @param int $now Now.
     * @return string
     */
    public static function when(int $time, int $now): string {
        $component = 'local_oksigeniamarandu';
        $hour = userdate($time, get_string('strftimetime24', 'langconfig'));
        $days = (int)round((self::midnight($time) - self::midnight($now)) / DAYSECS);
        if ($days === 0) {
            return get_string('panel:when:today', $component, $hour);
        }
        if ($days === -1) {
            return get_string('panel:when:yesterday', $component, $hour);
        }
        if ($days === 1) {
            return get_string('panel:when:tomorrow', $component, $hour);
        }
        if ($days > 1 && $days < 7) {
            return userdate($time, '%A') . ', ' . $hour;
        }
        return userdate($time, get_string('panel:when:date', $component));
    }

    /**
     * Whether the templates of this month have reached the limit of the site.
     *
     * @param int $now The time.
     * @return bool
     */
    private static function capreached(int $now): bool {
        $cap = (float)get_config('local_oksigeniamarandu', 'capmonth');
        return $cap > 0 && queue::spent($now) + queue::cost() > $cap + 0.00001;
    }

    /**
     * Midnight of today, in the time zone of the site.
     *
     * @param int $now The time.
     * @return int
     */
    private static function midnight(int $now): int {
        $date = new \DateTime('@' . $now);
        $date->setTimezone(\core_date::get_server_timezone_object());
        $date->modify('midnight');
        return $date->getTimestamp();
    }

    /**
     * The first moment of this month, in the time zone of the site.
     *
     * @param int $now The time.
     * @return int
     */
    private static function monthstart(int $now): int {
        $date = new \DateTime('@' . $now);
        $date->setTimezone(\core_date::get_server_timezone_object());
        $date->modify('first day of this month midnight');
        return $date->getTimestamp();
    }

    /**
     * The first moment of next month, in the time zone of the site.
     *
     * @param int $now The time.
     * @return int
     */
    private static function nextmonth(int $now): int {
        $date = new \DateTime('@' . $now);
        $date->setTimezone(\core_date::get_server_timezone_object());
        $date->modify('first day of next month midnight');
        return $date->getTimestamp();
    }
}
