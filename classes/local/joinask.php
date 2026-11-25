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

    /** How many questions go a day, unless the site says: Meta lets a small account write to 250 people a day. */
    public const DAILY = 150;

    /**
     * Asks a number to join, once.
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
        if ($contact && ($contact->status === 'optedout' || consent::out_of($contact, $component))) {
            return 'declined';
        }
        if ($contact && $contact->status === 'active' && self::linked($contact, $component, $itemid, $area)) {
            return 'joined';
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_oksigeniamarandu_joinask')->get_lock($number, 10);
        if (!$lock) {
            return 'waiting';
        }
        try {
            $last = self::last($component, $number);
            if ($last && $last->status === 'no') {
                return 'declined';
            }
            if ($last && $last->status === 'asked') {
                return 'waiting';
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
        if (!$row || $row->phone !== $phone) {
            return true;
        }
        $wamid = (string)($message['id'] ?? '');
        if ($row->status === 'yes' || $row->status === 'no') {
            // Answered already: a second touch changes nothing.
            return true;
        }
        $now = time();
        if ($m[2] === 'y') {
            $result = consent::optin_from_message($phone, $row->code, $wamid, $name);
            $DB->update_record(self::TABLE, (object)['id' => $row->id, 'status' => $result === 'optin' ? 'yes' : 'failed',
                'timeanswered' => $now, 'timemodified' => $now]);
            consent::permission($phone, (string)$row->consentsource, (int)$row->consentat, $wamid);
            \local_oksigeniamarandu\task\send_reply::queue($phone, $result);
        } else {
            $DB->update_record(self::TABLE, (object)['id' => $row->id, 'status' => 'no', 'timeanswered' => $now,
                'timemodified' => $now]);
            consent::declined($phone, $row, $wamid, $name);
            \local_oksigeniamarandu\task\send_reply::queue($phone, 'joinno');
        }
        return true;
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
        if ($row && $row->status === 'asked') {
            $DB->update_record(self::TABLE, (object)['id' => $row->id, 'status' => 'failed', 'timemodified' => time(),
                'error' => \core_text::substr($error === '' ? 'failed' : $error, 0, 255)]);
        }
    }

    /**
     * How a number stands for an item, for its plugin to show it: since when it was asked and whether it answered no.
     *
     * @param string $component The plugin.
     * @param int $itemid The item.
     * @param string $area The kind of item.
     * @return array asked (when, 0 if it was not asked or it answered) and declined (when, 0 if not).
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
            'declined' => $row && $row->status === 'no' ? (int)$row->timeanswered : 0];
    }

    /**
     * How many questions cost money this month, for the spending of the site.
     *
     * @param int $since When the month starts.
     * @return int
     */
    public static function sent_since(int $since): int {
        global $DB;
        return $DB->count_records_select(self::TABLE, "timeasked >= ? AND status <> 'failed'", [$since]);
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
        $code = \local_oksigeniamarandu\api::invite($component, $itemid, null, $area);
        $row = (object)['component' => $component, 'area' => $area, 'itemid' => $itemid, 'phone' => $number, 'code' => $code,
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
        return $DB->count_records_select(self::TABLE, "timeasked >= ? AND status <> 'failed'", [$day->getTimestamp()]);
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
