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

use local_oksigeniamarandu\task\send_reply;

/**
 * Telegram, the second channel: the bot of the site, what reaches it and the link to join.
 *
 * A person joins by opening the bot with its link (t.me/<bot>?start=<code>), which sends «/start <code>»: the same as
 * the opt-in word on WhatsApp. A bot cannot write first, so nothing reaches anybody who has not started it. The contact
 * of a chat has «tg:» and the chat as its address, beside the numbers of WhatsApp.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class telegram {
    /** What goes before a chat of Telegram in the address of a contact. */
    public const PREFIX = 'tg:';

    /**
     * The address of a chat, as a contact keeps it.
     *
     * @param string $chatid The chat.
     * @return string
     */
    public static function address(string $chatid): string {
        return self::PREFIX . $chatid;
    }

    /**
     * Whether an address is a chat of Telegram.
     *
     * @param string $address The address of a contact.
     * @return bool
     */
    public static function is_chat(string $address): bool {
        return strpos($address, self::PREFIX) === 0;
    }

    /**
     * The chat of an address.
     *
     * @param string $address The address of a contact.
     * @return string
     */
    public static function chat(string $address): string {
        return substr($address, strlen(self::PREFIX));
    }

    /**
     * The link that opens the bot with a code, ready to start.
     *
     * @param string $code A code from invite(), or of the family records.
     * @return string|null Null if the site has no bot.
     */
    public static function join_url(string $code): ?string {
        $bot = (string)get_config('local_oksigeniamarandu', 'telegrambot');
        return $bot === '' ? null : 'https://t.me/' . $bot . '?start=' . rawurlencode($code);
    }

    /**
     * Connects the bot of the site: checks its token with Telegram, keeps it and tells Telegram where the webhook is.
     *
     * @param telegram_api $api The client, with the new token.
     * @param string $token The token, to keep it encrypted.
     * @return array The bot, with first_name and username.
     * @throws api_exception If Telegram does not take the token or the webhook.
     */
    public static function connect(telegram_api $api, string $token): array {
        $bot = $api->get_me();
        $secret = random_string(48);
        $api->set_webhook((new \moodle_url('/local/oksigeniamarandu/tgwebhook.php'))->out(false), $secret);
        set_config('telegramtoken', \core\encryption::encrypt($token), 'local_oksigeniamarandu');
        set_config('telegrambotid', (string)($bot['id'] ?? self::bot_id($token)), 'local_oksigeniamarandu');
        set_config('telegrambot', (string)($bot['username'] ?? ''), 'local_oksigeniamarandu');
        set_config('telegramname', (string)($bot['first_name'] ?? ''), 'local_oksigeniamarandu');
        set_config('telegramsecret', $secret, 'local_oksigeniamarandu');
        unset_config('telegramblocked', 'local_oksigeniamarandu');
        self::menu($api);
        return $bot;
    }

    /**
     * Gives the bot its menu. Without it the bot works the same: a failure is only noted.
     *
     * @param telegram_api $api The bot.
     */
    public static function menu(telegram_api $api): void {
        try {
            telegram_menu::register($api);
        } catch (api_exception $e) {
            debugging('Marandu: the menu of the bot was not set: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * The bot a token is of: the number before its colon.
     *
     * @param string $token The token.
     * @return string
     */
    public static function bot_id(string $token): string {
        return (string)strtok($token, ':');
    }

    /**
     * Whether a new token is of the bot the site has now (a token renewed with /revoke), rather than of another bot.
     *
     * @param array $bot The new bot, as getMe gives it.
     * @return bool
     */
    public static function is_same_bot(array $bot): bool {
        $id = (string)get_config('local_oksigeniamarandu', 'telegrambotid');
        if ($id !== '') {
            return (string)($bot['id'] ?? '') === $id;
        }
        return strcasecmp((string)($bot['username'] ?? ''), (string)get_config('local_oksigeniamarandu', 'telegrambot')) === 0;
    }

    /**
     * The chats that have joined the bot and receive notices.
     *
     * @return int[] The contacts.
     */
    public static function joined(): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_select(
            consent::CONTACTS,
            'id',
            'channel = :channel AND status = :active',
            ['channel' => 'telegram', 'active' => 'active']
        ));
    }

    /**
     * Changes to another bot: the chats that had joined the old one wait for the new one (a bot cannot write first, so
     * they have to open it and start it), without opting out and still standing for whom they stood. The old bot can
     * send them the link to the new one first.
     *
     * @param string $newbot The username of the new bot.
     * @param string|null $oldtoken The token of the old bot, to send them the link; null not to.
     * @return int How many chats wait for the new bot.
     */
    public static function switch_bot(string $newbot, ?string $oldtoken): int {
        global $DB;
        $ids = self::joined();
        if ($ids) {
            [$insql, $params] = $DB->get_in_or_equal($ids);
            $DB->execute(
                'UPDATE {' . consent::CONTACTS . "} SET status = 'pending', timemodified = ? WHERE id $insql",
                array_merge([time()], $params)
            );
        }
        set_config('telegramchange', json_encode([
            'from' => (string)get_config('local_oksigeniamarandu', 'telegrambot'),
            'to' => $newbot,
            'contacts' => $ids,
            'time' => time(),
            'told' => $oldtoken !== null && $ids,
        ]), 'local_oksigeniamarandu');
        if ($oldtoken !== null && $ids) {
            \local_oksigeniamarandu\task\telegram_farewell::queue($oldtoken, $newbot, $ids);
        }
        return count($ids);
    }

    /**
     * How many of the chats of the old bot have started the new one, while it is worth saying.
     *
     * @return \stdClass|null With back, of and told; null if there was no change, or all are back.
     */
    public static function returned(): ?\stdClass {
        global $DB;
        $change = json_decode((string)get_config('local_oksigeniamarandu', 'telegramchange'));
        if (!is_object($change) || empty($change->contacts)) {
            return null;
        }
        [$insql, $params] = $DB->get_in_or_equal($change->contacts);
        $back = $DB->count_records_select(consent::CONTACTS, "id $insql AND status = 'active'", $params);
        if ($back >= count($change->contacts)) {
            return null;
        }
        return (object)['back' => $back, 'of' => count($change->contacts), 'told' => !empty($change->told)];
    }

    /**
     * Whether a request to the webhook comes from Telegram: it sends back the secret the site gave it.
     *
     * @param string $header The value of X-Telegram-Bot-Api-Secret-Token.
     * @return bool
     */
    public static function verify_secret(string $header): bool {
        $secret = (string)get_config('local_oksigeniamarandu', 'telegramsecret');
        return $secret !== '' && hash_equals($secret, $header);
    }

    /**
     * Handles what reaches the bot: a person who starts it (with a code or without), writes the opt-in or opt-out word
     * or «/stop», blocks it, uses its menu, presses a button of it or writes something of their own, which goes to the
     * plugins of the codes the chat joined with. Only private chats count. The answer goes back in the reply to the
     * webhook, so it arrives at once.
     *
     * @param array $update The update of Telegram.
     * @param telegram_api|null $api The bot, for what cannot wait for the reply (the button that is waiting).
     * @return array|null The answer, as a method of the Bot API; null if there is nothing to answer.
     */
    public static function handle(array $update, ?telegram_api $api = null): ?array {
        if (isset($update['callback_query'])) {
            return answers::from_telegram($update['callback_query'], $api)
                ?? channel::from_telegram($update['callback_query'], $api)
                ?? telegram_menu::callback($update['callback_query'], $api);
        }
        if (isset($update['message_reaction_count'])) {
            // The reactions to a post of the channel changed: anonymous, only how many of each.
            channel::reactions($update['message_reaction_count']);
            return null;
        }
        if (isset($update['my_chat_member'])) {
            $member = $update['my_chat_member'];
            // The bot made an administrator of a channel, or no longer: the channel of the site.
            channel::member_changed($member);
            if (($member['chat']['type'] ?? '') === 'private' && ($member['new_chat_member']['status'] ?? '') === 'kicked') {
                // The person blocked the bot: the same as opting out; nothing can reach them any more.
                consent::optout(self::address((string)$member['chat']['id']), 'blocked', 'telegram');
            }
            return null;
        }
        $message = $update['message'] ?? null;
        if (!is_array($message) || ($message['chat']['type'] ?? '') !== 'private') {
            return null;
        }
        $address = self::address((string)$message['chat']['id']);
        $from = $message['from'] ?? [];
        if (isset($message['contact']) && is_array($message['contact'])) {
            // The button «Share my number»: to join the family that has it.
            return telegram_menu::shared($address, $message['contact'], (int)($from['id'] ?? 0));
        }
        $name = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
        $name = $name === '' ? null : $name;
        if (!isset($message['text'])) {
            // A photo, a voice note, a location…: to the plugins the chat joined with, if they want it.
            $other = conversation::telegram_kind($message);
            return $other === null ? null : self::own($address, $other[0], $other[1], $message, $name)[1];
        }
        $detail = 'telegram:' . ($message['message_id'] ?? '');
        $text = trim((string)$message['text']);
        if (preg_match('/^\/start(?:@\w+)?(?:\s+([A-Za-z0-9_-]{1,64}))?$/', $text, $parts)) {
            $code = isset($parts[1]) ? strtoupper(str_replace(['-', '_'], '', $parts[1])) : null;
            return self::reply($address, consent::optin_from_message($address, $code, $detail, $name));
        }
        if (preg_match('/^\/(\w{1,32})(?:@\w+)?(?:\s|$)/u', $text, $parts)) {
            // A command of the menu, in any language of the site; an unknown one gets the help.
            return telegram_menu::answer($address, telegram_menu::command($parts[1]) ?? 'help', $detail);
        }
        $match = keywords::match($text);
        if ($match === null) {
            // Something of their own: the plugins the chat joined with may take it; if none does, nobody reads it.
            [$handled, $answer] = self::own($address, 'text', $text, $message, $name);
            return $handled ? $answer : telegram_menu::nobody($address);
        }
        if ($match['action'] === 'optout') {
            consent::optout($address, 'keyword', $detail);
            return self::reply($address, 'optout');
        }
        return self::reply($address, consent::optin_from_message($address, $match['code'], $detail, $name));
    }

    /**
     * Something the person wrote of their own, for the plugins the chat joined with.
     *
     * @param string $address The chat.
     * @param string $kind What it is, as conversation::KINDS.
     * @param string $text The text or the caption.
     * @param array $message The message, as Telegram sends it.
     * @param string|null $name The name of the person on Telegram.
     * @return array Whether a plugin took it, and the answer as a method of the Bot API (null for none).
     */
    private static function own(string $address, string $kind, string $text, array $message, ?string $name): array {
        [$handled, $reply] = conversation::received(
            $address,
            $kind,
            $text,
            (int)($message['date'] ?? 0) ?: time(),
            'tg.' . self::chat($address) . '.' . ($message['message_id'] ?? ''),
            $name
        );
        $answer = $reply === null ? null : ['method' => 'sendMessage', 'chat_id' => self::chat($address), 'text' => $reply];
        return [$handled, $answer];
    }

    /**
     * A reply of consent (joined, left, unknown code), in the language of the chat.
     *
     * @param string $address The chat.
     * @param string $reply optin, optout or unknowncode.
     * @return array The answer, as a method of the Bot API.
     */
    private static function reply(string $address, string $reply): array {
        global $DB, $CFG;
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => $address]) ?: null;
        $lang = $contact && $contact->lang ? $contact->lang : null;
        $answer = ['method' => 'sendMessage', 'chat_id' => self::chat($address), 'text' => send_reply::text($reply, $lang)];
        // Joined without a code: if the site has the family records, the number can find the family.
        return $reply === 'optin' ? telegram_menu::offer($answer, $contact, $lang ?? $CFG->lang) : $answer;
    }
}
