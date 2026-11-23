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
 * «Propose an answer»: a draft of the answer to the last messages of a conversation, written by the text generation of
 * the site (the AI subsystem of Moodle) from what was said, what the centre knows of whoever writes and what the centre
 * wrote down for its departments. The person reads it, changes it and sends it: it never goes by itself.
 *
 * Other plugins add what they know of a number with the callback <component>_oksigeniamarandu_chat_context(string
 * $phone, int $userid): array, plain lines of text, only what that user may see.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class draft {
    /** @var string The plugin. */
    private const COMPONENT = 'local_oksigeniamarandu';

    /** @var int The most characters of each piece of knowledge. */
    public const MAX_KNOWLEDGE = 20000;

    /** @var int How many of the last messages go. */
    private const MESSAGES = 12;

    /** @var int The most characters of each message. */
    private const MESSAGE_LENGTH = 700;

    /** @var int The most lines of what is known of whoever writes, and the most characters of each. */
    private const CONTEXT_LINES = 20;

    /** @var int The most characters of a line of context. */
    private const LINE_LENGTH = 300;

    /**
     * Whether the site can write a draft: Moodle has the AI subsystem and a provider with text generation switched on.
     *
     * @return bool
     */
    public static function available(): bool {
        if (!class_exists('\core_ai\manager') || !class_exists('\core_ai\aiactions\generate_text')) {
            return false;
        }
        try {
            return (bool)\core\di::get(\core_ai\manager::class)->is_action_available(\core_ai\aiactions\generate_text::class);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether the person accepted the policy of the site on AI, which Moodle asks of each one before the first use.
     *
     * @param int $userid The person.
     * @return bool
     */
    public static function policy_accepted(int $userid): bool {
        return class_exists('\core_ai\manager') && (bool)\core_ai\manager::get_user_policy_status($userid);
    }

    /**
     * The person accepts the policy of the site on AI.
     *
     * @param int $userid The person.
     */
    public static function accept_policy(int $userid): void {
        if (class_exists('\core_ai\manager') && !self::policy_accepted($userid)) {
            \core_ai\manager::user_policy_accepted($userid, \context_system::instance()->id);
        }
    }

    /**
     * The policy of the site on AI, as Moodle writes it.
     *
     * @return string HTML.
     */
    public static function policy_text(): string {
        return get_string_manager()->string_exists('userpolicy', 'core_ai') ? get_string('userpolicy', 'core_ai') : '';
    }

    /**
     * What the centre wrote down for every answer: its tone, its links, what it says after a visit.
     *
     * @return string
     */
    public static function knowledge(): string {
        return (string)get_config(self::COMPONENT, 'chatknowledge');
    }

    /**
     * What the centre wrote down for each department.
     *
     * @return string[] By department.
     */
    public static function team_knowledge(): array {
        $list = json_decode((string)get_config(self::COMPONENT, 'chatteamknowledge'), true);
        $clean = [];
        foreach (is_array($list) ? $list : [] as $teamid => $text) {
            if ((int)$teamid > 0 && trim((string)$text) !== '') {
                $clean[(int)$teamid] = (string)$text;
            }
        }
        return $clean;
    }

    /**
     * Writes down the knowledge of the whole centre; empty clears it.
     *
     * @param string $text The text.
     * @return int How many characters it kept.
     */
    public static function set_knowledge(string $text): int {
        $text = self::trim_knowledge($text);
        set_config('chatknowledge', $text, self::COMPONENT);
        return \core_text::strlen($text);
    }

    /**
     * Writes down the knowledge of a department; empty clears it.
     *
     * @param int $teamid The department.
     * @param string $text The text.
     * @return int How many characters it kept.
     */
    public static function set_team_knowledge(int $teamid, string $text): int {
        $list = self::team_knowledge();
        $text = self::trim_knowledge($text);
        if ($text === '') {
            unset($list[$teamid]);
        } else {
            $list[$teamid] = $text;
        }
        ksort($list);
        set_config('chatteamknowledge', $list ? json_encode($list) : '', self::COMPONENT);
        return \core_text::strlen($text);
    }

    /**
     * A piece of knowledge as it is kept: plain text, with its lines, up to the most characters.
     *
     * @param string $text The text.
     * @return string
     */
    private static function trim_knowledge(string $text): string {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return trim(\core_text::substr(clean_param($text, PARAM_RAW), 0, self::MAX_KNOWLEDGE));
    }

    /**
     * What is known of whoever writes: the family records, the marks of the team and what other plugins say.
     *
     * @param \stdClass $row The conversation, as the index keeps it.
     * @param int $userid The person who asks for the draft: other plugins only say what they may see.
     * @return string[]
     */
    public static function context_lines(\stdClass $row, int $userid): array {
        $lines = [];
        $name = trim((string)($row->name ?? ''));
        if ($name !== '') {
            $lines[] = get_string('chat:draft:name', self::COMPONENT, $name);
        }
        $phone = (string)($row->phone ?? '');
        $family = $phone !== '' ? family::of_phone($phone) : null;
        if ($family) {
            if (trim((string)$family['title']) !== '') {
                $lines[] = get_string('chat:draft:family', self::COMPONENT, trim((string)$family['title']));
            }
            foreach ($family['children'] as $child) {
                $parts = array_filter([trim((string)($child['group'] ?? '')), trim((string)($child['stage']['label'] ?? ''))], 'strlen');
                $where = implode(', ', array_unique($parts));
                $lines[] = get_string('chat:draft:child', self::COMPONENT, trim($child['name'] . ' ' . $child['lastname']) .
                    ($where !== '' ? ' (' . $where . ')' : ''));
            }
        }
        if (trim((string)($row->kindnote ?? '')) !== '') {
            $lines[] = get_string('chat:draft:marks', self::COMPONENT, trim((string)$row->kindnote));
        }
        if ($phone !== '') {
            foreach (get_plugins_with_function('oksigeniamarandu_chat_context') as $plugins) {
                foreach ($plugins as $function) {
                    try {
                        foreach ((array)$function($phone, $userid) as $line) {
                            $line = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$line)));
                            if ($line !== '') {
                                $lines[] = \core_text::substr($line, 0, self::LINE_LENGTH);
                            }
                        }
                    } catch (\Throwable $e) {
                        // A plugin that fails does not stop the draft.
                        debugging('Marandu: the context of a plugin failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                    }
                }
            }
        }
        return array_slice(array_values(array_unique($lines)), 0, self::CONTEXT_LINES);
    }

    /**
     * The last messages of the conversation, from the oldest, each with who wrote it.
     *
     * @param array $raw The messages, as Chatwoot gives them.
     * @return string[]
     */
    public static function conversation_lines(array $raw): array {
        usort($raw, function ($a, $b) {
            return [(int)($a['created_at'] ?? 0), (int)($a['id'] ?? 0)] <=> [(int)($b['created_at'] ?? 0), (int)($b['id'] ?? 0)];
        });
        $lines = [];
        foreach ($raw as $one) {
            $kind = message::kind($one);
            if ($kind === 'activity') {
                continue;
            }
            $text = (string)($one['content'] ?? '');
            if ($kind === 'outgoing') {
                // The signature the chat puts in front of what the team sends.
                $text = preg_replace('/^\*[^*\n]{1,80}\*\n/u', '', $text);
            }
            $text = trim(preg_replace('/[ \t]+/u', ' ', message::plain_text($text)));
            if ($text === '' && !empty($one['attachments'])) {
                $text = get_string('chat:draft:attachment', self::COMPONENT);
            }
            if ($text === '') {
                continue;
            }
            $lines[] = get_string('chat:draft:who:' . $kind, self::COMPONENT) . ': ' .
                \core_text::substr($text, 0, self::MESSAGE_LENGTH);
        }
        return array_slice($lines, -self::MESSAGES);
    }

    /**
     * What goes to the text generation: what to do, what the centre knows, what is known of whoever writes, and the
     * conversation.
     *
     * @param \stdClass $row The conversation, as the index keeps it.
     * @param int $userid The person who asks for it.
     * @param array $raw Its last messages, as Chatwoot gives them.
     * @param string $hint What the person already wrote in the box, as a hint of what to say.
     * @return string
     */
    public static function prompt(\stdClass $row, int $userid, array $raw, string $hint = ''): string {
        global $SITE;
        try {
            $teams = directory::teams();
        } catch (\Throwable $e) {
            $teams = [];
        }
        $teamid = (int)($row->teamid ?? 0);
        $team = $teamid && isset($teams[$teamid]) ? (string)$teams[$teamid]['name'] : '';
        $parts = [get_string('chat:draft:prompt', self::COMPONENT, (object)[
            'team' => $team !== '' ? $team : get_string('chat:draft:theteam', self::COMPONENT),
            'site' => format_string($SITE->fullname),
        ])];
        $hint = trim(preg_replace('/\s+/u', ' ', $hint));
        if ($hint !== '') {
            $parts[] = get_string('chat:draft:hint', self::COMPONENT, \core_text::substr($hint, 0, 1000));
        }
        $knowledge = trim(self::knowledge());
        if ($knowledge !== '') {
            $parts[] = '## ' . get_string('chat:draft:knowledge', self::COMPONENT) . "\n" . $knowledge;
        }
        $ofteam = trim(self::team_knowledge()[$teamid] ?? '');
        if ($ofteam !== '') {
            $parts[] = '## ' . get_string(
                'chat:draft:teamknowledge',
                self::COMPONENT,
                $team !== '' ? $team : get_string('chat:draft:theteam', self::COMPONENT)
            ) . "\n" . $ofteam;
        }
        $context = self::context_lines($row, $userid);
        if ($context) {
            $parts[] = '## ' . get_string('chat:draft:about', self::COMPONENT) . "\n- " . implode("\n- ", $context);
        }
        $lines = self::conversation_lines($raw);
        $parts[] = '## ' . get_string('chat:draft:conversation', self::COMPONENT) . "\n" .
            ($lines ? implode("\n", $lines) : get_string('chat:draft:nomessages', self::COMPONENT));
        $parts[] = get_string('chat:draft:end', self::COMPONENT);
        return implode("\n\n", $parts);
    }

    /**
     * The draft as it goes into the box: only the message, without the quotes, the fences or a heading the model may
     * put around it.
     *
     * @param string $text What the text generation gave.
     * @return string
     */
    public static function clean(string $text): string {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        $text = preg_replace('/^```[a-z]*\n?|\n?```$/u', '', $text);
        $text = trim($text);
        if (preg_match('/^(["«“])(.*)(["»”])$/us', $text, $match)) {
            $text = trim($match[2]);
        }
        return trim(preg_replace("/\n{3,}/u", "\n\n", $text));
    }

    /**
     * Writes a draft for a conversation.
     *
     * @param int $userid The person who asks for it.
     * @param \stdClass $row The conversation, as the index keeps it.
     * @param string $hint What the person already wrote in the box.
     * @return array status (ok, unavailable, policy or failed), text and error.
     */
    public static function make(int $userid, \stdClass $row, string $hint = ''): array {
        if (!self::available()) {
            return ['status' => 'unavailable', 'text' => '', 'error' => ''];
        }
        if (!self::policy_accepted($userid)) {
            return ['status' => 'policy', 'text' => '', 'error' => ''];
        }
        try {
            $answer = directory::client()->call('GET', '/conversations/' . (int)$row->cwid . '/messages', []);
            $raw = array_values($answer['payload'] ?? []);
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'text' => '', 'error' => $e->getMessage()];
        }
        \core_php_time_limit::raise(120);
        $action = new \core_ai\aiactions\generate_text(
            \context_system::instance()->id,
            $userid,
            self::prompt($row, $userid, $raw, $hint)
        );
        $response = \core\di::get(\core_ai\manager::class)->process_action($action);
        if (!$response->get_success()) {
            return ['status' => 'failed', 'text' => '',
                'error' => (string)($response->get_errormessage() ?: $response->get_error())];
        }
        $text = self::clean((string)($response->get_response_data()['generatedcontent'] ?? ''));
        return $text === '' ? ['status' => 'failed', 'text' => '', 'error' => ''] : ['status' => 'ok', 'text' => $text, 'error' => ''];
    }
}
