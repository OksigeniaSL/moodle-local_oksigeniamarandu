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
     * @return array Each with name, language, category, body, params (key and example), header, footer and buttons.
     */
    public static function all(): array {
        $list = [];
        foreach (directory::inboxes() as $inbox) {
            foreach ($inbox['templates'] as $template) {
                $one = self::read($template);
                if ($one) {
                    $list[$one['name'] . '|' . $one['language']] = $one;
                }
            }
        }
        ksort($list);
        return array_values($list);
    }

    /**
     * One approved message.
     *
     * @param string $name Its name.
     * @param string $language Its language.
     * @return array|null
     */
    public static function find(string $name, string $language): ?array {
        foreach (self::all() as $template) {
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
