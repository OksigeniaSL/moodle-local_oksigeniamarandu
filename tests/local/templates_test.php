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
 * Tests of the templates of the notices in Meta: the texts Meta would refuse, and what goes to Meta by itself. Meta is
 * made up.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\templates
 */
#[\PHPUnit\Framework\Attributes\CoversClass(templates::class)]
final class templates_test extends \advanced_testcase {
    /**
     * A client of WhatsApp that keeps what goes to Meta.
     *
     * @param bool $fail Whether Meta refuses everything.
     * @return cloud_api
     */
    private function meta(bool $fail = false): cloud_api {
        return new class ('1110000000', 'fake-token', $fail) extends cloud_api {
            /** @var array What was posted, by path. */
            public $posted = [];

            /** @var array What was deleted, by path. */
            public $deleted = [];

            /** @var string The id Meta gives to the templates it creates. */
            public $id = '9001';

            /** @var bool Whether Meta refuses everything. */
            private $fail;

            /**
             * A client that may fail.
             *
             * @param string $phoneid The number.
             * @param string $token The token.
             * @param bool $fail Whether Meta refuses everything.
             */
            public function __construct(string $phoneid, string $token, bool $fail) {
                parent::__construct($phoneid, $token);
                $this->fail = $fail;
            }

            /**
             * Keeps it and answers as Meta.
             *
             * @param string $path Where.
             * @param array $payload What.
             * @return array
             */
            protected function post(string $path, array $payload): array {
                if ($this->fail) {
                    throw new api_exception('error:meta', (object)['code' => 100, 'message' => 'Invalid parameter'], 100);
                }
                $this->posted[] = [$path, $payload];
                return str_ends_with($path, 'message_templates') ? ['id' => $this->id, 'status' => 'PENDING',
                    'category' => 'UTILITY'] : ['success' => true];
            }

            /**
             * Keeps it and answers as Meta.
             *
             * @param string $path Where, with its query.
             * @return array
             */
            protected function remove(string $path): array {
                $this->deleted[] = $path;
                return ['success' => true];
            }
        };
    }

    /**
     * A text that starts or ends with a value, or whose values skip a number, is one Meta refuses.
     */
    public function test_problem(): void {
        $this->assertNull(templates::problem('Visita confirmada en {{1}}: los esperamos el {{2}}.'));
        $this->assertNull(templates::problem('Aviso de {{1}}: «{{2}}». Puedes leerlo en el enlace.'));
        $this->assertSame('edge', templates::problem('{{1}}: hay un cambio en su visita. Ahora es el {{2}}.'));
        $this->assertSame('edge', templates::problem('Hay un cambio en su visita: ahora es el {{2}} '));
        $this->assertSame('numbers', templates::problem('Aviso de {{1}} para el {{3}}.'));
        $this->assertNull(templates::problem('Sin datos.'));
    }

    /**
     * What goes by itself: the template of a notice Meta does not have, and the new text of one that changed; not one
     * still in review, nor one that did not change; and what Meta refuses is tried again later.
     */
    public function test_sync(): void {
        global $DB;
        $this->resetAfterTest();
        $notice = notices::get('local_oksigeniamarandu', 'notification');
        $meta = $this->meta();
        // Every notice of the site that goes by WhatsApp and whose text Meta would take: those of other plugins installed
        // too. The conversations of the team only go by Telegram and never reach Meta.
        $whatsapp = array_filter(notices::all(), function (\stdClass $one): bool {
            return notices::allows($one, 'whatsapp');
        });
        $this->assertArrayNotHasKey('local_oksigeniamarandu/chat', $whatsapp);
        $good = array_filter($whatsapp, function (\stdClass $one): bool {
            return templates::problem(notices::text($one, 'template', 'en')) === null;
        });
        $counts = templates::sync($meta, 'waba1');
        $this->assertSame(count($good), $counts['created']);
        $this->assertSame(count($whatsapp) - count($good), $counts['wrong']);
        $this->assertSame('waba1/message_templates', $meta->posted[0][0]);
        $template = templates::get($notice, 'en');
        $this->assertSame('pending', $template->status);
        $this->assertSame('9001', $template->metaid);

        // In review, or approved and the same: nothing.
        $this->assertSame(['created' => 0, 'edited' => 0, 'failed' => 0], array_slice(templates::sync($meta, 'waba1'), 0, 3));
        $DB->set_field(templates::TABLE, 'status', 'approved', ['id' => $template->id]);
        $this->assertSame(0, templates::sync($meta, 'waba1')['edited']);

        // Approved, and its text changed in a new version of its plugin: the new text goes, and it is reviewed again.
        $DB->set_field(templates::TABLE, 'body', 'An older text of {{1}}: {{2}}.', ['id' => $template->id]);
        $this->assertSame(1, templates::sync($meta, 'waba1')['edited']);
        [$path, $payload] = end($meta->posted);
        $this->assertSame('9001', $path);
        $this->assertSame(notices::text($notice, 'template', 'en'), $payload['components'][0]['text']);
        $this->assertSame('pending', $DB->get_field(templates::TABLE, 'status', ['id' => $template->id]));

        // Meta refuses: it is counted, and tried again in the next run.
        $DB->delete_records(templates::TABLE);
        $this->assertSame(count($good), templates::sync($this->meta(true), 'waba1')['failed']);
        $this->assertDebuggingCalledCount(count($good));
        $this->assertFalse($DB->record_exists(templates::TABLE, []));
    }

    /**
     * A notice whose template Meta moves to marketing stops going by WhatsApp, and goes again when it comes back as
     * utility; one the organisation switches on by hand stays.
     */
    public function test_marketing_stops_whatsapp(): void {
        $this->resetAfterTest();
        set_config('marketingoff', 1, 'local_oksigeniamarandu');
        $key = 'local_oksigeniamarandu/notification';
        $name = 'mrd_local_oksigeniamarandu_notification';
        templates::sync($this->meta(), 'waba1');
        notices::set($key, 'whatsapp', true);
        notices::set($key, 'telegram', true);

        // Moved to marketing: off by WhatsApp, on by Telegram, and written down.
        $this->assertTrue(templates::update_from_meta($name, 'en', 'APPROVED', 'MARKETING'));
        $this->assertNotContains($key, notices::enabled('whatsapp'));
        $this->assertContains($key, notices::enabled('telegram'));
        $this->assertArrayHasKey($key, notices::marketing_off());
        // Said again, nothing more.
        $this->assertFalse(templates::update_from_meta($name, 'en', 'APPROVED', 'MARKETING'));

        // Back as utility: on again by itself.
        templates::update_from_meta($name, 'en', 'APPROVED', 'UTILITY');
        $this->assertContains($key, notices::enabled('whatsapp'));
        $this->assertSame([], notices::marketing_off());

        // Switched on by hand while in marketing, it stays: an exception of the organisation.
        templates::update_from_meta($name, 'en', 'APPROVED', 'MARKETING');
        notices::set($key, 'whatsapp', true);
        $this->assertSame([], notices::marketing_off());
        templates::update_from_meta($name, 'en', 'APPROVED', 'MARKETING');
        $this->assertContains($key, notices::enabled('whatsapp'));

        // An organisation that lets marketing go: nothing changes.
        set_config('marketingoff', 0, 'local_oksigeniamarandu');
        templates::update_from_meta($name, 'en', 'APPROVED', 'UTILITY');
        templates::update_from_meta($name, 'en', 'APPROVED', 'MARKETING');
        $this->assertContains($key, notices::enabled('whatsapp'));
    }

    /**
     * A template Meta moved to marketing keeps that category: its new text goes as a new template, under the name with
     * _2, and the old one goes on sending meanwhile; once Meta approves the new one, it takes its place and the old one is
     * deleted in the next run.
     */
    public function test_renew(): void {
        global $DB;
        $this->resetAfterTest();
        $notice = notices::get('local_oksigeniamarandu', 'notification');
        $meta = $this->meta();
        templates::sync($meta, 'waba1');
        $template = templates::get($notice, 'en');
        $old = 'An older text of {{1}}: {{2}}.';
        $DB->update_record(templates::TABLE, (object)['id' => $template->id, 'status' => 'approved',
            'category' => 'marketing', 'body' => $old]);
        $meta->id = '9002';

        // The new text goes as a new template, not as an edit.
        $counts = templates::sync($meta, 'waba1');
        $this->assertSame([1, 0], [$counts['created'], $counts['edited']]);
        [$path, $payload] = end($meta->posted);
        $this->assertSame('waba1/message_templates', $path);
        $this->assertSame('mrd_local_oksigeniamarandu_notification_2', $payload['name']);
        $this->assertSame('UTILITY', $payload['category']);
        $template = templates::get($notice, 'en');
        $this->assertTrue(templates::renewing($template));
        $this->assertSame(
            ['pending', '9002', 'approved', $old],
            [$template->nextstatus, $template->nextmetaid, $template->status, $template->body]
        );
        $this->assertFalse(templates::changed($template, $notice, 'en'));
        // The old one goes on sending, while its values are those of the notice.
        $this->assertEquals($template->id, templates::approved($notice, 'en')->id);
        $DB->set_field(templates::TABLE, 'body', 'An older text of {{1}}.', ['id' => $template->id]);
        $this->assertNull(templates::approved($notice, 'en'));
        $DB->set_field(templates::TABLE, 'body', $old, ['id' => $template->id]);
        // In review: nothing more.
        $this->assertSame(['created' => 0, 'edited' => 0, 'failed' => 0], array_slice(templates::sync($meta, 'waba1'), 0, 3));

        // Rejected: with the same text, nothing; when the text changes again, the new template takes it, as an edit.
        $this->assertTrue(templates::update_from_meta(
            'mrd_local_oksigeniamarandu_notification_2',
            'en',
            'REJECTED',
            '',
            'INVALID_FORMAT'
        ));
        $template = templates::get($notice, 'en');
        $this->assertSame(['rejected', 'INVALID_FORMAT', 'approved'], [$template->nextstatus, $template->nextreason,
            $template->status]);
        $this->assertSame(0, templates::sync($meta, 'waba1')['edited']);
        $DB->set_field(templates::TABLE, 'nextbody', 'A rejected text of {{1}}: {{2}}.', ['id' => $template->id]);
        $this->assertSame(1, templates::sync($meta, 'waba1')['edited']);
        $this->assertSame('9002', end($meta->posted)[0]);
        $this->assertSame('pending', templates::get($notice, 'en')->nextstatus);

        // Approved: it takes the place of the old one, which is deleted in the next run, and only once.
        $this->assertTrue(templates::update_from_meta(
            'mrd_local_oksigeniamarandu_notification_2',
            'en',
            'APPROVED',
            'UTILITY'
        ));
        $template = templates::get($notice, 'en');
        $this->assertSame(
            ['mrd_local_oksigeniamarandu_notification_2', '9002', 'approved', 'utility'],
            [$template->name, $template->metaid, $template->status, $template->category]
        );
        $this->assertSame(notices::text($notice, 'template', 'en'), $template->body);
        $this->assertSame(
            ['retired', 'mrd_local_oksigeniamarandu_notification', '9001'],
            [$template->nextstatus, $template->nextname, $template->nextmetaid]
        );
        $this->assertFalse(templates::renewing($template));
        // What Meta says of the old one no longer touches anything.
        $this->assertFalse(templates::update_from_meta('mrd_local_oksigeniamarandu_notification', 'en', 'PAUSED'));
        $this->assertSame(1, templates::sync($meta, 'waba1')['retired']);
        $this->assertSame(
            ['waba1/message_templates?hsm_id=9001&name=mrd_local_oksigeniamarandu_notification'],
            $meta->deleted
        );
        $this->assertNull(templates::get($notice, 'en')->nextname);
        $this->assertSame(0, templates::sync($meta, 'waba1')['retired']);

        // A newer text later: the template is a utility one now, so it goes as an edit.
        $DB->set_field(templates::TABLE, 'body', $old, ['id' => $template->id]);
        $this->assertSame(1, templates::sync($meta, 'waba1')['edited']);
        $this->assertSame('9002', end($meta->posted)[0]);

        // A third text of one moved to marketing again goes under the name with _3.
        $DB->update_record(templates::TABLE, (object)['id' => $template->id, 'status' => 'approved',
            'category' => 'marketing', 'body' => $old]);
        templates::sync($meta, 'waba1');
        $this->assertSame('mrd_local_oksigeniamarandu_notification_3', templates::get($notice, 'en')->nextname);
    }
}
