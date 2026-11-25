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
 * Who writes, when WhatsApp does not say their number: whoever has a username there may write with only an identifier that
 * stands for them before this organisation. Meta gives it with the number as long as it gives the number, so the site
 * learns whose each one is and, when the number is missing, puts it back: everything else works by numbers, also the
 * shared inbox the webhook is passed on to.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class waid {
    /** The table. */
    public const TABLE = 'local_oksigeniamarandu_waid';

    /** Where each piece of a webhook has the identifier and the number. */
    private const WHERE = ['contacts' => ['user_id', 'wa_id'], 'messages' => ['from_user_id', 'from'],
        'statuses' => ['recipient_user_id', 'recipient_id']];

    /** What an identifier is like: the country, a point and letters and figures. */
    private const FORMAT = '/^[A-Z]{2}\.[A-Za-z0-9.]{1,140}$/';

    /**
     * Learns from a webhook of the number of the site, and puts the numbers it knows where they are missing.
     *
     * @param \stdClass $payload The body, decoded as objects; changed in place.
     * @param string $mine The number of the site, as Meta identifies it.
     * @return bool Whether it put any number.
     */
    public static function complete(\stdClass $payload, string $mine): bool {
        $put = false;
        if ($mine === '' || ($payload->object ?? '') !== 'whatsapp_business_account') {
            return false;
        }
        foreach ((array)($payload->entry ?? []) as $entry) {
            foreach ((array)($entry->changes ?? []) as $change) {
                $value = $change->value ?? null;
                if (
                    ($change->field ?? '') !== 'messages' || !($value instanceof \stdClass)
                        || (string)($value->metadata->phone_number_id ?? '') !== $mine
                ) {
                    continue;
                }
                foreach (self::WHERE as $list => [$id, $number]) {
                    foreach ((array)($value->$list ?? []) as $one) {
                        $put = ($one instanceof \stdClass && self::one($one, $id, $number)) || $put;
                    }
                }
            }
        }
        return $put;
    }

    /**
     * Learns from one piece of a webhook, or puts its number.
     *
     * @param \stdClass $one The piece, changed in place.
     * @param string $id Where its identifier is.
     * @param string $number Where its number is.
     * @return bool Whether it put the number.
     */
    private static function one(\stdClass $one, string $id, string $number): bool {
        $user = (string)($one->$id ?? '');
        if (($one->type ?? '') === 'system') {
            self::system($one);
            $user = (string)($one->system->previous_user_id ?? $user);
        }
        if (!preg_match(self::FORMAT, $user)) {
            return false;
        }
        if (!empty($one->$number)) {
            if (($one->type ?? '') !== 'system') {
                self::learn($user, (string)$one->$number);
            }
            return false;
        }
        $phone = self::phone($user);
        if ($phone === null) {
            return false;
        }
        $one->$number = $phone;
        return true;
    }

    /**
     * Somebody changed their number, or their identifier: what was known of the old one is of the new one.
     *
     * @param \stdClass $message The message of the system.
     */
    private static function system(\stdClass $message): void {
        $system = $message->system ?? null;
        $type = (string)($system->type ?? '');
        if (!($system instanceof \stdClass) || !in_array($type, ['user_changed_number', 'user_changed_user_id'], true)) {
            return;
        }
        $before = (string)($system->previous_user_id ?? '');
        $now = (string)($system->user_id ?? '');
        $old = preg_replace('/[^0-9]/', '', (string)($message->from ?? '')) ?: (string)self::phone($before);
        $new = preg_replace('/[^0-9]/', '', (string)($system->wa_id ?? ''));
        if ($type === 'user_changed_number' && $new !== '' && $old !== '' && $new !== $old) {
            consent::moved($old, $new, (string)($message->id ?? ''));
        }
        $phone = $type === 'user_changed_number' ? $new : $old;
        if ($phone !== '' && preg_match(self::FORMAT, $now)) {
            self::learn($now, $phone);
        }
        if ($before !== '' && $before !== $now) {
            self::forget($before);
        }
    }

    /**
     * Keeps whose an identifier is.
     *
     * @param string $user The identifier.
     * @param string $phone The number.
     */
    public static function learn(string $user, string $phone): void {
        global $DB;
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if ($phone === '' || !preg_match(self::FORMAT, $user)) {
            return;
        }
        $row = $DB->get_record(self::TABLE, ['waid' => $user]);
        if ($row) {
            if ($row->phone !== $phone) {
                $DB->update_record(self::TABLE, (object)['id' => $row->id, 'phone' => $phone, 'timemodified' => time()]);
            }
            return;
        }
        try {
            $DB->insert_record(self::TABLE, (object)['waid' => $user, 'phone' => $phone, 'timemodified' => time()]);
        } catch (\dml_write_exception $e) {
            // Two webhooks of the same person at once: the other one kept it.
            debugging('Marandu: an identifier was already kept', DEBUG_DEVELOPER);
        }
    }

    /**
     * The number of an identifier, if the site knows it.
     *
     * @param string $user The identifier.
     * @return string|null Digits with the country code.
     */
    public static function phone(string $user): ?string {
        global $DB;
        $phone = $user === '' ? false : $DB->get_field(self::TABLE, 'phone', ['waid' => $user]);
        return $phone ? (string)$phone : null;
    }

    /**
     * Forgets an identifier that is nobody's any more.
     *
     * @param string $user The identifier.
     */
    public static function forget(string $user): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['waid' => $user]);
    }
}
