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

use local_oksigeniamarandu\hook\message_received;

/**
 * What people write of their own to the number or the bot of the site, and the texts a plugin sends back.
 *
 * What is written goes to the plugins of the codes the person joined with, by the hook message_received; Marandu does
 * not keep it. A plugin answers at once with the hook, or later with api::send_text(): on Telegram, always; on WhatsApp,
 * only inside the 24 hours after the person last wrote, when a text is free. Outside them, only a notice with its
 * template can reach them, with api::notify().
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class conversation {
    /** What a message can be, as the hook gives it. */
    public const KINDS = ['text', 'image', 'audio', 'video', 'document', 'sticker', 'location', 'contact'];

    /** The longest text WhatsApp and Telegram take in one message. */
    public const MAXTEXT = 4096;

    /** The kinds of a message of WhatsApp, by its type; the rest (reactions, orders, system messages) do not count. */
    private const WHATSAPP = ['image' => 'image', 'audio' => 'audio', 'video' => 'video', 'document' => 'document',
        'sticker' => 'sticker', 'location' => 'location', 'contacts' => 'contact'];

    /** The kinds of a message of Telegram, by its field, in this order (an animation also has a document). */
    private const TELEGRAM = ['photo' => 'image', 'voice' => 'audio', 'audio' => 'audio', 'animation' => 'video',
        'video' => 'video', 'video_note' => 'video', 'document' => 'document', 'sticker' => 'sticker', 'venue' => 'location',
        'location' => 'location'];

    /**
     * What a message of WhatsApp that is not text is, and its caption.
     *
     * @param array $message The message, as Meta sends it.
     * @return array|null The kind and the text; null if it does not count.
     */
    public static function whatsapp_kind(array $message): ?array {
        $type = (string)($message['type'] ?? '');
        if (!isset(self::WHATSAPP[$type])) {
            return null;
        }
        return [self::WHATSAPP[$type], trim((string)($message[$type]['caption'] ?? ''))];
    }

    /**
     * What a message of Telegram without text is, and its caption.
     *
     * @param array $message The message, as Telegram sends it.
     * @return array|null The kind and the text; null if it does not count.
     */
    public static function telegram_kind(array $message): ?array {
        foreach (self::TELEGRAM as $field => $kind) {
            if (isset($message[$field])) {
                return [$kind, trim((string)($message['caption'] ?? ''))];
            }
        }
        return null;
    }

    /**
     * Something a person wrote of their own: each plugin of a code they joined with hears of it, once.
     *
     * @param string $address The contact: digits with the country code, or tg:<chat>.
     * @param string $kind One of KINDS.
     * @param string $text The text or the caption.
     * @param int $time When it was written.
     * @param string $messageid The id of the message on its channel.
     * @param string|null $name The name the person has on the channel.
     * @return array Whether a plugin took it, and what to answer to the person (null for nothing).
     */
    public static function received(
        string $address,
        string $kind,
        string $text,
        int $time,
        string $messageid,
        ?string $name
    ): array {
        global $DB;
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => $address]);
        if (!$contact || $contact->status !== 'active') {
            return [false, null];
        }
        $links = $DB->get_records_select(
            consent::LINKS,
            "contactid = :contactid AND component <> 'core'",
            ['contactid' => $contact->id],
            'timeclaimed DESC, id DESC',
            'id, component, itemid, userid'
        );
        if (!$links) {
            return [false, null];
        }
        if ($messageid !== '') {
            // A webhook that comes twice (the channel did not hear the first answer) brings the same message again.
            $cache = \cache::make('local_oksigeniamarandu', 'inbound');
            $key = sha1($messageid);
            if ($cache->get($key)) {
                return [true, null];
            }
            $cache->set($key, time());
        }
        $channel = telegram::is_chat($address) ? 'telegram' : 'whatsapp';
        $handled = false;
        $reply = null;
        $heard = [];
        foreach ($links as $link) {
            $userid = $link->userid === null ? null : (int)$link->userid;
            $key = $link->component . '/' . $link->itemid . '/' . $userid;
            if (isset($heard[$key])) {
                continue;
            }
            $heard[$key] = true;
            $hook = new message_received(
                $link->component,
                (int)$link->itemid,
                $userid,
                $channel,
                $channel === 'telegram' ? $address : '+' . $address,
                $kind,
                \core_text::substr($text, 0, self::MAXTEXT),
                $time,
                $messageid,
                $name
            );
            try {
                \core\di::get(\core\hook\manager::class)->dispatch($hook);
            } catch (\Throwable $e) {
                // A plugin that fails must not stop the others, nor make the channel send the webhook again.
                debugging('Marandu: a plugin failed with a message: ' . $e->getMessage(), DEBUG_DEVELOPER);
                continue;
            }
            $handled = $handled || $hook->is_handled();
            $said = $hook->get_reply();
            if ($reply === null && $said !== null && trim($said) !== '') {
                $reply = \core_text::substr(trim($said), 0, self::MAXTEXT);
            }
        }
        return [$handled, $reply];
    }

    /**
     * Sends a text of a plugin, such as what its team answers, to the numbers and chats that joined for one of its items.
     *
     * @param string $component The plugin.
     * @param int $itemid Its item.
     * @param string $text Plain text.
     * @param string|null $url A link on this site: a button on Telegram, a line below the text on WhatsApp.
     * @param string $label The label of the button on Telegram; Open if empty.
     * @param string|null $address Only to this number (E.164) or chat (tg:<chat>), if it joined for the item.
     * @param cloud_api|null $api The client of WhatsApp; null if the site has none.
     * @param telegram_api|null $telegram The client of Telegram; null if the site has no bot.
     * @param int|null $now The time, for the tests.
     * @return array One for each number or chat, with channel, address, status (sent; closed, when the 24 hours of
     *               WhatsApp have passed and only a notice with its template can reach them; or failed), messageid and
     *               error.
     */
    public static function send(
        string $component,
        int $itemid,
        string $text,
        ?string $url,
        string $label,
        ?string $address,
        ?cloud_api $api,
        ?telegram_api $telegram,
        ?int $now = null
    ): array {
        global $DB, $CFG;
        $text = trim($text);
        if ($text === '') {
            throw new \coding_exception('send_text() needs a text');
        }
        $now = $now ?? time();
        $params = ['component' => $component, 'itemid' => $itemid, 'active' => 'active'];
        $only = '';
        if ($address !== null) {
            $only = ' AND c.phone = :phone';
            $params['phone'] = ltrim(trim($address), '+');
        }
        $contacts = $DB->get_records_sql("SELECT c.*
                                            FROM {" . consent::CONTACTS . "} c
                                           WHERE c.status = :active $only
                                             AND c.id IN (SELECT l.contactid
                                                            FROM {" . consent::LINKS . "} l
                                                           WHERE l.component = :component AND l.itemid = :itemid
                                                                 AND l.userid IS NULL)
                                        ORDER BY c.id", $params);
        $results = [];
        foreach ($contacts as $contact) {
            $telegramchat = telegram::is_chat($contact->phone);
            $result = [
                'channel' => $telegramchat ? 'telegram' : 'whatsapp',
                'address' => $telegramchat ? $contact->phone : '+' . $contact->phone,
                'status' => 'sent',
                'messageid' => null,
                'error' => null,
            ];
            try {
                if ($telegramchat) {
                    if (!$telegram) {
                        throw new api_exception('error:notconfigured');
                    }
                    $chat = telegram::chat($contact->phone);
                    $button = $url === null ? '' : ($label !== '' ? $label : get_string_manager()->get_string(
                        'conversation:open',
                        'local_oksigeniamarandu',
                        null,
                        $contact->lang ?: $CFG->lang
                    ));
                    $id = $telegram->send_text(
                        $chat,
                        \core_text::substr($text, 0, self::MAXTEXT),
                        $url,
                        $button,
                        !empty($contact->silent)
                    );
                    $result['messageid'] = 'tg.' . $chat . '.' . $id;
                } else if (empty($contact->lastinbound) || $contact->lastinbound <= $now - queue::WINDOW) {
                    $result['status'] = 'closed';
                } else {
                    if (!$api) {
                        throw new api_exception('error:notconfigured');
                    }
                    $tail = $url === null ? '' : "\n" . $url;
                    $body = \core_text::substr($text, 0, self::MAXTEXT - \core_text::strlen($tail)) . $tail;
                    $result['messageid'] = $api->send_text($contact->phone, $body);
                }
            } catch (api_exception $e) {
                $what = queue::failure($e);
                if ($what === 'window') {
                    $result['status'] = 'closed';
                } else {
                    if ($what === 'blocked') {
                        // The person blocked the bot of Telegram: the same as opting out.
                        consent::optout($contact->phone, 'blocked', 'telegram');
                    }
                    $result['status'] = 'failed';
                    $result['error'] = $e->getMessage();
                }
            }
            $results[] = $result;
        }
        return $results;
    }
}
