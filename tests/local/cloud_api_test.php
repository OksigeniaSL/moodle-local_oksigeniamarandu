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
 * Tests of the client of the WhatsApp Cloud API. Meta is never called: a fake answers for it.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\cloud_api
 */
#[\PHPUnit\Framework\Attributes\CoversClass(cloud_api::class)]
final class cloud_api_test extends \advanced_testcase {
    /**
     * A client that answers for Meta and keeps what it was asked.
     *
     * @param array|string $answer What Meta answers: an array is sent as JSON.
     * @return cloud_api
     */
    private function fake_api($answer): cloud_api {
        $api = new class ('1110000000', 'fake-token') extends cloud_api {
            /** @var string What Meta answers. */
            public $answer = '';

            /** @var array The requests, with their URL and decoded body. */
            public $sent = [];

            /**
             * Keeps the request and answers for Meta.
             *
             * @param string $url Full URL.
             * @param string|null $body JSON body, or null for a GET.
             * @return string
             */
            protected function request(string $url, ?string $body = null): string {
                $this->sent[] = ['url' => $url, 'body' => $body === null ? null : json_decode($body, true)];
                return $this->answer;
            }
        };
        $api->answer = is_array($answer) ? json_encode($answer) : $answer;
        return $api;
    }

    /**
     * A template goes to the messages of the number, in the shape of the API, and its id comes back.
     */
    public function test_send_template(): void {
        $api = $this->fake_api([
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => '34600000000', 'wa_id' => '34600000000']],
            'messages' => [['id' => 'wamid.FAKE1']],
        ]);

        $this->assertSame('wamid.FAKE1', $api->send_template('34600000000', 'hello_world', 'en_US'));

        $this->assertCount(1, $api->sent);
        $this->assertSame(
            'https://graph.facebook.com/' . cloud_api::DEFAULT_GRAPH_VERSION . '/1110000000/messages',
            $api->sent[0]['url']
        );
        $this->assertSame([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => '34600000000',
            'type' => 'template',
            'template' => ['name' => 'hello_world', 'language' => ['code' => 'en_US']],
        ], $api->sent[0]['body']);
    }

    /**
     * The parameters of a template travel as components.
     */
    public function test_send_template_with_components(): void {
        $api = $this->fake_api(['messages' => [['id' => 'wamid.FAKE2']]]);
        $components = [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Ana']]]];

        $api->send_template('34600000000', 'menu_reminder', 'es', $components);

        $this->assertSame($components, $api->sent[0]['body']['template']['components']);
    }

    /**
     * Plain text goes as a text message, without a link preview.
     */
    public function test_send_text(): void {
        $api = $this->fake_api(['messages' => [['id' => 'wamid.FAKE3']]]);

        $this->assertSame('wamid.FAKE3', $api->send_text('34600000000', 'Hecho'));
        $this->assertSame(['preview_url' => false, 'body' => 'Hecho'], $api->sent[0]['body']['text']);
        $this->assertSame('text', $api->sent[0]['body']['type']);
    }

    /**
     * The check of the details asks Meta for the number and its verified name.
     */
    public function test_get_phone_number(): void {
        $api = $this->fake_api(['display_phone_number' => '+34 900 00 00 00', 'verified_name' => 'Colegio Ejemplo',
            'id' => '1110000000']);

        $this->assertSame(['number' => '+34 900 00 00 00', 'name' => 'Colegio Ejemplo'], $api->get_phone_number());
        $this->assertSame('https://graph.facebook.com/' . cloud_api::DEFAULT_GRAPH_VERSION .
            '/1110000000?fields=display_phone_number,verified_name', $api->sent[0]['url']);
        $this->assertNull($api->sent[0]['body']);

        $this->expectException(api_exception::class);
        $this->fake_api(['error' => ['code' => 190, 'message' => 'Fake']])->get_phone_number();
    }

    /**
     * The errors of Meta that the administrator can fix get a message of their own.
     *
     * @dataProvider errors_provider
     * @param int $code Error code of Meta.
     * @param string $errorcode The string of the plugin.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('errors_provider')]
    public function test_errors_of_meta(int $code, string $errorcode): void {
        $api = $this->fake_api(['error' => [
            'message' => 'Fake <b>message</b> from Meta',
            'type' => 'OAuthException',
            'code' => $code,
            'fbtrace_id' => 'FAKE',
        ]]);

        try {
            $api->send_template('34600000000', 'hello_world', 'en_US');
            $this->fail('An error of Meta must throw.');
        } catch (api_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
            $this->assertSame($code, $e->metacode);
            $this->assertStringNotContainsString('<b>', $e->getMessage());
        }
    }

    /**
     * Error codes of Meta and the string each one gets.
     *
     * @return array
     */
    public static function errors_provider(): array {
        return [
            'invalid parameter' => [100, 'error:parameter'],
            'expired token' => [190, 'error:token'],
            'phone not on the list of the test number' => [131030, 'error:recipientnotallowed'],
            'template that does not exist' => [132001, 'error:templatenotfound'],
            'anything else' => [131026, 'error:meta'],
        ];
    }

    /**
     * An answer that is not JSON, or JSON without a message id, is not taken as sent.
     */
    public function test_unexpected_answers(): void {
        foreach (['<html>Bad gateway</html>', json_encode(['messages' => []])] as $answer) {
            try {
                $this->fake_api($answer)->send_template('34600000000', 'hello_world', 'en_US');
                $this->fail('An unexpected answer must throw.');
            } catch (api_exception $e) {
                $this->assertSame('error:unexpected', $e->errorcode);
            }
        }
    }

    /**
     * The client is built from the settings of the site, with the token decrypted.
     */
    public function test_from_config(): void {
        $this->resetAfterTest();

        $this->assertFalse(cloud_api::is_configured());
        try {
            cloud_api::from_config();
            $this->fail('A site without credentials must throw.');
        } catch (api_exception $e) {
            $this->assertSame('error:notconfigured', $e->errorcode);
        }

        set_config('phonenumberid', '1110000000', 'local_oksigeniamarandu');
        set_config('accesstoken', \core\encryption::encrypt('fake-token'), 'local_oksigeniamarandu');
        $this->assertTrue(cloud_api::is_configured());
        $this->assertInstanceOf(cloud_api::class, cloud_api::from_config());

        // A token that was not stored encrypted cannot be used.
        set_config('accesstoken', 'stored-in-clear', 'local_oksigeniamarandu');
        try {
            cloud_api::from_config();
            $this->fail('A token that cannot be decrypted must throw.');
        } catch (api_exception $e) {
            $this->assertSame('error:token', $e->errorcode);
        }
    }
}
