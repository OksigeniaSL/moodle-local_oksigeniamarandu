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
        // A question has its answers as quick replies, before the link: Meta takes them grouped, and in that order.
        $buttons = array_map(function ($option) use ($notice, $lang) {
            return ['type' => 'QUICK_REPLY', 'text' => notices::option_label($notice, $option, $lang)];
        }, $notice->options ?? []);
        $buttons[] = [
            'type' => 'URL',
            'text' => \core_text::substr(notices::text($notice, 'button', $lang), 0, 25),
            'url' => self::button_url(),
            // Meta takes the example of the end of the link, not the whole link.
            'example' => ['K7M2Q9XA'],
        ];
        return [
            'name' => notices::template_name($notice),
            'language' => notices::metalang($lang),
            'category' => 'UTILITY',
            'components' => [$body, ['type' => 'BUTTONS', 'buttons' => $buttons]],
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
        $record->status = self::status((string)($answer['status'] ?? 'PENDING')) ?? 'pending';
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
     * What is wrong with the text of a template for Meta, if anything: Meta refuses one that starts or ends with a value
     * (its error 2388299), and wants the values numbered from {{1}} with none missing.
     *
     * @param string $text The text.
     * @return string|null edge or numbers; null if it is good.
     */
    public static function problem(string $text): ?string {
        $text = trim($text);
        if (preg_match('/^\{\{\d+\}\}/', $text) || preg_match('/\{\{\d+\}\}$/', $text)) {
            return 'edge';
        }
        preg_match_all('/\{\{(\d+)\}\}/', $text, $matches);
        $numbers = array_values(array_unique(array_map('intval', $matches[1])));
        sort($numbers);
        return $numbers && $numbers !== range(1, count($numbers)) ? 'numbers' : null;
    }

    /**
     * Whether the text of the template of a notice changed since it went to Meta.
     *
     * @param \stdClass $template The stored template.
     * @param \stdClass $notice The notice.
     * @param string $lang Moodle language.
     * @return bool
     */
    public static function changed(\stdClass $template, \stdClass $notice, string $lang): bool {
        return (string)$template->body !== notices::text($notice, 'template', $lang);
    }

    /**
     * Sends to Meta what it does not have yet: the template of each new notice and the new text of those that changed
     * (approved, rejected or paused; one still in review waits). A text Meta would refuse does not go.
     *
     * @param cloud_api $api The client.
     * @param string $wabaid The WhatsApp Business account.
     * @param int $max The most it sends.
     * @return array Counts: created, edited, failed and wrong (texts Meta would refuse).
     */
    public static function sync(cloud_api $api, string $wabaid, int $max = 20): array {
        global $DB;
        $counts = ['created' => 0, 'edited' => 0, 'failed' => 0, 'wrong' => 0];
        foreach (notices::all() as $notice) {
            foreach (notices::languages() as $lang) {
                if ($counts['created'] + $counts['edited'] + $counts['failed'] >= $max) {
                    return $counts;
                }
                if (self::problem(notices::text($notice, 'template', $lang)) !== null) {
                    $counts['wrong']++;
                    continue;
                }
                $template = self::get($notice, $lang);
                try {
                    $metaid = $template ? (string)$template->metaid : '';
                    if (!$template || ($metaid === '' && $template->status === 'rejected')) {
                        self::submit($api, $wabaid, $notice, $lang);
                        $counts['created']++;
                    } else if (
                        in_array($template->status, ['approved', 'rejected', 'paused'], true)
                            && self::changed($template, $notice, $lang) && $metaid !== ''
                    ) {
                        $payload = self::payload($notice, $lang);
                        $api->edit_template($metaid, $payload['components']);
                        $DB->update_record(self::TABLE, (object)['id' => $template->id, 'status' => 'pending',
                            'reason' => null, 'body' => $payload['components'][0]['text'], 'timesubmitted' => time(),
                            'timemodified' => time()]);
                        $counts['edited']++;
                    }
                } catch (api_exception $e) {
                    // It is tried again in the next run; Meta says why on the page of the texts.
                    debugging('Marandu: a template did not go to Meta: ' . $e->getMessage(), DEBUG_DEVELOPER);
                    $counts['failed']++;
                }
            }
        }
        return $counts;
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
     * @param string $metalang Its language in Meta: es, es_MX, or es-MX as some webhooks write it.
     * @param string $status APPROVED, REJECTED, PENDING, PAUSED, DISABLED, REINSTATED…; empty to leave it.
     * @param string $category UTILITY, MARKETING…; empty to leave it.
     * @param string|null $reason Why it was rejected, NONE or empty if it was not; null to leave it.
     * @return bool Whether a template of the site changed.
     */
    public static function update_from_meta(
        string $name,
        string $metalang,
        string $status,
        string $category = '',
        ?string $reason = ''
    ): bool {
        global $DB;
        $changed = false;
        $wanted = self::langkey($metalang);
        foreach ($DB->get_records(self::TABLE, ['name' => $name]) as $record) {
            if (self::langkey(notices::metalang($record->lang)) !== $wanted) {
                continue;
            }
            $before = [$record->status, $record->category, $record->reason];
            $mapped = $status === '' ? null : self::status($status);
            if ($mapped !== null) {
                $record->status = $mapped;
            }
            if ($category !== '') {
                $record->category = strtolower($category);
            }
            if ($reason !== null) {
                $record->reason = $reason === '' || strtoupper($reason) === 'NONE' ? null : \core_text::substr($reason, 0, 255);
            }
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
     * Marks a template as Meta answered when a notice went out with it: paused, disabled or no longer there.
     *
     * @param \stdClass $template The stored template.
     * @param string $status pending, paused or disabled.
     */
    public static function set_status(\stdClass $template, string $status): void {
        global $DB;
        if ($template->status !== $status) {
            $DB->update_record(self::TABLE, (object)['id' => $template->id, 'status' => $status, 'timemodified' => time()]);
        }
    }

    /**
     * A status or an event of Meta, as stored.
     *
     * Flagged and locked templates can still be used: those events leave the status as it was.
     *
     * @param string $status As Meta writes it.
     * @return string|null pending, approved, rejected, paused or disabled; null to leave it.
     */
    private static function status(string $status): ?string {
        switch (strtoupper($status)) {
            case 'APPROVED':
            case 'REINSTATED':
                return 'approved';
            case 'REJECTED':
            case 'LIMIT_EXCEEDED':
                return 'rejected';
            case 'PAUSED':
                return 'paused';
            case 'DISABLED':
            case 'DELETED':
            case 'ARCHIVED':
            case 'PENDING_DELETION':
                return 'disabled';
            case 'FLAGGED':
            case 'LOCKED':
                return null;
            default:
                return 'pending';
        }
    }

    /**
     * A language code of Meta in one form, to compare it: es_MX and es-MX are the same.
     *
     * @param string $lang The code.
     * @return string
     */
    private static function langkey(string $lang): string {
        return strtolower(str_replace('-', '_', $lang));
    }
}
