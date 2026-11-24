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
 * The approved messages of WhatsApp a person can send from the conversations: those of the inbox, as Chatwoot brings
 * them from Meta, without the ones of the notices of the plugins (their names start with mrd_).
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class templates {
    /**
     * The approved messages of the inboxes.
     *
     * @return array Each with name, language, category, body, params (key, example and label), header, footer, buttons, the
     *               inbox it belongs to, whether it is one of those to start a conversation with (they go first) and write,
     *               the gap where whoever sends it writes the message itself, or empty if it comes written.
     */
    public static function all(): array {
        $list = [];
        foreach (directory::inboxes() as $inbox) {
            foreach ($inbox['templates'] as $template) {
                $one = self::read($template);
                if ($one && !isset($list[$one['name'] . '|' . $one['language']])) {
                    $list[$one['name'] . '|' . $one['language']] = $one + ['inbox' => (int)$inbox['id']];
                }
            }
        }
        $first = self::first();
        $info = self::info();
        foreach ($list as $key => $one) {
            $list[$key]['title'] = (string)($info[$one['name']]['title'] ?? '');
            $list[$key]['description'] = (string)($info[$one['name']]['description'] ?? '');
            // What goes in each gap, said by the organisation: an empty gap says it, not an example that reads as filled.
            $gaps = (array)($info[$one['name']]['gaps'] ?? []);
            foreach ($list[$key]['params'] as $n => $param) {
                $list[$key]['params'][$n]['label'] = trim((string)($gaps[$param['key']] ?? ''));
            }
            $list[$key]['write'] = self::written($one, (string)($info[$one['name']]['write'] ?? ''));
            $order = array_search($one['name'], $first, true);
            $list[$key]['start'] = $order !== false;
            $list[$key]['order'] = $order === false ? count($first) : $order;
        }
        uasort($list, function ($a, $b) {
            return [$a['order'], $a['name'], $a['language']] <=> [$b['order'], $b['name'], $b['language']];
        });
        return array_values($list);
    }

    /** From how many letters the example of a gap is a message and not a datum. */
    private const WRITTEN = 40;

    /**
     * The gap of an approved message where whoever sends it writes the message itself, with their own words: the one the
     * organisation says (write, in what it says of each message) or else the one with the longest example, when it is long
     * as a sentence and not as a name, a day or a subject.
     *
     * @param array $template The message, as read() gives it.
     * @param string $said The gap the organisation says, or empty.
     * @return string The gap, or empty if the message comes written.
     */
    public static function written(array $template, string $said = ''): string {
        $gap = '';
        $longest = self::WRITTEN - 1;
        foreach ($template['params'] as $param) {
            if ($said !== '' && $param['key'] === $said) {
                return $said;
            }
            $length = \core_text::strlen(trim($param['example']));
            if ($length > $longest) {
                $gap = $param['key'];
                $longest = $length;
            }
        }
        return $gap;
    }

    /**
     * The approved messages the team may send: without those Meta counts as marketing, unless the organisation lets them
     * go (setting marketingoff), since each costs several times a utility one, and without those the organisation keeps
     * without offering them (hidden, in the description of each).
     *
     * @return array As all() gives them.
     */
    public static function usable(): array {
        $all = !get_config('local_oksigeniamarandu', 'marketingoff');
        $info = self::info();
        return array_values(array_filter(self::all(), function ($template) use ($all, $info) {
            return ($all || strtoupper((string)$template['category']) !== 'MARKETING') &&
                empty($info[$template['name']]['hidden']);
        }));
    }

    /** The approved message offered after a call nobody answered, when the organisation does not name another. */
    public const CALL_DEFAULT = 'chat_llamada_sin_respuesta';

    /**
     * The approved message the page offers in one touch after a call nobody answered, once the 24 hours are over
     * (setting chatcalltemplate, cli/chat.php --call-template); the page offers it only if it is among usable().
     *
     * @return string Its name, or empty for none.
     */
    public static function call_name(): string {
        $name = get_config('local_oksigeniamarandu', 'chatcalltemplate');
        return $name === false ? self::CALL_DEFAULT : trim((string)$name);
    }

    /**
     * The approved messages the organisation uses to write first to someone who never wrote (setting
     * chatstarttemplates): they go first when a person starts a conversation.
     *
     * @return string[] Their names, in their order.
     */
    public static function first(): array {
        $names = array_map('trim', explode(',', (string)get_config('local_oksigeniamarandu', 'chatstarttemplates')));
        return array_values(array_filter($names, 'strlen'));
    }

    /**
     * What the organisation calls each approved message and what it says it does (setting chattemplatesinfo, a JSON
     * object by name with title, description, gaps, what goes in each gap by its key, and write, the gap where the message
     * itself is written), for the cards the team chooses from.
     *
     * @return array By name.
     */
    public static function info(): array {
        $info = json_decode((string)get_config('local_oksigeniamarandu', 'chattemplatesinfo'), true);
        return is_array($info) ? $info : [];
    }

    /**
     * How many messages of the team (not of the notices) Meta has not decided yet: what will come.
     *
     * @return int
     */
    public static function pending(): int {
        $count = 0;
        foreach (directory::inboxes() as $inbox) {
            foreach ($inbox['templates'] as $template) {
                $name = (string)($template['name'] ?? '');
                $status = strtoupper((string)($template['status'] ?? ''));
                if ($name !== '' && strpos($name, 'mrd_') !== 0 && in_array($status, ['PENDING', 'IN_APPEAL'])) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * One approved message.
     *
     * @param string $name Its name.
     * @param string $language Its language.
     * @return array|null
     */
    public static function find(string $name, string $language): ?array {
        foreach (self::usable() as $template) {
            $same = \core_text::strtolower($template['language']) === \core_text::strtolower($language);
            if ($template['name'] === $name && $same) {
                return $template;
            }
        }
        return null;
    }

    /**
     * An approved message as the page wants it, or null if it is not approved or is of a notice.
     *
     * @param array $template As Meta gives it.
     * @return array|null
     */
    public static function read(array $template): ?array {
        $name = (string)($template['name'] ?? '');
        if ($name === '' || strpos($name, 'mrd_') === 0 || strtoupper((string)($template['status'] ?? '')) !== 'APPROVED') {
            return null;
        }
        $result = ['name' => $name, 'language' => (string)($template['language'] ?? ''),
            'category' => strtoupper((string)($template['category'] ?? '')), 'body' => '', 'params' => [], 'header' => '',
            'footer' => '', 'buttons' => []];
        foreach ($template['components'] ?? [] as $component) {
            $type = strtoupper((string)($component['type'] ?? ''));
            if ($type === 'BODY') {
                $result['body'] = (string)($component['text'] ?? '');
                $examples = $component['example']['body_text'][0] ?? [];
                $named = [];
                foreach ($component['example']['body_text_named_params'] ?? [] as $param) {
                    $named[(string)($param['param_name'] ?? '')] = (string)($param['example'] ?? '');
                }
                preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $result['body'], $matches);
                foreach (array_unique($matches[1]) as $i => $key) {
                    $example = ctype_digit($key) ? (string)($examples[(int)$key - 1] ?? '') : ($named[$key] ?? '');
                    $result['params'][] = ['key' => $key, 'example' => $example];
                }
            } else if ($type === 'HEADER' && strtoupper((string)($component['format'] ?? 'TEXT')) === 'TEXT') {
                $result['header'] = (string)($component['text'] ?? '');
            } else if ($type === 'FOOTER') {
                $result['footer'] = (string)($component['text'] ?? '');
            } else if ($type === 'BUTTONS') {
                foreach ($component['buttons'] ?? [] as $button) {
                    $result['buttons'][] = (string)($button['text'] ?? '');
                }
            }
        }
        if (preg_match('/\{\{/', $result['header'])) {
            // A header with gaps is not for writing by hand.
            return null;
        }
        return $result;
    }

    /** What a message of each category costs when the centre does not say: rough figures, to check with Meta. */
    public const PRICES = ['currency' => 'EUR', 'utility' => 0.02, 'marketing' => 0.06, 'authentication' => 0.03,
        'service' => 0.02];

    /**
     * What a message of each category costs, as the centre set it (cli/chat.php --prices), for the cards of the
     * approved messages.
     *
     * @return array currency, utility, marketing, authentication and service (a plain text past the free ones of the month).
     */
    public static function prices(): array {
        $set = json_decode((string)get_config('local_oksigeniamarandu', 'chatprices'), true);
        $prices = self::PRICES;
        foreach (is_array($set) ? $set : [] as $key => $value) {
            if ($key === 'currency' && preg_match('/^[A-Z]{3}$/', (string)$value)) {
                $prices['currency'] = (string)$value;
            } else if (array_key_exists($key, $prices) && is_numeric($value) && $value >= 0) {
                $prices[$key] = (float)$value;
            }
        }
        return $prices;
    }

    /**
     * An approved message with its gaps filled, as Chatwoot takes it to send it by WhatsApp.
     *
     * @param array $template The message.
     * @param array $values By gap.
     * @return array
     */
    public static function message(array $template, array $values): array {
        return [
            'content' => self::render($template, $values),
            'message_type' => 'outgoing',
            'private' => false,
            'template_params' => [
                'name' => $template['name'],
                'category' => $template['category'],
                'language' => $template['language'],
                'processed_params' => ['body' => (object)$values],
            ],
        ];
    }

    /**
     * The text of an approved message with its gaps filled, as the family reads it.
     *
     * @param array $template The message.
     * @param array $values By gap.
     * @return string
     */
    public static function render(array $template, array $values): string {
        $body = preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', function ($match) use ($values) {
            return $values[$match[1]] ?? $match[0];
        }, $template['body']);
        return trim(implode("\n\n", array_filter([$template['header'], $body, $template['footer']], 'strlen')));
    }
}
