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

namespace local_oksigeniamarandu;

use local_oksigeniamarandu\local\cloud_api;
use local_oksigeniamarandu\local\consent;
use local_oksigeniamarandu\local\family;
use local_oksigeniamarandu\local\keywords;
use local_oksigeniamarandu\local\notices;
use local_oksigeniamarandu\local\queue;
use local_oksigeniamarandu\local\telegram;
use local_oksigeniamarandu\local\telegram_api;

/**
 * What other plugins use: a code and a link so that a family or a user joins on WhatsApp, whether they have, and the
 * notices for them.
 *
 * With the family records of Oksigenia Family (local_oksigeniafamily), a plugin tells the family of a student without
 * knowing their numbers, once it has declared its notices (see \local_oksigeniamarandu\local\notices):
 *     \local_oksigeniamarandu\api::notify_child('local_oksigeniameals', 'menu_missing', ['Ana', 'lunes 3'],
 *         new moodle_url('/local/oksigeniameals/k.php'), $student->id);
 * The family joins by writing ALTA with the student's code of the records, or just ALTA from the number the school has.
 *
 * Without the records, a plugin gives a code of its own to whom it keeps (a user, or an item such as a reader):
 *     $code = \local_oksigeniamarandu\api::invite('local_example', $item->id);
 *     $url = \local_oksigeniamarandu\api::optin_url($code); // Opens WhatsApp with a greeting and «(<code>)» written.
 *     \local_oksigeniamarandu\api::notify('local_example', 'reminder', ['Ana', 'lunes 3'], $pageurl, $item->id);
 *
 * What whoever joined with that code writes of their own reaches the plugin with the hook
 * \local_oksigeniamarandu\hook\message_received, and the plugin answers with send_text().
 *
 * A plugin with more than one kind of item, such as the diners of a canteen and its kitchen, gives each kind but the
 * usual one an area, as the files of Moodle do, and passes it, last, to every call for those items:
 *     $code = \local_oksigeniamarandu\api::invite('local_oksigeniameals', $key->id, null, 'kitchen');
 *     \local_oksigeniamarandu\api::notify('local_oksigeniameals', 'kitchen_summary', $values, $url, $key->id, area: 'kitchen');
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {
    /** A notice declared with 'ownjoin' reaches only what the user joined with a code of its own plugin (since 0.51.1). */
    public const OWNJOIN = true;

    /**
     * A code that links whoever writes it on WhatsApp with something of a plugin, or with a user.
     *
     * The code of a link nobody has claimed yet is reused; once claimed, a new call gives a new code, so that a second
     * person (the other parent) can join too.
     *
     * @param string $component The plugin, such as local_oksigeniameals.
     * @param int $itemid Its item, such as a family; 0 if the link is only for a user.
     * @param int|null $userid The Moodle user, if there is one.
     * @param string $area The kind of item, if the plugin has more than one (such as kitchen); empty for its usual items.
     * @param int $validfor Seconds the code can be claimed, for a code that must not outlive the moment, such as the one
     *                      that joins a chat to approve a login: then it is always a new one, and the open ones of before
     *                      stop working. 0 for a code that stays until someone uses it; the code of a user lasts a day
     *                      anyway, and the person hears when a number or a chat joins their account.
     * @return string The code.
     */
    public static function invite(
        string $component,
        int $itemid = 0,
        ?int $userid = null,
        string $area = '',
        int $validfor = 0
    ): string {
        global $DB;
        $component = clean_param($component, PARAM_COMPONENT);
        if ($component === '') {
            throw new \coding_exception('invite() needs the name of a plugin');
        }
        $area = self::area($area);
        $params = ['component' => $component, 'area' => $area, 'itemid' => $itemid];
        $usersql = 'userid IS NULL';
        if ($userid !== null) {
            $usersql = 'userid = :userid';
            $params['userid'] = $userid;
        }
        $opensql = "component = :component AND area = :area AND itemid = :itemid AND $usersql AND contactid IS NULL";
        if ($validfor > 0) {
            $DB->delete_records_select(consent::LINKS, $opensql, $params);
        } else if ($userid !== null) {
            // The code of a person stands for their account: it lasts a day (USER_CODE), then a new one.
            $open = $DB->get_records_select(
                consent::LINKS,
                "$opensql AND timeexpires > :now",
                $params + ['now' => time() + 60],
                'id ASC',
                'id, code',
                0,
                1
            );
            if ($open) {
                return reset($open)->code;
            }
            $DB->delete_records_select(consent::LINKS, $opensql, $params);
            $validfor = consent::USER_CODE;
        } else {
            $open = $DB->get_records_select(consent::LINKS, "$opensql AND timeexpires = 0", $params, 'id ASC', 'id, code', 0, 1);
            if ($open) {
                return reset($open)->code;
            }
        }
        do {
            $code = keywords::new_code();
        } while ($DB->record_exists(consent::LINKS, ['code' => $code]));
        $DB->insert_record(consent::LINKS, (object)[
            'component' => $component,
            'area' => $area,
            'itemid' => $itemid,
            'userid' => $userid,
            'code' => $code,
            'timecreated' => time(),
            'timeexpires' => $validfor > 0 ? time() + $validfor : 0,
        ]);
        return $code;
    }

    /**
     * The link that opens WhatsApp with the message that joins written, ready to send to the number of the site: a
     * greeting and the code between brackets. The opt-in word and the code, typed by hand, work too.
     *
     * @param string $code A code from invite().
     * @param string $greeting What the person says, in their words, such as «Hola, somos la familia de Leo.»; the
     *                         greeting of Marandu if empty.
     * @return string|null Null if the site has not set its number yet.
     */
    public static function optin_url(string $code, string $greeting = ''): ?string {
        $number = preg_replace('/[^0-9]/', '', (string)get_config('local_oksigeniamarandu', 'displayphone'));
        if ($number === '') {
            return null;
        }
        return 'https://wa.me/' . $number . '?text=' . rawurlencode(keywords::join_text($code, $greeting));
    }

    /**
     * The channels the site has ready: WhatsApp with its account and number, Telegram with its bot.
     *
     * @return array By channel (whatsapp, telegram), with name and ready.
     */
    public static function channels(): array {
        return [
            'whatsapp' => ['name' => 'WhatsApp', 'ready' => cloud_api::is_configured()
                && (string)get_config('local_oksigeniamarandu', 'displayphone') !== ''],
            'telegram' => ['name' => 'Telegram', 'ready' => telegram_api::is_configured()],
        ];
    }

    /**
     * The link that joins with a code on a channel: WhatsApp with a greeting and the code written, or the bot of
     * Telegram started with the code.
     *
     * @param string $channel whatsapp or telegram.
     * @param string $code A code from invite(), or of the family records.
     * @param string $greeting For WhatsApp, what the person says before the code; the greeting of Marandu if empty.
     * @return string|null Null if the site does not have that channel ready.
     */
    public static function join_url(string $channel, string $code, string $greeting = ''): ?string {
        if ($channel === 'telegram') {
            return telegram::join_url($code);
        }
        return $channel === 'whatsapp' ? self::optin_url($code, $greeting) : null;
    }

    /**
     * Whether a user has joined on a channel, with whatever code: from their notification preferences, the app or a
     * letter.
     *
     * @param int $userid The user.
     * @param string|null $channel whatsapp or telegram; either if null.
     * @return bool
     */
    public static function user_joined(int $userid, ?string $channel = null): bool {
        global $DB;
        $params = ['userid' => $userid, 'active' => 'active'];
        $where = '';
        if ($channel !== null) {
            $where = ' AND c.channel = :channel';
            $params['channel'] = $channel;
        }
        return $DB->record_exists_sql("SELECT 1
                                         FROM {" . consent::LINKS . "} l
                                         JOIN {" . consent::CONTACTS . "} c ON c.id = l.contactid
                                        WHERE l.userid = :userid AND c.status = :active$where", $params);
    }

    /**
     * For each channel the site has ready, whether a user is on it and the link to join it, for a screen where the user
     * chooses where to get the notices (such as the one of the app).
     *
     * @param int $userid The user.
     * @return array By channel, with name, joined, where (the number or the chat, half hidden) and url (to join).
     */
    public static function user_channels(int $userid): array {
        return self::channel_list('core', 0, $userid);
    }

    /**
     * The same for an adult of the family records of Oksigenia Family, who may have no account.
     *
     * @param int $adultid The adult.
     * @return array By channel, with name, joined, where and url.
     */
    public static function adult_channels(int $adultid): array {
        return self::channel_list(family::COMPONENT, $adultid, null);
    }

    /**
     * Whether a plugin has any notice switched on for a channel: if not, whoever joins that channel gets nothing from it.
     * The site switches them on in notices.php.
     *
     * @param string $component The plugin.
     * @param string $channel whatsapp or telegram.
     * @return bool
     */
    public static function notices_on(string $component, string $channel): bool {
        if (!isset(notices::SETTING[$channel])) {
            return false;
        }
        foreach (array_intersect(notices::enabled($channel), array_keys(notices::all())) as $key) {
            if (strpos($key, $component . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * The same for an item of a plugin, such as a family of the canteen, to tell whoever it stands for where the notices
     * reach them and offer the channels still to join. The links carry a code: show them only to whoever the item stands
     * for, as with signup_url().
     *
     * @param string $component The plugin, such as local_oksigeniameals.
     * @param int $itemid Its item.
     * @param string $area The kind of item, if the plugin has more than one; empty for its usual items.
     * @return array By channel, with name, joined, where, url and since: when the newest number or chat on that channel
     *     joined for the item (0 if none). A channel is as old as its newest chat: a check such as «joined a week ago»
     *     on since holds for every chat a message would reach. With ask_to_join(), also asked (when the question went and
     *     waits for its answer, 0 if none) and declined (when they said no, 0 if not), on WhatsApp. implied: the permission
     *     of the plugin the numbers were joined with (bulk_phones()) while none of them has written or answered; empty if
     *     they joined by themselves.
     */
    public static function item_channels(string $component, int $itemid, string $area = ''): array {
        return self::channel_list($component, $itemid, null, self::area($area));
    }

    /**
     * Says that an item of a plugin, such as a family of admissions, does not want messages any more, or wants them
     * again: on every number and chat that joined for it. With a scope, only the notices of that plugin; with all,
     * nothing of the centre. The plugin that asks is not told back. One decision, wherever it is taken: the word of
     * leaving, writing again, the team from the chat and the plugins all change the same.
     *
     * @param string $component The plugin of the item, such as local_oksigeniaeguahe.
     * @param int $itemid The item.
     * @param string $scope all, or a plugin, such as local_oksigeniaeguahe for what admissions sends.
     * @param bool $out No more (true) or again (false).
     * @param string $source Where it was asked, such as eguahe:family.
     * @param string $channel Only on this channel (whatsapp or telegram); empty for every one.
     * @param string $area The kind of item, if the plugin has more than one.
     * @return int How many numbers and chats changed.
     */
    public static function set_optout(
        string $component,
        int $itemid,
        string $scope,
        bool $out,
        string $source,
        string $channel = '',
        string $area = ''
    ): int {
        $changed = 0;
        foreach (self::linked_contacts($component, $itemid, $area) as $contact) {
            if ($channel !== '' && $contact->channel !== $channel) {
                continue;
            }
            $changed += consent::set_optout($contact, $scope === '' ? 'all' : $scope, $out, $source, $component, $itemid)
                ? 1 : 0;
        }
        return $changed;
    }

    /**
     * What an item of a plugin said no to, on each number and chat that joined for it, and the trail.
     *
     * @param string $component The plugin of the item.
     * @param int $itemid The item.
     * @param string $area The kind of item.
     * @return array Each with channel, all (since when, or 0), scopes (by plugin, since when) and trail (action, source,
     *               scope, time; the newest first).
     */
    public static function optout_state(string $component, int $itemid, string $area = ''): array {
        $list = [];
        foreach (self::linked_contacts($component, $itemid, $area) as $contact) {
            $list[] = ['channel' => (string)$contact->channel] + consent::optout_now($contact) +
                ['trail' => consent::trail((int)$contact->id)];
        }
        return $list;
    }

    /**
     * Asks a person who gave their number and their permission in a page of a plugin to join by WhatsApp with one touch: they
     * get «you asked for our news here, is that right?» with «yes» and «no». With yes they have joined for the item (hook of
     * marandu_joined) and the 24 hours are open; with no, marandu_optout says so and they are not asked again. Once per
     * number and plugin, as the answer says. It is not asked of whoever joined already.
     *
     * @param string $component The plugin of the item, such as local_oksigeniaeguahe.
     * @param int $itemid The item, such as a family.
     * @param string $phone The number, as the person gave it, with the + or 00 of their country.
     * @param int $when When they gave permission.
     * @param string $where Where, such as eguahe:family.
     * @param string $area The kind of item.
     * @return string asked, waiting, joined, declined, invalid, limit or notready, as joinask::ask() says.
     */
    public static function ask_to_join(
        string $component,
        int $itemid,
        string $phone,
        int $when,
        string $where,
        string $area = ''
    ): string {
        return local\joinask::ask($component, $itemid, $phone, $when, $where, self::area($area));
    }

    /**
     * Confirms to a person, in their chats, a change of the channels they are written by, with the card of the
     * confirmation: by a channel they take, «from now on, here» (or «here too», if they keep the others); by one they
     * leave, «from now on, by …» or, with no channel left, «the door is open». On WhatsApp only inside the 24 hours after
     * the person last wrote, which is when Meta lets a free message go. The email is the plugin's own. Joining needs no
     * call: Marandu welcomes whoever joins, with any code.
     *
     * @param string $component The plugin of the item, such as local_oksigeniaeguahe.
     * @param int $itemid The item.
     * @param array $now The channels now: whatsapp, telegram and email.
     * @param array $before The channels before.
     * @param string $area The kind of item, if the plugin has more than one.
     * @return int How many confirmations go.
     */
    public static function confirm_channel(string $component, int $itemid, array $now, array $before, string $area = ''): int {
        if (!local\confirm::enabled()) {
            return 0;
        }
        $now = array_values(array_intersect(local\confirm::CHANNELS, $now));
        $before = array_values(array_intersect(local\confirm::CHANNELS, $before));
        $count = 0;
        foreach (self::linked_contacts($component, $itemid, $area) as $contact) {
            $channel = (string)$contact->channel;
            if (!in_array($channel, local\confirm::CHATS, true) || $contact->status !== 'active') {
                continue;
            }
            $takes = in_array($channel, $now, true) && !in_array($channel, $before, true);
            $leaves = !in_array($channel, $now, true) && in_array($channel, $before, true);
            if ($takes) {
                $left = array_values(array_diff($before, $now));
                [$kind, $others] = $left ? ['here', $left] : ['also', array_values(array_diff($now, [$channel]))];
            } else if ($leaves) {
                [$kind, $others] = $now ? ['away', $now] : ['out', []];
            } else {
                continue;
            }
            if ($channel === 'whatsapp' && (int)$contact->lastinbound <= time() - queue::WINDOW) {
                continue;
            }
            \local_oksigeniamarandu\task\send_reply::queue_confirm((string)$contact->phone, $kind, $others);
            $count++;
        }
        return $count;
    }

    /**
     * What a send to many would be before it goes, such as inviting every family of admissions: how many messages cost
     * (WhatsApp outside the 24 hours), how many are free (WhatsApp inside them, Telegram), how many items nobody can get
     * (not joined, or said no to your plugin), what it costs and whether it will wait for approval. Only whoever joined
     * gets it.
     *
     * @param string $component Your plugin.
     * @param string $notice The notice it sends, declared by your plugin.
     * @param int[] $itemids The items.
     * @param string $area The kind of item, if your plugin has more than one.
     * @param array $phones For items nobody joined for, their number (E.164) by item, as bulk_request() would take it:
     *                      they count as WhatsApp, with what it costs, unless the number said no (bulk_phones()).
     * @return array whatsapp, whatsappfree, telegram, skipped, cost, currency, category, approval (bool) and approvers
     *               (names).
     */
    public static function bulk_quote(
        string $component,
        string $notice,
        array $itemids,
        string $area = '',
        array $phones = []
    ): array {
        return local\bulk::quote($component, $notice, $itemids, self::area($area), $phones);
    }

    /**
     * Whether a send to many takes, for items nobody joined for, a number to write to with the permission your plugin
     * has (bulk_request(), phone and implied). The number joins for the item as implied, without any welcome: its «no»,
     * any word of leaving or a «no» to a question to join stop it, and your plugin hears it by marandu_optout, as always.
     * Its first message opens the 24 hours, as anybody's. What WhatsApp lets the line write to in a day, and its quality,
     * decide when each one goes; nothing is lost while it waits (bulk_items()).
     *
     * @return bool
     */
    public static function bulk_phones(): bool {
        return true;
    }

    /**
     * What happened to each item of a send to many: whether it went, reached the phone or was read, by which channel,
     * and why not. Read receipts come only from people who let WhatsApp send them.
     *
     * @param int $id The send.
     * @return array Each with itemid, status (queued, waiting, sent, delivered, read, failed, nowhatsapp or skipped), time
     *               (of that status), why (optout, declined, invalid, nomarketing, quiet, limit, quality, approval, expired,
     *               rejected, cancelled, nobody, or the code of Meta when it failed), channel (whatsapp, telegram or empty)
     *               and implied (written to by your permission, without the number having joined).
     */
    public static function bulk_items(int $id): array {
        return local\bulk::items($id);
    }

    /**
     * Asks for a send to many: it goes now, by the usual queue and its quiet hours, unless it costs more than the
     * threshold of the site or the month has no budget left; then it waits for whoever may approve it, who hears it in
     * the bell and on Telegram. Your plugin hears the decision with the callback marandu_bulk_decided($id, $status,
     * $userid) in its lib.php, or asks with bulk_state().
     *
     * @param string $component Your plugin.
     * @param string $notice The notice it sends, declared by your plugin.
     * @param array $items Each with itemid, params (the values of the notice), url (a moodle_url of this site, or null)
     *                     and lang, as notify() takes them; for an item nobody joined for, also phone (E.164) and
     *                     implied (your permission to write to it, such as eguahe:invite; bulk_phones()).
     * @param int $userid Who asks for it.
     * @param string $label What it is, for whoever approves it, such as «Invitation to the open day of 12 March».
     * @param string $area The kind of item, if your plugin has more than one.
     * @param bool $sample A sample of one item (such as a made-up family with the phone of whoever wants to see it), to see
     *                     it before the rest: it goes at once, also in the quiet hours.
     * @return array id and status: queued (it goes now) or pending (it waits for approval).
     */
    public static function bulk_request(
        string $component,
        string $notice,
        array $items,
        int $userid,
        string $label,
        string $area = '',
        bool $sample = false
    ): array {
        return local\bulk::request($component, $notice, $items, $userid, $label, self::area($area), $sample);
    }

    /**
     * When somebody answers «no» (or «we cannot come», in the languages of the site) to the last message they got, and it
     * came from a send to many of your plugin, within a week: your plugin hears it with the callback
     * <plugin>_marandu_bulk_answer(int $bulkid, int $itemid, string $answer, int $time): ?string in its lib.php, which
     * returns what to answer them (free, inside the 24 hours) or null for nothing. It is an answer to what was sent, not a
     * «no» to the messages: only the words of leaving stop them. Without the callback, it reaches you as any message.
     *
     * @return string[] The answers that count as «no», as they are compared (small letters, without punctuation).
     */
    public static function bulk_nowords(): array {
        return local\bulk::nowords();
    }

    /**
     * How a send to many is.
     *
     * @param int $id The send.
     * @return array status (pending, queued, rejected or cancelled), decidedby (a name), timedecided, sent (numbers and
     *               chats it was queued for), whatsapp, telegram, skipped, cost and currency.
     */
    public static function bulk_state(int $id): array {
        return local\bulk::state($id);
    }

    /**
     * Takes back a send that still waits for its approval because it is not true any more, such as the invitation to an
     * act that was called off: the plugin does it, no matter who asked for it. What already went is not taken back.
     *
     * @param string $component The plugin of the send.
     * @param int $id The send.
     * @param string $reason Why, in a few words, for whoever looks at it.
     * @return bool Whether it was taken back.
     */
    public static function bulk_withdraw(string $component, int $id, string $reason = ''): bool {
        return local\bulk::withdraw($component, $id, $reason);
    }

    /**
     * Takes back a send to many that still waits for approval.
     *
     * @param int $id The send.
     * @param int $userid Who takes it back.
     * @return bool Whether it was taken back: false if it was already decided.
     */
    public static function bulk_cancel(int $id, int $userid): bool {
        return local\bulk::cancel($id, $userid);
    }

    /**
     * The numbers and chats that joined for an item, whatever they said since.
     *
     * @param string $component The plugin.
     * @param int $itemid The item.
     * @param string $area The kind of item.
     * @return \stdClass[]
     */
    private static function linked_contacts(string $component, int $itemid, string $area): array {
        global $DB;
        return $DB->get_records_sql(
            "SELECT DISTINCT c.*
               FROM {" . consent::LINKS . "} l
               JOIN {" . consent::CONTACTS . "} c ON c.id = l.contactid
              WHERE l.component = :component AND l.area = :area AND l.itemid = :itemid",
            ['component' => $component, 'area' => self::area($area), 'itemid' => $itemid]
        );
    }

    /**
     * Whom a channel list is for, and how they are on each channel.
     *
     * @param string $component The plugin of the links.
     * @param int $itemid Its item.
     * @param int|null $userid The user, if they are one.
     * @param string $area The kind of item.
     * @return array By channel, with name, joined, where and url.
     */
    private static function channel_list(string $component, int $itemid, ?int $userid, string $area = ''): array {
        global $DB;
        $params = ['active' => 'active', 'component' => $component, 'area' => $area, 'itemid' => $itemid];
        $where = 'l.component = :component AND l.area = :area AND l.itemid = :itemid AND ';
        if ($userid !== null) {
            $where = 'l.userid = :userid AND ';
            $params['userid'] = $userid;
        }
        $joined = $DB->get_records_sql("SELECT c.id, c.channel, c.phone, c.implied,
                                               MAX(COALESCE(l.timeclaimed, l.timecreated)) AS since
                                          FROM {" . consent::LINKS . "} l
                                          JOIN {" . consent::CONTACTS . "} c ON c.id = l.contactid
                                         WHERE {$where}c.status = :active
                                      GROUP BY c.id, c.channel, c.phone, c.implied", $params);
        $list = [];
        $code = null;
        foreach (self::channels() as $channel => $info) {
            if (!$info['ready']) {
                continue;
            }
            $contacts = array_filter($joined, function ($contact) use ($channel) {
                return $contact->channel === $channel;
            });
            $contact = reset($contacts);
            $implied = array_filter(array_map(function ($one) {
                return (string)$one->implied;
            }, $contacts));
            $code = $code ?? self::invite($component, $itemid, $userid, $area);
            $list[$channel] = [
                'name' => $info['name'],
                'joined' => (bool)$contact,
                // To the person themselves, the number half hidden, or just Telegram.
                'where' => $contact ? (local\telegram::is_chat($contact->phone) ? 'Telegram'
                    : local\panel::mask($contact->phone)) : '',
                'url' => self::join_url($channel, $code),
                // To the plugin of the item, never to a page as they are: which numbers get its notices.
                'numbers' => $userid !== null || $channel !== 'whatsapp' ? [] : array_values(array_map(function ($one) {
                    return '+' . $one->phone;
                }, $contacts)),
                'since' => $contacts ? max(array_map(function ($one) {
                    return (int)$one->since;
                }, $contacts)) : 0,
                // Joined by the permission of its plugin, such as eguahe:invite, while none of its numbers has written or
                // answered; empty, joined by themselves.
                'implied' => $userid === null && $contacts && count($implied) === count($contacts)
                    ? (string)reset($implied) : '',
            ] + ($channel === 'whatsapp' && $userid === null ? local\joinask::state($component, $itemid, $area) : []);
        }
        return $list;
    }

    /**
     * The private page where a family joins: it explains, opens WhatsApp on the phone and shows a QR code on a computer.
     * It goes in a letter or a message, never in a public place: whoever has it can join for that family.
     *
     * The page names whom the code stands for: the user, or what the plugin answers to the callback
     * <component>_oksigeniamarandu_label(int $itemid, string $area = ''): ?string in its lib.php (for example,
     * "Family of Ana", or "Kitchen of the North canteen" for the area kitchen).
     *
     * @param string $code A code from invite().
     * @return \moodle_url
     */
    public static function signup_url(string $code): \moodle_url {
        return new \moodle_url('/local/oksigeniamarandu/alta.php', ['code' => $code]);
    }

    /**
     * Whether somebody has joined for this item or user and has not opted out.
     *
     * @param string $component The plugin.
     * @param int $itemid Its item; 0 if the link is only for a user.
     * @param int|null $userid The Moodle user, if there is one.
     * @param string $area The kind of item, if the plugin has more than one; empty for its usual items.
     * @return bool
     */
    public static function has_active_contact(
        string $component,
        int $itemid = 0,
        ?int $userid = null,
        string $area = ''
    ): bool {
        global $DB;
        $params = ['component' => $component, 'area' => self::area($area), 'itemid' => $itemid, 'active' => 'active'];
        $usersql = 'l.userid IS NULL';
        if ($userid !== null) {
            $usersql = 'l.userid = :userid';
            $params['userid'] = $userid;
        }
        return $DB->record_exists_sql("SELECT 1
                                         FROM {" . consent::LINKS . "} l
                                         JOIN {" . consent::CONTACTS . "} c ON c.id = l.contactid
                                        WHERE l.component = :component AND l.area = :area AND l.itemid = :itemid
                                              AND $usersql AND c.status = :active", $params);
    }

    /**
     * Queues a notice for everyone who joined for an item of a plugin, or for a user.
     *
     * It goes out when it can: with its template approved, within the limit of the month and, unless it is urgent, out of
     * the quiet hours and within the notices a day. Inside the 24 hours after a family last wrote, it goes as plain text,
     * which is free.
     *
     * @param string $component The plugin that declares the notice.
     * @param string $notice The notice.
     * @param string[]|\Closure $params One value for each {{n}} of its text, in order; plain text. Or a function that takes
     *                                 a language and gives them written in it, fn(string $lang): array, for the dates and
     *                                 words of the values: Marandu calls it with the language of each person (the one they
     *                                 chose on Telegram, else $lang, else that of the site), and the text and its values
     *                                 go in the same.
     * @param \moodle_url|null $url Where its button goes, on this site.
     * @param int $itemid The item of the plugin, such as a family; 0 if the notice is for a user.
     * @param int|null $userid The user, if it is for a user: then every number linked to the user gets it.
     * @param bool $urgent Whether it goes out in the quiet hours and over the notices a day too.
     * @param int $validfor Seconds after which it is no longer worth sending.
     * @param array $rich A card on Telegram instead of its text: any of title, image (a URL of this site with a PNG or a
     *                    JPEG), lines, text and style of the button (primary, danger or success), as local\\rich::clean()
     *                    says. If Telegram refuses it, the text goes. WhatsApp ignores it and goes with its template, but
     *                    for caption: a message made to be forwarded, with the marks of WhatsApp, which inside the 24 hours
     *                    goes under its video (video, an MP4 of this site) or its image, with the notice and its link after
     *                    it; outside them, the template, as always.
     * @param string $area The kind of item, if the plugin has more than one; empty for its usual items.
     * @param string $lang The language to write it in, such as that of the family, if the site has its language pack (see
     *                     language()). With values given as they are, the whole notice goes in it, also to a person who
     *                     chose another language on Telegram, because the values are in this one; with a function, it is
     *                     only for those who chose none. Empty for theirs or the site's.
     * @param string $only Only by this channel (whatsapp or telegram); empty for every one the notice goes by.
     * @return int How many numbers it was queued for.
     */
    public static function notify(
        string $component,
        string $notice,
        array|\Closure $params,
        ?\moodle_url $url = null,
        int $itemid = 0,
        ?int $userid = null,
        bool $urgent = false,
        int $validfor = 2 * DAYSECS,
        array $rich = [],
        string $area = '',
        string $lang = '',
        string $only = ''
    ): int {
        global $DB;
        [$declared, $params] = self::prepare($component, $notice, $params);
        $area = self::area($area);
        $contactids = self::joined($component, $itemid, $userid, $area, $declared->ownjoin);
        if ($only !== '' && $contactids) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_map('intval', $contactids), SQL_PARAMS_NAMED, 'c');
            $contactids = $DB->get_fieldset_select(
                consent::CONTACTS,
                'id',
                "id $insql AND channel = :only",
                $inparams + ['only' => $only]
            );
        }
        return self::queue_for($contactids, $declared, $params, $url, $urgent, $validfor, $rich, $lang, $itemid, $area);
    }

    /**
     * The language Marandu writes a notice in when a plugin asks for one: that one, if the site has its language pack, so
     * that the plugin writes the values of the notice (dates, names) in the same; or else the language of the site. For a
     * person who chose a language on Telegram to get theirs, the plugin gives its values as a function of the language
     * (see notify()).
     *
     * @param string $lang The language asked for, as Moodle writes it (es, pt_br, ar).
     * @return string
     */
    public static function language(string $lang): string {
        global $CFG;
        $lang = trim($lang);
        return $lang !== '' && preg_match('/^[a-z]{2,3}(_[a-z0-9]+)*$/', $lang)
            && get_string_manager()->translation_exists($lang, false) ? $lang : (string)$CFG->lang;
    }

    /**
     * Sends a question with buttons to answer it to everyone who joined for an item of a plugin, or for a user, as
     * notify() does a notice. The question is a notice declared with 'options'. The answer comes back with the hook
     * \\local_oksigeniamarandu\\hook\\answer_received, with the reference given here.
     *
     * On WhatsApp, outside the 24 hours it goes with its template, whose quick replies are the answers; inside, as a text
     * with reply buttons. On Telegram, with a button for each answer. Below them, the button to its link.
     *
     * @param string $component The plugin that declares the question.
     * @param string $notice The question.
     * @param string[]|\Closure $params One value for each {{n}} of its text, in order; plain text. Or a function of the
     *                                 language that gives them, as notify() takes it.
     * @param string $ref What the plugin needs to know the question again when it is answered, up to 64 characters.
     * @param int $itemid The item of the plugin, such as an adult of the family records; 0 if it is for a user.
     * @param int|null $userid The user, if it is for a user.
     * @param \moodle_url|null $url Where its link goes, on this site.
     * @param int $validfor Seconds after which it is no longer worth sending, such as until the deadline of the answer.
     * @param string $area The kind of item, if the plugin has more than one; empty for its usual items. The answer
     *                     comes back with it.
     * @param bool $urgent Whether it goes at once, in the quiet hours and over the notices a day too, such as asking a person
     *                     whether a login is theirs.
     * @param string $lang The language to write it in, as notify() takes it.
     * @return int How many numbers and chats it was queued for.
     * @throws \coding_exception If the notice is not a question or the reference is not valid.
     */
    public static function ask(
        string $component,
        string $notice,
        array|\Closure $params,
        string $ref,
        int $itemid = 0,
        ?int $userid = null,
        ?\moodle_url $url = null,
        int $validfor = 2 * DAYSECS,
        string $area = '',
        bool $urgent = false,
        string $lang = ''
    ): int {
        [$declared, $params] = self::prepare($component, $notice, $params);
        $area = self::area($area);
        if (!$declared->options) {
            throw new \coding_exception("The notice {$component}/{$notice} has no options to answer");
        }
        if ($ref === '' || \core_text::strlen($ref) > 64) {
            throw new \coding_exception('The reference of a question has from 1 to 64 characters');
        }
        return self::queue_for(
            self::joined($component, $itemid, $userid, $area, $declared->ownjoin),
            $declared,
            $params,
            $url,
            $urgent,
            $validfor,
            [],
            $lang,
            $userid === null ? $itemid : 0,
            $userid === null ? $area : '',
            $ref
        );
    }

    /**
     * Whether this request comes from a machine and not from a person. Meta, Telegram and WhatsApp follow the links of the
     * notices and the posts to check them or to draw their preview, often before anyone taps them, and they go on to the
     * page of the plugin too: a plugin that counts the visits to that page asks this first. Marandu does not count them.
     *
     * @param string|null $agent The user agent; that of this request if not given.
     * @return bool
     */
    public static function is_robot(?string $agent = null): bool {
        return local\links::is_robot($agent);
    }

    /**
     * Sends a mail by the way of the site: to an address of home, by the mail of the site; to one out of home, by Mailjet
     * when the site chose it (page «Mail»), from the authenticated domain of the organization. The pictures go inside the
     * mail (cid:), and there is always a text version and never tracking. A mail to one person goes at once and, if Mailjet
     * fails, by the mail of the site; a send to many (bulk or later) waits in a line that goes out each minute, and never
     * falls to the mail of the site. What becomes of it later comes to <plugin>_marandu_mail_event() in its lib.php.
     *
     * @param \stdClass|int|string $to A user, its id, or an address.
     * @param string $subject
     * @param string $html The mail; empty for text only.
     * @param string $text Its text version; made from the HTML if empty.
     * @param array $images Each with cid (as written in the HTML, cid:<cid>), name, mime and data (the bytes).
     * @param array $options component (the plugin that sends it), ref (what the plugin calls it, back in its events),
     *     replyto, bulk (one of a send to many), unsubscribe (with bulk: the https address to leave, for List-Unsubscribe
     *     with one click), routine (daily and repeated: by the mail of the site once the month is near its quota), later
     *     (to the line, even to one person), files (attachments, each with name, mime and data: 9 MB at most in all),
     *     category (the kind of mail, in letters, digits, - and _, such as «invitation») and essential (true unless the
     *     plugin says false: a mail that can be done without does not go to an address that left that kind, or every
     *     kind, of the plugin; an enrolment, an appointment or what the person asked for are essential), and lang (of
     *     the page to leave). By Mailjet, a mail without unsubscribe brings the way out of Marandu: api::mail_optout_url().
     * @return string site or mailjet (the way it went), queued, bad (the address came back for good, was blocked, or marked
     *     a mail as spam and this is a send to many or routine), optedout (it can be done without, and the address left
     *     it), invalid, toobig (the attachments pass 9 MB: it does not go) or failed.
     */
    public static function mail(
        $to,
        string $subject,
        string $html,
        string $text = '',
        array $images = [],
        array $options = []
    ): string {
        if (is_numeric($to)) {
            $to = \core_user::get_user((int)$to);
            if (!$to) {
                return 'invalid';
            }
        }
        $letter = ['email' => '', 'name' => '', 'userid' => 0];
        if (is_object($to)) {
            if (!empty($to->deleted) || !empty($to->suspended) || ($to->auth ?? '') === 'nologin') {
                return 'invalid';
            }
            $letter = ['email' => (string)($to->email ?? ''), 'name' => isset($to->firstname) ? fullname($to) : '',
                'userid' => (int)($to->id ?? 0)];
        } else {
            $letter['email'] = (string)$to;
        }
        $allowed = array_intersect_key($options, array_flip(['component', 'ref', 'replyto', 'bulk', 'unsubscribe', 'routine',
            'later', 'files', 'category', 'essential', 'lang']));
        return local\mail\mailer::send($letter + $allowed + ['subject' => $subject, 'html' => $html, 'text' => $text,
            'images' => $images]);
    }

    /**
     * The address to leave the mails of a plugin that can be done without (of one kind, or of every kind if empty), with
     * one click: a POST «List-Unsubscribe=One-Click» (RFC 8058) leaves at once, and opening it shows a page that says what
     * it does, with a button, and lets the person come back. For List-Unsubscribe, with List-Unsubscribe-Post; Marandu
     * puts it itself in what it sends by Mailjet. The plugin hears it in <plugin>_marandu_mail_optout(string $email,
     * string $category, bool $out) («*» for every kind), and names its kinds for the page in
     * <plugin>_marandu_mail_category_name(string $category): string.
     *
     * @param string $email
     * @param string $component The plugin.
     * @param string $category Its kind of mail; empty for every kind that can be done without.
     * @param string $lang The language of the page; that of the site if empty.
     * @return string
     */
    public static function mail_optout_url(string $email, string $component, string $category = '', string $lang = ''): string {
        return local\mail\optout::url($email, $component, $category, $lang)->out(false);
    }

    /**
     * Whether an address left the mails of a plugin of that kind (or every kind that can be done without): before
     * sending one by another way, or to show it in the record of the person.
     *
     * @param string $email
     * @param string $component
     * @param string $category Empty for every kind.
     * @return bool
     */
    public static function mail_optout_state(string $email, string $component, string $category = ''): bool {
        return local\mail\optout::state($email, $component, $category);
    }

    /**
     * Leaves, or comes back to, the mails of a plugin of one kind (or every kind), as the record of the person says it:
     * the same as the page to leave. The plugin hears it in its callback too, as every change.
     *
     * @param string $email
     * @param string $component
     * @param string $category Empty for every kind.
     * @param bool $out Leave (true) or come back (false).
     * @return bool Whether anything changed.
     */
    public static function mail_optout(string $email, string $component, string $category = '', bool $out = true): bool {
        return local\mail\optout::set($email, $component, $category, $out, 'tool');
    }

    /**
     * A mail in the common look of the tools (the template of the organization: its name, its colour and its logo), from
     * its pieces. Give the result to api::mail(), or use api::mail_letter() to build and send at once.
     *
     * @param array $pieces heading; subject (the heading if not given); preheader (the line a mail program shows before
     *     opening it); hello; blocks, one piece each, in order: paragraph (text), rich (runs of run and bold), subheading,
     *     data (rows of label and value), items (texts), note (notetitle, notetext), code (a code to type by hand, large
     *     and alone on its line), picture (picturebytes, picturealt,
     *     picturecaption; pictureframe, a thin edge for a picture as white as the paper), action (actionurl, actiontext:
     *     the one button), link (linkbefore, linkurl, linktext), small;
     *     signature (signer, signrole); contact and reason (the foot: how to reach the organization, why it arrives);
     *     unsub (unsuburl, only to many: an https address that takes a POST «List-Unsubscribe=One-Click» and stops the
     *     mails, and shows a page with a button on a GET); hero (bytes, alt: a picture across the top); unit (who writes
     *     inside the organization).
     * @param string $lang The language of the reader; that of the site if empty.
     * @return array subject, html, text, images and unsubscribe, for api::mail().
     */
    public static function mail_html(array $pieces, string $lang = ''): array {
        return local\mail\compose::build($pieces, $lang);
    }

    /**
     * Builds a mail in the common look and sends it by the way of the site: api::mail_html() and api::mail() at once,
     * in the language of the person when it goes to an account.
     *
     * @param \stdClass|int|string $to A user, its id, or an address.
     * @param array $pieces As api::mail_html() takes them.
     * @param array $options As api::mail() takes them; lang for an address without an account.
     * @return string As api::mail() says.
     */
    public static function mail_letter($to, array $pieces, array $options = []): string {
        $user = is_numeric($to) ? \core_user::get_user((int)$to) : (is_object($to) ? $to : null);
        $lang = $user && !empty($user->lang) ? (string)$user->lang : (string)($options['lang'] ?? '');
        $built = local\mail\compose::build($pieces, $lang);
        $options['lang'] = $options['lang'] ?? $lang;
        if ($built['unsubscribe'] !== '') {
            $options['unsubscribe'] = $built['unsubscribe'];
            $options['bulk'] = $options['bulk'] ?? true;
        }
        return self::mail($user ?: $to, $built['subject'], $built['html'], $built['text'], $built['images'], $options);
    }

    /**
     * Gives a notice of Moodle (before message_send()) its mail in the common look and the way of the site: when Moodle
     * turns it into a mail, it goes with the template of the organization and its pictures inside, by the mail of the
     * site to home and by Mailjet to the rest when the site chose it. What the bell shows does not change. Moodle 5.3 and
     * later; before, Moodle sends the notice as always. One line before message_send():
     * api::mail_notice($message);
     *
     * @param \core\message\message $message
     * @param array $pieces As api::mail_html() takes them; empty for the subject, the text and the page of the notice.
     * @param bool $routine Daily and repeated: by the mail of the site once the month is near its quota.
     */
    public static function mail_notice(\core\message\message $message, array $pieces = [], bool $routine = false): void {
        local\mail\compose::for_notice($message, $pieces, $routine);
    }

    /**
     * The sender of the notices of a plugin (message_send()) whose mail goes by the way of the site as it comes, without
     * the common look: to an address out of home, by Mailjet, as api::mail(); to one of home, or if Mailjet does not take
     * it, by the mail of Moodle as always. api::mail_notice() is the same with the common look. Moodle 5.3 and later.
     *
     * @param string $component The plugin.
     * @param bool $routine Daily and repeated: by the mail of the site once the month is near its quota.
     * @return \stdClass The no-reply user of the site, marked.
     */
    public static function mail_from(string $component, bool $routine = false): \stdClass {
        return local\mail\hook::from($component, $routine);
    }

    /**
     * What an address is, for the mails: whether a mail came back for good, was blocked or was marked as spam.
     *
     * @param string $email
     * @return array|null event (bounce, blocked or spam), reason, component and time; null if it receives as any.
     */
    public static function mail_state(string $email): ?array {
        return local\mail\events::state($email);
    }

    /**
     * By which channels a notice would reach an item now: somebody joined for it there, said no neither to everything nor
     * to the plugin, the site has the notice switched on for that channel and the channel works; on WhatsApp, inside the
     * 24 hours or with its template approved. A plugin that gets none has to say it another way, such as by mail.
     *
     * @param string $component The plugin.
     * @param string $notice The notice.
     * @param int $itemid The item.
     * @param string $area The kind of item, if the plugin has more than one.
     * @return string[] Some of whatsapp and telegram.
     */
    public static function item_reaches(string $component, string $notice, int $itemid, string $area = ''): array {
        global $DB;
        $declared = local\notices::get($component, $notice);
        $contactids = $declared ? self::by_channels(self::joined($component, $itemid, null, self::area($area)), $declared) : [];
        $reaches = [];
        foreach ($contactids ? $DB->get_records_list(consent::CONTACTS, 'id', $contactids) : [] as $contact) {
            $channel = telegram::is_chat((string)$contact->phone) ? 'telegram' : 'whatsapp';
            if (!isset($reaches[$channel]) && queue::can_go($declared, $contact)) {
                $reaches[$channel] = true;
            }
        }
        return array_keys($reaches);
    }

    /**
     * The longest each value of a notice may be: a longer one is cut, so a plugin with more to say says it another way.
     *
     * @param string $component The plugin.
     * @param string $notice The notice.
     * @return int Characters; 0 if the notice is not declared.
     */
    public static function value_limit(string $component, string $notice): int {
        $declared = local\notices::get($component, $notice);
        return $declared ? $declared->valuelimit : 0;
    }

    /**
     * Takes out of the queue what has not gone yet for an item, because it is not true any more: an act that was called
     * off, a family that is gone.
     *
     * @param string $component The plugin.
     * @param int $itemid The item.
     * @param string[] $notices Only these notices; all of the plugin if empty.
     * @param string $area The kind of item, if the plugin has more than one.
     * @return int How many will not go.
     */
    public static function notices_cancel(string $component, int $itemid, array $notices = [], string $area = ''): int {
        return queue::cancel($component, $itemid, self::area($area), $notices);
    }

    /**
     * Whether a notice of a plugin would reach a user now, without sending anything: a chat of Telegram, or a WhatsApp
     * where it can go (inside the 24 hours, for a notice that never goes with a template), with the notice switched on
     * for that channel. For instance, to offer a person the approval of a login from Telegram only if it can reach them.
     *
     * @param string $component The plugin that declares the notice.
     * @param string $notice The notice.
     * @param int $userid The user.
     * @return bool
     */
    public static function reaches(string $component, string $notice, int $userid): bool {
        global $DB;
        $declared = local\notices::get($component, $notice);
        if (!$declared || !$userid) {
            return false;
        }
        $contactids = self::by_channels(self::joined($component, 0, $userid, '', $declared->ownjoin), $declared);
        foreach ($contactids as $contactid) {
            $contact = $DB->get_record(consent::CONTACTS, ['id' => $contactid]);
            if (!$contact || consent::out_of($contact, $component)) {
                continue;
            }
            $channel = telegram::is_chat($contact->phone) ? 'telegram' : 'whatsapp';
            if (!in_array($declared->key, local\notices::enabled($channel), true)) {
                continue;
            }
            if ($channel === 'telegram' || !$declared->freeonly || (int)$contact->lastinbound > time() - queue::WINDOW) {
                return true;
            }
        }
        return false;
    }

    /**
     * Closes a question asked with ask(), such as when it was answered in another way (in the app, or by another channel):
     * what has not gone does not go, it takes no more answers, and on Telegram its message says that it was answered or
     * that its time is over, without its buttons. A question whose time is over closes by itself.
     *
     * @param string $component The plugin that asked it.
     * @param string $ref The reference it was asked with.
     * @param string $how answered or expired.
     * @return int How many messages it closed.
     */
    public static function close_question(string $component, string $ref, string $how = 'answered'): int {
        global $DB;
        if (!in_array($how, ['answered', 'expired'], true)) {
            throw new \coding_exception('A question closes as answered or expired');
        }
        try {
            $bot = telegram_api::is_configured() ? telegram_api::from_config() : null;
        } catch (local\api_exception $e) {
            $bot = null;
        }
        $rows = $DB->get_records_select(
            queue::TABLE,
            'component = :component AND ref = :ref AND timeclosed = 0',
            ['component' => $component, 'ref' => $ref]
        );
        foreach ($rows as $row) {
            queue::close($row, $how, $bot);
        }
        return count($rows);
    }

    /**
     * Sends a text of the plugin now, such as what its team answers to what a person wrote, to the numbers and chats that
     * joined for one of its items. What people write of their own reaches the plugin with the hook
     * \\local_oksigeniamarandu\\hook\\message_received.
     *
     * It is a conversation, not a notice: it goes at once, without the quiet hours or the limits, and does not count in
     * the panel. On Telegram it always goes. On WhatsApp only inside the 24 hours after the person last wrote, when it is
     * free; outside them it does not try (closed), and only a notice with its template can reach them, with notify().
     *
     * @param string $component The plugin.
     * @param int $itemid Its item, such as a household.
     * @param string $text Plain text, up to 4096 characters.
     * @param \moodle_url|null $url A link on this site: a button on Telegram, a line below the text on WhatsApp.
     * @param string|null $address Only to whoever wrote: the number (E.164) or chat (tg:<chat>) of the hook.
     * @param string $label The label of the button on Telegram; «Open», in the language of the person, if empty.
     * @param string $area The kind of item, if the plugin has more than one; empty for its usual items.
     * @return array One for each number or chat, with channel (whatsapp or telegram), address, status (sent, closed or
     *               failed), messageid (the wamid, or tg.<chat>.<message>) and error. Empty if nobody joined for it.
     * @throws \coding_exception If the text is empty.
     */
    public static function send_text(
        string $component,
        int $itemid,
        string $text,
        ?\moodle_url $url = null,
        ?string $address = null,
        string $label = '',
        string $area = ''
    ): array {
        $area = self::area($area);
        $whatsapp = null;
        $bot = null;
        try {
            $whatsapp = cloud_api::is_configured() ? cloud_api::from_config() : null;
        } catch (local\api_exception $e) {
            $whatsapp = null;
        }
        try {
            $bot = telegram_api::is_configured() ? telegram_api::from_config() : null;
        } catch (local\api_exception $e) {
            $bot = null;
        }
        return local\conversation::send(
            $component,
            $itemid,
            $text,
            $url ? $url->out(false) : null,
            $label,
            $address,
            $whatsapp,
            $bot,
            null,
            $area
        );
    }

    /**
     * Whether the site has a channel of Telegram to post in, with its bot as an administrator that can post.
     *
     * @return bool
     */
    public static function channel_ready(): bool {
        return local\channel::is_ready();
    }

    /**
     * The channel of Telegram of the site, as anyone may find it: for a QR on a screen or a link on a page.
     *
     * @return array|null With url (https://t.me/<name>), title and members (how many it had the last time it was
     *                    counted, or null), and whatsapp (the link of the channel of WhatsApp of the site, if it gave one,
     *                    or null; since 0.57.0); null if the site has no channel ready or its channel is private.
     */
    public static function channel(): ?array {
        $channel = local\channel::current();
        if (!$channel || $channel->username === '' || !local\channel::is_ready()) {
            return null;
        }
        $members = local\channel::members(time());
        return ['url' => 'https://t.me/' . $channel->username, 'title' => $channel->title,
            'members' => $members ? $members->now : null, 'whatsapp' => self::whatsapp_channel()['url'] ?? null];
    }

    /**
     * The channel of WhatsApp of the site, as anyone may find it, whatever its channel of Telegram: for a QR on a screen
     * or a link on a page, beside that of Telegram.
     *
     * @return array|null With url (https://whatsapp.com/channel/…) and title (its name, or that of the site); null if the
     *                    site gave no link.
     */
    public static function whatsapp_channel(): ?array {
        $url = local\wachannel::channel_url();
        if ($url === '') {
            return null;
        }
        $title = local\wachannel::name() ?: format_string(get_site()->fullname, true, ['context' => \context_system::instance(),
            'escape' => false]);
        return ['url' => $url, 'title' => $title];
    }

    /**
     * The public link of the channel of Telegram of the site.
     *
     * @return string|null https://t.me/<name>; null if the site has no channel ready or its channel is private.
     */
    public static function channel_link(): ?string {
        return self::channel()['url'] ?? null;
    }

    /**
     * Posts in the channel of Telegram of the site something everyone may read, such as an open day or a book of the
     * library: never anything of one person. The plugin declares the kind in its lib.php,
     * <component>_oksigeniamarandu_posts() (see local\channel), and the site says whether it goes by itself, after whoever
     * reviews it, or not at all. Whoever reviews gets it first in their chat with the bot, in the hours of review; it goes
     * out in the hours of the channel, without sound. What is urgent goes at any hour, and its review too, with sound.
     *
     * @param string $component The plugin.
     * @param string $kind The kind of post, as declared.
     * @param array $content Any of title, image (a URL of this site with a PNG or a JPEG), lines, text, url (a web
     *                       address, for its button, which counts the visits), label (of the button), card (a card Marandu
     *                       draws from its templates, still and animated: ['type' => open_day, outing, this_week, no_class,
     *                       book, big_number or sky, 'zones' => [...]], see local\cards), animation (a URL of this site
     *                       with an MP4, instead of the card), utm (false for a link that must go as it is) and ai (true
     *                       when its own image or animation has something made or edited with artificial intelligence:
     *                       whoever publishes it in the channel of WhatsApp is told to label it there; a card says it in
     *                       its plantilla.json, "ai": true); a title or a text at least.
     * @param int|null $when Not before this time; now if null.
     * @param bool $urgent Whether it goes at any hour, such as classes called off.
     * @param string $key What the plugin knows it by, to change it while it waits or take it back with channel_cancel().
     * @param int|null $expires After this time it is no longer worth posting, such as the day of the event.
     * @param bool $again Whether a person asks for it: proposed again even if it had been skipped. Without it, what was skipped
     *                    comes back only if its lines (when and where) or when it is over changed.
     * @return int The id of the post; 0 if it does not go (no channel, the site switched the kind off, or the same key is
     *             in the channel already).
     * @throws \coding_exception If the kind is not declared or the content is not valid.
     */
    public static function channel_post(
        string $component,
        string $kind,
        array $content,
        ?int $when = null,
        bool $urgent = false,
        string $key = '',
        ?int $expires = null,
        bool $again = false
    ): int {
        return local\channel::post($component, $kind, $content, $when, $urgent, $key, $expires, null, $again);
    }

    /**
     * Takes back a post of the channel: if it has not gone out, it does not go; if it is in the channel, it is taken out.
     *
     * @param string $component The plugin.
     * @param string $kind The kind of post.
     * @param string $key What the plugin gave to channel_post().
     * @return int How many posts it took back.
     */
    public static function channel_cancel(string $component, string $kind, string $key): int {
        return local\channel::cancel($component, $kind, $key);
    }

    /**
     * Where a post of the channel is: waiting for its review, ready, in the channel or not; and when it goes out, as the
     * pace of the channel places it, or when it went. A post that does not fit in the pace before it expires has no time:
     * it does not go, unless a place frees up before then.
     *
     * @param string $component The plugin.
     * @param string $kind The kind of post.
     * @param string $key What the plugin gave to channel_post().
     * @return array|null With status (review, ready, published, skipped, cancelled, expired, remove, removed or failed),
     *                    time (when it goes or went; null if it has none) and expires; null if there is no such post.
     */
    public static function channel_state(string $component, string $kind, string $key): ?array {
        global $DB;
        $posts = $DB->get_records(
            local\channel::TABLE,
            ['component' => $component, 'kind' => $kind, 'sourcekey' => $key],
            'id DESC',
            '*',
            0,
            1
        );
        $post = reset($posts);
        if (!$post) {
            return null;
        }
        $time = null;
        if (in_array($post->status, ['review', 'ready'], true)) {
            $time = local\channel::goes_at($post, time());
        } else if ($post->timepublished) {
            $time = (int)$post->timepublished;
        }
        return ['status' => (string)$post->status, 'time' => $time,
            'expires' => $post->timeexpires ? (int)$post->timeexpires : null];
    }

    /**
     * How the posts of a plugin did in the channel, to measure it: when each went out, the visits through its button,
     * its reactions (anonymous, by emoji, as Telegram counts them, since 0.29.0) and how many people the channel had that
     * day. Telegram does not tell bots how many saw a post. Since 0.57.0, how it did in the channel of WhatsApp too: when
     * it was marked published there and the visits through its link there. Nothing of any person.
     *
     * @param string $component The plugin.
     * @param string $kind Its kind of post; every kind if empty.
     * @param string $key The key of one post; every one if empty.
     * @return array[] Each with key, kind, status, timepublished (null if it did not go out), clicks, reactions (emoji =>
     *                 how many), reactionstotal, members (null if the channel was not counted then) and whatsapp (null if
     *                 it did not wait for the channel of WhatsApp; or status waiting, published or withdrawn,
     *                 timepublished, null if it was not marked, and clicks).
     */
    public static function channel_stats(string $component, string $kind = '', string $key = ''): array {
        global $DB;
        $where = 'component = :component';
        $params = ['component' => $component];
        if ($kind !== '') {
            $where .= ' AND kind = :kind';
            $params['kind'] = $kind;
        }
        if ($key !== '') {
            $where .= ' AND sourcekey = :sourcekey';
            $params['sourcekey'] = $key;
        }
        $days = $DB->get_records_menu(local\channel::STATS, null, 'timeday ASC', 'timeday, members');
        $stats = [];
        $posts = $DB->get_records_select(local\channel::TABLE, $where, $params, 'id ASC');
        $whatsapp = local\wachannel::stats(array_keys($posts));
        foreach ($posts as $post) {
            $reactions = json_decode((string)($post->reactions ?? ''), true) ?: [];
            $members = null;
            if ($post->timepublished) {
                foreach ($days as $day => $count) {
                    if ($day > $post->timepublished) {
                        break;
                    }
                    $members = (int)$count;
                }
            }
            $stats[] = [
                'key' => $post->sourcekey,
                'kind' => $post->kind,
                'status' => $post->status,
                'timepublished' => $post->timepublished ? (int)$post->timepublished : null,
                'clicks' => (int)$post->clicks,
                'reactions' => $reactions,
                'reactionstotal' => array_sum($reactions),
                'members' => $members,
                'whatsapp' => $whatsapp[(int)$post->id] ?? null,
            ];
        }
        return $stats;
    }

    /**
     * How many people the channel had each day it was counted (once a day), for a series over time.
     *
     * @param int $from From this time.
     * @param int $to Until this one, not included.
     * @return int[] By day of the site (Y-m-d), the earliest first; only the days counted.
     */
    public static function channel_members(int $from, int $to): array {
        global $DB;
        $tz = \core_date::get_server_timezone();
        $days = [];
        foreach (
            $DB->get_records_select(
                local\channel::STATS,
                'timeday >= :timefrom AND timeday < :timeto',
                ['timefrom' => $from, 'timeto' => $to],
                'timeday ASC',
                'id, timeday, members'
            ) as $row
        ) {
            $days[userdate((int)$row->timeday, '%Y-%m-%d', $tz, false)] = (int)$row->members;
        }
        return $days;
    }

    /**
     * How the notices of a plugin did, by notice, channel and day (of the site), all summed: queued, sent, delivered and
     * read (WhatsApp says so; Telegram does not tell bots), clicked (through the button of the notice) and failed.
     *
     * @param string $component The plugin.
     * @param int $from From this time (queued).
     * @param int $to Until this one, not included.
     * @param string $notice One notice; every one if empty.
     * @param int[] $itemids Only those of these items of the plugin (since 0.30.0, which keeps the item of each notice);
     *                       every one if empty.
     * @return array[] Each with notice, channel, day (Y-m-d), queued, sent, delivered, read, clicked and failed.
     */
    public static function notice_stats(string $component, int $from, int $to, string $notice = '', array $itemids = []): array {
        global $DB;
        $where = 'q.component = :component AND q.timequeued >= :timefrom AND q.timequeued < :timeto';
        $params = ['component' => $component, 'timefrom' => $from, 'timeto' => $to];
        if ($notice !== '') {
            $where .= ' AND q.notice = :notice';
            $params['notice'] = $notice;
        }
        if ($itemids) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_map('intval', $itemids), SQL_PARAMS_NAMED, 'item');
            $where .= " AND q.itemid $insql";
            $params += $inparams;
        }
        $rows = $DB->get_recordset_sql("SELECT q.id, q.notice, q.status, q.timequeued, q.timeclicked, c.channel
                                          FROM {" . local\queue::TABLE . "} q
                                          JOIN {" . local\consent::CONTACTS . "} c ON c.id = q.contactid
                                         WHERE $where", $params);
        $tz = \core_date::get_server_timezone();
        $stats = [];
        foreach ($rows as $row) {
            $day = userdate((int)$row->timequeued, '%Y-%m-%d', $tz, false);
            $id = $day . '|' . $row->notice . '|' . $row->channel;
            $stats[$id] = $stats[$id] ?? ['notice' => $row->notice, 'channel' => $row->channel, 'day' => $day,
                'queued' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'clicked' => 0, 'failed' => 0];
            $stats[$id]['queued']++;
            $stats[$id]['sent'] += (int)in_array($row->status, ['sent', 'delivered', 'read'], true);
            $stats[$id]['delivered'] += (int)in_array($row->status, ['delivered', 'read'], true);
            $stats[$id]['read'] += (int)($row->status === 'read');
            $stats[$id]['clicked'] += (int)!empty($row->timeclicked);
            $stats[$id]['failed'] += (int)($row->status === 'failed');
        }
        $rows->close();
        ksort($stats);
        return array_values($stats);
    }

    /**
     * Queues a notice about a student for the numbers of the student's family, from the family records of Oksigenia
     * Family. Nothing goes out without them, nor to a number that has not opted in.
     *
     * @param string $component The plugin that declares the notice.
     * @param string $notice The notice.
     * @param string[]|\Closure $params One value for each {{n}} of its text, in order; plain text. Or a function of the
     *                                 language that gives them, as notify() takes it.
     * @param \moodle_url|null $url Where its button goes, on this site.
     * @param int $childuserid The student.
     * @param bool $urgent Whether it goes out in the quiet hours and over the notices a day too.
     * @param int $validfor Seconds after which it is no longer worth sending.
     * @param array $rich A card on Telegram instead of its text: any of title, image (a URL of this site with a PNG or a
     *                    JPEG), lines, text and style of the button (primary, danger or success), as local\\rich::clean()
     *                    says. If Telegram refuses it, the text goes. WhatsApp ignores it and goes with its template.
     * @param string $lang The language to write it in, if the site has it, as notify() takes it.
     * @return int How many numbers it was queued for.
     */
    public static function notify_child(
        string $component,
        string $notice,
        array|\Closure $params,
        ?\moodle_url $url,
        int $childuserid,
        bool $urgent = false,
        int $validfor = 2 * DAYSECS,
        array $rich = [],
        string $lang = ''
    ): int {
        global $DB;
        [$declared, $params] = self::prepare($component, $notice, $params);
        $contactids = family::contacts_of_child($childuserid);
        if (!$contactids) {
            return 0;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($contactids, SQL_PARAMS_NAMED);
        $active = $DB->get_fieldset_select(
            consent::CONTACTS,
            'id',
            "id $insql AND status = :active",
            $inparams + ['active' => 'active']
        );
        return self::queue_for($active, $declared, $params, $url, $urgent, $validfor, $rich, $lang);
    }

    /**
     * Stops numbers standing for an item of a plugin or for a user, when the plugin no longer has them (a family that
     * left, a user who is gone). The numbers keep their consent; they just stop getting these notices.
     *
     * @param string $component The plugin.
     * @param int $itemid Its item; 0 if the link is only for a user.
     * @param int|null $userid The user, if the links are for one.
     * @param string $area The kind of item, if the plugin has more than one; empty for its usual items.
     * @return int How many links were dropped.
     */
    public static function unlink(string $component, int $itemid = 0, ?int $userid = null, string $area = ''): int {
        global $DB;
        $params = ['component' => $component, 'area' => self::area($area), 'itemid' => $itemid];
        $where = 'component = :component AND area = :area AND itemid = :itemid AND '
            . ($userid === null ? 'userid IS NULL' : 'userid = :userid');
        if ($userid !== null) {
            $params['userid'] = $userid;
        }
        $count = $DB->count_records_select(consent::LINKS, $where, $params);
        $DB->delete_records_select(consent::LINKS, $where, $params);
        if ($userid === null) {
            local\joinask::close($component, $itemid, $params['area']);
            queue::cancel($component, $itemid, $params['area']);
        }
        return $count;
    }

    /**
     * Takes a number or a chat off one item of a plugin, such as the old phone of a family that gave a new one. The others
     * of the item stay, and the number keeps its consent for whatever else it joined.
     *
     * @param string $component The plugin.
     * @param int $itemid The item.
     * @param string $address The number in E.164, or tg:<chat>.
     * @param string $area The kind of item, if the plugin has more than one.
     * @return int How many links were dropped.
     */
    public static function unlink_number(string $component, int $itemid, string $address, string $area = ''): int {
        global $DB;
        $address = ltrim(trim($address), '+');
        $area = self::area($area);
        local\joinask::close($component, $itemid, $area, $address);
        $contactid = $DB->get_field(consent::CONTACTS, 'id', ['phone' => $address]);
        if (!$contactid) {
            return 0;
        }
        $link = ['component' => $component, 'area' => $area, 'itemid' => $itemid, 'contactid' => $contactid];
        $count = $DB->count_records(consent::LINKS, $link);
        $DB->delete_records(consent::LINKS, $link);
        queue::cancel($component, $itemid, $area, [], (int)$contactid);
        return $count;
    }

    /**
     * Two items of a plugin turn out to be the same, such as two records of one family: what joined for the first stands
     * for the second from now on, its codes work for it and its questions are about it.
     *
     * @param string $component The plugin.
     * @param int $from The item that goes.
     * @param int $to The item that stays.
     * @param string $area The kind of item, if the plugin has more than one.
     * @return int How many numbers and chats the second one gained.
     */
    public static function relink(string $component, int $from, int $to, string $area = ''): int {
        global $DB;
        if ($from === $to) {
            return 0;
        }
        $item = ['component' => $component, 'area' => self::area($area)];
        $where = 'component = :component AND area = :area AND itemid = :itemid AND userid IS NULL';
        $has = array_flip($DB->get_fieldset_select(
            consent::LINKS,
            'contactid',
            "$where AND contactid IS NOT NULL",
            $item + ['itemid' => $to]
        ));
        $gained = 0;
        foreach ($DB->get_records_select(consent::LINKS, $where, $item + ['itemid' => $from], 'id ASC') as $link) {
            if ($link->contactid && isset($has[$link->contactid])) {
                $DB->delete_records(consent::LINKS, ['id' => $link->id]);
                continue;
            }
            $DB->set_field(consent::LINKS, 'itemid', $to, ['id' => $link->id]);
            if ($link->contactid) {
                $has[$link->contactid] = true;
                $gained++;
            }
        }
        local\joinask::move($component, $from, $to, $item['area']);
        $DB->set_field(queue::TABLE, 'itemid', $to, $item + ['itemid' => $from, 'status' => 'queued']);
        return $gained;
    }

    /**
     * Closes the codes of an item of a plugin, or of a user, that nobody has written yet, such as those of a card that was
     * printed again: they stop working. What already joined with them stays; to stop it too, unlink().
     *
     * @param string $component The plugin.
     * @param int $itemid Its item; 0 if the codes are only for a user.
     * @param int|null $userid The user, if the codes are for one.
     * @param string $area The kind of item, if the plugin has more than one; empty for its usual items.
     * @return int How many codes were closed.
     */
    public static function close_codes(string $component, int $itemid = 0, ?int $userid = null, string $area = ''): int {
        global $DB;
        $params = ['component' => $component, 'area' => self::area($area), 'itemid' => $itemid];
        $where = 'component = :component AND area = :area AND itemid = :itemid AND contactid IS NULL AND '
            . ($userid === null ? 'userid IS NULL' : 'userid = :userid');
        if ($userid !== null) {
            $params['userid'] = $userid;
        }
        $count = $DB->count_records_select(consent::LINKS, $where, $params);
        $DB->delete_records_select(consent::LINKS, $where, $params);
        if ($userid === null) {
            local\joinask::close($component, $itemid, $params['area']);
        }
        return $count;
    }

    /**
     * An adult who leaves the family records: the numbers and chats that stand for that adult there, and for no other
     * adult of the records, stop getting the notices of items of a plugin, such as the card of a family that a parent
     * no longer belongs to. A number shared with another adult who stays keeps them. A chat of Telegram that joined only
     * with a code of the plugin does not stand for any adult: for it, a new code (unlink() and invite()).
     *
     * Call it from an observer of \local_oksigeniafamily\event\adult_removed: Marandu forgets the adult after the
     * observers of the other plugins, so that there it still knows who the adult was.
     *
     * @param string $component The plugin.
     * @param int $adultid The adult of the family records.
     * @param string $area The kind of item, if the plugin has more than one; empty for its usual items.
     * @param int[]|null $itemids Only these items, such as those of the family the adult leaves; null for all of them.
     * @return int How many links were dropped.
     */
    public static function unlink_adult(string $component, int $adultid, string $area = '', ?array $itemids = null): int {
        global $DB;
        $area = self::area($area);
        if ($itemids === []) {
            return 0;
        }
        $contactids = $DB->get_fieldset_sql(
            "SELECT DISTINCT l.contactid
               FROM {" . consent::LINKS . "} l
              WHERE l.component = :family AND l.itemid = :adultid AND l.contactid IS NOT NULL
                    AND NOT EXISTS (SELECT 1
                                      FROM {" . consent::LINKS . "} o
                                     WHERE o.contactid = l.contactid AND o.component = :family2 AND o.itemid <> :adultid2)",
            ['family' => family::COMPONENT, 'adultid' => $adultid, 'family2' => family::COMPONENT, 'adultid2' => $adultid]
        );
        if (!$contactids) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_map('intval', $contactids), SQL_PARAMS_NAMED, 'c');
        $where = "component = :component AND area = :area AND userid IS NULL AND contactid $insql";
        $params += ['component' => $component, 'area' => $area];
        if ($itemids !== null) {
            [$initems, $itemparams] = $DB->get_in_or_equal(array_map('intval', $itemids), SQL_PARAMS_NAMED, 'i');
            $where .= " AND itemid $initems";
            $params += $itemparams;
        }
        $count = $DB->count_records_select(consent::LINKS, $where, $params);
        $DB->delete_records_select(consent::LINKS, $where, $params);
        return $count;
    }

    /**
     * Which accounts and adults of the family records already get the notices of items of a plugin on their phone: the
     * same active number or chat stands for the item and for them, through their account or their record. For instance,
     * not to send by email to an adult what already reaches their phone.
     *
     * @param string $component The plugin.
     * @param int[] $itemids Its items.
     * @param int[] $userids The accounts to look for.
     * @param int[] $adultids The adults of the family records to look for.
     * @param string $area The kind of item, if the plugin has more than one; empty for its usual items.
     * @return array Item id => ['users' => user ids, 'adults' => adult ids], only for the items with any of them.
     */
    public static function same_contact(
        string $component,
        array $itemids,
        array $userids = [],
        array $adultids = [],
        string $area = ''
    ): array {
        global $DB;
        $area = self::area($area);
        $itemids = array_values(array_unique(array_map('intval', $itemids)));
        $userids = array_flip(array_map('intval', $userids));
        $adultids = array_flip(array_map('intval', $adultids));
        if (!$itemids || (!$userids && !$adultids)) {
            return [];
        }
        // The active numbers and chats of each item.
        $byitem = [];
        foreach (array_chunk($itemids, 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'i');
            $rows = $DB->get_recordset_sql(
                "SELECT l.id, l.itemid, l.contactid
                   FROM {" . consent::LINKS . "} l
                   JOIN {" . consent::CONTACTS . "} c ON c.id = l.contactid AND c.status = :active
                  WHERE l.component = :component AND l.area = :area AND l.userid IS NULL AND l.itemid $insql",
                $params + ['active' => 'active', 'component' => $component, 'area' => $area]
            );
            foreach ($rows as $row) {
                $byitem[(int)$row->itemid][(int)$row->contactid] = true;
            }
            $rows->close();
        }
        if (!$byitem) {
            return [];
        }
        // Whom each of those numbers and chats stands for: accounts, and adults of the family records.
        $contactids = [];
        foreach ($byitem as $theirs) {
            $contactids += $theirs;
        }
        $users = [];
        $adults = [];
        foreach (array_chunk(array_keys($contactids), 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'c');
            $rows = $DB->get_recordset_sql(
                "SELECT id, contactid, userid, component, itemid
                   FROM {" . consent::LINKS . "}
                  WHERE contactid $insql AND (userid IS NOT NULL OR component = :family)",
                $params + ['family' => family::COMPONENT]
            );
            foreach ($rows as $row) {
                if ((int)$row->userid > 0 && isset($userids[(int)$row->userid])) {
                    $users[(int)$row->contactid][(int)$row->userid] = (int)$row->userid;
                }
                if ($row->component === family::COMPONENT && isset($adultids[(int)$row->itemid])) {
                    $adults[(int)$row->contactid][(int)$row->itemid] = (int)$row->itemid;
                }
            }
            $rows->close();
        }
        $same = [];
        foreach ($byitem as $itemid => $theirs) {
            $found = ['users' => [], 'adults' => []];
            foreach (array_keys($theirs) as $contactid) {
                $found['users'] += $users[$contactid] ?? [];
                $found['adults'] += $adults[$contactid] ?? [];
            }
            if ($found['users'] || $found['adults']) {
                sort($found['users']);
                sort($found['adults']);
                $same[$itemid] = $found;
            }
        }
        return $same;
    }

    /**
     * Loads on this page «Propose an improvement», the piece the tools of the site share: any element with
     * data-marandu-proposal="<component>" (and data-screen="<screen>", as the tool names it) opens it, and the script
     * window.MaranduProposal.open({component, screen}) too. The person marks on a picture of what they see what they
     * would change and says how; the people the site chose hear of it by Telegram, and cli/proposals.php gives them.
     * Only for an account that may propose; for others it loads nothing.
     *
     * @return bool Whether it is on this page.
     */
    public static function proposal_js(): bool {
        return local\proposals::js();
    }

    /**
     * What «Propose an improvement» needs, for a page that prints itself without the header of Moodle (and perhaps with a
     * policy of content that allows no scripts in the page): the script and the styles to link, and the data to print
     * as is inside <script type="application/json" id="marandu-proposal">…</script>, which proposal.js reads (the texts,
     * the address of the site and the key of the session). Then any element with data-marandu-proposal opens it.
     *
     * @return array|null js and css (addresses), strings, config (wwwroot, sesskey) and data (the JSON to print); null for
     *                    an account that may not propose.
     */
    public static function proposal_assets(): ?array {
        return local\proposals::assets();
    }

    /**
     * The button «Sugerencias» for a screen of a tool, with what it needs loaded on the page: a pill with its icon and its
     * word in the header; on a phone, the icon alone. It carries the name and the version of the tool, which go with what
     * does not work.
     *
     * @param string $component The tool, such as local_oksigeniameals.
     * @param string $screen The screen, as the tool names it (such as «the week of a diner»).
     * @param string $class More classes for the button.
     * @return string Its HTML; empty for an account that may not propose.
     */
    public static function proposal_button(string $component, string $screen = '', string $class = ''): string {
        return local\proposals::button($component, $screen, $class);
    }

    /**
     * An account of the site that passes to another person (in many centres, the account of whoever leaves goes to who
     * comes): the new person does not inherit anything of Marandu from the old one. The numbers and chats joined to the
     * account stop getting its notices, and what the conversations kept of that person goes: what they read, their
     * reminders and favourites, their board, their place among those who approve the sends or publish in the channel of
     * WhatsApp. The person of the account of Chatwoot follows the email of the account: if the email does not change,
     * give the new person their own agent.
     *
     * @param int $userid The account.
     */
    public static function account_reassigned(int $userid): void {
        if ($userid <= 0) {
            return;
        }
        \local_oksigeniamarandu\privacy\provider::delete_user($userid);
        foreach ([\local_oksigeniamarandu\external\chat_prefs::PREFERENCE, local\chat\agenda::PREF] as $name) {
            unset_user_preference($name, $userid);
        }
        foreach (array_keys(\local_oksigeniamarandu\external\chat_flag::FLAGS) as $flag) {
            unset_user_preference('local_oksigeniamarandu_chat' . $flag, $userid);
        }
        $people = array_values(array_diff(array_map('intval', explode(
            ',',
            (string)get_config('local_oksigeniamarandu', 'spendpeople')
        )), [$userid, 0]));
        if ((string)get_config('local_oksigeniamarandu', 'spendpeople') !== '') {
            set_config('spendpeople', implode(',', $people), 'local_oksigeniamarandu');
        }
        // Nor their place among those who publish in the channel of WhatsApp.
        if (in_array($userid, local\wachannel::chosen(), true)) {
            local\wachannel::set_people(array_diff(local\wachannel::chosen(), [$userid]));
        }
    }

    /**
     * The open conversation of the team with an item of a plugin, such as a household of admissions, to take the team
     * there instead of answering twice: the newest one, not resolved, of the numbers that joined for it. The page of the
     * conversation checks, as always, who may see it.
     *
     * @param string $component The plugin of the item.
     * @param int $itemid The item.
     * @param string $area The kind of item, if the plugin has more than one.
     * @return \moodle_url|null Null if the conversations are not connected or there is none open.
     */
    public static function conversation_url(string $component, int $itemid, string $area = ''): ?\moodle_url {
        global $DB;
        if (!local\chat\chatwoot::is_configured()) {
            return null;
        }
        $phones = [];
        foreach (self::linked_contacts($component, $itemid, $area) as $contact) {
            if (!telegram::is_chat((string)$contact->phone)) {
                $phones[] = (string)$contact->phone;
            }
        }
        if (!$phones) {
            return null;
        }
        [$in, $params] = $DB->get_in_or_equal($phones, SQL_PARAMS_NAMED, 'ph');
        $rows = $DB->get_records_select(
            local\chat\mirror::TABLE,
            "phone $in AND status <> 'resolved'",
            $params,
            'lastactivity DESC',
            'id, cwid',
            0,
            1
        );
        return $rows ? new \moodle_url('/local/oksigeniamarandu/chat.php', ['c' => (int)reset($rows)->cwid]) : null;
    }

    /**
     * The numbers and chats a user joined with a code of a plugin, such as the chats that can approve their logins: for
     * a page where the person sees them and takes away one that is not theirs.
     *
     * @param string $component The plugin of the code.
     * @param int $userid The user.
     * @return array Each with id (to take it away), channel (whatsapp or telegram), name (as the chat or WhatsApp
     *               gives it, or empty), ending (the last digits of a number; empty for a chat) and since (when it joined).
     */
    public static function own_chats(string $component, int $userid): array {
        global $DB;
        $rows = $DB->get_records_sql("SELECT l.id, l.timeclaimed, c.phone, c.name
                                        FROM {" . consent::LINKS . "} l
                                        JOIN {" . consent::CONTACTS . "} c ON c.id = l.contactid
                                       WHERE l.component = :component AND l.userid = :userid AND l.bynumber = 0
                                             AND c.status = :active
                                    ORDER BY l.timeclaimed ASC", ['component' => $component, 'userid' => $userid,
            'active' => 'active']);
        $list = [];
        foreach ($rows as $row) {
            $chat = telegram::is_chat((string)$row->phone);
            $list[] = ['id' => (int)$row->id, 'channel' => $chat ? 'telegram' : 'whatsapp', 'name' => (string)$row->name,
                'ending' => $chat ? '' : substr((string)$row->phone, -3), 'since' => (int)$row->timeclaimed];
        }
        return $list;
    }

    /**
     * Takes away one of the numbers or chats a user joined with a code of a plugin (from own_chats()): it gets nothing
     * of that plugin for that user any more; the rest of the person's notices do not change.
     *
     * @param string $component The plugin of the code.
     * @param int $userid The user.
     * @param int $id The one to take away, as own_chats() gives it.
     * @return bool Whether there was one.
     */
    public static function unlink_chat(string $component, int $userid, int $id): bool {
        global $DB;
        $conditions = ['id' => $id, 'component' => $component, 'userid' => $userid];
        if (!$DB->record_exists(consent::LINKS, $conditions)) {
            return false;
        }
        $DB->delete_records(consent::LINKS, $conditions);
        return true;
    }

    /**
     * The kind of item a plugin gives, checked: empty, or a short name such as kitchen.
     *
     * @param string $area The kind of item.
     * @return string
     * @throws \coding_exception If it is not a short name of lowercase letters, digits and underscores.
     */
    private static function area(string $area): string {
        if (!preg_match('/^([a-z][a-z0-9_]{0,39})?$/', $area)) {
            throw new \coding_exception('An area is a short name of lowercase letters, digits and underscores, such as kitchen');
        }
        return $area;
    }

    /**
     * A declared notice and its values, as WhatsApp takes them.
     *
     * @param string $component The plugin that declares the notice.
     * @param string $notice The notice.
     * @param string[]|\Closure $params Its values, or the function of the language that gives them (checked when called).
     * @return array The notice and the values.
     * @throws \coding_exception If the notice is not declared or takes another number of values.
     */
    private static function prepare(string $component, string $notice, array|\Closure $params): array {
        $declared = notices::get($component, $notice);
        if (!$declared) {
            throw new \coding_exception("The notice {$component}/{$notice} is not declared");
        }
        return [$declared, $params instanceof \Closure ? $params : self::values($declared, $params)];
    }

    /**
     * The values of a notice, as WhatsApp takes them.
     *
     * @param \stdClass $declared The notice.
     * @param mixed $params Its values.
     * @return string[]
     * @throws \coding_exception If they are not as many as the notice takes.
     */
    private static function values(\stdClass $declared, mixed $params): array {
        if (!is_array($params) || count($params) !== count($declared->examples)) {
            throw new \coding_exception(
                "The notice {$declared->component}/{$declared->notice} takes " . count($declared->examples) . ' values'
            );
        }
        // WhatsApp refuses values with new lines, tabs or long runs of spaces.
        return array_map(function ($value) use ($declared) {
            return \core_text::substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string)$value))), 0, $declared->valuelimit);
        }, array_values($params));
    }

    /**
     * The active numbers and chats that joined for an item of a plugin, or for a user.
     *
     * @param string $component The plugin.
     * @param int $itemid The item; 0 for a user.
     * @param int|null $userid The user, if it is for a user.
     * @param string $area The kind of item.
     * @param bool $ownjoin For a user, only what they joined with a code of this plugin.
     * @return int[]
     */
    private static function joined(string $component, int $itemid, ?int $userid, string $area, bool $ownjoin = false): array {
        global $DB;
        if ($userid !== null && $ownjoin) {
            // Only what the user joined with a code of this plugin: not from their account settings, nor for another
            // plugin, nor because the family records have the number.
            $where = 'l.userid = :userid AND l.component = :component AND l.bynumber = 0';
            $sqlparams = ['userid' => $userid, 'component' => $component];
        } else if ($userid !== null) {
            $where = 'l.userid = :userid';
            $sqlparams = ['userid' => $userid];
        } else {
            $where = 'l.component = :component AND l.area = :area AND l.itemid = :itemid AND l.userid IS NULL';
            $sqlparams = ['component' => $component, 'area' => $area, 'itemid' => $itemid];
        }
        return $DB->get_fieldset_sql("SELECT DISTINCT c.id
                                        FROM {" . consent::LINKS . "} l
                                        JOIN {" . consent::CONTACTS . "} c ON c.id = l.contactid
                                       WHERE $where AND c.status = :active", $sqlparams + ['active' => 'active']);
    }

    /**
     * Only the contacts of the channels a notice goes by.
     *
     * @param int[] $contactids The contacts.
     * @param \stdClass $declared The notice.
     * @return int[]
     */
    private static function by_channels(array $contactids, \stdClass $declared): array {
        global $DB;
        // A notice that only goes by some channels is not queued for the others.
        $channels = $declared->channels ?? array_keys(notices::SETTING);
        if (!$contactids || !array_diff(array_keys(notices::SETTING), $channels)) {
            return $contactids;
        }
        [$inids, $idparams] = $DB->get_in_or_equal(array_map('intval', $contactids), SQL_PARAMS_NAMED, 'c');
        [$inchannels, $chparams] = $DB->get_in_or_equal($channels, SQL_PARAMS_NAMED, 'ch');
        return $DB->get_fieldset_select(consent::CONTACTS, 'id', "id $inids AND channel $inchannels", $idparams + $chparams);
    }

    /**
     * Queues a notice for some contacts.
     *
     * @param int[] $contactids The contacts.
     * @param \stdClass $declared The notice.
     * @param string[]|\Closure $params Its values, or the function of the language that gives them.
     * @param \moodle_url|null $url Where its button goes.
     * @param bool $urgent Whether it goes out in the quiet hours and over the notices a day too.
     * @param int $validfor Seconds after which it is no longer worth sending.
     * @param array $rich The parts of its card on Telegram, if it has one.
     * @param string $lang The language its plugin asked for.
     * @param int $itemid The item of the plugin it goes to, to count its notices.
     * @param string $area The kind of that item.
     * @param string|null $ref For a question, what the plugin needs to know it again when it is answered.
     * @return int How many.
     */
    private static function queue_for(
        array $contactids,
        \stdClass $declared,
        array|\Closure $params,
        ?\moodle_url $url,
        bool $urgent,
        int $validfor,
        array $rich,
        string $lang = '',
        int $itemid = 0,
        string $area = '',
        ?string $ref = null
    ): int {
        global $CFG, $DB;
        $rich = local\rich::clean($rich);
        // Only a language the site has: the texts of the notices are in its language packs.
        $lang = $lang !== '' && self::language($lang) === trim($lang) ? trim($lang) : '';
        $contactids = array_map('intval', self::by_channels($contactids, $declared));
        // Each contact with the language of its notice and the values written in it. Values given as they are go in the
        // language the plugin asked for (or that of the person or the site, if it asked for none); a function writes
        // them in the language of each person: the one they chose, else the one asked for, else that of the site.
        $rows = [];
        if ($params instanceof \Closure && $contactids) {
            $chosen = $DB->get_records_list(consent::CONTACTS, 'id', $contactids, '', 'id, lang');
            $values = [];
            foreach ($contactids as $contactid) {
                $rowlang = (string)($chosen[$contactid]->lang ?? '') ?: ($lang ?: (string)$CFG->lang);
                $values[$rowlang] = $values[$rowlang] ?? self::values($declared, ($params)($rowlang));
                $rows[$contactid] = [$values[$rowlang], $rowlang];
            }
        } else {
            foreach ($contactids as $contactid) {
                $rows[$contactid] = [$params, $lang];
            }
        }
        foreach ($rows as $contactid => [$rowparams, $rowlang]) {
            queue::add(
                $contactid,
                $declared,
                $rowparams,
                $url ? $url->out(false) : null,
                $urgent,
                $validfor,
                null,
                $rich,
                $ref,
                $itemid,
                $area,
                $rowlang
            );
        }
        return count($rows);
    }
}
