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
 * Tests of «Propose an answer»: what the centre writes down, what goes to the text generation of the site and what comes
 * back into the box. Names, numbers and texts are made up.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\chat\draft
 */
#[\PHPUnit\Framework\Attributes\CoversClass(draft::class)]
final class draft_test extends \advanced_testcase {
    /**
     * The knowledge of the whole centre and of each department: kept as plain text, cleared when empty, with a top.
     */
    public function test_knowledge(): void {
        $this->resetAfterTest();
        $this->assertSame('', draft::knowledge());
        $this->assertSame([], draft::team_knowledge());

        $this->assertSame(20, draft::set_knowledge("  Hablamos de ustedes.\r\n "));
        $this->assertSame('Hablamos de ustedes.', draft::knowledge());
        draft::set_team_knowledge(4, "Rutas:\n- Norte: ejemplo.org/norte");
        draft::set_team_knowledge(2, 'Recibos el día 5.');
        $this->assertSame([2 => 'Recibos el día 5.', 4 => "Rutas:\n- Norte: ejemplo.org/norte"], draft::team_knowledge());

        draft::set_team_knowledge(2, '   ');
        $this->assertSame([4], array_keys(draft::team_knowledge()));
        $this->assertSame(draft::MAX_KNOWLEDGE, draft::set_knowledge(str_repeat('a', draft::MAX_KNOWLEDGE + 50)));
        $this->assertSame(0, draft::set_knowledge(''));
        $this->assertSame('', draft::knowledge());
    }

    /**
     * What goes to the text generation: what to do, the knowledge of the centre and of the department, what is known
     * of whoever writes, the hint of the person and the last messages from the oldest, with who wrote each one. What
     * happened in the conversation (an assignment) does not go, nor the signature of the team.
     */
    public function test_prompt(): void {
        $this->resetAfterTest();
        draft::set_knowledge('Firmamos como Admisiones.');
        draft::set_team_knowledge(4, 'Ruta norte: ejemplo.org/norte');
        draft::set_team_knowledge(5, 'Lo de otro departamento.');
        $row = (object)['cwid' => 9, 'teamid' => 4, 'name' => 'Marta Pérez', 'phone' => '34600000001',
            'kindnote' => 'Proveedor de libros'];
        $raw = [
            ['id' => 3, 'created_at' => 300, 'message_type' => 1, 'private' => false, 'content' => "*Lucía · Secretaría*\nHola, Marta.",
                'sender' => ['type' => 'user', 'id' => 2]],
            ['id' => 1, 'created_at' => 100, 'message_type' => 0, 'private' => false, 'content' => '¿Hay guagua por el norte?'],
            ['id' => 2, 'created_at' => 200, 'message_type' => 2, 'private' => false, 'content' => 'Asignada a Lucía'],
            ['id' => 4, 'created_at' => 400, 'message_type' => 1, 'private' => true, 'content' => 'Pide plaza para dos.',
                'sender' => ['type' => 'user', 'id' => 2]],
            ['id' => 5, 'created_at' => 500, 'message_type' => 0, 'private' => false, 'content' => '', 'attachments' => [['id' => 1]]],
        ];
        $prompt = draft::prompt($row, 2, $raw, '  dile lo de la guagua ');

        $this->assertStringContainsString('Firmamos como Admisiones.', $prompt);
        $this->assertStringContainsString('Ruta norte: ejemplo.org/norte', $prompt);
        $this->assertStringNotContainsString('Lo de otro departamento.', $prompt);
        $this->assertStringContainsString('Name in the chat: Marta Pérez', $prompt);
        $this->assertStringContainsString('Marked by the team as: Proveedor de libros', $prompt);
        $this->assertStringContainsString('What the person of the team wants to say: dile lo de la guagua', $prompt);
        $this->assertStringNotContainsString('Asignada a Lucía', $prompt);
        $this->assertStringNotContainsString('Lucía · Secretaría', $prompt);
        $lines = draft::conversation_lines($raw);
        $this->assertSame([
            'Whoever writes: ¿Hay guagua por el norte?',
            'The team: Hola, Marta.',
            'Internal note of the team (not to be told as it is): Pide plaza para dos.',
            'Whoever writes: (a file)',
        ], $lines);
        $this->assertStringContainsString(implode("\n", $lines), $prompt);
        $this->assertStringEndsWith('Now write only the answer:', $prompt);

        // Without messages, nor knowledge, nor a department: it still says what to do.
        $empty = draft::prompt((object)['cwid' => 9, 'teamid' => 0, 'name' => '', 'phone' => ''], 2, []);
        $this->assertStringContainsString('(no messages yet)', $empty);
    }

    /**
     * What comes back goes into the box as a message: without the quotes or the fences a model may put around it.
     */
    public function test_clean(): void {
        $this->assertSame('Hola, Marta.', draft::clean('  «Hola, Marta.»  '));
        $this->assertSame("Hola.\n\nAdiós.", draft::clean("```\nHola.\n\n\n\nAdiós.\n```"));
        $this->assertSame('Dijo "sí" y vino.', draft::clean('Dijo "sí" y vino.'));
    }

    /**
     * Without a provider of text generation, nothing is asked: the page does not show the button.
     */
    public function test_unavailable(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->assertFalse(draft::available());
        $this->assertSame('unavailable', draft::make((int)$user->id, (object)['cwid' => 9])['status']);
    }

    /**
     * With a provider (OpenAI, answering from a fixture): first the policy of the site, then the draft, clean, made
     * from the conversation; nothing is sent to the conversation.
     */
    public function test_make(): void {
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
        $this->assertTrue(draft::available());
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $client = new class ('https://chat.example.org', 1, 'token') extends chatwoot {
            /** @var array The calls it got. */
            public $calls = [];

            /**
             * Its messages.
             *
             * @param string $method Method.
             * @param string $path Path.
             * @param array $data Data.
             * @return array
             */
            public function call(string $method, string $path, array $data = []): array {
                $this->calls[] = [$method, $path];
                if ($path !== '/conversations/9/messages') {
                    // The directory of the team: nobody, in these tests.
                    return $path === '/teams' || $path === '/agents' || $path === '/inboxes' ? [] : ['payload' => []];
                }
                return ['payload' => [['id' => 1, 'created_at' => 100, 'message_type' => 0, 'private' => false,
                    'content' => '¿A qué hora sale la guagua?']]];
            }
        };
        directory::use_client($client);
        try {
            $row = (object)['cwid' => 9, 'teamid' => 0, 'name' => 'Marta Pérez', 'phone' => ''];
            $this->assertSame('policy', draft::make((int)$user->id, $row)['status']);
            $this->assertSame([], $client->calls);

            draft::accept_policy((int)$user->id);
            ['mock' => $mock] = $this->get_mocked_http_client();
            $mock->append(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'chatcmpl-1', 'object' => 'chat.completion', 'created' => 1, 'model' => 'gpt-4o',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => '"Hola, Marta. Sale a las 7:45."'],
                    'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 9, 'total_tokens' => 19],
                'system_fingerprint' => 'fp_example',
            ])));
            $result = draft::make((int)$user->id, $row, 'la de las 7:45');
            $this->assertSame(['ok', 'Hola, Marta. Sale a las 7:45.'], [$result['status'], $result['text']]);
            // It read the conversation, and sent nothing to it.
            $this->assertSame(['GET', '/conversations/9/messages'], $client->calls[0]);
            $this->assertSame(['GET'], array_values(array_unique(array_column($client->calls, 0))));
            $sent = json_decode((string)$mock->getLastRequest()->getBody(), true);
            $this->assertStringContainsString('¿A qué hora sale la guagua?', end($sent['messages'])['content']);
            $this->assertStringContainsString('la de las 7:45', end($sent['messages'])['content']);

            // The provider fails: the person hears it, and writes the answer.
            $mock->append(new \GuzzleHttp\Psr7\Response(500, ['Content-Type' => 'application/json'], '{}'));
            $this->assertSame('failed', draft::make((int)$user->id, $row)['status']);
        } finally {
            directory::use_client(null);
        }
    }
}
