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

namespace local_oksigeniamarandu\local\chat;

/**
 * What a contact writes in a conversation of the team, told to the plugins that listen: each one with the callback
 * <plugin>_marandu_chat_in(string $phone, string $text, int $time, string $url, int $messageid): void in its lib.php,
 * whether the number joined anything or not. Once for each new text the index sees, by the webhook of Chatwoot or by the
 * task of each minute; not what was brought in from a phone, nor what is older than a day, nor a contact without a phone.
 * The same message can come again (by tell_since(), or when two passes cross): the plugin knows it by its messageid.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class incoming {
    /** The callback. */
    public const CALLBACK = 'marandu_chat_in';

    /** How old a message can be and still be told when the index sees it for the first time. */
    public const FRESH = DAYSECS;

    /** The most pages of a conversation tell_since() reads back. */
    private const PAGES = 10;

    /**
     * Tells the plugins the new texts of a contact among the messages of a conversation.
     *
     * @param int $id The conversation in Chatwoot.
     * @param string $phone The number of its contact: digits with the country code.
     * @param array $messages Its messages, as Chatwoot gives them.
     * @param int $after Only those after this message (the last one the index had seen).
     * @param int $since Only those written since this time.
     * @param string|null $only Only this plugin.
     * @return int How many messages it told.
     */
    public static function tell(int $id, string $phone, array $messages, int $after, int $since, ?string $only = null): int {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if ($phone === '' || !$messages) {
            return 0;
        }
        $listeners = self::listeners($only);
        if (!$listeners) {
            return 0;
        }
        $url = (new \moodle_url('/local/oksigeniamarandu/chat.php', ['c' => $id]))->out(false);
        $told = 0;
        foreach ($messages as $message) {
            $messageid = (int)($message['id'] ?? 0);
            $time = (int)($message['created_at'] ?? 0);
            if (
                $messageid <= $after || $time < $since || message::kind($message) !== 'incoming'
                    || !empty($message['content_attributes']['imported'])
            ) {
                continue;
            }
            $text = trim(message::plain_text((string)($message['content'] ?? '')));
            if ($text === '') {
                continue;
            }
            foreach ($listeners as $component => $function) {
                try {
                    $function($phone, $text, $time, $url, $messageid);
                } catch (\Throwable $e) {
                    debugging('Marandu: ' . $component . ' did not take a message of the chat: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
            $told++;
        }
        return $told;
    }

    /**
     * Tells again what the contacts wrote since a time, in every conversation of the index that had news since then: to
     * bring a plugin what came before it listened.
     *
     * @param int $since The time.
     * @param string|null $only Only this plugin.
     * @return array conversations read and messages told.
     */
    public static function tell_since(int $since, ?string $only = null): array {
        global $DB;
        if (!self::listeners($only)) {
            return [0, 0];
        }
        $rows = $DB->get_records_select(
            mirror::TABLE,
            "phone <> '' AND (lastincoming >= :a OR lastactivity >= :b)",
            ['a' => $since, 'b' => $since],
            'lastactivity ASC',
            'id, cwid, phone'
        );
        $read = 0;
        $told = 0;
        foreach ($rows as $row) {
            $messages = self::messages_since((int)$row->cwid, $since);
            if ($messages === null) {
                continue;
            }
            $read++;
            $told += self::tell((int)$row->cwid, (string)$row->phone, $messages, 0, $since, $only);
        }
        return [$read, $told];
    }

    /**
     * The plugins that listen, with the function each one has.
     *
     * @param string|null $only Only this plugin.
     * @return array The functions, by plugin.
     */
    public static function listeners(?string $only = null): array {
        $out = [];
        foreach (get_plugins_with_function(self::CALLBACK) as $type => $plugins) {
            foreach ($plugins as $name => $function) {
                $component = $type . '_' . $name;
                if ($only === null || $only === '' || $only === $component) {
                    $out[$component] = $function;
                }
            }
        }
        return $out;
    }

    /**
     * The messages of a conversation since a time, page after page back.
     *
     * @param int $id The conversation.
     * @param int $since The time.
     * @return array|null In order; null if Chatwoot did not give them.
     */
    protected static function messages_since(int $id, int $since): ?array {
        $all = [];
        $before = 0;
        for ($page = 0; $page < self::PAGES; $page++) {
            try {
                $answer = directory::client()->call(
                    'GET',
                    '/conversations/' . $id . '/messages',
                    $before ? ['before' => $before] : []
                );
            } catch (\moodle_exception $e) {
                return $page ? $all : null;
            }
            $list = array_values($answer['payload'] ?? []);
            $first = $list[0] ?? null;
            if (!$first || ($before && (int)($first['id'] ?? 0) >= $before)) {
                // Nothing older, or the same page again.
                break;
            }
            $all = array_merge($list, $all);
            if ((int)($first['created_at'] ?? 0) < $since || !(int)($first['id'] ?? 0)) {
                break;
            }
            $before = (int)$first['id'];
        }
        return $all;
    }
}
