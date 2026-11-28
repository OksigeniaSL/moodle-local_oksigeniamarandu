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
use local_oksigeniamarandu\external\proposal_send;

/**
 * Tests of the proposals of improvement: what is kept, who hears of them, and how they are read and closed. Names and
 * texts are made up.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\proposals
 * @covers     \local_oksigeniamarandu\external\proposal_send
 */
#[\PHPUnit\Framework\Attributes\CoversClass(proposals::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(proposal_send::class)]
final class proposals_test extends \advanced_testcase {
    /**
     * A small JPEG, as the browser sends it.
     *
     * @return string Base64.
     */
    private function jpeg(): string {
        $image = imagecreatetruecolor(40, 30);
        imagefill($image, 0, 0, imagecolorallocate($image, 240, 101, 20));
        ob_start();
        imagejpeg($image, null, 80);
        return base64_encode((string)ob_get_clean());
    }

    /**
     * A proposal is kept with its picture, the version of its tool and where it was made, and a task tells of it; one
     * that says nothing, or whose picture is not a picture, is refused.
     */
    public function test_save(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $id = proposals::save(
            (int)$user->id,
            'local_oksigeniamarandu',
            'chat',
            'https://example.org/local/oksigeniamarandu/chat.php',
            "  Que se vea la semana entera.\r\n ",
            'data:image/jpeg;base64,' . $this->jpeg()
        );
        $record = $DB->get_record(proposals::TABLE, ['id' => $id]);
        $this->assertSame(
            ['new', 'Que se vea la semana entera.', 'chat', proposals::release('local_oksigeniamarandu')],
            [$record->status, $record->body, $record->screen, $record->toolrelease]
        );
        $this->assertSame('captura.jpg', proposals::image_file($id)->get_filename());
        $this->assertStringContainsString('/local_oksigeniamarandu/proposal/' . $id . '/captura.jpg', proposals::image_url($id)->out());
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks('\local_oksigeniamarandu\task\proposal_received'));

        // Without a picture, it is kept all the same.
        $this->assertNull(proposals::image_file(proposals::save((int)$user->id, 'local_oksigeniamarandu', '', '', 'Más grande', '')));

        foreach ([['', $this->jpeg()], ['Algo', base64_encode('<svg></svg>')], ['Algo', 'not base64 !!']] as [$body, $image]) {
            try {
                proposals::save((int)$user->id, 'local_oksigeniamarandu', '', '', $body, $image);
                $this->fail('A proposal without text, or with something that is not a picture, is refused');
            } catch (\invalid_parameter_exception $e) {
                $this->assertSame(2, $DB->count_records(proposals::TABLE));
            }
        }
    }

    /**
     * The people chosen hear of each proposal by Telegram, with its picture, once; whoever reads them is one of them or
     * has the capability.
     */
    public function test_notify(): void {
        global $DB;
        $this->resetAfterTest();
        $author = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Ejemplo']);
        $person = $this->getDataGenerator()->create_user();
        consent::optin_from_message('tg:777', api::invite('core', 0, (int)$person->id), 'join');
        $this->assertFalse(proposals::can_read((int)$person->id));
        proposals::set_people([(int)$person->id, 0, (int)$person->id]);
        $this->assertSame([(int)$person->id], array_map(fn($user) => (int)$user->id, proposals::people()));
        $this->assertTrue(proposals::can_read((int)$person->id));
        $this->assertFalse(proposals::can_read((int)$author->id));

        $id = proposals::save(
            (int)$author->id,
            'local_oksigeniamarandu',
            'chat',
            '',
            'Que se vea la semana entera.',
            $this->jpeg(),
            'issue',
            'Chrome 131 · Windows · 1366×768 · claro'
        );
        $this->expectOutputRegex('/Suggestion \d+: told to 1 chats/');
        $this->runAdhocTasks('\local_oksigeniamarandu\task\proposal_received');
        $row = $DB->get_record(queue::TABLE, ['notice' => 'proposal'], '*', MUST_EXIST);
        $this->assertStringEndsWith(': Que se vea la semana entera.', json_decode($row->params, true)[0]);
        $rich = json_decode($row->rich, true);
        $this->assertStringContainsString('/proposal/' . $id . '/captura.jpg', $rich['image']);
        $this->assertStringContainsString('Ana Ejemplo', implode(' ', $rich['text']));
        $this->assertNotEmpty($DB->get_field(proposals::TABLE, 'timenotified', ['id' => $id]));

        // Once: the task again tells nobody.
        $task = new \local_oksigeniamarandu\task\proposal_received();
        $task->set_custom_data(['id' => $id]);
        $task->execute();
        $this->assertSame(1, $DB->count_records(queue::TABLE, ['notice' => 'proposal']));
        $this->assertNotSame('', proposals::release('local_oksigeniamarandu'));
        $this->assertSame($GLOBALS['CFG']->release, proposals::release('core'));
    }

    /**
     * An idea is not told by itself: it waits for the summary of the day, from 8:00, once a day, to the people chosen, by
     * Telegram and in the bell.
     */
    public function test_digest(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setTimezone('Europe/Madrid', 'Europe/Madrid');
        $author = $this->getDataGenerator()->create_user();
        $person = $this->getDataGenerator()->create_user();
        consent::optin_from_message('tg:778', api::invite('core', 0, (int)$person->id), 'join');
        proposals::set_people([(int)$person->id]);
        $sink = $this->redirectMessages();
        foreach (['Ver dos semanas a la vez.', 'El postre con sus alérgenos.'] as $text) {
            $id = proposals::received(proposals::save((int)$author->id, 'local_oksigeniamarandu', 'menu', '', $text, ''));
        }
        $this->assertSame(0, $DB->count_records(queue::TABLE, ['notice' => 'proposal']));
        $seven = make_timestamp(2026, 11, 30, 7, 30, 0, 'Europe/Madrid');
        $this->assertSame(0, proposals::round($seven)['ideas']);
        $nine = make_timestamp(2026, 11, 30, 9, 20, 0, 'Europe/Madrid');
        $this->assertSame(2, proposals::round($nine)['ideas']);
        $row = $DB->get_record(queue::TABLE, ['notice' => 'proposal'], '*', MUST_EXIST);
        $this->assertStringStartsWith(
            get_string('proposal:digest:many', 'local_oksigeniamarandu', 2),
            json_decode($row->params, true)[0]
        );
        $this->assertStringContainsString('El postre con sus alérgenos.', json_decode($row->params, true)[0]);
        $this->assertSame(['proposal'], array_map(fn($message) => $message->eventtype, $sink->get_messages()));
        $this->assertSame(0, proposals::round($nine + HOURSECS)['ideas']);
        $this->assertSame(0, $DB->count_records_select(proposals::TABLE, 'timedigest IS NULL'));
        $sink->close();
    }

    /**
     * Closed as done in a version, or not and why: whoever proposed it hears it, in the bell and by mail, a few seconds
     * later; undone before, nobody hears anything.
     */
    public function test_close_and_tell(): void {
        global $DB;
        $this->resetAfterTest();
        $author = $this->getDataGenerator()->create_user(['firstname' => 'Ana']);
        $closer = $this->getDataGenerator()->create_user();
        $sink = $this->redirectMessages();
        $id = proposals::save(
            (int)$author->id,
            'local_oksigeniamarandu',
            'menu',
            'https://example.org/menu',
            'Dos semanas.',
            $this->jpeg()
        );
        $now = time();
        $this->assertTrue(proposals::close($id, 'done', '', '0.116.2', (int)$closer->id, $now));
        $this->assertFalse(proposals::close($id, 'no', 'Otra', '', (int)$closer->id, $now));
        $task = \core\task\manager::get_adhoc_tasks('\local_oksigeniamarandu\task\proposal_tell');
        $this->assertSame($now + proposals::UNDO, (int)reset($task)->get_next_run_time());

        // Undone in time: nothing reaches Ana, and the task finds nothing to tell.
        $this->assertTrue(proposals::undo($id));
        $this->assertSame('new', $DB->get_field(proposals::TABLE, 'status', ['id' => $id]));
        $this->assertFalse(proposals::tell($id, $now));

        // Turned down, with its reason: Ana hears it, once.
        $this->assertTrue(proposals::close($id, 'no', 'En el móvil no caben dos semanas.', '', (int)$closer->id, $now + 5));
        $this->assertFalse(proposals::tell($id, $now));
        $this->assertTrue(proposals::tell($id, $now + 5));
        $this->assertFalse(proposals::tell($id, $now + 5));
        $this->assertFalse(proposals::undo($id));
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame(
            ['proposalanswer', get_string('proposal:tell:idea:no', 'local_oksigeniamarandu'), (int)$author->id],
            [$messages[0]->eventtype, $messages[0]->subject, (int)$messages[0]->useridto]
        );
        $this->assertStringContainsString('Dos semanas.', $messages[0]->fullmessage);
        $sink->close();

        // Something that did not work, fixed in a version.
        $sink = $this->redirectMessages();
        $issue = proposals::save((int)$author->id, 'local_oksigeniamarandu', 'menu', '', 'No guarda.', '', 'issue');
        proposals::close($issue, 'done', '', '0.116.3', (int)$closer->id, $now);
        proposals::tell($issue, $now);
        $this->assertSame(
            get_string('proposal:tell:issue:done:release', 'local_oksigeniamarandu', '0.116.3'),
            $sink->get_messages()[0]->subject
        );
        $sink->close();
    }

    /**
     * Passed to Oksigenia: it goes a few seconds later without the name or the mail of the person; the receiver gives its
     * id and key; once a day the site asks, and its answer reaches whoever proposed it. What the receiver will not take
     * comes back; what it cannot take now is tried again each hour, for a day.
     */
    public function test_forward(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $author = $this->getDataGenerator()->create_user(['firstname' => 'Jorge', 'email' => 'jorge@example.org']);
        $calls = [];
        $answers = [[201, ['id' => 'p-41', 'clave' => 'k-41']]];
        proposal_receiver::fake_for_tests(function (string $method, string $url, ?array $body) use (&$calls, &$answers): array {
            $calls[] = [$method, $url, $body];
            return array_shift($answers) ?? [503, []];
        });
        $sink = $this->redirectMessages();
        $id = proposals::save(
            (int)$author->id,
            'local_oksigeniamarandu',
            'menu',
            'https://example.org/menu',
            'Copiar un menú.',
            $this->jpeg(),
            'idea',
            'Firefox 133 · Linux · 1800×1000 · oscuro'
        );
        $now = time();
        $this->assertTrue(proposals::forward($id, 2, $now));
        $this->assertFalse(proposals::forward($id, 2, $now));
        // Not before the time to undo it.
        $this->assertSame(0, proposals::round($now + 1)['sent']);
        $this->assertSame([], $calls);
        $this->assertSame('received', proposals::push($id, $now + proposals::UNDO));
        [$method, $url, $body] = $calls[0];
        $this->assertSame(['POST', proposal_receiver::URL], [$method, $url]);
        $this->assertSame(
            ['local_oksigeniamarandu', 'idea', 'Copiar un menú.', 'menu', (string)$id,
            parse_url($CFG->wwwroot, PHP_URL_HOST), 'Firefox 133 · Linux · 1800×1000 · oscuro'],
            [$body['producto'], $body['tipo'], $body['texto'], $body['pantalla'], $body['ref'], $body['sitio'], $body['navegador']]
        );
        $this->assertNotEmpty($body['imagen']);
        $this->assertStringNotContainsString('jorge', json_encode($body));
        $this->assertStringNotContainsString('Jorge', json_encode($body));

        // Asked the next day: still with them; then done, and Jorge hears it at once.
        $answers = [[200, ['estado' => 'recibida']], [200, ['estado' => 'hecha', 'respuesta' => 'Ya se puede.',
            'version' => '0.117.0']]];
        $this->assertSame('received', proposals::ask($id, $now + DAYSECS));
        $this->assertStringEndsWith('/p-41?k=k-41', $calls[1][1]);
        $this->assertSame(1, proposals::round($now + 2 * DAYSECS)['asked']);
        $record = $DB->get_record(proposals::TABLE, ['id' => $id]);
        $this->assertSame(['done', 'done', '0.117.0', 'Ya se puede.'], [$record->status, $record->outcome,
            $record->outcomerelease, $record->note]);
        $this->expectOutputRegex('/Suggestion \d+: told/');
        $this->runAdhocTasks('\local_oksigeniamarandu\task\proposal_tell');
        $this->assertSame(
            get_string('proposal:tell:idea:done:release', 'local_oksigeniamarandu', '0.117.0'),
            $sink->get_messages()[0]->subject
        );

        // Refused: back to wait for the organization. Not taken for a day: back too.
        $two = proposals::save((int)$author->id, 'local_oksigeniamarandu', 'menu', '', 'Otra.', '');
        proposals::forward($two, 2, $now);
        $answers = [[400, ['error' => 'texto']]];
        $this->assertSame('refused', proposals::push($two, $now + 20));
        $this->assertSame(['new', 'refused'], array_values((array)$DB->get_record(
            proposals::TABLE,
            ['id' => $two],
            'status, remotestate'
        )));
        $three = proposals::save((int)$author->id, 'local_oksigeniamarandu', 'menu', '', 'Y otra.', '');
        proposals::forward($three, 2, $now);
        $answers = [[429, []]];
        $this->assertSame('retry', proposals::push($three, $now + 20));
        $this->assertSame('failed', proposals::push($three, $now + DAYSECS + 20));
        $this->assertSame('new', $DB->get_field(proposals::TABLE, 'status', ['id' => $three]));
        proposal_receiver::fake_for_tests(null);
        $sink->close();
    }

    /**
     * As the command line gives them, with the picture copied; closed with what was done, once.
     */
    public function test_export_and_done(): void {
        $this->resetAfterTest();
        $author = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Ejemplo']);
        $id = proposals::save((int)$author->id, 'local_oksigeniamarandu', 'chat', 'https://example.org/x', 'Más grande', $this->jpeg());
        $dir = make_request_directory();
        [$one] = array_map(fn($record) => proposals::export($record, $dir), proposals::list());
        $this->assertSame(
            [$id, 'Ana Ejemplo', 'local_oksigeniamarandu', 'chat', 'https://example.org/x', 'Más grande', 'new'],
            [$one['id'], $one['who'], $one['tool'], $one['screen'], $one['url'], $one['text'], $one['status']]
        );
        $this->assertFileExists($one['picture']);
        $this->assertSame($dir . '/propuesta-' . $id . '.jpg', $one['picture']);

        $this->assertTrue(proposals::done($id, 'Hecho en la 0.62', 2));
        $this->assertFalse(proposals::done($id, 'Otra vez', 2));
        $this->assertSame([], proposals::list());
        $this->assertSame('Hecho en la 0.62', proposals::list('')[0]->note);
    }

    /**
     * From the page of a tool: an account with the capability proposes; a guest does not.
     */
    public function test_external(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $result = proposal_send::execute('local_oksigeniamarandu', 'chat', 'https://example.org/x', 'Más grande', $this->jpeg());
        $result = \core_external\external_api::clean_returnvalue(proposal_send::execute_returns(), $result);
        $this->assertGreaterThan(0, $result['id']);

        $this->setGuestUser();
        $this->expectException(\moodle_exception::class);
        proposal_send::execute('local_oksigeniamarandu', 'chat', '', 'Más grande', '');
    }

    /**
     * The button of a tool: for an account that may propose, with what opens it; nothing for a guest.
     */
    public function test_button(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $button = api::proposal_button('local_oksigeniameals', 'La semana');
        $this->assertStringContainsString('data-marandu-proposal="local_oksigeniameals"', $button);
        $this->assertStringContainsString('data-screen="La semana"', $button);
        $this->setGuestUser();
        $this->assertSame('', api::proposal_button('local_oksigeniameals', 'La semana'));
    }

    /**
     * For a page that prints itself without the header of Moodle: the script and the styles to link, and the data to
     * print (texts, site and key of the session); nothing for a guest.
     */
    public function test_assets(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $assets = api::proposal_assets();
        $this->assertStringContainsString('/local/oksigeniamarandu/proposal/proposal.js?v=', $assets['js']);
        $this->assertStringContainsString('/local/oksigeniamarandu/proposal/proposal.css?v=', $assets['css']);
        $data = json_decode($assets['data'], true);
        $this->assertSame([$CFG->wwwroot, sesskey()], [$data['config']['wwwroot'], $data['config']['sesskey']]);
        $this->assertSame(get_string('proposal:js:title', 'local_oksigeniamarandu'), $data['strings']['title']);
        $this->assertStringNotContainsString('</', $assets['data']);
        $this->setGuestUser();
        $this->assertNull(api::proposal_assets());
    }

    /**
     * With a provider of text generation (OpenAI, answering from a fixture): the proposal in a line and its tool.
     */
    public function test_summarise(): void {
        global $DB;
        $this->resetAfterTest();
        $manager = \core\di::get(\core_ai\manager::class);
        if (!method_exists($manager, 'create_provider_instance') || !class_exists('\aiprovider_openai\provider')) {
            $this->markTestSkipped('This Moodle has no instances of providers of AI');
        }
        $manager->create_provider_instance(
            classname: '\aiprovider_openai\provider',
            name: 'example',
            enabled: true,
            config: ['apikey' => '123', 'enableuserratelimit' => false, 'enableglobalratelimit' => false],
            actionconfig: [\core_ai\aiactions\generate_text::class => ['enabled' => true, 'settings' => ['model' => 'gpt-4o',
                'endpoint' => 'https://api.openai.com/v1/chat/completions', 'systeminstruction' => '']]],
        );
        $user = $this->getDataGenerator()->create_user();
        $id = proposals::save((int)$user->id, 'core', 'Inicio', '', 'En el chat, que el botón de enviar sea más grande.', '');
        ['mock' => $mock] = $this->get_mocked_http_client();
        $mock->append(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'chatcmpl-1', 'object' => 'chat.completion', 'created' => 1, 'model' => 'gpt-4o',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant',
                'content' => "Resumen: Botón de enviar más grande\nHerramienta: local_oksigeniamarandu"], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 9, 'total_tokens' => 19],
            'system_fingerprint' => 'fp_example',
        ])));
        $this->assertTrue(proposals::summarise($DB->get_record(proposals::TABLE, ['id' => $id])));
        $record = $DB->get_record(proposals::TABLE, ['id' => $id]);
        $this->assertSame(['Botón de enviar más grande', 'local_oksigeniamarandu'], [$record->summary, $record->tool]);
    }
}
