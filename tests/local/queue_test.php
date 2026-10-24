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

use local_oksigeniamarandu\api;

/**
 * Tests of the notices: declared, their templates in Meta, and the queue with the limits of the site.
 *
 * Meta is never called: a fake keeps what would be sent. Numbers and names are made up.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\queue
 * @covers     \local_oksigeniamarandu\local\notices
 * @covers     \local_oksigeniamarandu\local\templates
 * @covers     \local_oksigeniamarandu\api
 */
#[\PHPUnit\Framework\Attributes\CoversClass(queue::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(notices::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(templates::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(api::class)]
final class queue_test extends \advanced_testcase {
    /** A Wednesday at 11:00 in Madrid, out of the quiet hours. */
    private const WEDNESDAY = 1793181600;

    /**
     * A site with its account, Spanish, Madrid time, and the general notice switched on.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        $CFG->lang = 'es';
        $this->setTimezone('Europe/Madrid');
        set_config('phonenumberid', '1110000000', 'local_oksigeniamarandu');
        set_config('enablednotices', 'local_oksigeniamarandu/notification', 'local_oksigeniamarandu');
        set_config('quietfrom', '20:00', 'local_oksigeniamarandu');
        set_config('quietto', '08:00', 'local_oksigeniamarandu');
        set_config('quietdays', '6,0', 'local_oksigeniamarandu');
    }

    /**
     * A client that keeps what it would send.
     *
     * @return cloud_api
     */
    private function fake_api(): cloud_api {
        return new class ('1110000000', 'fake-token') extends cloud_api {
            /** @var array What was sent. */
            public $sent = [];

            /** @var array Templates sent for review. */
            public $created = [];

            /**
             * Keeps a template message.
             *
             * @param string $to Number.
             * @param string $template Name.
             * @param string $language Language.
             * @param array $components Values.
             * @return string
             */
            public function send_template(string $to, string $template, string $language, array $components = []): string {
                $this->sent[] = ['template', $to, $template, $language, $components];
                return 'wamid.T' . count($this->sent);
            }

            /**
             * Keeps a text message.
             *
             * @param string $to Number.
             * @param string $text Text.
             * @return string
             */
            public function send_text(string $to, string $text): string {
                $this->sent[] = ['text', $to, $text];
                return 'wamid.T' . count($this->sent);
            }

            /**
             * Keeps a template sent for review.
             *
             * @param string $wabaid Account.
             * @param array $payload Template.
             * @return array
             */
            public function create_template(string $wabaid, array $payload): array {
                $this->created[] = [$wabaid, $payload];
                return ['id' => '900' . count($this->created), 'status' => 'PENDING', 'category' => 'UTILITY'];
            }

            /**
             * What Meta says of the templates.
             *
             * @param string $wabaid Account.
             * @return array
             */
            public function list_templates(string $wabaid): array {
                return [['name' => 'mrd_local_oksigeniamarandu_notification', 'language' => 'es', 'status' => 'APPROVED',
                    'category' => 'UTILITY']];
            }
        };
    }

    /**
     * A family of a made-up plugin that joined with a code.
     *
     * @param string $phone Its number.
     * @param int $itemid Its id in the plugin.
     * @return \stdClass The contact.
     */
    private function family(string $phone, int $itemid = 42): \stdClass {
        global $DB;
        consent::optin_from_message($phone, api::invite('local_example', $itemid), 'wamid.FAKE');
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => $phone], '*', MUST_EXIST);
        // Its message of joining was long ago: what goes to it now needs a template.
        $DB->set_field(consent::CONTACTS, 'lastinbound', self::WEDNESDAY - 3 * DAYSECS, ['id' => $contact->id]);
        return $contact;
    }

    /**
     * The general notice, approved by Meta.
     *
     * @return \stdClass
     */
    private function approve(): \stdClass {
        $notice = notices::get('local_oksigeniamarandu', 'notification');
        templates::submit($this->fake_api(), '2220000000', $notice, 'es');
        templates::update_from_meta('mrd_local_oksigeniamarandu_notification', 'es', 'APPROVED');
        return $notice;
    }

    /**
     * Queues the general notice for a family of the made-up plugin, as the queue holds it.
     *
     * @param int $itemid The family.
     * @param bool $urgent Whether it is urgent.
     * @return int How many numbers.
     */
    private function notify(int $itemid = 42, bool $urgent = false): int {
        global $DB;
        // A notice for a family of another plugin, queued directly: that plugin has no notices of its own here.
        $count = 0;
        $notice = notices::get('local_oksigeniamarandu', 'notification');
        foreach ($DB->get_records(consent::LINKS, ['component' => 'local_example', 'itemid' => $itemid]) as $link) {
            if ($link->contactid && $DB->get_field(consent::CONTACTS, 'status', ['id' => $link->contactid]) === 'active') {
                queue::add(
                    (int)$link->contactid,
                    $notice,
                    ['Colegio Ejemplo', 'Reunión el jueves'],
                    'https://www.example.com/moodle/x.php',
                    $urgent,
                    2 * DAYSECS,
                    self::WEDNESDAY
                );
                $count++;
            }
        }
        return $count;
    }

    /**
     * The general notice of the plugin is declared, with its texts.
     */
    public function test_declared_notices(): void {
        $notice = notices::get('local_oksigeniamarandu', 'notification');
        $this->assertNotNull($notice);
        $this->assertCount(2, $notice->examples);
        $this->assertSame('mrd_local_oksigeniamarandu_notification', notices::template_name($notice));
        $this->assertStringContainsString('{{2}}', notices::text($notice, 'template', 'es'));
        $this->assertSame('es_MX', notices::metalang('es_mx'));
        $this->assertSame('es', notices::metalang('es'));
        $this->assertSame(['local_oksigeniamarandu/notification'], notices::enabled());
    }

    /**
     * What goes to Meta: the text with its examples, utility, and the button to the link of the site.
     */
    public function test_submit_and_status_of_templates(): void {
        global $DB;
        $api = $this->fake_api();
        $notice = notices::get('local_oksigeniamarandu', 'notification');

        $template = templates::submit($api, '2220000000', $notice, 'es');

        [$wabaid, $payload] = $api->created[0];
        $this->assertSame('2220000000', $wabaid);
        $this->assertSame('UTILITY', $payload['category']);
        $this->assertSame('es', $payload['language']);
        $this->assertSame([$notice->examples], $payload['components'][0]['example']['body_text']);
        $button = $payload['components'][1]['buttons'][0];
        $this->assertStringEndsWith('/local/oksigeniamarandu/go.php?k={{1}}', $button['url']);
        $this->assertSame('pending', $template->status);
        $this->assertNull(templates::approved($notice));

        // Meta moves it to marketing, then the webhook says it is approved.
        templates::update_from_meta('mrd_local_oksigeniamarandu_notification', 'es', '', 'MARKETING');
        templates::update_from_meta('mrd_local_oksigeniamarandu_notification', 'es', 'APPROVED');
        $stored = $DB->get_record(templates::TABLE, ['id' => $template->id]);
        $this->assertSame('approved', $stored->status);
        $this->assertSame('marketing', $stored->category);
        $this->assertNotNull(templates::approved($notice));

        templates::update_from_meta('mrd_local_oksigeniamarandu_notification', 'es', 'REJECTED', '', 'INVALID_FORMAT');
        $this->assertSame('INVALID_FORMAT', $DB->get_field(templates::TABLE, 'reason', ['id' => $template->id]));
        // Another language of the same name is not this one.
        $this->assertFalse(templates::update_from_meta('mrd_local_oksigeniamarandu_notification', 'en', 'APPROVED'));

        $this->assertSame(1, templates::refresh($api, '2220000000'));
        $this->assertSame('approved', $DB->get_field(templates::TABLE, 'status', ['id' => $template->id]));
    }

    /**
     * Notify reaches the numbers that joined for the item, not the others nor those who left.
     */
    public function test_notify_reaches_who_joined(): void {
        global $DB;
        set_config('enablednotices', 'local_oksigeniamarandu/notification', 'local_oksigeniamarandu');
        $this->family('34600000001');
        $this->family('34600000002');
        $this->family('34600000003', 43);
        consent::optout('34600000002', 'keyword', 'wamid.FAKE');

        // The general notice, for a user: every number linked to the user.
        // The language pack of the site is not installed in the tests: the user keeps English.
        $user = $this->getDataGenerator()->create_user(['lang' => 'en']);
        consent::optin_from_message('34600000004', api::invite('core', 0, $user->id), 'wamid.FAKE');
        $count = api::notify(
            'local_oksigeniamarandu',
            'notification',
            ["Colegio\nEjemplo", '  Reunión   el jueves '],
            new \moodle_url('/course/view.php', ['id' => 2]),
            0,
            $user->id
        );
        $this->assertSame(1, $count);
        $row = $DB->get_record(queue::TABLE, []);
        $this->assertSame(['Colegio Ejemplo', 'Reunión el jueves'], json_decode($row->params, true));
        $this->assertSame('queued', $row->status);

        $this->assertSame(1, $this->notify(42));
    }

    /**
     * A notice that is not declared, or with the wrong number of values, is a mistake of the plugin.
     */
    public function test_notify_checks_the_notice(): void {
        try {
            api::notify('local_oksigeniamarandu', 'nothing', []);
            $this->fail('An undeclared notice must throw.');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('not declared', $e->getMessage());
        }
        $this->expectException(\coding_exception::class);
        api::notify('local_oksigeniamarandu', 'notification', ['only one']);
    }

    /**
     * With its template approved, the notice goes as a template with its values and the button of the site.
     */
    public function test_sends_with_the_template(): void {
        global $DB;
        $this->approve();
        $this->family('34600000001');
        $this->notify();
        $api = $this->fake_api();

        $counts = queue::process($api, self::WEDNESDAY);

        $this->assertSame(1, $counts['sent']);
        [$kind, $to, $name, $lang, $components] = $api->sent[0];
        $this->assertSame(
            ['template', '34600000001', 'mrd_local_oksigeniamarandu_notification', 'es'],
            [$kind, $to, $name, $lang]
        );
        $this->assertSame('Reunión el jueves', $components[0]['parameters'][1]['text']);
        $row = $DB->get_record(queue::TABLE, []);
        $this->assertSame($row->token, $components[1]['parameters'][0]['text']);
        $this->assertSame(['sent', 'template', 'wamid.T1'], [$row->status, $row->kind, $row->wamid]);

        // What Meta tells the webhook afterwards.
        queue::status('wamid.T1', 'delivered');
        queue::status('wamid.T1', 'read');
        $row = $DB->get_record(queue::TABLE, ['id' => $row->id]);
        $this->assertSame('read', $row->status);
        $this->assertNotEmpty($row->timedelivered);
    }

    /**
     * Inside the 24 hours after the family wrote, the notice goes as free text, even without a template.
     */
    public function test_free_inside_the_window(): void {
        global $DB;
        $contact = $this->family('34600000001');
        $DB->set_field(consent::CONTACTS, 'lastinbound', self::WEDNESDAY - HOURSECS, ['id' => $contact->id]);
        $this->notify();
        $api = $this->fake_api();

        queue::process($api, self::WEDNESDAY);

        $this->assertSame('text', $api->sent[0][0]);
        $notice = notices::get('local_oksigeniamarandu', 'notification');
        $this->assertStringStartsWith(queue::render($notice, ['Colegio Ejemplo', 'Reunión el jueves'], 'es'), $api->sent[0][2]);
        $this->assertStringContainsString('«Reunión el jueves»', $api->sent[0][2]);
        $this->assertStringContainsString('/local/oksigeniamarandu/go.php?k=', $api->sent[0][2]);
        $this->assertSame(0.0, queue::spent(self::WEDNESDAY));
    }

    /**
     * Without an approved template, the notice waits; switched off, it is dropped.
     */
    public function test_waits_for_the_template_and_drops_what_is_off(): void {
        global $DB;
        $this->family('34600000001');
        $this->notify();
        $api = $this->fake_api();

        $this->assertSame(1, queue::process($api, self::WEDNESDAY)['waiting']);
        $this->assertSame([], $api->sent);

        set_config('enablednotices', '', 'local_oksigeniamarandu');
        $this->assertSame(1, queue::process($api, self::WEDNESDAY)['skipped']);
        $this->assertSame('disabled', $DB->get_field(queue::TABLE, 'error', []));
    }

    /**
     * In the quiet hours and days only urgent notices go out; the rest wait, and expire if they wait too long.
     */
    public function test_quiet_hours(): void {
        global $DB;
        $this->approve();
        $this->family('34600000001');
        $night = self::WEDNESDAY + 11 * HOURSECS;
        $saturday = self::WEDNESDAY + 3 * DAYSECS;
        $this->assertTrue(queue::is_quiet($night));
        $this->assertTrue(queue::is_quiet($saturday));
        $this->assertFalse(queue::is_quiet(self::WEDNESDAY));

        $this->notify();
        $this->notify(42, true);
        $api = $this->fake_api();
        $counts = queue::process($api, $night);
        $this->assertSame([1, 1], [$counts['sent'], $counts['waiting']]);

        $counts = queue::process($api, $night + 3 * DAYSECS);
        $this->assertSame(1, $counts['expired']);
        $this->assertSame(1, $DB->count_records(queue::TABLE, ['status' => 'expired']));
    }

    /**
     * No more notices a day to a family than the site allows; the rest wait for the next day.
     */
    public function test_notices_a_day(): void {
        set_config('capfamily', 2, 'local_oksigeniamarandu');
        $this->approve();
        $this->family('34600000001');
        $this->notify();
        $this->notify();
        $this->notify();

        $counts = queue::process($this->fake_api(), self::WEDNESDAY);

        $this->assertSame([2, 1], [$counts['sent'], $counts['waiting']]);
    }

    /**
     * At the limit of the month, templates stop and whoever the site said is told, once.
     */
    public function test_limit_of_the_month(): void {
        set_config('capmonth', 0.03, 'local_oksigeniamarandu');
        set_config('costpermessage', 0.02, 'local_oksigeniamarandu');
        set_config('capemail', 'secretaria@example.com', 'local_oksigeniamarandu');
        $this->approve();
        $this->family('34600000001');
        $this->family('34600000002', 43);
        $this->notify(42);
        $this->notify(43);
        $sink = $this->redirectEmails();

        $counts = queue::process($this->fake_api(), self::WEDNESDAY);
        queue::process($this->fake_api(), self::WEDNESDAY + MINSECS);

        $this->assertSame([1, 1], [$counts['sent'], $counts['waiting']]);
        $this->assertSame(0.02, queue::spent(self::WEDNESDAY));
        $this->assertCount(1, $sink->get_messages());
        $this->assertSame('secretaria@example.com', $sink->get_messages()[0]->to);
    }

    /**
     * A refusal of Meta closes the notice as failed, with the reason.
     */
    public function test_failure(): void {
        global $DB;
        $this->approve();
        $this->family('34600000001');
        $this->notify();
        $api = new class ('1110000000', 'fake-token') extends cloud_api {
            /**
             * Meta refuses.
             *
             * @param string $to Number.
             * @param string $template Name.
             * @param string $language Language.
             * @param array $components Values.
             * @return string
             */
            public function send_template(string $to, string $template, string $language, array $components = []): string {
                throw new api_exception('error:recipientnotallowed', null, 131030);
            }
        };

        $this->assertSame(1, queue::process($api, self::WEDNESDAY)['failed']);
        $row = $DB->get_record(queue::TABLE, []);
        $this->assertSame('failed', $row->status);
        $this->assertNotEmpty($row->error);
    }
}
