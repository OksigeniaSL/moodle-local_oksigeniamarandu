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
        return $bot;
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
     * or «/stop», or blocks it. Only private chats count.
     *
     * @param array $update The update of Telegram.
     * @return bool Whether it changed the consent of a chat.
     */
    public static function handle(array $update): bool {
        if (isset($update['my_chat_member'])) {
            $member = $update['my_chat_member'];
            if (($member['chat']['type'] ?? '') === 'private' && ($member['new_chat_member']['status'] ?? '') === 'kicked') {
                // The person blocked the bot: the same as opting out; nothing can reach them any more.
                consent::optout(self::address((string)$member['chat']['id']), 'blocked', 'telegram');
                return true;
            }
            return false;
        }
        $message = $update['message'] ?? null;
        if (!is_array($message) || ($message['chat']['type'] ?? '') !== 'private' || !isset($message['text'])) {
            return false;
        }
        $address = self::address((string)$message['chat']['id']);
        $from = $message['from'] ?? [];
        $name = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
        $detail = 'telegram:' . ($message['message_id'] ?? '');
        $text = trim((string)$message['text']);
        if (preg_match('/^\/start(?:@\w+)?(?:\s+([A-Za-z0-9_-]{1,64}))?$/', $text, $parts)) {
            $code = isset($parts[1]) ? strtoupper(str_replace(['-', '_'], '', $parts[1])) : null;
            $reply = consent::optin_from_message($address, $code, $detail, $name === '' ? null : $name);
        } else if (preg_match('/^\/stop(?:@\w+)?$/', $text)) {
            consent::optout($address, 'keyword', $detail);
            $reply = 'optout';
        } else {
            $match = keywords::match($text);
            if ($match === null) {
                return false;
            }
            if ($match['action'] === 'optout') {
                consent::optout($address, 'keyword', $detail);
                $reply = 'optout';
            } else {
                $reply = consent::optin_from_message($address, $match['code'], $detail, $name === '' ? null : $name);
            }
        }
        send_reply::queue($address, $reply);
        return true;
    }
}
