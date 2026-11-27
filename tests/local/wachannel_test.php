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
 * Tests of the channel of WhatsApp: the posts of the channel of Telegram, for whoever publishes them there. Chats,
 * numbers, channels, links and posts are made up.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\wachannel
 * @covers     \local_oksigeniamarandu\local\links
 * @covers     \local_oksigeniamarandu\local\queue
 */
#[\PHPUnit\Framework\Attributes\CoversClass(wachannel::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(links::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(queue::class)]
final class wachannel_test extends \advanced_testcase {
    /** A Wednesday at 11:00 in Madrid: in the hours of review and of the channel. */
    private const WEDNESDAY = 1793181600;

    /** The channel of Telegram of the made-up site. */
    private const CHANNEL = '-1001234567890';

    /** A made-up link of a channel of WhatsApp. */
    private const LINK = 'https://whatsapp.com/channel/0029VaEjemploInventado00';

    /**
     * A site with its bot and its channel, Madrid time, a made-up plugin that posts, and the admin, who reviews on
     * Telegram.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Europe/Madrid');
        set_config('telegramtoken', \core\encryption::encrypt('123456789:' . str_repeat('A', 35)), 'local_oksigeniamarandu');
        set_config('telegrambot', 'example_school_bot', 'local_oksigeniamarandu');
        set_config('telegrambotid', '123456789', 'local_oksigeniamarandu');
        set_config('channelid', self::CHANNEL, 'local_oksigeniamarandu');
        set_config('channeltitle', 'Escuela Ejemplo', 'local_oksigeniamarandu');
        set_config('phonenumberid', '1110000000', 'local_oksigeniamarandu');
        channel::declare_for_tests(['local_example' => ['book' => ['review' => false]]]);
        consent::optin_from_message('tg:555', api::invite('core', 0, (int)get_admin()->id), 'join');
        foreach (['postgap', 'postperday', 'postperweek'] as $name) {
            set_config($name, 0, 'local_oksigeniamarandu');
        }
    }

    /**
     * The tests forget their kinds and their shortener.
     */
    protected function tearDown(): void {
        channel::declare_for_tests([]);
        links::fake_for_tests(null);
        parent::tearDown();
    }

    /**
     * A bot that keeps what it is asked and answers as Telegram.
     *
     * @return telegram_api
     */
    private function bot(): telegram_api {
        return new class ('123456789:fake') extends telegram_api {
            /** @var array The methods called, with their parameters. */
            public $calls = [];

            /**
             * Keeps it and answers.
             *
             * @param string $method The method.
             * @param string $body Its parameters.
             * @return string
             */
            protected function request(string $method, string $body): string {
                $this->calls[] = [$method, json_decode($body, true)];
                return json_encode(['ok' => true, 'result' => $method === 'getChatMemberCount' ? 1
                    : ['message_id' => 40 + count($this->calls)]]);
            }

            /**
             * Keeps a request with a file.
             *
             * @param string $method The method.
             * @param array $fields Its parameters.
             * @param string $name The name of the file.
             * @param string $bytes The file.
             * @return string
             */
            protected function request_file(string $method, array $fields, string $name, string $bytes): string {
                $this->calls[] = [$method, $fields + ['file' => $name . ':' . strlen($bytes)]];
                return json_encode(['ok' => true, 'result' => ['message_id' => 40 + count($this->calls),
                    'animation' => ['file_id' => 'anim1']]]);
            }

            /**
             * The calls of a method.
             *
             * @param string $method The method.
             * @return array Their parameters.
             */
            public function of(string $method): array {
                return array_values(array_map(function ($call) {
                    return $call[1];
                }, array_filter($this->calls, function ($call) use ($method) {
                    return $call[0] === $method;
                })));
            }
        };
    }

    /**
     * A number of WhatsApp that keeps what it would send, and fails a video if told.
     *
     * @return cloud_api
     */
    private function whatsapp(): cloud_api {
        return new class ('1110000000', 'fake-token') extends cloud_api {
            /** @var array What was sent. */
            public $sent = [];

            /** @var bool Whether Meta refuses the video for itself. */
            public $refusevideo = false;

            /**
             * Keeps an upload.
             *
             * @param string $bytes The file.
             * @param string $name Its name.
             * @return string
             */
            public function upload_media(string $bytes, string $name): string {
                $this->sent[] = ['upload', $name, strlen($bytes)];
                return 'media' . count($this->sent);
            }

            /**
             * Keeps an image.
             *
             * @param string $to Number.
             * @param string $mediaid Its id.
             * @param string $caption Its text.
             * @return string
             */
            public function send_image(string $to, string $mediaid, string $caption): string {
                $this->sent[] = ['image', $to, $mediaid, $caption];
                return 'wamid.I' . count($this->sent);
            }

            /**
             * Keeps a video, or refuses it.
             *
             * @param string $to Number.
             * @param string $mediaid Its id.
             * @param string $caption Its text.
             * @return string
             */
            public function send_video(string $to, string $mediaid, string $caption): string {
                if ($this->refusevideo) {
                    throw new api_exception('error:meta', (object)['code' => 131053, 'message' => 'Media upload error'], 131053);
                }
                $this->sent[] = ['video', $to, $mediaid, $caption];
                return 'wamid.V' . count($this->sent);
            }

            /**
             * Keeps a text.
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
             * Keeps a template message.
             *
             * @param string $to Number.
             * @param string $template Name.
             * @param string $language Language.
             * @param array $components Values.
             * @return string
             */
            public function send_template(string $to, string $template, string $language, array $components = []): string {
                $this->sent[] = ['template', $to, $template, $components];
                return 'wamid.P' . count($this->sent);
            }

            /**
             * Keeps a template sent for review.
             *
             * @param string $wabaid Account.
             * @param array $payload Template.
             * @return array
             */
            public function create_template(string $wabaid, array $payload): array {
                return ['id' => '9001', 'status' => 'PENDING', 'category' => 'UTILITY'];
            }
        };
    }

    /**
     * A post of the made-up plugin, with its card and its animation, gone out in the channel of Telegram.
     *
     * @param telegram_api $bot The bot.
     * @param array $content More of its content.
     * @return \stdClass The post.
     */
    private function publish(telegram_api $bot, array $content = []): \stdClass {
        global $CFG, $DB;
        $fixtures = $CFG->wwwroot . '/local/oksigeniamarandu/tests/fixtures/';
        $id = api::channel_post('local_example', 'book', $content + ['title' => 'El libro del mes',
            'text' => 'Un clásico para el verano.', 'url' => 'https://example.org/libro', 'label' => 'Reservarlo',
            'image' => $fixtures . 'card.png', 'animation' => $fixtures . 'animation.mp4'], self::WEDNESDAY, false, 'b1');
        channel::process($bot, self::WEDNESDAY);
        return $DB->get_record(channel::TABLE, ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Without the channel of WhatsApp switched on, a post goes to Telegram as always, and nothing else happens.
     */
    public function test_off(): void {
        global $DB;
        $sink = $this->redirectMessages();
        $post = $this->publish($this->bot());
        $this->assertSame('published', $post->status);
        $this->assertSame(0, $DB->count_records(wachannel::TABLE));
        $this->assertSame(0, $DB->count_records(queue::TABLE, ['notice' => wachannel::NOTICE]));
        $this->assertSame([], $sink->get_messages());
        $this->assertSame(0, wachannel::remind(self::WEDNESDAY + 4 * HOURSECS));
        $this->assertNotContains('local_oksigeniamarandu/' . wachannel::NOTICE, notices::enabled('telegram'));
    }

    /**
     * The robots that look at a link (Meta, the previews of Telegram and WhatsApp, search engines, scripts) are told from
     * the people who tap it, also on a phone whose brand ends in «bot».
     */
    public function test_robots(): void {
        $robots = [
            'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
            'facebookexternalhit/1.1',
            'meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)',
            'WhatsApp/2.23.20.0 A',
            'TelegramBot (like TwitterBot)',
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)',
            'curl/8.5.0',
            'python-requests/2.31.0',
            '',
        ];
        foreach ($robots as $agent) {
            $this->assertTrue(api::is_robot($agent), $agent);
        }
        $people = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 '
                . 'Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Linux; Android 12; CUBOT X50) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile '
                . 'Safari/537.36',
            'Mozilla/5.0 (Linux; Android 13; SM-A145R Build/TP1A.220624.014; wv) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Version/4.0 Chrome/130.0.6723.107 Mobile Safari/537.36',
            'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0',
        ];
        foreach ($people as $agent) {
            $this->assertFalse(api::is_robot($agent), $agent);
        }

        // Without an agent given, that of the request; a HEAD request is a machine.
        $server = $_SERVER;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_USER_AGENT'] = $people[0];
        $this->assertFalse(links::is_robot());
        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        $this->assertTrue(links::is_robot());
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_USER_AGENT'] = $robots[0];
        $this->assertTrue(links::is_robot());
        $_SERVER = $server;
    }

    /**
     * Switched on, a post that goes out in Telegram waits for WhatsApp, and who publishes there hears of it: by the
     * notice of Marandu on what they joined, or in the bell and the app; its link counts apart, with utm_source=whatsapp.
     */
    public function test_published_tells_who_publishes(): void {
        global $DB;
        $other = $this->getDataGenerator()->create_user(['firstname' => 'Luis', 'lastname' => 'Prueba']);
        wachannel::set_enabled(true);
        $this->assertContains('local_oksigeniamarandu/' . wachannel::NOTICE, notices::enabled('telegram'));
        $this->assertContains('local_oksigeniamarandu/' . wachannel::NOTICE, notices::enabled('whatsapp'));
        wachannel::set_people([(int)get_admin()->id, (int)$other->id]);
        set_config('wachannelname', 'Escuela Ejemplo', 'local_oksigeniamarandu');
        set_config('utm', 1, 'local_oksigeniamarandu');
        $sink = $this->redirectMessages();

        $post = $this->publish($this->bot());
        $wa = $DB->get_record(wachannel::TABLE, ['postid' => $post->id], '*', MUST_EXIST);
        $this->assertSame(['waiting', (int)self::WEDNESDAY], [$wa->status, (int)$wa->timecreated]);
        $this->assertSame((new \moodle_url('/local/oksigeniamarandu/go.php', ['w' => $wa->token]))->out(false), $wa->link);

        // Whoever has nothing joined: in the bell and the app, with the icon of Marandu and the page.
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame(
            ['local_oksigeniamarandu', 'wachannel', (int)$other->id],
            [$messages[0]->component, $messages[0]->eventtype, (int)$messages[0]->useridto]
        );
        $this->assertStringContainsString('«El libro del mes»', $messages[0]->subject);
        $this->assertStringEndsWith('/local/oksigeniamarandu/wachannel.php?t=' . $wa->token, $messages[0]->contexturl);

        // Whoever has Telegram: the notice, with the post ready for WhatsApp inside it and a card for Telegram.
        $row = $DB->get_record(queue::TABLE, ['notice' => wachannel::NOTICE], '*', MUST_EXIST);
        $this->assertSame([(int)$wa->id, wachannel::url($wa)->out(false)], [(int)$row->itemid, $row->url]);
        $this->assertSame([get_site()->fullname, 'El libro del mes'], json_decode($row->params, true));
        $rich = json_decode($row->rich, true);
        $this->assertSame(
            "*El libro del mes*\n\nUn clásico para el verano.\n\n\u{1F449} Reservarlo: " . $wa->link,
            $rich['caption']
        );
        $this->assertStringEndsWith('/tests/fixtures/animation.mp4', $rich['video']);
        $this->assertSame(get_string('wachannel:title', 'local_oksigeniamarandu'), $rich['title']);

        $bot = $this->bot();
        $this->assertSame(1, queue::process(null, self::WEDNESDAY + MINSECS, $bot)['sent']);
        $card = $bot->of('sendRichMessage')[0];
        $this->assertSame('555', $card['chat_id']);
        $blocks = (is_string($card['rich_message']) ? json_decode($card['rich_message'], true) : $card['rich_message'])['blocks'];
        $this->assertSame(get_string('wachannel:title', 'local_oksigeniamarandu'), $blocks[0]['text']);
        $this->assertStringContainsString('/local/oksigeniamarandu/go.php?k=', end($blocks)['buttons'][0]['url']);

        // The link in WhatsApp counts apart from that of Telegram, and says where the visit came from.
        $this->assertSame(
            'https://example.org/libro?utm_source=whatsapp&utm_medium=channel&utm_campaign=book',
            wachannel::click($wa->token)
        );
        $this->assertSame(['1', '0'], [$DB->get_field(wachannel::TABLE, 'clicks', ['id' => $wa->id]),
            $DB->get_field(channel::TABLE, 'clicks', ['id' => $post->id])]);
        // A robot that looks at the link goes to the same page, and does not count.
        $this->assertSame(
            'https://example.org/libro?utm_source=whatsapp&utm_medium=channel&utm_campaign=book',
            wachannel::click($wa->token, false)
        );
        $this->assertNotNull(channel::click($post->token, false));
        $this->assertSame(['1', '0'], [$DB->get_field(wachannel::TABLE, 'clicks', ['id' => $wa->id]),
            $DB->get_field(channel::TABLE, 'clicks', ['id' => $post->id])]);
        $this->assertNull(wachannel::click('nothing'));
        $stats = api::channel_stats('local_example', 'book', 'b1')[0]['whatsapp'];
        $this->assertSame(['status' => 'waiting', 'timepublished' => null, 'clicks' => 1], $stats);

        // The same post does not wait twice.
        $this->assertSame((int)$wa->id, wachannel::published($post, self::WEDNESDAY + HOURSECS));

        // The dashboard («Your work») says what waits for WhatsApp, with its page.
        $alerts = work::for_user((int)get_admin()->id, self::WEDNESDAY + HOURSECS)[0]['alerts'];
        $this->assertContains([
            'text' => get_string('work:wachannel', 'local_oksigeniamarandu', 'El libro del mes'),
            'url' => wachannel::url($wa)->out(false),
            'level' => 'today',
            'due' => self::WEDNESDAY,
        ], $alerts);
    }

    /**
     * What its plugin marked for Telegram says WhatsApp in the version for WhatsApp, unless the link must go as it is.
     */
    public function test_link_marked_for_telegram(): void {
        global $DB;
        wachannel::set_enabled(true);
        set_config('utm', 1, 'local_oksigeniamarandu');
        $post = $this->publish($this->bot());
        $wa = $DB->get_record(wachannel::TABLE, ['postid' => $post->id], '*', MUST_EXIST);
        $content = ['title' => 'El libro del mes', 'url' => 'https://example.org/libro?id=telegram4&d=telegram#telegram'];
        $DB->set_field(channel::TABLE, 'content', json_encode($content), ['id' => $post->id]);
        $this->assertSame(
            'https://example.org/libro?id=telegram4&d=whatsapp&utm_source=whatsapp&utm_medium=channel' .
                '&utm_campaign=book#telegram',
            wachannel::click($wa->token)
        );
        $this->assertStringContainsString('&d=telegram&', channel::click($post->token));
        $DB->set_field(channel::TABLE, 'content', json_encode($content + ['utm' => false]), ['id' => $post->id]);
        $this->assertSame($content['url'], wachannel::click($wa->token));
    }

    /**
     * The link of a post in WhatsApp goes through the shortener of the site, as in Telegram: with the link of Marandu.
     */
    public function test_shortened_link(): void {
        global $DB;
        wachannel::set_enabled(true);
        $asked = [];
        links::fake_for_tests(function (array $params) use (&$asked) {
            $asked[] = $params['url'];
            return json_encode(['shorturl' => 'https://sho.rt/' . count($asked)]);
        });
        $post = $this->publish($this->bot());
        $wa = $DB->get_record(wachannel::TABLE, ['postid' => $post->id], '*', MUST_EXIST);
        $this->assertSame('https://sho.rt/2', $wa->link);
        $this->assertStringEndsWith('/local/oksigeniamarandu/go.php?w=' . $wa->token, $asked[1]);
        $this->assertStringEndsWith('https://sho.rt/2', wachannel::text($wa, $post));
    }

    /**
     * The shortener gets its fields as a form, urlencoded and joined with &: some take nothing else.
     */
    public function test_shortener_takes_a_form(): void {
        set_config('shortenersignature', \core\encryption::encrypt('firma-de-ejemplo'), 'local_oksigeniamarandu');
        $bodies = [];
        links::fake_for_tests(function (array $params, string $body) use (&$bodies) {
            $bodies[] = $body;
            return json_encode(['shorturl' => 'https://sho.rt/x']);
        });
        $url = 'https://example.org/go.php?p=abc&q=1 2';
        $this->assertSame('https://sho.rt/x', links::shorten($url));
        $this->assertCount(1, $bodies);
        $this->assertStringNotContainsString('&amp;', $bodies[0]);
        parse_str($bodies[0], $fields);
        $this->assertSame(
            ['action' => 'shorturl', 'format' => 'json', 'url' => $url, 'signature' => 'firma-de-ejemplo'],
            $fields
        );
        $this->assertSame('action=shorturl&format=json&url=' . urlencode($url) . '&signature=firma-de-ejemplo', $bodies[0]);
    }

    /**
     * «It is published»: who and when, once; what has not gone does not go, and on Telegram the notice says who did it.
     */
    public function test_done(): void {
        global $DB;
        wachannel::set_enabled(true);
        $post = $this->publish($this->bot());
        $wa = $DB->get_record(wachannel::TABLE, ['postid' => $post->id], '*', MUST_EXIST);
        $bot = $this->bot();
        queue::process(null, self::WEDNESDAY + MINSECS, $bot);
        // One more notice still in the queue, for a number that has not gone yet.
        $DB->insert_record(queue::TABLE, (object)['contactid' => 1, 'component' => 'local_oksigeniamarandu',
            'notice' => wachannel::NOTICE, 'params' => '[]', 'itemid' => $wa->id, 'area' => '', 'token' => 'tokenqueued',
            'urgent' => 0, 'status' => 'queued', 'timequeued' => self::WEDNESDAY, 'timeexpires' => self::WEDNESDAY + DAYSECS]);

        $bot = $this->bot();
        $this->assertTrue(wachannel::done($wa, (int)get_admin()->id, $bot, self::WEDNESDAY + HOURSECS));
        $this->assertFalse(wachannel::done($wa, (int)get_admin()->id, $bot, self::WEDNESDAY + 2 * HOURSECS));
        $wa = $DB->get_record(wachannel::TABLE, ['id' => $wa->id]);
        $this->assertSame(
            ['published', (int)get_admin()->id, self::WEDNESDAY + HOURSECS],
            [$wa->status, (int)$wa->doneby, (int)$wa->timedone]
        );
        $this->assertSame('skipped', $DB->get_field(queue::TABLE, 'status', ['token' => 'tokenqueued']));
        $edit = $bot->of('editMessageText');
        $this->assertCount(1, $edit);
        $this->assertSame('555', $edit[0]['chat_id']);
        $this->assertSame(get_string('wachannel:tg:done', 'local_oksigeniamarandu', (object)['title' => 'El libro del mes',
            'name' => fullname(get_admin())]), $edit[0]['text']);
        $stats = api::channel_stats('local_example', 'book', 'b1')[0]['whatsapp'];
        $this->assertSame(self::WEDNESDAY + HOURSECS, $stats['timepublished']);
        // Nothing waits any more: no reminder.
        $this->assertSame(0, wachannel::remind(self::WEDNESDAY + 4 * HOURSECS));
    }

    /**
     * Nobody marked it in three hours: one reminder, in the hours of the channel, to the same people; never two.
     */
    public function test_reminder_once(): void {
        global $DB;
        $other = $this->getDataGenerator()->create_user();
        wachannel::set_enabled(true);
        wachannel::set_people([(int)get_admin()->id, (int)$other->id]);
        $sink = $this->redirectMessages();
        $post = $this->publish($this->bot());
        $sink->clear();
        $this->assertSame(1, $DB->count_records(queue::TABLE, ['notice' => wachannel::NOTICE]));

        $this->assertSame(0, wachannel::remind(self::WEDNESDAY + 2 * HOURSECS));
        $this->assertSame(1, wachannel::remind(self::WEDNESDAY + 3 * HOURSECS));
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame((int)$other->id, (int)$messages[0]->useridto);
        $this->assertStringContainsString('«El libro del mes»', $messages[0]->subject);
        $rows = $DB->get_records(queue::TABLE, ['notice' => wachannel::NOTICE], 'id');
        $this->assertCount(2, $rows);
        // The reminder is the notice alone: the post went with the first one.
        $this->assertNull(end($rows)->rich);
        $this->assertSame(0, wachannel::remind(self::WEDNESDAY + 5 * HOURSECS));
        $this->assertCount(1, $sink->get_messages());

        // Out of the hours of the channel, it waits for them.
        $DB->set_field(wachannel::TABLE, 'timereminded', null, ['postid' => $post->id]);
        $night = self::WEDNESDAY + 11 * HOURSECS;
        $this->assertFalse(channel::open('post', $night));
        $this->assertSame(0, wachannel::remind($night));
        // Past its moment, never.
        $this->assertSame(0, wachannel::remind(self::WEDNESDAY + 4 * DAYSECS));
    }

    /**
     * A post taken out of Telegram no longer waits for WhatsApp; if it was published there, whoever did it is told.
     */
    public function test_withdrawn(): void {
        global $DB;
        wachannel::set_enabled(true);
        $sink = $this->redirectMessages();
        $post = $this->publish($this->bot());
        api::channel_cancel('local_example', 'book', 'b1');
        channel::process($this->bot(), self::WEDNESDAY + MINSECS);
        $this->assertSame('withdrawn', $DB->get_field(wachannel::TABLE, 'status', ['postid' => $post->id]));
        $this->assertSame([], $sink->get_messages());

        $second = $this->publish($this->bot(), ['title' => 'Otro libro']);
        $this->assertNotEquals($post->id, $second->id);
        $wa = $DB->get_record(wachannel::TABLE, ['postid' => $second->id], '*', MUST_EXIST);
        wachannel::done($wa, (int)get_admin()->id);
        api::channel_cancel('local_example', 'book', 'b1');
        channel::process($this->bot(), self::WEDNESDAY + 2 * MINSECS);
        $this->assertSame('withdrawn', $DB->get_field(wachannel::TABLE, 'status', ['id' => $wa->id]));
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame([(int)get_admin()->id, 'wachannel'], [(int)$messages[0]->useridto, $messages[0]->eventtype]);
        $this->assertStringContainsString('«Otro libro»', $messages[0]->subject);
    }

    /**
     * On WhatsApp, inside the 24 hours, the post itself to forward: its video with its text below, and the notice with
     * its link after it; a video Meta refuses gives way to the image, and a text longer than a caption goes on its own.
     * Outside the 24 hours, the template with its button.
     */
    public function test_forward_by_whatsapp(): void {
        global $DB;
        wachannel::set_enabled(true);
        $phone = '34600000009';
        consent::optin_from_message($phone, api::invite('core', 0, (int)get_admin()->id), 'wamid.FAKE');
        $DB->set_field(consent::CONTACTS, 'lastinbound', self::WEDNESDAY - HOURSECS, ['phone' => $phone]);
        $post = $this->publish($this->bot());
        $wa = $DB->get_record(wachannel::TABLE, ['postid' => $post->id], '*', MUST_EXIST);
        $api = $this->whatsapp();
        $counts = queue::process($api, self::WEDNESDAY + MINSECS, $this->bot());
        $this->assertSame(2, $counts['sent']);
        $this->assertSame(['upload', 'post.mp4'], array_slice($api->sent[0], 0, 2));
        $this->assertSame(['video', $phone, 'media1', wachannel::text($wa, $post)], $api->sent[1]);
        $this->assertSame('text', $api->sent[2][0]);
        $this->assertStringContainsString('«El libro del mes»', $api->sent[2][2]);
        $this->assertStringContainsString('/local/oksigeniamarandu/go.php?k=', $api->sent[2][2]);
        $contactid = (int)$DB->get_field(consent::CONTACTS, 'id', ['phone' => $phone]);
        $this->assertSame('wamid.V2', $DB->get_field(queue::TABLE, 'wamid', ['contactid' => $contactid,
            'notice' => wachannel::NOTICE]));

        // The video refused: the image, with the text below. A text longer than a caption: after the image, on its own.
        // The video went up before and is used again; the image goes up now.
        $notice = notices::get('local_oksigeniamarandu', wachannel::NOTICE);
        $content = json_decode($post->content, true);
        $long = str_repeat('Texto largo. ', 100);
        queue::add(
            $contactid,
            $notice,
            ['Escuela', 'Libro'],
            'https://www.example.com/x.php',
            false,
            DAYSECS,
            self::WEDNESDAY,
            rich::clean(['caption' => $long, 'video' => $content['animation'], 'image' => $content['image']])
        );
        $api = $this->whatsapp();
        $api->refusevideo = true;
        $this->assertSame(1, queue::process($api, self::WEDNESDAY + 2 * MINSECS, null)['sent']);
        $this->assertSame(['upload', 'image', 'text', 'text'], array_column($api->sent, 0));
        $this->assertSame(['post.png', ''], [$api->sent[0][1], $api->sent[1][3]]);
        $this->assertSame(trim($long), $api->sent[2][2]);
        $this->assertDebuggingCalled();

        // Outside the 24 hours: the template, with the button to its page.
        $DB->set_field(consent::CONTACTS, 'lastinbound', self::WEDNESDAY - 3 * DAYSECS, ['id' => $contactid]);
        templates::submit($this->whatsapp(), '2220000000', $notice, current_language());
        templates::update_from_meta(notices::template_name($notice), current_language(), 'APPROVED');
        queue::add(
            $contactid,
            $notice,
            ['Escuela', 'Libro'],
            'https://www.example.com/x.php',
            false,
            DAYSECS,
            self::WEDNESDAY,
            rich::clean(['caption' => 'Para reenviar', 'image' => $content['image']])
        );
        $api = $this->whatsapp();
        $this->assertSame(1, queue::process($api, self::WEDNESDAY + 3 * MINSECS, null)['sent']);
        $this->assertSame(['template', $phone, notices::template_name($notice)], array_slice($api->sent[0], 0, 3));
        $this->assertCount(1, $api->sent);
    }

    /**
     * Made with artificial intelligence, only when it is: the plugin says it of its own image ('ai' => true), or the
     * template of its card ("ai": true in plantilla.json); those of the house are drawn with code and never say it.
     */
    public function test_ai(): void {
        global $DB;
        wachannel::set_enabled(true);
        $post = $this->publish($this->bot());
        $this->assertFalse(wachannel::ai($post));
        $id = api::channel_post(
            'local_example',
            'book',
            ['title' => 'Cartel del festival', 'ai' => true],
            self::WEDNESDAY,
            false,
            'b2'
        );
        $made = $DB->get_record(channel::TABLE, ['id' => $id]);
        $this->assertTrue(json_decode($made->content, true)['ai']);
        $this->assertTrue(wachannel::ai($made));
        $this->assertFalse(wachannel::ai((object)['content' => json_encode(['title' => 'x', 'ai' => false])]));
        foreach (array_merge(cards::TYPES, cards::CONFIRMATIONS) as $type) {
            $this->assertFalse(cards::with_ai($type), $type);
        }
        $this->assertFalse(cards::with_ai('none'));
        $this->assertTrue(cards::says_ai(['ai' => true, 'zones' => []]));
        $this->assertFalse(cards::says_ai(['ai' => 'yes']));
        $this->assertFalse(wachannel::ai((object)['content' => json_encode(['title' => 'x',
            'card' => ['type' => 'book', 'zones' => []]])]));
    }

    /**
     * Who publishes: the people chosen; without them, whoever reviews on Telegram; without anybody there, whoever may
     * review on the page. They, whoever reviews and whoever runs the site open the page of a post.
     */
    public function test_people(): void {
        $other = $this->getDataGenerator()->create_user();
        $this->assertSame([(int)get_admin()->id], array_map(function ($user) {
            return (int)$user->id;
        }, wachannel::people()));
        wachannel::set_people([(int)$other->id, 0, (int)$other->id]);
        $this->assertSame([(int)$other->id], wachannel::chosen());
        $this->assertTrue(wachannel::may_publish((int)$other->id));
        $this->assertTrue(wachannel::may_publish((int)get_admin()->id));
        $stranger = $this->getDataGenerator()->create_user();
        $this->assertFalse(wachannel::may_publish((int)$stranger->id));
        api::account_reassigned((int)$other->id);
        $this->assertSame([], wachannel::chosen());
        $this->assertSame(
            'Channel of WhatsApp: off; published by ' . fullname(get_admin()) . ' (whoever reviews)',
            setup::whatsapp_channel()
        );
    }
}
