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

    /** Seconds the code of a user can be claimed: it stands for their account. */
    public const USER_CODE = DAYSECS;

    /** Table of the opt-ins and opt-outs, kept as proof. */
    public const LOG = 'local_oksigeniamarandu_consent';

    /** What a number or a chat said no to, short of everything: the notices of one plugin. */
    public const OPTOUTS = 'local_oksigeniamarandu_optout';

    /** Wrong codes a number may write before it has to wait. */
    private const WRONG_TRIES = 5;

    /** How long a number waits after too many wrong codes. */
    private const WRONG_PAUSE = 15 * MINSECS;

    /**
     * For how long writing again does not take back a no: the «thanks» that answers the confirmation is not a change of
     * mind. The opt-in word works at once.
     */
    public const LIFT_GRACE = DAYSECS;

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
            'channel' => telegram::is_chat($phone) ? 'telegram' : 'whatsapp',
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
        $adultids = [];
        if ($code !== null) {
            if (self::guessing($phone)) {
                return 'unknowncode';
            }
            $link = $DB->get_record(self::LINKS, ['code' => $code]);
            $expired = $link && !$link->contactid && ((int)$link->timeexpires > 0 ? (int)$link->timeexpires < time()
                : ($link->userid !== null && (int)$link->timecreated < time() - self::USER_CODE));
            if ($expired) {
                // A code that could only be claimed for a while, and its time is over.
                self::wrong($phone);
                return 'unknowncode';
            }
            $mine = $link && $link->contactid
                && $DB->record_exists(self::CONTACTS, ['id' => $link->contactid, 'phone' => $phone]);
            if (!$link) {
                // Not a code of Marandu: perhaps a student's code of the family records.
                $adultid = family::claim($phone, $code, $name);
                if ($adultid === null) {
                    self::wrong($phone);
                    return 'unknowncode';
                }
                $adultids = [$adultid];
            } else if ($link->contactid && !$mine) {
                // A code that another number already used: nothing is linked.
                self::wrong($phone);
                return 'unknowncode';
            }
        } else {
            // Without a code, a number the school already has for an adult of the family records stands for them.
            $adultids = family::adults($phone);
        }
        $contact = self::get_or_create($phone, $name);
        $wasout = $contact->status === 'optedout' || $DB->record_exists(self::OPTOUTS, ['contactid' => $contact->id]);
        self::set_status($contact, 'optin', 'keyword', $detail);
        if ($link && $link->component !== 'core') {
            // Joining with the code of a plugin takes back the «no» to that plugin only, not to the others.
            $DB->delete_records(self::OPTOUTS, ['contactid' => $contact->id, 'scope' => $link->component]);
        } else {
            $DB->delete_records(self::OPTOUTS, ['contactid' => $contact->id]);
        }
        if ($link && !$link->contactid) {
            $link->contactid = $contact->id;
            $link->timeclaimed = time();
            $DB->update_record(self::LINKS, $link);
            if ($link->userid) {
                self::tell_account($link, $contact);
            }
        }
        family::link((int)$contact->id, $adultids);
        if ($wasout) {
            self::tell($contact, false, 'keyword');
        }
        // The plugin of the code hears that someone joined for it, and by which channel.
        if ($link && $link->component !== '' && $link->component !== 'core') {
            self::callback($link->component, 'marandu_joined', [(int)$link->itemid, (string)$contact->channel, time()]);
        }
        return 'optin';
    }

    /**
     * Tells a person that a number or a chat joined their account, by email and in the bell, never by that chat: if it
     * was not them, they know at once.
     *
     * @param \stdClass $link The link that was claimed.
     * @param \stdClass $contact Who claimed it.
     */
    private static function tell_account(\stdClass $link, \stdClass $contact): void {
        $user = \core_user::get_user((int)$link->userid);
        if (!$user || $user->deleted || $user->suspended) {
            return;
        }
        $chat = telegram::is_chat((string)$contact->phone);
        $a = (object)['channel' => $chat ? 'Telegram' : 'WhatsApp', 'site' => format_string(get_site()->fullname),
            'who' => $chat ? trim((string)$contact->name) : '+' . substr((string)$contact->phone, 0, -4) . '····'];
        $text = get_string($a->who !== '' ? 'newchat:text' : 'newchat:textnoname', 'local_oksigeniamarandu', $a);
        $message = new \core\message\message();
        $message->component = 'local_oksigeniamarandu';
        $message->name = 'newchat';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = get_string('newchat:subject', 'local_oksigeniamarandu', $a);
        $message->fullmessage = $text;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $text;
        $message->notification = 1;
        $message->contexturl = (new \moodle_url('/message/notificationpreferences.php'))->out(false);
        $message->contexturlname = get_string('newchat:where', 'local_oksigeniamarandu');
        try {
            message_send($message);
        } catch (\Throwable $e) {
            debugging('Marandu: the person was not told of a new chat: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Whether a contact that has just joined was already in: a second code, or the opt-in word again. Then the welcome
     * does not go twice.
     *
     * @param string $address The number or the chat.
     * @return bool
     */
    public static function rejoined(string $address): bool {
        global $DB;
        $contact = $DB->get_record(self::CONTACTS, ['phone' => $address], 'id');
        if (!$contact) {
            return false;
        }
        $last = array_values($DB->get_records(self::LOG, ['contactid' => $contact->id], 'id DESC', 'id, action', 0, 2));
        return count($last) === 2 && $last[0]->action === 'optin' && $last[1]->action === 'optin';
    }

    /**
     * Whether a number has written too many wrong codes lately, and has to wait before trying again. It counts by number,
     * not by address: on WhatsApp everything arrives from the addresses of Meta.
     *
     * @param string $phone Digits with the country code.
     * @return bool
     */
    private static function guessing(string $phone): bool {
        $tries = \cache::make('local_oksigeniamarandu', 'wrongcodes')->get(self::cachekey($phone));
        return $tries && $tries['count'] >= self::WRONG_TRIES && $tries['since'] > time() - self::WRONG_PAUSE;
    }

    /**
     * Counts a wrong code from a number.
     *
     * @param string $phone Digits with the country code.
     */
    private static function wrong(string $phone): void {
        $cache = \cache::make('local_oksigeniamarandu', 'wrongcodes');
        $tries = $cache->get(self::cachekey($phone));
        if (!$tries || $tries['since'] <= time() - self::WRONG_PAUSE) {
            $tries = ['count' => 0, 'since' => time()];
        }
        $tries['count']++;
        $cache->set(self::cachekey($phone), $tries);
    }

    /**
     * An address as a key of the cache, which takes only letters, digits and _: «tg:555» becomes «tg_555».
     *
     * @param string $phone The address of a contact.
     * @return string
     */
    private static function cachekey(string $phone): string {
        return preg_replace('/[^A-Za-z0-9_]/', '_', $phone);
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
        self::tell($contact, true, $source);
        return $contact;
    }

    /**
     * Whether a contact takes nothing from a plugin: out of everything, or out of what that plugin sends.
     *
     * @param \stdClass $contact The contact.
     * @param string $component The plugin of the notice.
     * @return bool
     */
    public static function out_of(\stdClass $contact, string $component): bool {
        global $DB;
        return $contact->status === 'optedout'
            || $DB->record_exists(self::OPTOUTS, ['contactid' => $contact->id, 'scope' => $component]);
    }

    /**
     * Puts or takes away what a contact said no to: everything (all) or the notices of a plugin, with its proof.
     *
     * @param \stdClass $contact The contact, updated in place.
     * @param string $scope all, or a plugin.
     * @param bool $out No more.
     * @param string $source Where it was asked: keyword, wrote, chat, or what the plugin says (eguahe:family…).
     * @param string $except A plugin not to tell, because it asked for it.
     * @param int|null $exceptitem Only that item of it, not its other items with the same number; null for all of them.
     * @return bool Whether anything changed.
     */
    public static function set_optout(
        \stdClass $contact,
        string $scope,
        bool $out,
        string $source,
        string $except = '',
        ?int $exceptitem = null
    ): bool {
        global $DB;
        $source = \core_text::substr($source, 0, 100);
        if ($scope === 'all') {
            if ($out === ($contact->status === 'optedout')) {
                return false;
            }
            self::set_status($contact, $out ? 'optout' : 'optin', $source, '');
        } else {
            $row = $DB->get_record(self::OPTOUTS, ['contactid' => $contact->id, 'scope' => $scope]);
            if ($out === (bool)$row) {
                return false;
            }
            if ($out) {
                $DB->insert_record(self::OPTOUTS, (object)['contactid' => $contact->id,
                    'scope' => \core_text::substr($scope, 0, 100), 'source' => $source, 'timecreated' => time()]);
            } else {
                $DB->delete_records(self::OPTOUTS, ['id' => $row->id]);
            }
            $DB->insert_record(self::LOG, (object)['contactid' => $contact->id, 'action' => $out ? 'optout' : 'optin',
                'source' => $source, 'detail' => 'scope:' . $scope, 'timecreated' => time()]);
        }
        self::tell($contact, $out, $source, $except, $scope, $exceptitem);
        return true;
    }

    /**
     * Whoever writes again of their own takes back what they said no to, on that channel: they are in the list again. Not
     * in the day after saying it (LIFT_GRACE), when what they write is most likely an answer to the confirmation.
     *
     * @param string $address The number or the chat.
     * @param string $detail The id of the message.
     * @return bool Whether there was anything to take back.
     */
    public static function lift(string $address, string $detail): bool {
        global $DB;
        $contact = $DB->get_record(self::CONTACTS, ['phone' => $address]);
        if (!$contact) {
            return false;
        }
        $before = time() - self::LIFT_GRACE;
        $scoped = $DB->get_records_select(
            self::OPTOUTS,
            'contactid = :contactid AND timecreated <= :before',
            ['contactid' => $contact->id, 'before' => $before]
        );
        $out = $contact->status === 'optedout' && (int)$contact->optouttime <= $before;
        if (!$out && !$scoped) {
            return false;
        }
        if ($out) {
            self::set_status($contact, 'optin', 'wrote', $detail);
        }
        foreach ($scoped as $row) {
            $DB->delete_records(self::OPTOUTS, ['id' => $row->id]);
            $DB->insert_record(self::LOG, (object)['contactid' => $contact->id, 'action' => 'optin', 'source' => 'wrote',
                'detail' => 'scope:' . $row->scope, 'timecreated' => time()]);
        }
        self::tell($contact, false, 'wrote');
        return true;
    }

    /**
     * What a contact said no to now, and since when.
     *
     * @param \stdClass $contact The contact.
     * @return array all (time, or 0) and scopes (by plugin, the time).
     */
    public static function optout_now(\stdClass $contact): array {
        global $DB;
        $scopes = [];
        foreach ($DB->get_records(self::OPTOUTS, ['contactid' => $contact->id], 'timecreated ASC') as $row) {
            $scopes[$row->scope] = (int)$row->timecreated;
        }
        return ['all' => $contact->status === 'optedout' ? (int)$contact->optouttime : 0, 'scopes' => $scopes];
    }

    /**
     * What a contact asked and when, the newest first: joining, leaving, writing again.
     *
     * @param int $contactid The contact.
     * @param int $max How many.
     * @return array Each with action (optin or optout), source, scope (all or a plugin) and time.
     */
    public static function trail(int $contactid, int $max = 20): array {
        global $DB;
        $list = [];
        foreach ($DB->get_records(self::LOG, ['contactid' => $contactid], 'timecreated DESC, id DESC', '*', 0, $max) as $row) {
            $scope = strpos((string)$row->detail, 'scope:') === 0 ? substr((string)$row->detail, 6) : 'all';
            $list[] = ['action' => (string)$row->action, 'source' => (string)$row->source, 'scope' => $scope,
                'time' => (int)$row->timecreated];
        }
        return $list;
    }

    /**
     * Tells the plugins a contact is linked to that what it said no to changed.
     *
     * @param \stdClass $contact The contact.
     * @param bool $out No more.
     * @param string $source Where it was asked.
     * @param string $except A plugin not to tell.
     * @param string $scope What changed: all, or the notices of one plugin. The plugins hear it, so that one does not
     *                      take another's «no» as its own.
     * @param int|null $exceptitem Only that item of the plugin not to tell; null for all of them.
     */
    private static function tell(
        \stdClass $contact,
        bool $out,
        string $source,
        string $except = '',
        string $scope = 'all',
        ?int $exceptitem = null
    ): void {
        global $DB;
        $links = $DB->get_records_select(
            self::LINKS,
            "contactid = :contactid AND component <> 'core'",
            ['contactid' => $contact->id],
            'id ASC',
            'id, component, itemid'
        );
        foreach ($links as $link) {
            if ($except !== '' && $link->component === $except && ($exceptitem === null || (int)$link->itemid === $exceptitem)) {
                continue;
            }
            $args = [(int)$link->itemid, (string)$contact->channel, $out, $source, time(), $scope];
            self::callback($link->component, 'marandu_optout', $args);
        }
    }

    /**
     * Calls a plugin, if it is still installed: a link can outlive the plugin it was made for.
     *
     * @param string $component The plugin.
     * @param string $name The callback, without the prefix of the plugin.
     * @param array $args Its arguments.
     */
    private static function callback(string $component, string $name, array $args): void {
        if (\core_component::get_component_directory($component) === null) {
            return;
        }
        component_callback($component, $name, $args);
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
