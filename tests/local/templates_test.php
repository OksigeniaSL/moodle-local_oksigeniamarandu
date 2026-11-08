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
                return str_ends_with($path, 'message_templates') ? ['id' => '9001', 'status' => 'PENDING',
                    'category' => 'UTILITY'] : ['success' => true];
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
        // Every notice of the site whose text Meta would take: those of other plugins installed too.
        $good = array_filter(notices::all(), function (\stdClass $one): bool {
            return templates::problem(notices::text($one, 'template', 'en')) === null;
        });
        $counts = templates::sync($meta, 'waba1');
        $this->assertSame(count($good), $counts['created']);
        $this->assertSame(count(notices::all()) - count($good), $counts['wrong']);
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
}
