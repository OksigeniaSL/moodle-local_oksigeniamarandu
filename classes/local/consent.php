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
 * Contacts and their consent: without an opt-in, nothing is ever sent to a number.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class consent {
    /** Table of contacts. */
    public const CONTACTS = 'local_oksigeniamarandu_contact';

    /** Table of links between contacts and whom they stand for. */
    public const LINKS = 'local_oksigeniamarandu_link';

    /** Table of the opt-ins and opt-outs, kept as proof. */
    public const LOG = 'local_oksigeniamarandu_consent';

    /**
     * The contact of a number, created as pending if it does not exist yet.
     *
     * @param string $phone Digits with the country code.
     * @param string|null $name Profile name WhatsApp sent, if any.
     * @return \stdClass
     */
    public static function get_or_create(string $phone, ?string $name = null): \stdClass {
        global $DB;
        $contact = $DB->get_record(self::CONTACTS, ['phone' => $phone]);
        if ($contact) {
            return $contact;
        }
        $now = time();
        $contact = (object)[
            'phone' => $phone,
            'name' => $name === null ? null : \core_text::substr(clean_param($name, PARAM_NOTAGS), 0, 255),
            'status' => 'pending',
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        try {
            $contact->id = $DB->insert_record(self::CONTACTS, $contact);
        } catch (\dml_write_exception $e) {
            // Two messages of the same number at once: the other one created it.
            return $DB->get_record(self::CONTACTS, ['phone' => $phone], '*', MUST_EXIST);
        }
        return $contact;
    }

    /**
     * Notes that a number has just written: it opens the 24-hour window of WhatsApp.
     *
     * @param string $phone Digits with the country code.
     * @param string|null $name Profile name WhatsApp sent, if any.
     */
    public static function touch(string $phone, ?string $name = null): void {
        global $DB;
        $contact = $DB->get_record(self::CONTACTS, ['phone' => $phone]);
        if (!$contact) {
            return;
        }
        $contact->lastinbound = time();
        if ($name !== null && empty($contact->name)) {
            $contact->name = \core_text::substr(clean_param($name, PARAM_NOTAGS), 0, 255);
        }
        $DB->update_record(self::CONTACTS, $contact);
    }

    /**
     * Opt in from a message: with a code, the number is linked to whom the code stands for.
     *
     * @param string $phone Digits with the country code.
     * @param string|null $code The code written after the opt-in word, if any.
     * @param string $detail The id of the WhatsApp message.
     * @param string|null $name Profile name WhatsApp sent, if any.
     * @return string The reply to send: optin or unknowncode.
     */
    public static function optin_from_message(string $phone, ?string $code, string $detail, ?string $name = null): string {
        global $DB;
        $link = null;
        if ($code !== null) {
            $link = $DB->get_record(self::LINKS, ['code' => $code]);
            $mine = $link && $link->contactid
                && $DB->record_exists(self::CONTACTS, ['id' => $link->contactid, 'phone' => $phone]);
            if (!$link || ($link->contactid && !$mine)) {
                // A code that does not exist, or that another number already used: nothing is linked.
                return 'unknowncode';
            }
        }
        $contact = self::get_or_create($phone, $name);
        self::set_status($contact, 'optin', 'keyword', $detail);
        if ($link && !$link->contactid) {
            $link->contactid = $contact->id;
            $link->timeclaimed = time();
            $DB->update_record(self::LINKS, $link);
        }
        return 'optin';
    }

    /**
     * Opt out a number. An unknown number is kept as opted out, so that nothing adds it again by mistake.
     *
     * @param string $phone Digits with the country code.
     * @param string $source keyword, page or admin.
     * @param string $detail The id of the WhatsApp message, or who did it.
     * @return \stdClass The contact.
     */
    public static function optout(string $phone, string $source, string $detail): \stdClass {
        $contact = self::get_or_create($phone);
        self::set_status($contact, 'optout', $source, $detail);
        return $contact;
    }

    /**
     * Changes the status of a contact and keeps the proof.
     *
     * @param \stdClass $contact The contact, updated in place.
     * @param string $action optin or optout.
     * @param string $source keyword, page or admin.
     * @param string $detail The id of the WhatsApp message, or who did it.
     */
    private static function set_status(\stdClass $contact, string $action, string $source, string $detail): void {
        global $DB;
        $now = time();
        if ($action === 'optin') {
            $contact->status = 'active';
            $contact->consenttime = $now;
            $contact->consentsource = $source;
        } else {
            $contact->status = 'optedout';
            $contact->optouttime = $now;
        }
        if ($source === 'keyword') {
            $contact->lastinbound = $now;
        }
        $contact->timemodified = $now;
        $DB->update_record(self::CONTACTS, $contact);
        $DB->insert_record(self::LOG, (object)[
            'contactid' => $contact->id,
            'action' => $action,
            'source' => $source,
            'detail' => \core_text::substr($detail, 0, 255),
            'timecreated' => $now,
        ]);
    }
}
