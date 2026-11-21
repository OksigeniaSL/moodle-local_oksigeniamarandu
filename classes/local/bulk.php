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

use local_oksigeniamarandu\local\chat\templates as prices;

/**
 * The sends to many at once that a plugin asks for, such as inviting every family of admissions to an open day: what
 * they cost before they go, and the approval of whoever may approve them when they cost more than the threshold of the
 * site or the month has no budget left. Only to whoever joined and did not say no; by the usual queue, with its quiet
 * hours.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk {
    /** The sends. */
    public const TABLE = 'local_oksigeniamarandu_bulk';

    /** What each send carries for each item. */
    public const ITEMS = 'local_oksigeniamarandu_bulkitem';

    /** The cost from which a send waits for approval when the site sets none. */
    public const DEFAULT_THRESHOLD = 10.0;

    /** The reference of the question of approval on Telegram: k: and the send. */
    public const REF = 'k:';

    /** The states of a send. */
    public const STATUSES = ['pending', 'queued', 'rejected', 'cancelled'];

    /** The most items of a send. */
    public const MAXITEMS = 5000;

    /** @var string The component. */
    private const COMPONENT = 'local_oksigeniamarandu';

    /**
     * What a send would be: how many messages go by each channel, how many items are left out, and what it costs.
     *
     * @param string $component The plugin.
     * @param string $notice The notice it sends.
     * @param int[] $itemids The items.
     * @param string $area The kind of item.
     * @return array whatsapp (paid), whatsappfree (inside the 24 hours), telegram, skipped, cost, currency, category,
     *               approval and approvers.
     */
    public static function quote(string $component, string $notice, array $itemids, string $area = ''): array {
        $declared = notices::get($component, $notice);
        if (!$declared) {
            throw new \coding_exception("The notice {$component}/{$notice} is not declared");
        }
        $reach = self::reach($component, $declared, $itemids, $area);
        $category = self::category($declared);
        $price = prices::prices();
        $cost = round($reach['whatsapp'] * (float)($price[$category] ?? $price['marketing']), 2);
        return $reach + [
            'cost' => $cost,
            'currency' => (string)$price['currency'],
            'category' => $category,
            'approval' => self::needs_approval($cost),
            'approvers' => array_values(array_map('fullname', self::approvers())),
        ];
    }

    /**
     * Asks for a send: it goes now if it does not need approval, or waits for it.
     *
     * @param string $component The plugin.
     * @param string $notice The notice it sends.
     * @param array $items Each with itemid, params, url (a moodle_url or a URL of this site) and lang, as notify() takes them.
     * @param int $userid Who asks for it.
     * @param string $label What it is, for whoever approves it: «Invitation to the open day of 12 March».
     * @param string $area The kind of item.
     * @return array id and status (queued or pending).
     */
    public static function request(
        string $component,
        string $notice,
        array $items,
        int $userid,
        string $label,
        string $area = ''
    ): array {
        global $DB;
        if (!$items || count($items) > self::MAXITEMS) {
            throw new \coding_exception('A send takes from 1 to ' . self::MAXITEMS . ' items');
        }
        $quote = self::quote($component, $notice, array_map(function ($item) {
            return (int)($item['itemid'] ?? 0);
        }, $items), $area);
        $now = time();
        $bulk = (object)[
            'component' => $component,
            'area' => $area,
            'notice' => $notice,
            'label' => \core_text::substr(trim(strip_tags($label)), 0, 255),
            'userid' => $userid,
            'whatsapp' => $quote['whatsapp'],
            'telegram' => $quote['telegram'],
            'skipped' => $quote['skipped'],
            'cost' => $quote['cost'],
            'currency' => $quote['currency'],
            'category' => $quote['category'],
            'status' => $quote['approval'] ? 'pending' : 'queued',
            'decidedby' => 0,
            'reason' => '',
            'onlyby' => '',
            'sent' => 0,
            'timecreated' => $now,
            'timedecided' => $quote['approval'] ? 0 : $now,
        ];
        $transaction = $DB->start_delegated_transaction();
        $bulk->id = $DB->insert_record(self::TABLE, $bulk);
        foreach ($items as $item) {
            $url = $item['url'] ?? null;
            $DB->insert_record(self::ITEMS, (object)[
                'bulkid' => $bulk->id,
                'itemid' => (int)($item['itemid'] ?? 0),
                'params' => json_encode(array_values((array)($item['params'] ?? []))),
                'url' => $url instanceof \moodle_url ? $url->out(false) : (string)$url,
                'lang' => \core_text::substr((string)($item['lang'] ?? ''), 0, 30),
            ]);
        }
        $transaction->allow_commit();
        if ($bulk->status === 'queued') {
            self::release($bulk);
        } else {
            self::ask_approvers($bulk);
        }
        return ['id' => (int)$bulk->id, 'status' => $bulk->status];
    }

    /**
     * How a send is.
     *
     * @param int $id The send.
     * @return array status, decidedby (the name), timedecided, sent, and what the quote said.
     */
    public static function state(int $id): array {
        global $DB;
        $bulk = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
        $by = $bulk->decidedby ? \core_user::get_user((int)$bulk->decidedby) : null;
        return [
            'status' => (string)$bulk->status,
            'decidedby' => $by ? fullname($by) : '',
            'timedecided' => (int)$bulk->timedecided,
            'reason' => (string)$bulk->reason,
            'only' => (string)$bulk->onlyby,
            'said' => self::said($bulk),
            'sent' => (int)$bulk->sent,
            'whatsapp' => (int)$bulk->whatsapp,
            'telegram' => (int)$bulk->telegram,
            'skipped' => (int)$bulk->skipped,
            'cost' => (float)$bulk->cost,
            'currency' => (string)$bulk->currency,
        ];
    }

    /**
     * Approves or rejects a send that waits: approved, it goes now, by every channel or only by the free one.
     *
     * @param int $id The send.
     * @param bool $approve Whether it goes.
     * @param int $userid Who decides; they must be able to approve.
     * @param string $reason Why it was rejected, for whoever asked for it.
     * @param string $only Approved only by this channel, such as telegram (free); empty for every one.
     * @return bool Whether it changed: false if it was already decided.
     */
    public static function decide(int $id, bool $approve, int $userid, string $reason = '', string $only = ''): bool {
        global $DB;
        if (!spend::is_person($userid)) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'local/oksigeniamarandu:bulkapprove',
                'nopermissions',
                ''
            );
        }
        // One decision only, though two arrive at once (the web and Telegram, or a double tap): it would go, and be paid,
        // twice.
        $lock = \core\lock\lock_config::get_lock_factory('local_oksigeniamarandu_bulk')->get_lock('bulk' . $id, 10);
        if (!$lock) {
            return false;
        }
        try {
            return self::decide_locked($id, $approve, $userid, $reason, $only);
        } finally {
            $lock->release();
        }
    }

    /**
     * Decides a send, with its lock held.
     *
     * @param int $id The send.
     * @param bool $approve Whether it goes.
     * @param int $userid Who decides.
     * @param string $reason Why it does not go.
     * @param string $only Only by this channel, or empty.
     * @return bool Whether it was decided now.
     */
    private static function decide_locked(int $id, bool $approve, int $userid, string $reason, string $only): bool {
        global $DB;
        $bulk = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
        if ($bulk->status !== 'pending') {
            return false;
        }
        $bulk->status = $approve ? 'queued' : 'rejected';
        $bulk->decidedby = $userid;
        $bulk->timedecided = time();
        $bulk->reason = $approve ? '' : \core_text::substr(trim(strip_tags($reason)), 0, 255);
        $bulk->onlyby = $approve && in_array($only, ['whatsapp', 'telegram'], true) ? $only : '';
        $DB->update_record(self::TABLE, $bulk);
        if ($approve) {
            self::release($bulk);
        }
        // The question that went to the others by Telegram is answered.
        \local_oksigeniamarandu\api::close_question('local_oksigeniamarandu', self::REF . $bulk->id, 'answered');
        self::tell_plugin($bulk);
        self::tell_asker($bulk);
        return true;
    }

    /**
     * Takes back a send that still waits, by whoever asked for it.
     *
     * @param int $id The send.
     * @param int $userid Who takes it back.
     * @return bool Whether it was taken back.
     */
    public static function cancel(int $id, int $userid): bool {
        global $DB;
        $lock = \core\lock\lock_config::get_lock_factory('local_oksigeniamarandu_bulk')->get_lock('bulk' . $id, 10);
        if (!$lock) {
            return false;
        }
        try {
            $bulk = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
            // Only whoever asked for it takes it back.
            if ($bulk->status !== 'pending' || (int)$bulk->userid !== $userid) {
                return false;
            }
            $bulk->status = 'cancelled';
            $bulk->decidedby = $userid;
            $bulk->timedecided = time();
            $DB->update_record(self::TABLE, $bulk);
        } finally {
            $lock->release();
        }
        \local_oksigeniamarandu\api::close_question('local_oksigeniamarandu', self::REF . $id, 'expired');
        return true;
    }

    /**
     * The sends that wait, and those decided lately, for the home of whoever sees everything.
     *
     * @param int $days How far back the decided ones go.
     * @return array pending and decided, each a list as export() gives it.
     */
    public static function recent(int $days = 7): array {
        global $DB;
        $pending = $DB->get_records(self::TABLE, ['status' => 'pending'], 'timecreated ASC');
        $decided = $DB->get_records_select(
            self::TABLE,
            "status <> 'pending' AND timedecided >= :since",
            ['since' => time() - $days * DAYSECS],
            'timedecided DESC',
            '*',
            0,
            10
        );
        return ['pending' => array_values(array_map([self::class, 'export'], $pending)),
            'decided' => array_values(array_map([self::class, 'export'], $decided))];
    }

    /**
     * A send for a page.
     *
     * @param \stdClass $bulk The send.
     * @return array
     */
    public static function export(\stdClass $bulk): array {
        global $PAGE;
        $asker = $bulk->userid ? \core_user::get_user((int)$bulk->userid) : null;
        $by = $bulk->decidedby ? \core_user::get_user((int)$bulk->decidedby) : null;
        $picture = '';
        if ($asker && $asker->picture) {
            $picture = (new \user_picture($asker))->get_url($PAGE)->out(false);
        }
        return [
            'id' => (int)$bulk->id,
            'label' => (string)$bulk->label,
            'plugin' => notices::group((string)$bulk->component)->title,
            'status' => (string)$bulk->status,
            'whatsapp' => (int)$bulk->whatsapp,
            'telegram' => (int)$bulk->telegram,
            'skipped' => (int)$bulk->skipped,
            'cost' => (float)$bulk->cost,
            'currency' => (string)$bulk->currency,
            'category' => (string)$bulk->category,
            'askedby' => $asker ? fullname($asker) : '',
            'askerid' => $asker ? (int)$asker->id : 0,
            'askerpicture' => $picture,
            'asked' => (int)$bulk->timecreated,
            'decidedby' => $by ? fullname($by) : '',
            'decidedbyfirst' => $by ? (string)$by->firstname : '',
            'decided' => (int)$bulk->timedecided,
            'reason' => (string)$bulk->reason,
            'only' => (string)$bulk->onlyby,
            'said' => self::said($bulk),
            'sent' => (int)$bulk->sent,
            'preview' => self::preview($bulk),
            'why' => $bulk->status === 'pending' ? self::why((float)$bulk->cost) : '',
        ];
    }

    /**
     * What whoever asked for a send reads of it, the same words wherever it is shown: «Waiting for the approval of Lucía
     * or David», «Approved by David · it goes now», «Rejected by Lucía: …».
     *
     * @param \stdClass $bulk The send.
     * @return string
     */
    public static function said(\stdClass $bulk): string {
        $by = $bulk->decidedby ? \core_user::get_user((int)$bulk->decidedby) : null;
        $a = (object)['by' => $by ? $by->firstname : '', 'reason' => (string)$bulk->reason];
        switch ($bulk->status) {
            case 'pending':
                $names = array_values(array_map(function ($user) {
                    return $user->firstname;
                }, self::approvers()));
                $last = array_pop($names);
                $who = $names ? get_string('bulk:or', self::COMPONENT, (object)['list' => implode(', ', $names),
                    'last' => $last]) : (string)$last;
                return get_string('bulk:said:waiting', self::COMPONENT, $who);
            case 'queued':
                return $by ? get_string(
                    $bulk->onlyby === 'telegram' ? 'bulk:said:approvedtelegram' : 'bulk:said:approved',
                    self::COMPONENT,
                    $a
                ) : get_string('bulk:said:queued', self::COMPONENT);
            case 'rejected':
                return get_string($a->reason !== '' ? 'bulk:said:rejectedwhy' : 'bulk:said:rejected', self::COMPONENT, $a);
            default:
                return get_string('bulk:said:cancelled', self::COMPONENT);
        }
    }

    /**
     * Why a send of this cost waits: it costs more than the threshold, or the month has no budget left for it.
     *
     * @param float $cost What it costs.
     * @return string threshold, budget or empty.
     */
    public static function why(float $cost): string {
        if ($cost > self::threshold()) {
            return 'threshold';
        }
        $budget = (float)get_config(self::COMPONENT, 'budgetmonth');
        return $budget > 0 && spend::month()['cost'] + $cost > $budget ? 'budget' : '';
    }

    /**
     * The cost from which a send waits for approval.
     *
     * @return float
     */
    public static function threshold(): float {
        $threshold = get_config(self::COMPONENT, 'bulkthreshold');
        return $threshold === false || $threshold === '' ? self::DEFAULT_THRESHOLD : (float)$threshold;
    }

    /**
     * The text a send carries, with the values of its first item, as the first person will read it.
     *
     * @param \stdClass $bulk The send.
     * @return string
     */
    private static function preview(\stdClass $bulk): string {
        global $DB;
        $declared = notices::get((string)$bulk->component, (string)$bulk->notice);
        $item = $DB->get_record(self::ITEMS, ['bulkid' => $bulk->id], 'params, lang', IGNORE_MULTIPLE);
        if (!$declared || !$item) {
            return '';
        }
        $params = (array)json_decode((string)$item->params, true);
        $text = notices::text($declared, 'template', $item->lang ?: null);
        return (string)preg_replace_callback('/\{\{(\d+)\}\}/', function ($match) use ($params) {
            return (string)($params[(int)$match[1] - 1] ?? $match[0]);
        }, $text);
    }

    /**
     * Whether a send of this cost waits for approval: from the threshold of the site, or when the month would go over its
     * budget.
     *
     * @param float $cost What it costs.
     * @return bool
     */
    public static function needs_approval(float $cost): bool {
        return $cost > 0 && self::why($cost) !== '';
    }

    /**
     * The people who can approve a send.
     *
     * @return \stdClass[]
     */
    public static function approvers(): array {
        return spend::people();
    }

    /**
     * Who each item reaches: by WhatsApp outside the 24 hours (it costs), inside them (free), by Telegram (free); the
     * items nobody of which can get it are left out.
     *
     * @param string $component The plugin.
     * @param \stdClass $declared The notice.
     * @param int[] $itemids The items.
     * @param string $area The kind of item.
     * @return array whatsapp, whatsappfree, telegram and skipped.
     */
    private static function reach(string $component, \stdClass $declared, array $itemids, string $area): array {
        global $DB;
        $count = ['whatsapp' => 0, 'whatsappfree' => 0, 'telegram' => 0, 'skipped' => 0];
        $itemids = array_values(array_unique(array_map('intval', $itemids)));
        if (!$itemids) {
            return $count;
        }
        $on = [];
        foreach (['whatsapp', 'telegram'] as $channel) {
            $on[$channel] = notices::allows($declared, $channel) && in_array($declared->key, notices::enabled($channel), true);
        }
        $reached = [];
        foreach (array_chunk($itemids, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'i');
            $rows = $DB->get_recordset_sql(
                "SELECT DISTINCT l.itemid, c.*
                   FROM {" . consent::LINKS . "} l
                   JOIN {" . consent::CONTACTS . "} c ON c.id = l.contactid
                  WHERE l.component = :component AND l.area = :area AND l.userid IS NULL AND l.itemid $insql
                        AND c.status = 'active'",
                $params + ['component' => $component, 'area' => $area]
            );
            $seen = [];
            foreach ($rows as $row) {
                $contactid = (int)$row->id;
                if (isset($seen[$contactid]) || empty($on[$row->channel]) || consent::out_of($row, $component)) {
                    continue;
                }
                $seen[$contactid] = true;
                $reached[(int)$row->itemid] = true;
                if ($row->channel === 'telegram') {
                    $count['telegram']++;
                } else if ((int)$row->lastinbound > time() - queue::WINDOW) {
                    $count['whatsappfree']++;
                } else {
                    $count['whatsapp']++;
                }
            }
            $rows->close();
        }
        $count['skipped'] = count($itemids) - count($reached);
        return $count;
    }

    /**
     * The category of the template of a notice, as Meta gave it; marketing until Meta says.
     *
     * @param \stdClass $declared The notice.
     * @return string utility, marketing or authentication.
     */
    private static function category(\stdClass $declared): string {
        $template = templates::approved($declared);
        $category = strtolower((string)($template->category ?? ''));
        return in_array($category, ['utility', 'marketing', 'authentication'], true) ? $category : 'marketing';
    }

    /**
     * Queues every item of a send, by the usual queue.
     *
     * @param \stdClass $bulk The send.
     */
    private static function release(\stdClass $bulk): void {
        global $DB;
        $itemids = [];
        foreach ($DB->get_recordset(self::ITEMS, ['bulkid' => $bulk->id], 'id ASC') as $item) {
            $url = trim((string)$item->url);
            $itemids[] = (int)$item->itemid;
            try {
                \local_oksigeniamarandu\api::notify(
                    (string)$bulk->component,
                    (string)$bulk->notice,
                    (array)json_decode((string)$item->params, true),
                    $url === '' ? null : new \moodle_url($url),
                    (int)$item->itemid,
                    null,
                    false,
                    2 * DAYSECS,
                    [],
                    (string)$bulk->area,
                    (string)$item->lang,
                    (string)$bulk->onlyby
                );
            } catch (\coding_exception $e) {
                debugging('Marandu: an item of a send did not go: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        // Who it reaches: the queue leaves out, when it sends, whoever said no to the plugin.
        $declared = notices::get((string)$bulk->component, (string)$bulk->notice);
        $reach = $declared ? self::reach((string)$bulk->component, $declared, $itemids, (string)$bulk->area) : [];
        $sent = $bulk->onlyby === 'telegram' ? (int)($reach['telegram'] ?? 0)
            : (int)($reach['whatsapp'] ?? 0) + (int)($reach['whatsappfree'] ?? 0) + (int)($reach['telegram'] ?? 0);
        $bulk->sent = $sent;
        $DB->set_field(self::TABLE, 'sent', $sent, ['id' => $bulk->id]);
    }

    /**
     * Tells whoever may approve that a send waits for them: in the bell of the site and, if they linked their Telegram,
     * with «Approve» and «Reject» there.
     *
     * @param \stdClass $bulk The send.
     */
    private static function ask_approvers(\stdClass $bulk): void {
        $asker = \core_user::get_user((int)$bulk->userid);
        $a = (object)[
            'by' => $asker ? $asker->firstname : '',
            'label' => (string)$bulk->label,
            'count' => (int)$bulk->whatsapp,
            'cost' => format_float((float)$bulk->cost, 2) . ' ' . $bulk->currency,
        ];
        $url = new \moodle_url('/local/oksigeniamarandu/chat.php', ['b' => 'inicio']);
        $subject = get_string('bulk:notify', self::COMPONENT, $a);
        foreach (self::approvers() as $approver) {
            $user = \core_user::get_user((int)$approver->id);
            if (!$user || $user->deleted || $user->suspended) {
                continue;
            }
            $message = new \core\message\message();
            $message->component = self::COMPONENT;
            $message->name = 'chat';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $user;
            $message->subject = $subject;
            $message->fullmessage = $subject;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '';
            $message->smallmessage = $subject;
            $message->notification = 1;
            $message->contexturl = $url->out(false);
            $message->contexturlname = get_string('chat:title', self::COMPONENT);
            message_send($message);
            try {
                \local_oksigeniamarandu\api::ask(
                    self::COMPONENT,
                    'bulk',
                    [get_string('bulk:line', self::COMPONENT, $a)],
                    self::REF . $bulk->id,
                    0,
                    (int)$user->id,
                    $url
                );
            } catch (\Throwable $e) {
                debugging('Marandu: the approval did not go to Telegram: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Tells whoever asked for a send that it was decided, in the bell: «Approved by David · it goes now», or «Rejected by
     * Lucía: …».
     *
     * @param \stdClass $bulk The send.
     */
    private static function tell_asker(\stdClass $bulk): void {
        $asker = $bulk->userid ? \core_user::get_user((int)$bulk->userid) : null;
        $by = \core_user::get_user((int)$bulk->decidedby);
        if (!$asker || $asker->deleted || $asker->suspended || !$by || (int)$asker->id === (int)$by->id) {
            return;
        }
        $subject = get_string('bulk:said', self::COMPONENT, (object)['label' => (string)$bulk->label, 'said' => self::said($bulk)]);
        $message = new \core\message\message();
        $message->component = self::COMPONENT;
        $message->name = 'chat';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $asker;
        $message->subject = $subject;
        $message->fullmessage = $subject;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $subject;
        $message->notification = 1;
        message_send($message);
    }

    /**
     * Tells the plugin of a send that it was decided.
     *
     * @param \stdClass $bulk The send.
     */
    private static function tell_plugin(\stdClass $bulk): void {
        if (\core_component::get_component_directory((string)$bulk->component) === null) {
            return;
        }
        component_callback(
            (string)$bulk->component,
            'marandu_bulk_decided',
            [(int)$bulk->id, (string)$bulk->status, (int)$bulk->decidedby]
        );
    }
}
