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
 * The confirmations of a person's channels: someone joins, changes channel or leaves, and the chat answers with a card
 * (the cards of Jujol, with the colour, the name and the logo of the organization) and a warm text below it. Without
 * the cards (the site turned them off, or the server cannot draw them, or the language has letters the font has not),
 * the text goes alone.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class confirm {
    /**
     * The confirmations: joined; here (this channel instead of others); also (this channel besides others); away (others
     * instead of this channel); out (no more by this channel).
     */
    public const KINDS = ['joined', 'here', 'also', 'away', 'out'];

    /** The channels a person can be written by, in the order they are named. */
    public const CHANNELS = ['whatsapp', 'telegram', 'email'];

    /** The channels a confirmation can go by. */
    public const CHATS = ['whatsapp', 'telegram'];

    /** The way of the cards, for the cache of the cards drawn. */
    private const VERSION = 1;

    /** How long an image stays with Meta: 30 days; it is used again for 25. */
    private const MEDIA_DAYS = 25;

    /** @var string The component. */
    private const COMPONENT = 'local_oksigeniamarandu';

    /**
     * Whether the site wants the warm confirmations, with their card if it can (on by default). Without them, the short
     * replies of before.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return get_config(self::COMPONENT, 'confirmcards') !== '0';
    }

    /**
     * A confirmation: its text and, if it can go with one, its card.
     *
     * @param string $kind One of KINDS.
     * @param string $channel The channel it goes by: whatsapp or telegram.
     * @param string|null $lang The language; the site's if empty.
     * @param array $others The other channels: for here, those left; for also, those kept; for away, those now.
     * @return array With text and card (as cards::clean() gives it, or null).
     */
    public static function build(string $kind, string $channel, ?string $lang = null, array $others = []): array {
        global $CFG;
        if (!in_array($kind, self::KINDS, true) || !in_array($channel, self::CHATS, true)) {
            throw new \coding_exception('There is no confirmation ' . $kind . ' by ' . $channel);
        }
        $lang = $lang ?: $CFG->lang;
        $others = array_values(array_intersect(self::CHANNELS, $others));
        $str = function (string $id, $a = null) use ($lang): string {
            return get_string_manager()->get_string('confirm:' . $id, self::COMPONENT, $a, $lang);
        };
        $name = function (string $one) use ($str): string {
            return $str('channel:' . $one);
        };
        $list = self::names(array_map($name, $others), $lang);
        $a = (object)[
            'of' => self::of($lang),
            'channel' => $name($channel),
            'others' => $list,
            'optout' => keywords::first('optoutkeywords'),
        ];
        if ($kind === 'here' && !$others) {
            // Back to this channel, with nothing left behind.
            $text = $str('here:caption:none', $a);
        } else if ($kind === 'also' && !$others) {
            $kind = 'here';
            $text = $str('here:caption:none', $a);
        } else {
            $text = $str($kind . ':caption', $a);
        }
        $template = ['joined' => 'joined', 'here' => 'channel_changed', 'also' => 'channel_changed',
            'away' => $others === ['email'] ? 'channel_changed_mail' : 'channel_changed', 'out' => 'opted_out'][$kind];
        $done = $str('done');
        $zones = [
            'joined' => ['kicker' => $str('joined:kicker'), 'title' => $str('joined:title'),
                'channel' => $str('by', $name($channel)), 'detail' => $str('joined:detail')],
            'here' => ['kicker' => $done, 'title' => $str('here:title'), 'channel' => $str('by', $name($channel)),
                'detail' => $others ? $str('here:detail', $list) : $str('joined:detail')],
            'also' => ['kicker' => $done, 'title' => $str('also:title'), 'channel' => $str('by', $name($channel)),
                'detail' => $str('also:detail', $list)],
            'away' => ['kicker' => $done, 'title' => $str('away:title', $list), 'channel' => $str('by', $list),
                'detail' => $str('away:detail', $list)],
            'out' => ['kicker' => $done, 'title' => $str('out:title'), 'back' => $str('out:back'),
                'detail' => $str('out:detail')],
        ][$kind];
        $card = null;
        if (cards::can_draw() && self::latin(implode(' ', $zones) . ' ' . cards::org())) {
            $card = cards::clean(['type' => $template, 'zones' => $zones]);
        }
        return ['text' => $text, 'card' => $card];
    }

    /**
     * The name of the organization as it goes inside a sentence, after «the notices…»: what the site wrote for its own
     * language («del Colegio Monte Lirio»), or else the plain way of the language with the name of the cards.
     *
     * @param string $lang The language.
     * @return string
     */
    public static function of(string $lang): string {
        global $CFG;
        $own = trim((string)get_config(self::COMPONENT, 'cardorgof'));
        if ($own !== '' && $lang === $CFG->lang) {
            return $own;
        }
        return get_string_manager()->get_string('confirm:of', self::COMPONENT, cards::org(), $lang);
    }

    /**
     * A list of names as the language says it: «WhatsApp y Telegram».
     *
     * @param string[] $names The names.
     * @param string $lang The language.
     * @return string
     */
    private static function names(array $names, string $lang): string {
        if (count($names) < 2) {
            return (string)reset($names);
        }
        $last = array_pop($names);
        return get_string_manager()->get_string(
            'confirm:and',
            self::COMPONENT,
            (object)['list' => implode(', ', $names), 'last' => $last],
            $lang
        );
    }

    /**
     * Whether the font of the cards has every letter of a text: the Latin alphabets, their marks and the usual signs.
     *
     * @param string $text The text.
     * @return bool
     */
    private static function latin(string $text): bool {
        return !preg_match('/[^\x{0000}-\x{024F}\x{1E00}-\x{1EFF}\x{2000}-\x{206F}\x{20AC}]/u', $text);
    }

    /**
     * The card as a JPEG, drawn once for its texts, the colour, the name and the logo of the organization.
     *
     * @param array $card The card.
     * @return string|null Null if the server could not draw it.
     */
    public static function image(array $card): ?string {
        $cache = \cache::make(self::COMPONENT, 'confirmcards');
        $key = sha1(json_encode([self::VERSION, $card, cards::brand(), cards::org(), cards::logo_key()]));
        $bytes = $cache->get($key);
        if ($bytes) {
            return $bytes;
        }
        try {
            $bytes = cards::still($card);
        } catch (\Throwable $e) {
            debugging('Marandu: the card of a confirmation was not drawn: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
        $cache->set($key, $bytes);
        return $bytes;
    }

    /**
     * Sends a confirmation by WhatsApp, inside the 24 hours: the card with the text below, or the text alone if the
     * card cannot go. The card goes up to Meta once and is used again for 25 days.
     *
     * @param cloud_api $api The number of the site.
     * @param string $phone Digits with the country code.
     * @param array $message As build() gives it.
     * @return string The id Meta gives to the message.
     * @throws api_exception If Meta does not take the text either.
     */
    public static function whatsapp(cloud_api $api, string $phone, array $message): string {
        $bytes = $message['card'] ? self::image($message['card']) : null;
        if ($bytes !== null) {
            $cache = \cache::make(self::COMPONENT, 'confirmcards');
            $key = 'wa_' . sha1((string)get_config(self::COMPONENT, 'phonenumberid') . ':' . sha1($bytes));
            $media = $cache->get($key);
            try {
                if (!$media || $media['time'] < time() - self::MEDIA_DAYS * DAYSECS) {
                    $media = ['id' => $api->upload_media($bytes, 'card.jpg'), 'time' => time()];
                    $cache->set($key, $media);
                }
                return $api->send_image($phone, $media['id'], $message['text']);
            } catch (api_exception $e) {
                // Perhaps Meta no longer has it: the next one uploads it again. This one goes as text.
                $cache->delete($key);
                if ($e->metacode === 131047 || $e->metacode === 190) {
                    // Outside the 24 hours, or a token that no longer works: the text would not go either.
                    throw $e;
                }
            }
        }
        return $api->send_text($phone, $message['text']);
    }

    /**
     * Sends a confirmation by Telegram: the card with the text below, or the text alone if the card cannot go.
     *
     * @param telegram_api $bot The bot of the site.
     * @param string $chatid The chat.
     * @param array $message As build() gives it, with markup if it carries a keyboard.
     * @return string The id of the message.
     * @throws api_exception If Telegram does not take the text either.
     */
    public static function telegram(telegram_api $bot, string $chatid, array $message): string {
        $bytes = $message['card'] ? self::image($message['card']) : null;
        $markup = $message['markup'] ?? null;
        if ($bytes !== null) {
            try {
                return $bot->send_photo($chatid, $bytes, $message['text'], $markup);
            } catch (api_exception $e) {
                if ($e->metacode === -403 || $e->metacode === -401) {
                    // The person blocked the bot, or the token no longer works: the text would not go either.
                    throw $e;
                }
            }
        }
        return $bot->send_text($chatid, $message['text']);
    }

    /**
     * The answer to the webhook of Telegram with a confirmation: the card at once if the bot already has it, by its id;
     * if not, it goes up now with the bot and the webhook answers nothing more. Without a card, the text.
     *
     * @param string $chatid The chat.
     * @param array $message As build() gives it, with markup if it carries a keyboard.
     * @param telegram_api|null $bot The bot, to upload the card the first time.
     * @return array|null The answer, as a method of the Bot API; null if it was already sent.
     */
    public static function telegram_answer(string $chatid, array $message, ?telegram_api $bot): ?array {
        $answer = ['chat_id' => $chatid];
        if (!empty($message['markup'])) {
            $answer['reply_markup'] = $message['markup'];
        }
        $bytes = $message['card'] && $bot ? self::image($message['card']) : null;
        if ($bytes !== null) {
            $fileid = $bot->photo_id_of($bytes);
            if ($fileid !== null) {
                return ['method' => 'sendPhoto', 'photo' => $fileid,
                    'caption' => \core_text::substr($message['text'], 0, 1024)] + $answer;
            }
            try {
                $bot->send_photo($chatid, $bytes, $message['text'], $message['markup'] ?? null);
                return null;
            } catch (api_exception $e) {
                debugging('Marandu: the card of a confirmation did not go: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        return ['method' => 'sendMessage', 'text' => $message['text']] + $answer;
    }
}
