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
 * Writing first to a number that has not joined, with the permission its plugin has: a family that gave its phone to be
 * written to, and has not said no. The number joins for that item as implied, until it writes or answers. Only a word of
 * leaving (or a «no» to a question to join) stops it, as always: a «no» to what a send to many brought, such as an
 * invitation, is an answer to that, and reaches its plugin (bulk::answer()). Also what WhatsApp lets a number write to in
 * a day, and how well its messages are taken, as Meta says each hour: what goes to people who did not join waits while
 * the line is not green.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class implied {
    /** The people a day each level of Meta lets a number write first to; 0, no limit. */
    public const LIMITS = ['TIER_250' => 250, 'TIER_1K' => 1000, 'TIER_2K' => 2000, 'TIER_10K' => 10000,
        'TIER_100K' => 100000, 'TIER_UNLIMITED' => 0];

    /** The people a day when Meta has not said yet: the lowest level. */
    public const UNKNOWN = 250;

    /** The share of the people a day the queue uses, so that the team still has room to write first from the chat. */
    public const SHARE = 0.8;

    /** What Meta says when a number cannot get the message, such as a landline or a number without WhatsApp. */
    public const NOWHATSAPP = [131026];

    /** What Meta says when it holds back a marketing message to keep people from getting too many. */
    public const METALIMIT = [131049];

    /** How long a notice of a send to people who did not join may wait for room. */
    public const VALIDFOR = 7 * DAYSECS;

    /** @var string The component. */
    private const COMPONENT = 'local_oksigeniamarandu';

    /**
     * A number as WhatsApp takes it: digits with the country code, from E.164.
     *
     * @param string $phone Such as +34600000000.
     * @return string|null The digits; null if it is not a number.
     */
    public static function digits(string $phone): ?string {
        $phone = trim($phone);
        if (!preg_match('/^\+?[1-9][0-9]{6,14}$/', $phone)) {
            return null;
        }
        return ltrim($phone, '+');
    }

    /**
     * Why a number cannot be written to by a plugin: it said no to everything, to that plugin or to its question to join.
     *
     * @param \stdClass|null $contact The contact of the number, if the site has it.
     * @param string $component The plugin.
     * @return string Empty if it can; optout, declined or nomarketing.
     */
    public static function why_not(?\stdClass $contact, string $component): string {
        global $DB;
        if (!$contact) {
            return '';
        }
        if ($contact->status === 'optedout') {
            return 'optout';
        }
        $row = $DB->get_record(consent::OPTOUTS, ['contactid' => $contact->id, 'scope' => $component]);
        if ($row) {
            return $row->source === 'asked' ? 'declined' : 'optout';
        }
        if ($DB->record_exists(consent::OPTOUTS, ['contactid' => $contact->id, 'scope' => queue::MARKETING])) {
            return 'nomarketing';
        }
        return '';
    }

    /**
     * Joins a number for an item with the permission its plugin has, unless it said no. A number already joined only gets
     * the item too; a new one is joined as implied, without any welcome.
     *
     * @param string $phone The number, E.164.
     * @param string $component The plugin.
     * @param string $area The kind of item.
     * @param int $itemid The item.
     * @param string $source The permission, such as eguahe:invite.
     * @return string Empty if it is joined; invalid, optout, declined or nomarketing.
     */
    public static function join(string $phone, string $component, string $area, int $itemid, string $source): string {
        global $DB;
        $digits = self::digits($phone);
        if ($digits === null) {
            return 'invalid';
        }
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => $digits]);
        $why = self::why_not($contact ?: null, $component);
        if ($why !== '') {
            return $why;
        }
        $source = \core_text::substr(trim($source) ?: $component, 0, 100);
        $contact = $contact ?: consent::get_or_create($digits);
        if ($contact->status !== 'active') {
            $now = time();
            $DB->update_record(consent::CONTACTS, (object)['id' => $contact->id, 'status' => 'active', 'consenttime' => $now,
                'consentsource' => $source, 'implied' => $source, 'timemodified' => $now]);
            $DB->insert_record(consent::LOG, (object)['contactid' => $contact->id, 'action' => 'implied', 'source' => $source,
                'detail' => \core_text::substr($component . ':' . $itemid, 0, 255), 'timecreated' => $now]);
        }
        consent::link_asked($contact, (object)['component' => $component, 'area' => $area, 'itemid' => $itemid]);
        return '';
    }

    /**
     * The permission a number was joined with while it has not written or answered.
     *
     * @param string $phone Digits with the country code.
     * @return string Empty if it joined by itself or is not joined.
     */
    public static function of(string $phone): string {
        global $DB;
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => $phone], 'id, status, implied');
        return $contact && $contact->status === 'active' ? (string)$contact->implied : '';
    }

    /**
     * How the line is, as Meta said last: its quality, the people a day it may write first to, and when it was read.
     *
     * @return array quality (GREEN, YELLOW, RED or empty), limit (people a day; 0, no limit) and time.
     */
    public static function line(): array {
        $config = get_config(self::COMPONENT);
        $time = (int)($config->linetime ?? 0);
        return [
            'quality' => (string)($config->linequality ?? ''),
            'limit' => $time ? (int)($config->linelimit ?? self::UNKNOWN) : self::UNKNOWN,
            'time' => $time,
        ];
    }

    /**
     * Reads the line again from Meta. When its quality stops being green, whoever approves the sends hears once that what
     * goes to people who did not join waits.
     *
     * @param cloud_api $api The number of the site.
     * @return array As line() gives it.
     */
    public static function refresh(cloud_api $api): array {
        $before = self::line();
        $answer = $api->get_line();
        $tier = (string)($answer['whatsapp_business_manager_messaging_limit'] ?? '');
        $quality = strtoupper((string)($answer['quality_rating'] ?? ''));
        set_config('linequality', $quality, self::COMPONENT);
        set_config('linelimit', array_key_exists($tier, self::LIMITS) ? self::LIMITS[$tier] : self::UNKNOWN, self::COMPONENT);
        set_config('linetime', time(), self::COMPONENT);
        if (in_array($quality, ['YELLOW', 'RED'], true) && $before['quality'] !== $quality) {
            self::tell_quality($quality);
        }
        return self::line();
    }

    /**
     * Whether the quality of the line lets what goes to people who did not join go out: green, or not read yet.
     *
     * @return bool
     */
    public static function quality_ok(): bool {
        $quality = self::line()['quality'];
        return $quality === '' || $quality === 'GREEN' || $quality === 'UNKNOWN';
    }

    /**
     * The numbers written first to in the last 24 hours, by the queue and by the questions to join: what Meta counts.
     *
     * @param int $now The time.
     * @return array The numbers, as keys.
     */
    public static function written_today(int $now): array {
        global $DB;
        $since = $now - DAYSECS;
        $phones = $DB->get_fieldset_sql("SELECT DISTINCT c.phone
                                           FROM {" . queue::TABLE . "} q
                                           JOIN {" . consent::CONTACTS . "} c ON c.id = q.contactid
                                          WHERE q.kind = 'template' AND q.timesent > :since
                                                AND q.status IN ('sent', 'delivered', 'read')", ['since' => $since]);
        $asked = $DB->get_fieldset_sql("SELECT DISTINCT phone FROM {" . joinask::TABLE . "}
                                         WHERE wamid IS NOT NULL AND timeasked > :since", ['since' => $since]);
        return array_fill_keys(array_merge($phones, $asked), true);
    }

    /**
     * Whether one more number fits in what the line may write first to today.
     *
     * @param array $today The numbers written first to, as written_today() gives them.
     * @param string $phone The number.
     * @return bool
     */
    public static function room(array $today, string $phone): bool {
        $limit = self::line()['limit'];
        return $limit <= 0 || isset($today[$phone]) || count($today) < (int)floor($limit * self::SHARE);
    }

    /**
     * Tells whoever approves the sends that the quality of the line went down, in the bell.
     *
     * @param string $quality YELLOW or RED.
     */
    private static function tell_quality(string $quality): void {
        $subject = get_string('implied:quality', self::COMPONENT, get_string(
            'implied:quality' . strtolower($quality),
            self::COMPONENT
        ));
        foreach (bulk::approvers() as $approver) {
            $user = \core_user::get_user((int)$approver->id);
            if (!$user || $user->deleted || $user->suspended) {
                continue;
            }
            $message = new \core\message\message();
            $message->component = self::COMPONENT;
            $message->name = 'spend';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $user;
            $message->subject = $subject;
            $message->fullmessage = $subject;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '';
            $message->smallmessage = $subject;
            $message->notification = 1;
            $message->customdata = message_icon::customdata();
            $message->contexturl = (new \moodle_url('/local/oksigeniamarandu/chat.php', ['b' => 'inicio']))->out(false);
            $message->contexturlname = get_string('chat:title', self::COMPONENT);
            try {
                \local_oksigeniamarandu\api::mail_notice($message);
                message_send($message);
            } catch (\Throwable $e) {
                debugging('Marandu: the quality of the line was not told: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }
}
