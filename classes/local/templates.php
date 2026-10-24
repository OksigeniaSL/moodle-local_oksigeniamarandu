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
 * The templates of the notices in Meta: sent for review, and what Meta says of them.
 *
 * Outside the 24 hours after a family last wrote, WhatsApp only lets a notice go out with a template Meta approved.
 * Each notice has one, with fixed text and the values in between, and a button to its link on the site.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class templates {
    /** Table of the templates. */
    public const TABLE = 'local_oksigeniamarandu_tpl';

    /**
     * The address of the button of every notice: the link of the site that sends to the page of the notice.
     *
     * @param string $token The end of the link; {{1}} in the template.
     * @return string
     */
    public static function button_url(string $token = '{{1}}'): string {
        return (new \moodle_url('/local/oksigeniamarandu/go.php'))->out(false) . '?k=' . $token;
    }

    /**
     * The template of a notice in a language, as stored.
     *
     * @param \stdClass $notice The notice.
     * @param string $lang Moodle language.
     * @return \stdClass|null
     */
    public static function get(\stdClass $notice, string $lang): ?\stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, [
            'component' => $notice->component,
            'notice' => $notice->notice,
            'lang' => $lang,
        ]);
        return $record ?: null;
    }

    /**
     * What goes to Meta to create the template of a notice.
     *
     * @param \stdClass $notice The notice.
     * @param string $lang Moodle language.
     * @return array
     */
    public static function payload(\stdClass $notice, string $lang): array {
        $body = ['type' => 'BODY', 'text' => notices::text($notice, 'template', $lang)];
        if ($notice->examples) {
            $body['example'] = ['body_text' => [$notice->examples]];
        }
        return [
            'name' => notices::template_name($notice),
            'language' => notices::metalang($lang),
            'category' => 'UTILITY',
            'components' => [
                $body,
                ['type' => 'BUTTONS', 'buttons' => [[
                    'type' => 'URL',
                    'text' => \core_text::substr(notices::text($notice, 'button', $lang), 0, 25),
                    'url' => self::button_url(),
                    'example' => [self::button_url('K7M2Q9XA')],
                ]]],
            ],
        ];
    }

    /**
     * Sends the template of a notice to Meta for review, and keeps what Meta answers.
     *
     * @param cloud_api $api The client.
     * @param string $wabaid The WhatsApp Business account.
     * @param \stdClass $notice The notice.
     * @param string $lang Moodle language.
     * @return \stdClass The stored template.
     * @throws api_exception If Meta does not take it.
     */
    public static function submit(cloud_api $api, string $wabaid, \stdClass $notice, string $lang): \stdClass {
        global $DB;
        $payload = self::payload($notice, $lang);
        $answer = $api->create_template($wabaid, $payload);
        $now = time();
        $record = self::get($notice, $lang) ?? (object)[
            'component' => $notice->component,
            'notice' => $notice->notice,
            'lang' => $lang,
        ];
        $record->name = $payload['name'];
        $record->metaid = (string)($answer['id'] ?? '');
        $record->status = self::status((string)($answer['status'] ?? 'PENDING'));
        $record->category = strtolower((string)($answer['category'] ?? 'UTILITY'));
        $record->reason = null;
        $record->body = $payload['components'][0]['text'];
        $record->timesubmitted = $now;
        $record->timemodified = $now;
        if (empty($record->id)) {
            $record->id = $DB->insert_record(self::TABLE, $record);
        } else {
            $DB->update_record(self::TABLE, $record);
        }
        return $record;
    }

    /**
     * Asks Meta how the templates of the site are, for when its webhook of templates is not subscribed.
     *
     * @param cloud_api $api The client.
     * @param string $wabaid The WhatsApp Business account.
     * @return int How many templates changed.
     */
    public static function refresh(cloud_api $api, string $wabaid): int {
        $changed = 0;
        foreach ($api->list_templates($wabaid) as $template) {
            $changed += (int)self::update_from_meta(
                (string)($template['name'] ?? ''),
                (string)($template['language'] ?? ''),
                (string)($template['status'] ?? ''),
                (string)($template['category'] ?? ''),
                (string)($template['rejected_reason'] ?? '')
            );
        }
        return $changed;
    }

    /**
     * Keeps what Meta says of a template, from the webhook or from a refresh.
     *
     * @param string $name Its name in Meta.
     * @param string $metalang Its language in Meta.
     * @param string $status APPROVED, REJECTED, PENDING, PAUSED, DISABLED…; empty to leave it.
     * @param string $category UTILITY, MARKETING…; empty to leave it.
     * @param string $reason Why it was rejected, if it was.
     * @return bool Whether a template of the site changed.
     */
    public static function update_from_meta(
        string $name,
        string $metalang,
        string $status,
        string $category = '',
        string $reason = ''
    ): bool {
        global $DB;
        $changed = false;
        foreach ($DB->get_records(self::TABLE, ['name' => $name]) as $record) {
            if (notices::metalang($record->lang) !== $metalang) {
                continue;
            }
            $before = [$record->status, $record->category, $record->reason];
            if ($status !== '') {
                $record->status = self::status($status);
            }
            if ($category !== '') {
                $record->category = strtolower($category);
            }
            $record->reason = $reason === '' || strtoupper($reason) === 'NONE' ? null : \core_text::substr($reason, 0, 255);
            if ($before !== [$record->status, $record->category, $record->reason]) {
                $record->timemodified = time();
                $DB->update_record(self::TABLE, $record);
                $changed = true;
            }
        }
        return $changed;
    }

    /**
     * The approved template of a notice, in the language asked or, failing it, in the language of the site.
     *
     * @param \stdClass $notice The notice.
     * @param string|null $lang The language of the family.
     * @return \stdClass|null
     */
    public static function approved(\stdClass $notice, ?string $lang = null): ?\stdClass {
        foreach (array_unique(array_filter(array_merge([$lang], notices::languages()))) as $try) {
            $template = self::get($notice, $try);
            if ($template && $template->status === 'approved') {
                return $template;
            }
        }
        return null;
    }

    /**
     * A status of Meta, as stored.
     *
     * @param string $status As Meta writes it.
     * @return string pending, approved, rejected, paused or disabled.
     */
    private static function status(string $status): string {
        $status = strtolower($status);
        return in_array($status, ['approved', 'rejected', 'paused', 'disabled'], true) ? $status : 'pending';
    }
}
