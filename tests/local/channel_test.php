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
 * Tests of the channel of Telegram of the site. Chats, channels and posts are made up.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\channel
 */
#[\PHPUnit\Framework\Attributes\CoversClass(channel::class)]
final class channel_test extends \advanced_testcase {
    /** A Wednesday at 11:00 in Madrid: in the hours of review and of the channel. */
    private const WEDNESDAY = 1793181600;

    /** The channel of the made-up site. */
    private const CHANNEL = '-1001234567890';

    /**
     * A site with its bot and its channel, Madrid time, a made-up plugin that posts and the admin as reviewer.
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
        channel::declare_for_tests(['local_example' => [
            'open_day' => [],
            'book' => ['review' => false],
        ]]);
        consent::optin_from_message('tg:555', api::invite('core', 0, (int)get_admin()->id), 'join');
    }

    /**
     * The tests forget their kinds.
     */
    protected function tearDown(): void {
        channel::declare_for_tests([]);
        parent::tearDown();
    }

    /**
     * A bot that keeps what it is asked and answers as Telegram, or with the error it is given for a method.
     *
     * @return telegram_api
     */
    private function bot(): telegram_api {
        return new class ('123456789:fake') extends telegram_api {
            /** @var array The methods called, with their parameters. */
            public $calls = [];

            /** @var array What to answer, by method: a result, or ['error' => [code, description]]. */
            public $answers = [];

            /**
             * Keeps it and answers.
             *
             * @param string $method The method.
             * @param string $body Its parameters.
             * @return string
             */
            protected function request(string $method, string $body): string {
                $this->calls[] = [$method, json_decode($body, true)];
                if ($method === 'getChatMemberCount' && !isset($this->answers[$method])) {
                    return '{"ok":true,"result":1}';
                }
                $answer = $this->answers[$method] ?? ['message_id' => 40 + count($this->calls)];
                if (is_array($answer) && isset($answer['error'])) {
                    return json_encode(['ok' => false, 'error_code' => $answer['error'][0],
                        'description' => $answer['error'][1]]);
                }
                return json_encode(['ok' => true, 'result' => $answer]);
            }

            /**
             * Keeps a request with a file, as Telegram would take it.
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
     * What Telegram sends when the bot is made an administrator of a channel, or loses it.
     *
     * @param string $id The channel.
     * @param bool $canpost Whether it can post.
     * @return array
     */
    private function member(string $id, bool $canpost): array {
        return ['chat' => ['id' => (int)$id, 'type' => 'channel', 'title' => 'Escuela Ejemplo', 'username' => 'escuelaejemplo'],
            'new_chat_member' => ['user' => ['id' => 123456789], 'status' => $canpost ? 'administrator' : 'left',
                'can_post_messages' => $canpost]];
    }

    /**
     * The channel shows up when Telegram says the bot can post in it, is taken after checking it, and says when the bot
     * cannot post any more.
     */
    public function test_connect(): void {
        channel::disconnect();
        $this->assertNull(channel::current());
        $this->assertFalse(api::channel_ready());
        telegram::handle(['my_chat_member' => $this->member(self::CHANNEL, true)]);
        $this->assertSame(['Escuela Ejemplo', 'escuelaejemplo'], [channel::candidate()->title, channel::candidate()->username]);

        $bot = $this->bot();
        $bot->answers['getChat'] = ['id' => (int)self::CHANNEL, 'type' => 'channel', 'title' => 'Escuela Ejemplo',
            'username' => 'escuelaejemplo'];
        $bot->answers['getChatMember'] = ['status' => 'administrator', 'can_post_messages' => true];
        $channel = channel::connect($bot, 'https://t.me/escuelaejemplo');
        $this->assertSame([self::CHANNEL, 'escuelaejemplo', ''], [$channel->id, $channel->username, $channel->problem]);
        $this->assertSame('@escuelaejemplo', $bot->of('getChat')[0]['chat_id']);
        $this->assertNull(channel::candidate());
        $this->assertTrue(api::channel_ready());

        channel::member_changed($this->member(self::CHANNEL, false));
        $this->assertSame('norights', channel::current()->problem);
        channel::member_changed($this->member(self::CHANNEL, true));
        $this->assertSame('', channel::current()->problem);

        $bot->answers['getChatMember'] = ['status' => 'administrator', 'can_post_messages' => false];
        try {
            channel::connect($bot, '@escuelaejemplo');
            $this->fail('A channel where the bot cannot post is not taken');
        } catch (\moodle_exception $e) {
            $this->assertSame('channel:notadmin', $e->errorcode);
        }
        $bot->answers['getChat'] = ['id' => 555, 'type' => 'private'];
        $this->expectException(\moodle_exception::class);
        channel::connect($bot, '555');
    }

    /**
     * A post goes to review or straight to its time as the site says of its kind; it is changed while it waits, and not
     * posted twice; without a channel or with its kind off, it does not go.
     */
    public function test_post(): void {
        global $DB;
        $open = ['title' => 'Jornada de puertas abiertas', 'text' => "El sábado 14, de 10:00 a 13:00.\nCon visita.",
            'url' => new \moodle_url('/local/example/visit.php'), 'label' => 'Reservar visita'];
        $id = api::channel_post('local_example', 'open_day', $open, null, false, 'day14');
        $post = $DB->get_record(channel::TABLE, ['id' => $id]);
        $this->assertSame('review', $post->status);
        $this->assertSame(['El sábado 14, de 10:00 a 13:00.', 'Con visita.'], json_decode($post->content, true)['text']);

        $changed = api::channel_post('local_example', 'open_day', ['title' => 'Jornada, cambiada'], null, false, 'day14');
        $this->assertSame($id, $changed);
        $content = json_decode($DB->get_field(channel::TABLE, 'content', ['id' => $id]), true);
        $this->assertSame('Jornada, cambiada', $content['title']);

        $book = api::channel_post('local_example', 'book', ['title' => 'El libro del mes'], null, false, 'b1');
        $this->assertSame('ready', $DB->get_field(channel::TABLE, 'status', ['id' => $book]));
        $DB->set_field(channel::TABLE, 'status', 'published', ['id' => $book]);
        $this->assertSame(0, api::channel_post('local_example', 'book', ['title' => 'El libro del mes'], null, false, 'b1'));

        channel::set_policies(['local_example/book' => 'off', 'local_example/open_day' => 'auto', 'local_example/none' => 'auto']);
        $this->assertSame(0, api::channel_post('local_example', 'book', ['title' => 'Otro libro']));
        $this->assertSame('auto', channel::policy('local_example/open_day'));
        $this->assertSame('off', channel::policy('local_example/none'));

        channel::disconnect();
        $this->assertSame(0, api::channel_post('local_example', 'open_day', ['title' => 'Sin canal']));

        foreach (
            [[], ['title' => 'x', 'url' => 'javascript:alert(1)'], ['title' => 'x', 'colour' => 'red'],
                ['title' => 'x', 'animation' => 'https://example.org/a.mp4']] as $bad
        ) {
            try {
                api::channel_post('local_example', 'open_day', $bad);
                $this->fail('Not a valid post');
            } catch (\coding_exception $e) {
                $this->assertStringContainsString('post', $e->getMessage());
            }
        }
        $this->expectException(\coding_exception::class);
        api::channel_post('local_example', 'undeclared', ['title' => 'x']);
    }

    /**
     * The review: the post as it will look and the question, to whoever reviews, in the hours of review; their answer
     * decides, only once, and the post goes to the channel without sound, with the link that counts the visits.
     */
    public function test_review_and_post(): void {
        global $DB;
        $id = api::channel_post('local_example', 'open_day', ['title' => 'Jornada de puertas abiertas',
            'url' => new \moodle_url('/local/example/visit.php'), 'label' => 'Reservar visita'], self::WEDNESDAY - HOURSECS);
        $bot = $this->bot();
        $this->assertSame(1, channel::process($bot, self::WEDNESDAY)['reviewed']);
        $post = $DB->get_record(channel::TABLE, ['id' => $id]);
        [$preview, $question] = [$bot->of('sendRichMessage')[0], $bot->of('sendMessage')[0]];
        $this->assertSame(['555', '555'], [$preview['chat_id'], $question['chat_id']]);
        $this->assertSame(
            ['p:' . $post->token . ':pub', 'p:' . $post->token . ':skip'],
            array_column($question['reply_markup']['inline_keyboard'][0], 'callback_data')
        );
        $this->assertSame(0, channel::process($bot, self::WEDNESDAY + MINSECS)['reviewed']);

        $press = function (int $chat, string $choice) use ($post): array {
            return ['callback_query' => ['id' => 'cb', 'data' => 'p:' . $post->token . ':' . $choice, 'from' => ['id' => $chat],
                'message' => ['message_id' => 9, 'chat' => ['id' => $chat, 'type' => 'private']]]];
        };
        $this->assertSame('answerCallbackQuery', telegram::handle($press(556, 'pub'), $bot)['method']);
        $this->assertSame('review', $DB->get_field(channel::TABLE, 'status', ['id' => $id]));
        $this->assertSame('answerCallbackQuery', telegram::handle($press(555, 'pub'), $bot)['method']);
        $this->assertSame('ready', $DB->get_field(channel::TABLE, 'status', ['id' => $id]));
        $this->assertEquals(get_admin()->id, $DB->get_field(channel::TABLE, 'decidedby', ['id' => $id]));
        $this->assertSame('555', $bot->of('editMessageText')[0]['chat_id']);
        $this->assertSame(
            get_string('channel:review:already', 'local_oksigeniamarandu'),
            telegram::handle($press(555, 'skip'), $bot)['text']
        );

        $bot = $this->bot();
        $this->assertSame(1, channel::process($bot, self::WEDNESDAY + 2 * MINSECS)['published']);
        $sent = $bot->of('sendRichMessage')[0];
        $this->assertSame([self::CHANNEL, true], [$sent['chat_id'], $sent['disable_notification']]);
        $button = end($sent['rich_message']['blocks'])['buttons'][0];
        $this->assertSame('Reservar visita', $button['text']);
        $this->assertStringEndsWith('/local/oksigeniamarandu/go.php?p=' . $post->token, $button['url']);
        $this->assertSame('published', $DB->get_field(channel::TABLE, 'status', ['id' => $id]));

        $this->assertStringEndsWith('/local/example/visit.php', channel::click($post->token));
        $this->assertEquals(1, $DB->get_field(channel::TABLE, 'clicks', ['id' => $id]));
        $this->assertNull(channel::click('nothing'));
    }

    /**
     * Out of the hours of review nothing reaches whoever reviews, and out of those of the channel nothing is posted; what
     * is urgent goes at any hour, with sound.
     */
    public function test_hours(): void {
        global $DB;
        $saturday = self::WEDNESDAY + 3 * DAYSECS;
        $night = self::WEDNESDAY + 12 * HOURSECS;
        $this->assertFalse(channel::open('review', $saturday));
        $this->assertTrue(channel::open('post', $saturday));
        $this->assertFalse(channel::open('post', $night));

        api::channel_post('local_example', 'open_day', ['title' => 'Jornada'], $saturday);
        $urgent = api::channel_post('local_example', 'open_day', ['title' => 'Mañana, sin clases'], $saturday, true);
        $bot = $this->bot();
        $this->assertSame(1, channel::process($bot, $saturday)['reviewed']);
        $this->assertSame('555', $bot->of('sendMessage')[0]['chat_id']);
        $this->assertNotEmpty($DB->get_field(channel::TABLE, 'timereview', ['id' => $urgent]));

        $book = api::channel_post('local_example', 'book', ['title' => 'El libro del mes'], $night);
        $closure = api::channel_post('local_example', 'book', ['title' => 'Cerrado mañana'], $night, true);
        $bot = $this->bot();
        $this->assertSame(1, channel::process($bot, $night)['published']);
        $this->assertSame([self::CHANNEL, false], [$bot->of('sendRichMessage')[0]['chat_id'],
            $bot->of('sendRichMessage')[0]['disable_notification']]);
        $this->assertSame(['ready', 'published'], [$DB->get_field(channel::TABLE, 'status', ['id' => $book]),
            $DB->get_field(channel::TABLE, 'status', ['id' => $closure])]);

        // Said yes at night: it goes when the channel opens in the morning, and the review says so; urgent, at once.
        $morning = self::WEDNESDAY + 21 * HOURSECS;
        $this->assertSame($morning, channel::next_open('post', $night));
        $this->assertSame(self::WEDNESDAY, channel::next_open('post', self::WEDNESDAY));
        $review = api::channel_post('local_example', 'open_day', ['title' => 'Salida al museo'], $night);
        $DB->set_field(channel::TABLE, 'reviews', json_encode(['555' => [1, 2]]), ['id' => $review]);
        $bot = $this->bot();
        channel::decide($DB->get_record(channel::TABLE, ['id' => $review]), true, (int)get_admin()->id, $bot, $night);
        $this->assertStringContainsString(
            userdate($morning, get_string('strftimedaydatetime', 'langconfig')),
            $bot->of('editMessageText')[0]['text']
        );
        $DB->set_field(channel::TABLE, 'status', 'review', ['id' => $urgent]);
        $DB->set_field(channel::TABLE, 'timeplanned', $night - MINSECS, ['id' => $urgent]);
        $DB->set_field(channel::TABLE, 'reviews', json_encode(['555' => [3, 4]]), ['id' => $urgent]);
        channel::decide($DB->get_record(channel::TABLE, ['id' => $urgent]), true, (int)get_admin()->id, $bot, $night);
        $this->assertStringContainsString(
            get_string('channel:review:soon', 'local_oksigeniamarandu'),
            $bot->of('editMessageText')[1]['text']
        );
    }

    /**
     * Taken back, a post does not go, or goes out of the channel; one whose time passed does not go; and when the bot
     * cannot post any more, the channel says so and the posts wait.
     */
    public function test_cancel_expire_and_problem(): void {
        global $DB;
        $waiting = api::channel_post('local_example', 'book', ['title' => 'Uno'], self::WEDNESDAY + DAYSECS, false, 'one');
        $out = api::channel_post('local_example', 'book', ['title' => 'Dos'], self::WEDNESDAY, false, 'two');
        $old = api::channel_post(
            'local_example',
            'book',
            ['title' => 'Tres'],
            self::WEDNESDAY + DAYSECS,
            false,
            '',
            self::WEDNESDAY - MINSECS
        );
        $bot = $this->bot();
        $counts = channel::process($bot, self::WEDNESDAY);
        $this->assertSame([1, 1], [$counts['published'], $counts['expired']]);
        $this->assertSame('expired', $DB->get_field(channel::TABLE, 'status', ['id' => $old]));

        $this->assertSame(1, api::channel_cancel('local_example', 'book', 'one'));
        $this->assertSame(1, api::channel_cancel('local_example', 'book', 'two'));
        $this->assertSame(1, channel::process($bot, self::WEDNESDAY + MINSECS)['removed']);
        $this->assertSame(['cancelled', 'removed'], [$DB->get_field(channel::TABLE, 'status', ['id' => $waiting]),
            $DB->get_field(channel::TABLE, 'status', ['id' => $out])]);
        $this->assertSame(self::CHANNEL, $bot->of('deleteMessage')[0]['chat_id']);

        $next = api::channel_post('local_example', 'book', ['title' => 'Cuatro'], self::WEDNESDAY);
        $bot = $this->bot();
        $bot->answers['sendRichMessage'] = ['error' => [403, 'Forbidden: bot is not a member of the channel chat']];
        $this->assertSame(0, channel::process($bot, self::WEDNESDAY + 2 * MINSECS)['published']);
        $this->assertSame('norights', channel::current()->problem);
        $this->assertSame('ready', $DB->get_field(channel::TABLE, 'status', ['id' => $next]));
    }

    /**
     * The people of the channel are counted once a day, to see how it grows.
     */
    public function test_members(): void {
        $bot = $this->bot();
        $bot->answers['getChatMemberCount'] = 120;
        channel::process($bot, self::WEDNESDAY - WEEKSECS);
        $bot->answers['getChatMemberCount'] = 135;
        channel::process($bot, self::WEDNESDAY);
        channel::process($bot, self::WEDNESDAY + MINSECS);
        $this->assertCount(2, $bot->of('getChatMemberCount'));
        $members = channel::members(self::WEDNESDAY);
        $this->assertSame([135, 120], [$members->now, $members->before]);
    }

    /**
     * What is written in the announcements forum of the front page goes by itself, after the time to edit it, or at
     * once if it is sent now; deleted, it does not go. Other forums do not count.
     */
    public function test_news_forum(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/forum/lib.php');
        $news = forum_get_course_forum(SITEID, 'news');
        $cm = get_coursemodule_from_instance('forum', $news->id, SITEID);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $user = $this->getDataGenerator()->create_user();
        $created = function (\stdClass $discussion, \stdClass $forum, int $cmid): void {
            \mod_forum\event\discussion_created::create(['context' => \context_module::instance($cmid),
                'objectid' => $discussion->id, 'other' => ['forumid' => $forum->id]])->trigger();
        };

        $discussion = $generator->create_discussion(['course' => SITEID, 'forum' => $news->id, 'userid' => $user->id,
            'name' => 'Jornada de puertas abiertas', 'message' => '<p>El sábado 14, de 10:00 a 13:00.</p>']);
        $created($discussion, $news, (int)$cm->id);
        $post = $DB->get_record(channel::TABLE, ['sourcekey' => 'd' . $discussion->id]);
        $this->assertSame(['local_oksigeniamarandu', 'news', 'ready', '0'], [$post->component, $post->kind, $post->status,
            (string)$post->urgent]);
        $content = json_decode($post->content, true);
        $this->assertSame(['Jornada de puertas abiertas', ['El sábado 14, de 10:00 a 13:00.']], [$content['title'],
            $content['text']]);
        $this->assertGreaterThanOrEqual((int)$discussion->timemodified + 1800, (int)$post->timeplanned);

        $now = $generator->create_discussion(['course' => SITEID, 'forum' => $news->id, 'userid' => $user->id,
            'name' => 'Mañana no hay clase', 'message' => 'Por la alerta.', 'mailnow' => 1]);
        $created($now, $news, (int)$cm->id);
        $this->assertSame('1', (string)$DB->get_field(channel::TABLE, 'urgent', ['sourcekey' => 'd' . $now->id]));

        \mod_forum\event\discussion_deleted::create(['context' => \context_module::instance($cm->id),
            'objectid' => $discussion->id, 'other' => ['forumid' => $news->id]])->trigger();
        $this->assertSame('cancelled', $DB->get_field(channel::TABLE, 'status', ['sourcekey' => 'd' . $discussion->id]));

        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $other = $generator->create_discussion(['course' => $course->id, 'forum' => $forum->id, 'userid' => $user->id]);
        $created($other, $forum, (int)$forum->cmid);
        $this->assertFalse($DB->record_exists(channel::TABLE, ['sourcekey' => 'd' . $other->id]));
    }

    /**
     * A post changed after its review is reviewed again, and the old buttons do nothing.
     */
    public function test_review_again(): void {
        global $DB;
        $id = api::channel_post('local_example', 'open_day', ['title' => 'Jornada'], self::WEDNESDAY, false, 'day14');
        $bot = $this->bot();
        channel::process($bot, self::WEDNESDAY);
        $old = $DB->get_record(channel::TABLE, ['id' => $id]);
        channel::decide($old, true, (int)get_admin()->id, null, self::WEDNESDAY);
        api::channel_post('local_example', 'open_day', ['title' => 'Jornada, a las 10:00'], self::WEDNESDAY, false, 'day14');
        $new = $DB->get_record(channel::TABLE, ['id' => $id]);
        $this->assertSame(['review', null, null], [$new->status, $new->timereview, $new->decidedby]);
        $this->assertNotSame($old->token, $new->token);
        $press = ['callback_query' => ['id' => 'cb', 'data' => 'p:' . $old->token . ':pub', 'from' => ['id' => 555],
            'message' => ['message_id' => 9, 'chat' => ['id' => 555, 'type' => 'private']]]];
        telegram::handle($press, $bot);
        $this->assertSame('review', $DB->get_field(channel::TABLE, 'status', ['id' => $id]));
    }

    /**
     * The button of a post carries the UTM marks of the site; with a shortener, its short link, which counts the visits;
     * a link that must go as it is, without marks. An animation goes with its text below and the same button.
     */
    public function test_links_and_animation(): void {
        global $CFG, $DB;
        set_config('utm', 1, 'local_oksigeniamarandu');
        set_config('utmmedium', 'canal', 'local_oksigeniamarandu');
        $plain = api::channel_post('local_example', 'book', ['title' => 'El libro del mes',
            'url' => 'https://example.org/libro?id=4#ficha'], self::WEDNESDAY);
        $this->assertSame(
            'https://example.org/libro?id=4&utm_source=telegram&utm_medium=canal&utm_campaign=book#ficha',
            channel::click($DB->get_field(channel::TABLE, 'token', ['id' => $plain]))
        );
        $this->assertSame(
            'https://example.org/x?utm_source=otra&utm_medium=canal&utm_campaign=book',
            links::utm('https://example.org/x?utm_source=otra', 'book')
        );

        $asked = [];
        links::fake_for_tests(function (array $params) use (&$asked) {
            $asked[] = $params['url'];
            return json_encode(['status' => 'fail', 'code' => 'error:url', 'shorturl' => 'https://sho.rt/abc']);
        });
        $calendar = api::channel_post('local_example', 'book', ['title' => 'Esta semana',
            'url' => 'https://example.org/calendar.ics', 'utm' => false], self::WEDNESDAY);
        $animated = api::channel_post('local_example', 'book', ['title' => 'Jornada de puertas abiertas',
            'text' => 'El sábado, de 10:00 a 13:00.', 'url' => 'https://example.org/visita', 'label' => 'Reservar visita',
            'animation' => $CFG->wwwroot . '/local/oksigeniamarandu/tests/fixtures/animation.mp4'], self::WEDNESDAY);
        $bot = $this->bot();
        try {
            channel::process($bot, self::WEDNESDAY);
        } finally {
            links::fake_for_tests(null);
        }
        $this->assertSame(['https://example.org/libro?id=4&utm_source=telegram&utm_medium=canal&utm_campaign=book#ficha',
            'https://example.org/calendar.ics',
            'https://example.org/visita?utm_source=telegram&utm_medium=canal&utm_campaign=book'], $asked);
        $sent = $bot->of('sendAnimation')[0];
        $this->assertSame([self::CHANNEL, 'animation:1770'], [$sent['chat_id'], $sent['file']]);
        $this->assertSame("Jornada de puertas abiertas\n\nEl sábado, de 10:00 a 13:00.", $sent['caption']);
        $this->assertSame(
            '{"inline_keyboard":[[{"text":"Reservar visita","url":"https:\/\/sho.rt\/abc"}]]}',
            $sent['reply_markup']
        );
        $this->assertSame('published', $DB->get_field(channel::TABLE, 'status', ['id' => $animated]));
        $this->assertSame('published', $DB->get_field(channel::TABLE, 'status', ['id' => $calendar]));
    }

    /**
     * A post with a card has it made before its review, which shows it, and keeps it while it does not change.
     */
    public function test_card(): void {
        global $DB;
        if (!cards::can_draw()) {
            $this->markTestSkipped('GD with FreeType is needed');
        }
        $content = ['title' => 'Jornada de puertas abiertas', 'text' => 'El sábado 14, de 10:00 a 13:00.',
            'card' => ['type' => 'open_day', 'zones' => ['kicker' => 'Jornada de puertas abiertas',
                'title' => 'Ven a conocernos', 'date' => 'Sábado 14 de noviembre', 'detail' => 'De 10:00 a 13:00.']]];
        $id = api::channel_post('local_example', 'open_day', $content, self::WEDNESDAY, false, 'card1');
        $this->assertTrue(cards::pending($DB->get_record(channel::TABLE, ['id' => $id])));
        $bot = $this->bot();
        $this->assertSame(1, channel::process($bot, self::WEDNESDAY)['reviewed']);
        $post = $DB->get_record(channel::TABLE, ['id' => $id]);
        $made = json_decode($post->content, true);
        $this->assertStringContainsString('/local_oksigeniamarandu/card/' . $id . '/card.jpg', $made['image']);
        $this->assertFalse(cards::pending($post));
        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->buffer((string)rich::image($made['image'])));
        $this->assertStringStartsWith('image:', $bot->of('sendRichMessage')[0]['file']);

        api::channel_post('local_example', 'open_day', $content, self::WEDNESDAY, false, 'card1');
        $again = $DB->get_record(channel::TABLE, ['id' => $id]);
        $this->assertSame([$post->token, $post->content], [$again->token, $again->content]);
    }
}
