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
 * The menu of the bot of Telegram, the same for every site: what can be done there, whom the notices of the chat are
 * about, their language, notices without sound, and leaving. Telegram shows it on the button «/» of the chat, in the
 * language of each phone; the words of every language of the site are understood.
 *
 * The replies go in the answer to the webhook itself, as Telegram allows, so they arrive at once.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class telegram_menu {
    /** The commands, by their English word; each language of the site has its own word for each. */
    public const COMMANDS = ['help', 'who', 'language', 'mute', 'stop'];

    /** What a button of the language list sends back to the bot. */
    private const LANG = 'lang:';

    /**
     * The commands that make sense on this site: the language, only with more than one.
     *
     * @return string[]
     */
    public static function commands(): array {
        return array_values(array_filter(self::COMMANDS, function ($command) {
            return $command !== 'language' || count(self::languages()) > 1;
        }));
    }

    /**
     * The languages of the site, by code, with their names as they are written in them.
     *
     * @return string[]
     */
    public static function languages(): array {
        $list = [];
        foreach (get_string_manager()->get_list_of_translations() as $code => $name) {
            // Moodle writes «Español - Internacional ‎(es)‎»: the code between brackets is not for families.
            $list[$code] = trim(preg_replace('/\s*\x{200E}?\([^)]*\)\x{200E}?\s*$/u', '', $name));
        }
        return $list;
    }

    /**
     * The command a word stands for, in any language of the site or in English; null if it is none.
     *
     * @param string $word The word after the slash, without the name of the bot.
     * @return string|null
     */
    public static function command(string $word): ?string {
        $word = \core_text::strtolower($word);
        foreach (self::COMMANDS as $command) {
            if ($word === $command) {
                return $command;
            }
            foreach (array_keys(self::languages()) as $lang) {
                if ($word === self::word($command, $lang)) {
                    return $command;
                }
            }
        }
        return null;
    }

    /**
     * Tells Telegram the menu of the bot: for each language of the site, its words and what they do; for the rest of
     * the phones, those of the language of the site.
     *
     * @param telegram_api $api The bot.
     */
    public static function register(telegram_api $api): void {
        global $CFG;
        $api->set_commands(self::list($CFG->lang));
        $done = [];
        // The plain packs first (es before es_mx), as Telegram knows languages by two letters.
        $codes = array_keys(self::languages());
        usort($codes, function ($a, $b) {
            return [strlen($a), $a] <=> [strlen($b), $b];
        });
        foreach ($codes as $code) {
            $two = substr($code, 0, 2);
            if (isset($done[$two]) || !preg_match('/^[a-z]{2}$/', $two)) {
                continue;
            }
            $done[$two] = true;
            $api->set_commands(self::list($code), $two);
        }
    }

    /**
     * The menu in a language, as Telegram takes it.
     *
     * @param string $lang The language.
     * @return array Each with command and description.
     */
    public static function list(string $lang): array {
        $list = [];
        foreach (self::commands() as $command) {
            $list[] = ['command' => self::word($command, $lang), 'description' => self::text('cmd:' . $command . '_desc', $lang)];
        }
        return $list;
    }

    /**
     * What the bot answers to a command.
     *
     * @param string $address The chat, as Marandu keeps it (tg: and the chat).
     * @param string $command One of COMMANDS.
     * @param string $detail Where it came from, for the record of consent.
     * @return array The answer, as a method of the Bot API.
     */
    public static function answer(string $address, string $command, string $detail): array {
        global $DB;
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => $address]) ?: null;
        $active = $contact && $contact->status === 'active';
        $lang = self::lang($contact);
        $reply = ['method' => 'sendMessage', 'chat_id' => telegram::chat($address)];
        if ($command === 'stop') {
            consent::optout($address, 'keyword', $detail);
            return $reply + ['text' => \local_oksigeniamarandu\task\send_reply::text('optout', $lang)];
        }
        if (!$active && $command !== 'help') {
            return $reply + ['text' => self::text('menu:out', $lang)];
        }
        if ($command === 'who') {
            return $reply + ['text' => self::who($contact, $lang)];
        }
        if ($command === 'mute') {
            $contact->silent = empty($contact->silent) ? 1 : 0;
            $contact->timemodified = time();
            $DB->update_record(consent::CONTACTS, $contact);
            return $reply + ['text' => self::text(
                $contact->silent ? 'menu:mute:on' : 'menu:mute:off',
                $lang,
                '/' . self::word('mute', $lang)
            )];
        }
        if ($command === 'language') {
            $buttons = [];
            foreach (self::languages() as $code => $name) {
                $buttons[] = [['text' => ($code === $lang ? '✓ ' : '') . $name, 'callback_data' => self::LANG . $code]];
            }
            return $reply + ['text' => self::text('menu:language', $lang), 'reply_markup' => ['inline_keyboard' => $buttons]];
        }
        return $reply + ['text' => self::help($lang, $active)];
    }

    /**
     * What a person who writes to the bot is told: nobody reads it.
     *
     * @param string $address The chat.
     * @return array The answer, as a method of the Bot API.
     */
    public static function nobody(string $address): array {
        global $DB;
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => $address]);
        $site = format_string(get_site()->fullname, true, ['context' => \context_system::instance(), 'escape' => false]);
        return ['method' => 'sendMessage', 'chat_id' => telegram::chat($address),
            'text' => self::text('menu:nobody', self::lang($contact ?: null), $site)];
    }

    /**
     * A button of the language list: the notices of the chat change language, and the list becomes what was chosen.
     *
     * @param array $query The callback query of Telegram.
     * @param telegram_api|null $api The bot, to stop the wait on the button; null not to.
     * @return array|null The answer, as a method of the Bot API; null if it was not a button of the menu.
     */
    public static function callback(array $query, ?telegram_api $api = null): ?array {
        global $DB;
        $data = (string)($query['data'] ?? '');
        $chat = $query['message']['chat'] ?? [];
        if (!str_starts_with($data, self::LANG) || ($chat['type'] ?? '') !== 'private') {
            return null;
        }
        $address = telegram::address((string)$chat['id']);
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => $address]);
        $code = substr($data, strlen(self::LANG));
        $languages = self::languages();
        $ok = $contact && $contact->status === 'active' && isset($languages[$code]);
        if ($ok) {
            $contact->lang = $code;
            $contact->timemodified = time();
            $DB->update_record(consent::CONTACTS, $contact);
        }
        $text = $ok ? self::text('menu:language:done', $code, $languages[$code])
            : self::text('menu:out', self::lang($contact ?: null));
        if ($api && isset($query['id'])) {
            try {
                $api->answer_callback((string)$query['id']);
            } catch (api_exception $e) {
                // The button stops waiting by itself after a while.
                debugging('Marandu: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        return ['method' => 'editMessageText', 'chat_id' => (string)$chat['id'],
            'message_id' => (int)($query['message']['message_id'] ?? 0), 'text' => $text];
    }

    /**
     * Whom the notices of a chat are about.
     *
     * @param \stdClass $contact The contact.
     * @param string $lang The language.
     * @return string
     */
    private static function who(\stdClass $contact, string $lang): string {
        $whom = panel::standsfor([(int)$contact->id])[$contact->id] ?? [];
        $site = format_string(get_site()->fullname, true, ['context' => \context_system::instance(), 'escape' => false]);
        if (!$whom) {
            return self::text('menu:who:none', $lang, $site);
        }
        $lines = [];
        foreach ($whom as $item) {
            $lines[] = '• ' . ($item->name !== '' ? $item->name . ' (' . $item->kind . ')' : $item->kind);
        }
        return self::text('menu:who', $lang, $site) . "\n" . implode("\n", $lines);
    }

    /**
     * What can be done with the bot.
     *
     * @param string $lang The language.
     * @param bool $active Whether the chat receives notices.
     * @return string
     */
    private static function help(string $lang, bool $active): string {
        $site = format_string(get_site()->fullname, true, ['context' => \context_system::instance(), 'escape' => false]);
        $lines = [self::text($active ? 'menu:help' : 'menu:help:out', $lang, $site)];
        if ($active) {
            foreach (self::commands() as $command) {
                $lines[] = '/' . self::word($command, $lang) . ' · ' . self::text('cmd:' . $command . '_desc', $lang);
            }
        }
        return implode("\n", $lines);
    }

    /**
     * The language to answer a chat in: its own, or that of the site.
     *
     * @param \stdClass|null $contact The contact, if there is one.
     * @return string
     */
    private static function lang(?\stdClass $contact): string {
        global $CFG;
        $lang = $contact->lang ?? '';
        return $lang !== '' && isset(self::languages()[$lang]) ? $lang : $CFG->lang;
    }

    /**
     * The word of a command in a language.
     *
     * @param string $command One of COMMANDS.
     * @param string $lang The language.
     * @return string
     */
    private static function word(string $command, string $lang): string {
        return \core_text::strtolower(self::text('cmd:' . $command, $lang));
    }

    /**
     * A text of the menu in a language.
     *
     * @param string $key The string, after «telegram:».
     * @param string $lang The language.
     * @param mixed $a What goes in it.
     * @return string
     */
    private static function text(string $key, string $lang, $a = null): string {
        return get_string_manager()->get_string('telegram:' . $key, 'local_oksigeniamarandu', $a, $lang);
    }
}
