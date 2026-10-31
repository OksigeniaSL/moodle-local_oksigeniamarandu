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
     * Whether the notices go out and, if not, why and what to do; and how each channel is.
     *
     * @param int $now The time.
     * @return \stdClass With kind (connect: no channel yet; disconnected: no channel, but there was one; choose: no notice
     *     switched on; blocked; cap; ok), what (for blocked: token, restricted, payment, account or telegram), channel (the
     *     one stopped or capped), since, message, waiting (how many), next (the end of the quiet hours, if now is quiet),
     *     channels (each ready one with ok, stopped or cap) and others (whether another channel still goes out).
     */
    public static function state(int $now): \stdClass {
        global $DB;
        $waiting = $DB->count_records(queue::TABLE, ['status' => 'queued']);
        $state = (object)['kind' => 'ok', 'what' => '', 'channel' => '', 'since' => 0, 'message' => '', 'waiting' => $waiting,
            'next' => 0, 'channels' => [], 'others' => false];
        foreach (\local_oksigeniamarandu\api::channels() as $channel => $info) {
            if ($info['ready']) {
                $state->channels[$channel] = 'ok';
            }
        }
        if (!$state->channels) {
            $before = $DB->record_exists(consent::CONTACTS, []) || $DB->record_exists(queue::TABLE, []);
            $state->kind = $before ? 'disconnected' : 'connect';
            return $state;
        }
        $blocked = null;
        foreach (array_keys($state->channels) as $channel) {
            $stop = queue::blocked($channel);
            if ($stop) {
                $state->channels[$channel] = 'stopped';
                $blocked = $blocked ?? [$channel, $stop];
            }
        }
        if (isset($state->channels['whatsapp']) && $state->channels['whatsapp'] === 'ok' && self::capreached($now)) {
            $state->channels['whatsapp'] = 'cap';
        }
        $state->others = in_array('ok', $state->channels, true);
        if (!notices::any_on()) {
            $state->kind = 'choose';
            return $state;
        }
        if ($blocked) {
            [$channel, $stop] = $blocked;
            $state->kind = 'blocked';
            $state->channel = $channel;
            $state->what = $channel === 'telegram' ? 'telegram' : (self::BLOCKED[(int)$stop->code] ?? 'account');
            $state->since = (int)$stop->time;
            $state->message = (string)$stop->message;
            return $state;
        }
        if (($state->channels['whatsapp'] ?? '') === 'cap') {
            $state->kind = 'cap';
            $state->channel = 'whatsapp';
            return $state;
        }
        if (queue::is_quiet($now)) {
            $state->next = queue::quiet_end($now);
        }
        return $state;
    }

    /**
     * The links in the header of the panel and of the page of the notices, as pills with their drawing.
     *
     * @param string $current panel or notices.
     * @return array For local_oksigeniamarandu/panel_links.
     */
    public static function links(string $current): array {
        global $DB;
        $component = 'local_oksigeniamarandu';
        $link = function (string $path, string $label, string $drawing, string $class = '', array $more = []): array {
            return ['url' => (new \moodle_url('/local/oksigeniamarandu/' . $path))->out(false), 'label' => $label,
                'icon' => drawings::svg($drawing, 22, $class)] + $more;
        };
        if ($current !== 'panel') {
            // Back to the panel and, from the notices and the limits, from one to the other: they go together.
            $links = [$link('index.php', get_string('list:panel', $component), 'en-marcha')];
            if ($current === 'notices') {
                $links[] = $link('limits.php', get_string('limits:title', $component), 'silencio');
            } else if ($current === 'limits') {
                $links[] = $link('notices.php', get_string('panel:notices', $component), 'elige');
            }
            return $links;
        }
        $channels = \local_oksigeniamarandu\api::channels();
        $links = [
            $link(
                'contacts.php',
                get_string('panel:numbers', $component),
                'numeros',
                '',
                ['count' => $DB->count_records(consent::CONTACTS)]
            ),
            $link('notices.php', get_string('panel:notices', $component), 'elige'),
            $link('wizard.php', 'WhatsApp', 'whatsapp', 'wa'),
            $link('telegram.php', 'Telegram', 'telegram', 'tg'),
        ];
        if ($channels['whatsapp']['ready']) {
            $links[] = $link('wizard.php?step=texts', get_string('panel:texts', $component), 'textos');
        }
        return $links;
    }

    /**
     * The firsts of the site: when the first number joined and when the first notice went out, to celebrate them.
     *
     * @return \stdClass With join and sent (times, or 0), joincontact and sentrow (the records, or null).
     */
    public static function firsts(): \stdClass {
        global $DB;
        $join = $DB->get_records(consent::LOG, ['action' => 'optin'], 'timecreated ASC, id ASC', '*', 0, 1);
        $join = $join ? reset($join) : null;
        $sent = $DB->get_records_select(
            queue::TABLE,
            "status IN ('sent', 'delivered', 'read')",
            [],
            'timesent ASC, id ASC',
            '*',
            0,
            1
        );
        $sent = $sent ? reset($sent) : null;
        return (object)[
            'join' => $join ? (int)$join->timecreated : 0,
            'joincontact' => $join ? $DB->get_record(consent::CONTACTS, ['id' => $join->contactid]) : null,
            'sent' => $sent ? (int)$sent->timesent : 0,
            'sentrow' => $sent,
        ];
    }

    /**
     * Whom a contact stands for, in a few words: the first one it stands for, or its masked number.
     *
     * @param \stdClass $contact The contact.
     * @return string
     */
    public static function who(\stdClass $contact): string {
        $whom = self::standsfor([(int)$contact->id])[$contact->id] ?? [];
        $name = $whom ? $whom[0]->name : '';
        return $name !== '' ? $name : self::mask($contact->phone);
    }

    /**
     * The channel of a contact.
     *
     * @param string $phone Its address.
     * @return string whatsapp or telegram.
     */
    public static function channel(string $phone): string {
        return telegram::is_chat($phone) ? 'telegram' : 'whatsapp';
    }

    /**
     * What went out today, since midnight in the time zone of the site, by channel: WhatsApp with what got delivered and
     * read; Telegram, which does not tell a bot, only with what it sent.
     *
     * @param int $now The time.
     * @return array whatsapp (sent, delivered, read, failed), telegram (sent, failed), and sent and failed in all.
     */
    public static function today(int $now): array {
        global $DB;
        $start = self::midnight($now);
        $count = function (string $channel, string $where) use ($DB, $start): int {
            return $DB->count_records_sql(
                "SELECT COUNT(1)
                                             FROM {" . queue::TABLE . "} q
                                             JOIN {" . consent::CONTACTS . "} c ON c.id = q.contactid
                                            WHERE q.timesent >= :start AND c.channel = :channel AND $where",
                ['start' => $start, 'channel' => $channel]
            );
        };
        // A notice that the channel could not deliver after taking it was sent all the same.
        $sent = "(q.status IN ('sent', 'delivered', 'read') OR (q.status = 'failed' AND q.wamid IS NOT NULL))";
        $today = [
            'whatsapp' => [
                'sent' => $count('whatsapp', $sent),
                'delivered' => $count('whatsapp', "q.status IN ('delivered', 'read')"),
                'read' => $count('whatsapp', "q.status = 'read'"),
                'failed' => $count('whatsapp', "q.status = 'failed'"),
            ],
            'telegram' => [
                'sent' => $count('telegram', $sent),
                'failed' => $count('telegram', "q.status = 'failed'"),
            ],
        ];
        $today['sent'] = $today['whatsapp']['sent'] + $today['telegram']['sent'];
        $today['failed'] = $today['whatsapp']['failed'] + $today['telegram']['failed'];
        return $today;
    }

    /**
     * The last notice that went out, for the quiet hours, when today has little to say.
     *
     * @return \stdClass|null With name, time and channel; null if none has gone out.
     */
    public static function last(): ?\stdClass {
        global $DB;
        $rows = $DB->get_records_sql("SELECT q.id, q.component, q.notice, q.timesent, c.channel
                                        FROM {" . queue::TABLE . "} q
                                        JOIN {" . consent::CONTACTS . "} c ON c.id = q.contactid
                                       WHERE q.status IN ('sent', 'delivered', 'read')
                                    ORDER BY q.timesent DESC, q.id DESC", [], 0, 1);
        if (!$rows) {
            return null;
        }
        $row = reset($rows);
        $notice = notices::get($row->component, $row->notice);
        return (object)['name' => $notice ? notices::text($notice) : $row->notice, 'time' => (int)$row->timesent,
            'channel' => $row->channel === 'telegram' ? 'telegram' : 'whatsapp'];
    }

    /**
     * The notices that went out each day of this month until today, by channel.
     *
     * @param int $now The time.
     * @return array whatsapp and telegram, each a list by day (the first is the 1st), and today (its number) and length
     *     (the days of the month).
     */
    public static function days(int $now): array {
        global $DB;
        $zone = \core_date::get_server_timezone();
        $today = (int)userdate($now, '%d', $zone);
        $length = (int)userdate(self::nextmonth($now) - DAYSECS, '%d', $zone);
        $days = ['whatsapp' => array_fill(0, $today, 0), 'telegram' => array_fill(0, $today, 0), 'today' => $today,
            'length' => $length];
        $rows = $DB->get_recordset_sql(
            "SELECT q.id, q.timesent, c.channel
                                          FROM {" . queue::TABLE . "} q
                                          JOIN {" . consent::CONTACTS . "} c ON c.id = q.contactid
                                         WHERE q.timesent >= :start AND q.timesent <= :now
                                           AND (q.status IN ('sent', 'delivered', 'read')
                                                OR (q.status = 'failed' AND q.wamid IS NOT NULL))",
            ['start' => self::monthstart($now), 'now' => $now]
        );
        foreach ($rows as $row) {
            $day = (int)userdate((int)$row->timesent, '%d', $zone) - 1;
            $channel = $row->channel === 'telegram' ? 'telegram' : 'whatsapp';
            if (isset($days[$channel][$day])) {
                $days[$channel][$day]++;
            }
        }
        $rows->close();
        return $days;
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
        $stopped = ['whatsapp' => (bool)queue::blocked('whatsapp'), 'telegram' => (bool)queue::blocked('telegram')];
        $quiet = queue::is_quiet($now);
        $quietend = $quiet ? queue::quiet_end($now) : 0;
        $urgent = notices::urgent();
        $cap = self::capreached($now);
        [$insql, $params] = $DB->get_in_or_equal(array_unique(array_column($rows, 'contactid')));
        $contacts = $DB->get_records_select(consent::CONTACTS, "id $insql", $params, '', 'id, phone, lang, lastinbound');
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
            $contact = $contacts[$row->contactid] ?? null;
            $chat = $contact && telegram::is_chat($contact->phone);
            if ($stopped[$chat ? 'telegram' : 'whatsapp']) {
                $add('blocked', 'blocked');
                continue;
            }
            if ($row->timeretry > $now) {
                $add('retry', 'retry', (int)$row->timeretry);
                continue;
            }
            if ($quiet && !$row->urgent && !in_array($key, $urgent, true)) {
                $add('quiet', 'quiet', $quietend);
                continue;
            }
            // Telegram is free and has no templates; inside the 24 hours, WhatsApp too.
            $free = $chat || ($contact && $row->kind !== 'template' && $contact->lastinbound > $now - DAYSECS + 30 * MINSECS);
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
        $order = ['now' => 0, 'retry' => 1, 'quiet' => 2, 'template' => 3, 'cap' => 4, 'blocked' => 5];
        uasort($groups, function ($a, $b) use ($order) {
            return [$order[$a->reason], -$a->count] <=> [$order[$b->reason], -$b->count];
        });
        return array_values($groups);
    }

    /**
     * What the month costs, roughly, against the limit of the site.
     *
     * @param int $now The time.
     * @return \stdClass With spent, cap (0 for none), cost (of a template), templates, free and telegram (how many went out
     *     each way), likely (the end of the month at this pace) and full (whether the limit is reached).
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
            'telegram' => $count('telegram'),
            'likely' => $days >= $length ? $spent : $spent / $elapsed * $length,
            'full' => self::capreached($now),
        ];
    }

    /**
     * The last notices that failed or expired, newest first.
     *
     * @param int $limit How many.
     * @return \stdClass[] Each with time, name (of the notice), phone (masked), channel and why (a reason in plain words).
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
                'channel' => self::channel($row->phone),
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
     * @return \stdClass With active, pending, optedout, bychannel (active ones by channel) and moves (each with time,
     *     action, who, how and channel).
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
                'channel' => $contact ? self::channel($contact->phone) : 'whatsapp',
            ];
        }
        return (object)[
            'active' => (int)($counts['active'] ?? 0),
            'pending' => (int)($counts['pending'] ?? 0),
            'optedout' => (int)($counts['optedout'] ?? 0),
            'bychannel' => $DB->get_records_sql_menu('SELECT channel, COUNT(1) FROM {' . consent::CONTACTS . "}
                                                        WHERE status = 'active' GROUP BY channel"),
            'moves' => $moves,
        ];
    }

    /**
     * How many notices of each kind went out this month, by channel: those switched on (even if none went out) and
     * those that went out, and apart the ones switched off.
     *
     * @param int $now The time.
     * @return \stdClass With on (each with key, component, name, label, icon, whatsapp, telegram and count; the most first)
     *     and off (each with key, name and icon).
     */
    public static function bynotice(int $now): \stdClass {
        global $DB;
        $rows = $DB->get_recordset_sql(
            "SELECT q.id, q.component, q.notice, c.channel
                                          FROM {" . queue::TABLE . "} q
                                          JOIN {" . consent::CONTACTS . "} c ON c.id = q.contactid
                                         WHERE q.timesent >= :start AND q.status IN ('sent', 'delivered', 'read')",
            ['start' => self::monthstart($now)]
        );
        $counts = [];
        foreach ($rows as $row) {
            $channel = $row->channel === 'telegram' ? 'telegram' : 'whatsapp';
            $key = $row->component . '/' . $row->notice;
            $counts[$key][$channel] = ($counts[$key][$channel] ?? 0) + 1;
        }
        $rows->close();
        $ready = array_keys(array_filter(\local_oksigeniamarandu\api::channels(), function ($info) {
            return $info['ready'];
        })) ?: ['whatsapp', 'telegram'];
        $on = [];
        foreach ($ready as $channel) {
            $on = array_merge($on, notices::enabled($channel));
        }
        $list = (object)['on' => [], 'off' => []];
        foreach (notices::all() as $key => $notice) {
            $item = (object)[
                'key' => $key,
                'component' => $notice->component,
                'name' => notices::text($notice),
                'label' => notices::label($notice),
                'icon' => notices::icon($notice->component),
                'whatsapp' => (int)($counts[$key]['whatsapp'] ?? 0),
                'telegram' => (int)($counts[$key]['telegram'] ?? 0),
            ];
            $item->count = $item->whatsapp + $item->telegram;
            if (in_array($key, $on, true) || $item->count) {
                $list->on[] = $item;
            } else {
                $list->off[] = $item;
            }
        }
        usort($list->on, function ($a, $b) {
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
        if ($entry->source === 'blocked') {
            return get_string('panel:how:blocked', $component);
        }
        if ($entry->source === 'keyword' && str_starts_with((string)$entry->detail, 'telegram:')) {
            return get_string($entry->action === 'optout' ? 'panel:how:tgstop' : 'panel:how:tgstart', $component);
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
        if (telegram::is_chat($phone)) {
            // A chat, with the end of its number, so that two of them are told apart: Chat ••07.
            return get_string('panel:tgchat', 'local_oksigeniamarandu', substr(telegram::chat($phone), -2));
        }
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
        if (telegram::is_chat($phone)) {
            return 'Telegram';
        }
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
     *     expired, tgblocked or generic.
     */
    public static function why(\stdClass $row): string {
        if ($row->status === 'expired') {
            return 'expired';
        }
        if ((int)$row->errorcode === -403) {
            return 'tgblocked';
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
