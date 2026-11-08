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
 *     $url = \local_oksigeniamarandu\api::optin_url($code); // Opens WhatsApp with «ALTA <code>» written.
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
     * @return string The code.
     */
    public static function invite(string $component, int $itemid = 0, ?int $userid = null, string $area = ''): string {
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
        $open = $DB->get_records_select(consent::LINKS, "component = :component AND area = :area AND itemid = :itemid
            AND $usersql AND contactid IS NULL", $params, 'id ASC', 'id, code', 0, 1);
        if ($open) {
            return reset($open)->code;
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
        ]);
        return $code;
    }

    /**
     * The link that opens WhatsApp with the opt-in word and the code written, ready to send to the number of the site.
     *
     * @param string $code A code from invite().
     * @return string|null Null if the site has not set its number yet.
     */
    public static function optin_url(string $code): ?string {
        $number = preg_replace('/[^0-9]/', '', (string)get_config('local_oksigeniamarandu', 'displayphone'));
        if ($number === '') {
            return null;
        }
        return 'https://wa.me/' . $number . '?text=' . rawurlencode(keywords::first('optinkeywords') . ' ' . $code);
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
     * The link that joins with a code on a channel: WhatsApp with the opt-in word and the code written, or the bot of
     * Telegram started with the code.
     *
     * @param string $channel whatsapp or telegram.
     * @param string $code A code from invite(), or of the family records.
     * @return string|null Null if the site does not have that channel ready.
     */
    public static function join_url(string $channel, string $code): ?string {
        if ($channel === 'telegram') {
            return telegram::join_url($code);
        }
        return $channel === 'whatsapp' ? self::optin_url($code) : null;
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
     * @return array By channel, with name, joined, where and url.
     */
    public static function item_channels(string $component, int $itemid, string $area = ''): array {
        return self::channel_list($component, $itemid, null, self::area($area));
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
        $joined = $DB->get_records_sql("SELECT c.id, c.channel, c.phone
                                          FROM {" . consent::LINKS . "} l
                                          JOIN {" . consent::CONTACTS . "} c ON c.id = l.contactid
                                         WHERE {$where}c.status = :active", $params);
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
            $code = $code ?? self::invite($component, $itemid, $userid, $area);
            $list[$channel] = [
                'name' => $info['name'],
                'joined' => (bool)$contact,
                // To the person themselves, the number half hidden, or just Telegram.
                'where' => $contact ? (local\telegram::is_chat($contact->phone) ? 'Telegram'
                    : local\panel::mask($contact->phone)) : '',
                'url' => self::join_url($channel, $code),
            ];
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
     * @param string[] $params One value for each {{n}} of its text, in order; plain text.
     * @param \moodle_url|null $url Where its button goes, on this site.
     * @param int $itemid The item of the plugin, such as a family; 0 if the notice is for a user.
     * @param int|null $userid The user, if it is for a user: then every number linked to the user gets it.
     * @param bool $urgent Whether it goes out in the quiet hours and over the notices a day too.
     * @param int $validfor Seconds after which it is no longer worth sending.
     * @param array $rich A card on Telegram instead of its text: any of title, image (a URL of this site with a PNG or a
     *                    JPEG), lines, text and style of the button (primary, danger or success), as local\\rich::clean()
     *                    says. If Telegram refuses it, the text goes. WhatsApp ignores it and goes with its template.
     * @param string $area The kind of item, if the plugin has more than one; empty for its usual items.
     * @param string $lang The language to write it in, such as that of the family, if the site has its language pack (see
     *                     language()); a person who chose a language on Telegram gets theirs. Empty for theirs or the site's.
     * @return int How many numbers it was queued for.
     */
    public static function notify(
        string $component,
        string $notice,
        array $params,
        ?\moodle_url $url = null,
        int $itemid = 0,
        ?int $userid = null,
        bool $urgent = false,
        int $validfor = 2 * DAYSECS,
        array $rich = [],
        string $area = '',
        string $lang = ''
    ): int {
        [$declared, $params] = self::prepare($component, $notice, $params);
        $contactids = self::joined($component, $itemid, $userid, self::area($area));
        return self::queue_for($contactids, $declared, $params, $url, $urgent, $validfor, $rich, $lang);
    }

    /**
     * The language Marandu writes a notice in when a plugin asks for one: that one, if the site has its language pack, so
     * that the plugin writes the values of the notice (dates, names) in the same; or else the language of the site. A
     * person who chose a language on Telegram gets theirs, whatever the plugin asks.
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
     * @param string[] $params One value for each {{n}} of its text, in order; plain text.
     * @param string $ref What the plugin needs to know the question again when it is answered, up to 64 characters.
     * @param int $itemid The item of the plugin, such as an adult of the family records; 0 if it is for a user.
     * @param int|null $userid The user, if it is for a user.
     * @param \moodle_url|null $url Where its link goes, on this site.
     * @param int $validfor Seconds after which it is no longer worth sending, such as until the deadline of the answer.
     * @param string $area The kind of item, if the plugin has more than one; empty for its usual items. The answer
     *                     comes back with it.
     * @return int How many numbers and chats it was queued for.
     * @throws \coding_exception If the notice is not a question or the reference is not valid.
     */
    public static function ask(
        string $component,
        string $notice,
        array $params,
        string $ref,
        int $itemid = 0,
        ?int $userid = null,
        ?\moodle_url $url = null,
        int $validfor = 2 * DAYSECS,
        string $area = ''
    ): int {
        [$declared, $params] = self::prepare($component, $notice, $params);
        $area = self::area($area);
        if (!$declared->options) {
            throw new \coding_exception("The notice {$component}/{$notice} has no options to answer");
        }
        if ($ref === '' || \core_text::strlen($ref) > 64) {
            throw new \coding_exception('The reference of a question has from 1 to 64 characters');
        }
        $contactids = self::joined($component, $itemid, $userid, $area);
        foreach ($contactids as $contactid) {
            queue::add(
                (int)$contactid,
                $declared,
                $params,
                $url ? $url->out(false) : null,
                false,
                $validfor,
                null,
                [],
                $ref,
                $userid === null ? $itemid : 0,
                $userid === null ? $area : ''
            );
        }
        return count($contactids);
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
     *                    counted, or null); null if the site has no channel ready or its channel is private.
     */
    public static function channel(): ?array {
        $channel = local\channel::current();
        if (!$channel || $channel->username === '' || !local\channel::is_ready()) {
            return null;
        }
        $members = local\channel::members(time());
        return ['url' => 'https://t.me/' . $channel->username, 'title' => $channel->title,
            'members' => $members ? $members->now : null];
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
     *                       with an MP4, instead of the card) and utm (false for a link that must go as it is); a title or
     *                       a text at least.
     * @param int|null $when Not before this time; now if null.
     * @param bool $urgent Whether it goes at any hour, such as classes called off.
     * @param string $key What the plugin knows it by, to change it while it waits or take it back with channel_cancel().
     * @param int|null $expires After this time it is no longer worth posting, such as the day of the event.
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
        ?int $expires = null
    ): int {
        return local\channel::post($component, $kind, $content, $when, $urgent, $key, $expires);
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
     * How the posts of a plugin did in the channel, to measure it: when each went out, the visits through its button,
     * its reactions (anonymous, by emoji, as Telegram counts them, since 0.29.0) and how many people the channel had that
     * day. Telegram does not tell bots how many saw a post. Nothing of any person.
     *
     * @param string $component The plugin.
     * @param string $kind Its kind of post; every kind if empty.
     * @param string $key The key of one post; every one if empty.
     * @return array[] Each with key, kind, status, timepublished (null if it did not go out), clicks, reactions (emoji =>
     *                 how many), reactionstotal and members (null if the channel was not counted then).
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
        foreach ($DB->get_records_select(local\channel::TABLE, $where, $params, 'id ASC') as $post) {
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
            ];
        }
        return $stats;
    }

    /**
     * How the notices of a plugin did, by notice, channel and day (of the site), all summed: queued, sent, delivered and
     * read (WhatsApp says so; Telegram does not tell bots), clicked (through the button of the notice) and failed.
     *
     * @param string $component The plugin.
     * @param int $from From this time (queued).
     * @param int $to Until this one, not included.
     * @param string $notice One notice; every one if empty.
     * @return array[] Each with notice, channel, day (Y-m-d), queued, sent, delivered, read, clicked and failed.
     */
    public static function notice_stats(string $component, int $from, int $to, string $notice = ''): array {
        global $DB;
        $where = 'q.component = :component AND q.timequeued >= :timefrom AND q.timequeued < :timeto';
        $params = ['component' => $component, 'timefrom' => $from, 'timeto' => $to];
        if ($notice !== '') {
            $where .= ' AND q.notice = :notice';
            $params['notice'] = $notice;
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
     * @param string[] $params One value for each {{n}} of its text, in order; plain text.
     * @param \moodle_url|null $url Where its button goes, on this site.
     * @param int $childuserid The student.
     * @param bool $urgent Whether it goes out in the quiet hours and over the notices a day too.
     * @param int $validfor Seconds after which it is no longer worth sending.
     * @param array $rich A card on Telegram instead of its text: any of title, image (a URL of this site with a PNG or a
     *                    JPEG), lines, text and style of the button (primary, danger or success), as local\\rich::clean()
     *                    says. If Telegram refuses it, the text goes. WhatsApp ignores it and goes with its template.
     * @param string $lang The language to write it in, if the site has it (see language()).
     * @return int How many numbers it was queued for.
     */
    public static function notify_child(
        string $component,
        string $notice,
        array $params,
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
        return $count;
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
     * @param string[] $params Its values.
     * @return array The notice and the values.
     * @throws \coding_exception If the notice is not declared or takes another number of values.
     */
    private static function prepare(string $component, string $notice, array $params): array {
        $declared = notices::get($component, $notice);
        if (!$declared) {
            throw new \coding_exception("The notice {$component}/{$notice} is not declared");
        }
        if (count($params) !== count($declared->examples)) {
            throw new \coding_exception("The notice {$component}/{$notice} takes " . count($declared->examples) . ' values');
        }
        // WhatsApp refuses values with new lines, tabs or long runs of spaces.
        $params = array_map(function ($value) {
            return \core_text::substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string)$value))), 0, 200);
        }, array_values($params));
        return [$declared, $params];
    }

    /**
     * The active numbers and chats that joined for an item of a plugin, or for a user.
     *
     * @param string $component The plugin.
     * @param int $itemid The item; 0 for a user.
     * @param int|null $userid The user, if it is for a user.
     * @param string $area The kind of item.
     * @return int[]
     */
    private static function joined(string $component, int $itemid, ?int $userid, string $area): array {
        global $DB;
        if ($userid !== null) {
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
     * Queues a notice for some contacts.
     *
     * @param int[] $contactids The contacts.
     * @param \stdClass $declared The notice.
     * @param string[] $params Its values.
     * @param \moodle_url|null $url Where its button goes.
     * @param bool $urgent Whether it goes out in the quiet hours and over the notices a day too.
     * @param int $validfor Seconds after which it is no longer worth sending.
     * @param array $rich The parts of its card on Telegram, if it has one.
     * @param string $lang The language its plugin asked for.
     * @return int How many.
     */
    private static function queue_for(
        array $contactids,
        \stdClass $declared,
        array $params,
        ?\moodle_url $url,
        bool $urgent,
        int $validfor,
        array $rich,
        string $lang = ''
    ): int {
        $rich = local\rich::clean($rich);
        // Only a language the site has: the texts of the notices are in its language packs.
        $lang = $lang !== '' && self::language($lang) === trim($lang) ? trim($lang) : '';
        foreach ($contactids as $contactid) {
            queue::add(
                (int)$contactid,
                $declared,
                $params,
                $url ? $url->out(false) : null,
                $urgent,
                $validfor,
                null,
                $rich,
                null,
                0,
                '',
                $lang
            );
        }
        return count($contactids);
    }
}
