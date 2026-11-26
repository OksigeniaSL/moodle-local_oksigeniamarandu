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
 * Asking a person to join by WhatsApp with one touch: they gave their number and their permission somewhere else, such as a
 * page of a plugin, and here they say, with a button, that the number is theirs and that the messages may come. Nobody
 * joins without touching «Yes»: a number written wrong brings nothing to a stranger but one question. Meta takes permission
 * given outside WhatsApp, and a template that confirms it is a utility one.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class joinask {
    /** The table. */
    public const TABLE = 'local_oksigeniamarandu_joinask';

    /** The notice whose template asks. */
    public const NOTICE = 'join';

    /** What the buttons send back, with the number of the question and the answer. */
    private const DATA = '/^mrdj:(\d+):([yn])$/';

    /** How long a question waits for its answer before a new permission of the person can ask it again. */
    public const AGAIN = 7 * DAYSECS;

    /** The most questions a number gets from a plugin, whatever it answers. */
    public const MOST = 3;

    /** For how long «sí», written instead of touched, is the answer. */
    private const TYPED = 3 * DAYSECS;

    /** How many questions go a day, unless the site says: Meta lets a small account write to 250 people a day. */
    public const DAILY = 150;

    /**
     * Asks a number to join. Once, unless the person gives a new permission after saying no, or a week after a question
     * they did not answer; and never more than MOST times. A question that waits for an answer is the same for the other
     * items of the plugin with that number: a yes joins them all.
     *
     * @param string $component The plugin of the item.
     * @param int $itemid The item, such as a family.
     * @param string $phone The number, as the person gave it (with + or 00).
     * @param int $when When the person gave permission.
     * @param string $where Where, such as eguahe:family.
     * @param string $area The kind of item.
     * @param cloud_api|null $api The client, for the tests; that of the site if null.
     * @param int|null $now The time, for the tests.
     * @return string asked (it went now), waiting (asked before, without an answer yet), joined (they had joined already),
     *                declined (they said no), invalid (not a number), limit (not today) or notready (WhatsApp is not ready, or
     *                its template is not approved yet, or the site did not switch it on).
     */
    public static function ask(
        string $component,
        int $itemid,
        string $phone,
        int $when,
        string $where,
        string $area = '',
        ?cloud_api $api = null,
        ?int $now = null
    ): string {
        global $DB;
        $now = $now ?? time();
        $number = phone::normalise($phone);
        if ($number === null) {
            return 'invalid';
        }
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => $number]);
        if ($contact) {
            $out = consent::optout_now($contact);
            if ($out['all'] || $contact->status === 'optedout' || ($out['scopes'][$component] ?? 0) >= $when) {
                return 'declined';
            }
        }
        $in = $contact && $contact->status === 'active' && !isset($out['scopes'][$component]);
        if ($in && self::linked($contact, $component, $itemid, $area)) {
            return 'joined';
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_oksigeniamarandu_joinask')->get_lock($number, 10);
        if (!$lock) {
            return 'waiting';
        }
        try {
            $last = self::last($component, $number);
            if ($last && $last->status === 'no' && (int)$last->timeanswered >= $when) {
                return 'declined';
            }
            $soon = $last && ($when <= (int)$last->timeasked || $now < (int)$last->timeasked + self::AGAIN);
            if ($last && $last->status === 'asked' && $soon) {
                self::wait_too($last, $itemid, $area, $when, $where, $now);
                return 'waiting';
            }
            if ($last && self::times($component, $number) >= self::MOST) {
                return $last->status === 'asked' ? 'waiting' : 'declined';
            }
            $template = self::ready($api);
            if (!$template) {
                return 'notready';
            }
            if (self::today($now) >= self::daily()) {
                return 'limit';
            }
            return self::send($component, $itemid, $area, $number, $when, $where, $template, $api ?? cloud_api::from_config(), $now);
        } finally {
            $lock->release();
        }
    }

    /**
     * The answer of a button, if a message of WhatsApp is one.
     *
     * @param array $message The message, as Meta sends it.
     * @param string $phone Who wrote, digits with the country code.
     * @param string|null $name Their profile name, if WhatsApp sent it.
     * @return bool Whether it was an answer to a question, and so nothing else.
     */
    public static function from_whatsapp(array $message, string $phone, ?string $name): bool {
        global $DB;
        $data = (string)($message['button']['payload'] ?? $message['interactive']['button_reply']['id'] ?? '');
        if (!preg_match(self::DATA, $data, $m)) {
            return false;
        }
        $row = $DB->get_record(self::TABLE, ['id' => (int)$m[1]]);
        if ($row && $row->phone === $phone) {
            $at = (int)($message['timestamp'] ?? 0) ?: time();
            self::answer($row, $m[2] === 'y', (string)($message['id'] ?? ''), $at, $name);
        }
        return true;
    }

    /**
     * A «sí» or a «no» written instead of touched, to a question of the last days: only if it is all the message says, the
     * question is the last thing the site sent to that number and nobody wrote since.
     *
     * @param string $phone Who wrote, digits with the country code.
     * @param string $text What they wrote.
     * @param string $detail The id of the message.
     * @param string|null $name Their profile name, if WhatsApp sent it.
     * @return bool Whether it was the answer, and so nothing else.
     */
    public static function typed(string $phone, string $text, string $detail, ?string $name): bool {
        global $DB;
        $said = self::plain($text);
        $yes = in_array($said, self::words('yes'), true);
        if (!$yes && !in_array($said, self::words('no'), true)) {
            return false;
        }
        $rows = $DB->get_records_select(
            self::TABLE,
            "phone = :phone AND wamid IS NOT NULL AND timeasked > :since",
            ['phone' => $phone, 'since' => time() - self::TYPED],
            'id DESC',
            '*',
            0,
            1
        );
        $row = reset($rows);
        if (!$row || $row->status !== 'asked') {
            return false;
        }
        $since = ['phone' => $phone, 'since' => (int)$row->timeasked];
        $talked = $DB->record_exists_select(
            chat\mirror::TABLE,
            "phone = :phone AND (status = 'open' OR lastincoming > :since)",
            $since
        ) || $DB->record_exists_sql("SELECT q.id
                                       FROM {" . queue::TABLE . "} q
                                       JOIN {" . consent::CONTACTS . "} c ON c.id = q.contactid
                                      WHERE c.phone = :phone AND q.timesent > :since", $since);
        if ($talked) {
            return false;
        }
        self::answer($row, $yes, $detail, time(), $name);
        return true;
    }

    /**
     * The answer to a question: yes joins the number for its item, and for the others of the plugin that wait for the same
     * answer; no stops what the plugin sends to that number. The last touch counts, so that whoever touched the wrong
     * button can touch the other one.
     *
     * @param \stdClass $row The question.
     * @param bool $yes The answer.
     * @param string $detail The id of the message.
     * @param int $at When it was answered, as WhatsApp says it.
     * @param string|null $name The profile name, if WhatsApp sent it.
     */
    protected static function answer(\stdClass $row, bool $yes, string $detail, int $at, ?string $name): void {
        global $DB;
        // A webhook that comes twice, the same touch again, or one older than the last.
        if ((int)$row->timeanswered && ($at <= (int)$row->timeanswered || $row->status === ($yes ? 'yes' : 'no'))) {
            return;
        }
        $phone = (string)$row->phone;
        $now = time();
        $done = ['timeanswered' => $at, 'timemodified' => $now];
        if ($row->status === 'over') {
            // Whatever it was about is not there any more: nothing to join, but a no is a no.
            $DB->update_record(self::TABLE, (object)(['id' => $row->id] + $done));
            if (!$yes) {
                consent::declined($phone, $row, $detail, $name);
            }
            \local_oksigeniamarandu\task\send_reply::queue($phone, $yes ? 'joinover' : 'joinno');
            return;
        }
        // The other items of the plugin with that number: those that wait for this answer and, for a no, those that joined.
        [$in, $params] = $DB->get_in_or_equal($yes ? ['asked'] : ['asked', 'yes'], SQL_PARAMS_NAMED);
        $rows = [$row->id => $row] + $DB->get_records_select(
            self::TABLE,
            "component = :component AND phone = :phone AND status $in",
            $params + ['component' => $row->component, 'phone' => $phone],
            'id ASC'
        );
        foreach ($rows as $one) {
            $DB->update_record(self::TABLE, (object)(['id' => $one->id, 'status' => $yes ? 'yes' : 'no'] + $done));
        }
        if ($yes) {
            consent::join_asked($phone, $rows, $detail, $name);
            consent::permission($phone, (string)$row->consentsource, (int)$row->consentat, $detail);
            \local_oksigeniamarandu\task\send_reply::queue($phone, 'optin');
            return;
        }
        foreach ($rows as $one) {
            consent::declined($phone, $one, $detail, $name);
        }
        \local_oksigeniamarandu\task\send_reply::queue($phone, 'joinno');
    }

    /**
     * Whoever writes the opt-in word says yes to what their number was asked and did not answer, or had said no to: that is
     * what the answer to the no tells them to do.
     *
     * @param \stdClass $contact The number, already in.
     * @param string $detail The id of the message.
     */
    public static function word(\stdClass $contact, string $detail): void {
        global $DB;
        [$in, $params] = $DB->get_in_or_equal(['asked', 'no'], SQL_PARAMS_NAMED);
        $rows = $DB->get_records_select(
            self::TABLE,
            "phone = :phone AND status $in",
            $params + ['phone' => $contact->phone],
            'id ASC'
        );
        $now = time();
        foreach ($rows as $row) {
            $DB->update_record(self::TABLE, (object)['id' => $row->id, 'status' => 'yes', 'timeanswered' => $now,
                'timemodified' => $now]);
            consent::link_asked($contact, $row);
        }
        if ($rows) {
            consent::joined_asked($contact, $rows);
            $last = end($rows);
            consent::permission((string)$contact->phone, (string)$last->consentsource, (int)$last->consentat, $detail);
        }
    }

    /**
     * The item of a question is not there any more: a touch joins nobody to it.
     *
     * @param string $component The plugin.
     * @param int $itemid The item.
     * @param string $area The kind of item.
     * @param string|null $phone Only the question to this number, in digits; those of the item if null.
     */
    public static function close(string $component, int $itemid, string $area = '', ?string $phone = null): void {
        global $DB;
        $DB->set_field(self::TABLE, 'status', 'over', ['component' => $component, 'itemid' => $itemid, 'area' => $area,
            'status' => 'asked'] + ($phone === null ? [] : ['phone' => $phone]));
    }

    /**
     * The questions of an item are about another one from now on: both were the same.
     *
     * @param string $component The plugin.
     * @param int $from The item that goes.
     * @param int $to The item that stays.
     * @param string $area The kind of item.
     */
    public static function move(string $component, int $from, int $to, string $area = ''): void {
        global $DB;
        $DB->set_field(self::TABLE, 'itemid', $to, ['component' => $component, 'itemid' => $from, 'area' => $area]);
    }

    /**
     * What Meta says of a question that went: if it could not be delivered, it may be asked again.
     *
     * @param string $wamid The message.
     * @param string $status What happened to it.
     * @param string $error What Meta says.
     */
    public static function status(string $wamid, string $status, string $error = ''): void {
        global $DB;
        if ($wamid === '' || $status !== 'failed') {
            return;
        }
        $row = $DB->get_record(self::TABLE, ['wamid' => $wamid]);
        if (!$row || $row->status !== 'asked') {
            return;
        }
        // And what waited for its answer too.
        $rows = [$row->id => $row] + $DB->get_records_select(
            self::TABLE,
            "component = ? AND phone = ? AND status = 'asked' AND wamid IS NULL",
            [$row->component, $row->phone]
        );
        foreach ($rows as $one) {
            $DB->update_record(self::TABLE, (object)['id' => $one->id, 'status' => 'failed', 'timemodified' => time(),
                'error' => \core_text::substr($error === '' ? 'failed' : $error, 0, 255)]);
        }
    }

    /**
     * How a number stands for an item, for its plugin to show it: since when it was asked, whether it answered no and
     * whether the question could not be delivered.
     *
     * @param string $component The plugin.
     * @param int $itemid The item.
     * @param string $area The kind of item.
     * @return array asked (when, 0 if it was not asked or it answered), declined (when, 0 if not) and failed (when, 0 if
     *               not).
     */
    public static function state(string $component, int $itemid, string $area = ''): array {
        global $DB;
        $rows = $DB->get_records(
            self::TABLE,
            ['component' => $component, 'itemid' => $itemid, 'area' => $area],
            'id DESC',
            '*',
            0,
            1
        );
        $row = reset($rows);
        return ['asked' => $row && $row->status === 'asked' ? (int)$row->timeasked : 0,
            'declined' => $row && $row->status === 'no' ? (int)$row->timeanswered : 0,
            'failed' => $row && $row->status === 'failed' ? (int)$row->timemodified : 0];
    }

    /**
     * The last question that went to a number.
     *
     * @param string $phone Digits with the country code.
     * @param int $since Not before.
     * @return \stdClass|null
     */
    public static function last_sent(string $phone, int $since): ?\stdClass {
        global $DB;
        $rows = $DB->get_records_select(
            self::TABLE,
            "phone = ? AND timeasked >= ? AND status <> 'failed' AND wamid IS NOT NULL",
            [$phone, $since],
            'timeasked DESC, id DESC',
            '*',
            0,
            1
        );
        return $rows ? reset($rows) : null;
    }

    /**
     * The questions that went to a number, as steps of its trail.
     *
     * @param string $phone Digits with the country code.
     * @return array Each with action (asked), source (what became of it), scope (the plugin) and time.
     */
    public static function asked_to(string $phone): array {
        global $DB;
        $rows = $DB->get_records_select(
            self::TABLE,
            'phone = ? AND wamid IS NOT NULL',
            [$phone],
            'timeasked DESC, id DESC',
            '*',
            0,
            5
        );
        return array_values(array_map(function ($row) {
            return ['action' => 'asked', 'source' => (string)$row->status, 'scope' => (string)$row->component,
                'time' => (int)$row->timeasked];
        }, $rows));
    }

    /**
     * How many questions cost money this month, for the spending of the site.
     *
     * @param int $since When the month starts.
     * @return int
     */
    public static function sent_since(int $since): int {
        global $DB;
        return $DB->count_records_select(self::TABLE, "timeasked >= ? AND status <> 'failed' AND wamid IS NOT NULL", [$since]);
    }

    /**
     * Sends the question.
     *
     * @param string $component The plugin.
     * @param int $itemid The item.
     * @param string $area The kind of item.
     * @param string $number The number.
     * @param int $when When the permission was given.
     * @param string $where Where.
     * @param \stdClass $template The approved template.
     * @param cloud_api $api The client.
     * @param int $now The time.
     * @return string asked, or notready if Meta did not take it.
     */
    protected static function send(
        string $component,
        int $itemid,
        string $area,
        string $number,
        int $when,
        string $where,
        \stdClass $template,
        cloud_api $api,
        int $now
    ): string {
        global $DB, $CFG;
        $row = (object)['component' => $component, 'area' => $area, 'itemid' => $itemid, 'phone' => $number,
            'status' => 'asked', 'consentsource' => \core_text::substr($where, 0, 100), 'consentat' => $when,
            'timeasked' => $now, 'timemodified' => $now];
        $row->id = (int)$DB->insert_record(self::TABLE, $row);
        $lang = (string)$template->lang;
        $of = trim((string)get_config('local_oksigeniamarandu', 'cardorgof'));
        $value = $of !== '' && $lang === $CFG->lang ? $of
            : get_string_manager()->get_string('confirm:of', 'local_oksigeniamarandu', cards::org(), $lang);
        try {
            $wamid = $api->send_template($number, $template->name, notices::metalang($lang), [
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $value]]],
                ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => '0',
                    'parameters' => [['type' => 'payload', 'payload' => 'mrdj:' . $row->id . ':y']]],
                ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => '1',
                    'parameters' => [['type' => 'payload', 'payload' => 'mrdj:' . $row->id . ':n']]],
            ]);
        } catch (api_exception $e) {
            debugging('Marandu: a question to join did not go: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $DB->update_record(self::TABLE, (object)['id' => $row->id, 'status' => 'failed',
                'error' => \core_text::substr($e->getMessage(), 0, 255), 'timemodified' => $now]);
            return 'notready';
        }
        $DB->update_record(self::TABLE, (object)['id' => $row->id, 'wamid' => $wamid, 'timemodified' => $now]);
        return 'asked';
    }

    /**
     * The template of the question, if WhatsApp is ready for it.
     *
     * @param cloud_api|null $api The client of a test: then the account does not have to be set up.
     * @return \stdClass|null
     */
    protected static function ready(?cloud_api $api): ?\stdClass {
        $key = 'local_oksigeniamarandu/' . self::NOTICE;
        if ((!$api && !cloud_api::is_configured()) || !in_array($key, notices::enabled('whatsapp'), true)) {
            return null;
        }
        $notice = notices::get('local_oksigeniamarandu', self::NOTICE);
        return $notice ? templates::approved($notice) : null;
    }

    /**
     * The last question a number got from a plugin.
     *
     * @param string $component The plugin.
     * @param string $number The number.
     * @return \stdClass|null
     */
    protected static function last(string $component, string $number): ?\stdClass {
        global $DB;
        $rows = $DB->get_records(self::TABLE, ['component' => $component, 'phone' => $number], 'id DESC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    /**
     * How many questions a number got from a plugin.
     *
     * @param string $component The plugin.
     * @param string $number The number.
     * @return int
     */
    protected static function times(string $component, string $number): int {
        global $DB;
        return $DB->count_records_select(
            self::TABLE,
            "component = ? AND phone = ? AND status <> 'failed' AND wamid IS NOT NULL",
            [$component, $number]
        );
    }

    /**
     * Another item of the plugin with the same number waits for the answer to the question that already went.
     *
     * @param \stdClass $question The question.
     * @param int $itemid The item.
     * @param string $area The kind of item.
     * @param int $when When the permission was given.
     * @param string $where Where.
     * @param int $now The time.
     */
    protected static function wait_too(
        \stdClass $question,
        int $itemid,
        string $area,
        int $when,
        string $where,
        int $now
    ): void {
        global $DB;
        $item = ['component' => $question->component, 'area' => $area, 'itemid' => $itemid, 'phone' => $question->phone,
            'status' => 'asked'];
        if (!$DB->record_exists(self::TABLE, $item)) {
            $DB->insert_record(self::TABLE, (object)($item + ['consentsource' => \core_text::substr($where, 0, 100),
                'consentat' => $when, 'timeasked' => (int)$question->timeasked, 'timemodified' => $now]));
        }
    }

    /**
     * The words that are a whole answer, in the languages of the site.
     *
     * @param string $answer yes or no.
     * @return string[]
     */
    public static function words(string $answer): array {
        $strings = get_string_manager();
        $words = [];
        foreach (array_unique(array_merge(['en'], array_keys($strings->get_list_of_translations()))) as $lang) {
            $list = $strings->get_string('join:' . $answer . 'words', 'local_oksigeniamarandu', null, $lang);
            array_push($words, ...array_map([self::class, 'plain'], explode(',', $list)));
        }
        return array_values(array_filter(array_unique($words), 'strlen'));
    }

    /**
     * A text in small letters and without punctuation.
     *
     * @param string $text The text.
     * @return string
     */
    public static function plain(string $text): string {
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', \core_text::strtolower($text));
        return trim((string)$text);
    }

    /**
     * Whether a number already stands for an item.
     *
     * @param \stdClass $contact The number.
     * @param string $component The plugin.
     * @param int $itemid The item.
     * @param string $area The kind of item.
     * @return bool
     */
    protected static function linked(\stdClass $contact, string $component, int $itemid, string $area): bool {
        global $DB;
        return $DB->record_exists(consent::LINKS, ['contactid' => $contact->id, 'component' => $component, 'itemid' => $itemid,
            'area' => $area]);
    }

    /**
     * How many questions went today.
     *
     * @param int $now The time.
     * @return int
     */
    protected static function today(int $now): int {
        global $DB;
        $day = new \DateTime('@' . $now);
        $day->setTimezone(\core_date::get_server_timezone_object());
        $day->setTime(0, 0);
        return $DB->count_records_select(self::TABLE, "timeasked >= ? AND status <> 'failed' AND wamid IS NOT NULL", [$day->getTimestamp()]);
    }

    /**
     * How many questions may go a day.
     *
     * @return int
     */
    protected static function daily(): int {
        $said = get_config('local_oksigeniamarandu', 'joinaskday');
        return $said === false || $said === '' ? self::DAILY : max(0, (int)$said);
    }
}
