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

use core_external\external_api;
use local_oksigeniamarandu\external\whatsapp_context;
use local_oksigeniamarandu\local\chat\chatwoot;
use local_oksigeniamarandu\local\chat\closed;
use local_oksigeniamarandu\local\chat\directory;
use local_oksigeniamarandu\local\chat\hours;
use local_oksigeniamarandu\local\chat\mirror;
use local_oksigeniamarandu\local\chat\notify;
use local_oksigeniamarandu\local\chat\team_hours;
use local_oksigeniamarandu\local\chat\workcard;

/**
 * Tests for the hours of each department of the conversations: a made-up account whose inbox attends from Monday to
 * Friday, 7:00 to 17:00, with a canteen, an office, admissions and guidance, and a made-up calendar.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\chat\team_hours
 * @covers     \local_oksigeniamarandu\local\chat\hours
 * @covers     \local_oksigeniamarandu\local\chat\closed
 * @covers     \local_oksigeniamarandu\local\chat\workcard
 * @covers     \local_oksigeniamarandu\local\chat\notify
 * @covers     \local_oksigeniamarandu\external\whatsapp_context
 */
#[\PHPUnit\Framework\Attributes\CoversClass(team_hours::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(hours::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(closed::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(workcard::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(notify::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(whatsapp_context::class)]
final class team_hours_test extends \advanced_testcase {
    /** Monday 16 November 2026 at 0:00 in the Canary Islands (UTC in winter). */
    private const MONDAY = 1794787200;

    /** The hours of the example: the canteen, admissions with two spans and the office, by its number. */
    private const EXAMPLE = 'Comedor=lun-vie 7:30-15:30; Admisiones=mon-fri 9:00-14:00,16:00-18:00; ' .
        '21=lun-jue 8:00-14:00, vie 8:00-13:00';

    /** @var chatwoot The made-up account. */
    private $client;

    /**
     * The account: four departments, two people, the inbox from Monday to Friday, 7:00 to 17:00.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        assign_capability(
            'local/oksigeniamarandu:chat',
            CAP_ALLOW,
            $CFG->defaultuserroleid,
            \context_system::instance()->id,
            true
        );
        set_config('chatwooturl', 'https://chat.example.com', 'local_oksigeniamarandu');
        set_config('chatwootaccount', 7, 'local_oksigeniamarandu');
        set_config('chatwoottoken', \core\encryption::encrypt('token'), 'local_oksigeniamarandu');
        $this->client = new class ('https://chat.example.com', 7, 'token') extends chatwoot {
            /** @var array The conversations, by id. */
            public $conversations = [];

            /** @var array The messages of each conversation. */
            public $messages = [];

            /**
             * Answers as the account would.
             *
             * @param string $method The method.
             * @param string $path The path.
             * @param array $data The data.
             * @return array
             */
            public function call(string $method, string $path, array $data = []): array {
                if ($path === '/agents') {
                    return [
                        ['id' => 1, 'name' => 'Ana Pérez', 'email' => 'ana@example.com', 'role' => 'agent'],
                        ['id' => 2, 'name' => 'Luis Gómez', 'email' => 'luis@example.com', 'role' => 'agent'],
                    ];
                }
                if ($path === '/teams') {
                    return [['id' => 20, 'name' => 'comedor'], ['id' => 21, 'name' => 'secretaría'],
                        ['id' => 22, 'name' => 'admisiones'], ['id' => 23, 'name' => 'orientación']];
                }
                if (preg_match('~^/teams/(\d+)/team_members$~', $path, $m)) {
                    return $m[1] == 20 ? [['id' => 1]] : ($m[1] == 21 ? [['id' => 2]] : []);
                }
                if ($path === '/inboxes') {
                    $week = [];
                    for ($day = 0; $day < 7; $day++) {
                        $week[] = ['day_of_week' => $day, 'closed_all_day' => $day === 0 || $day === 6, 'open_all_day' => false,
                            'open_hour' => 7, 'open_minutes' => 0, 'close_hour' => 17, 'close_minutes' => 0];
                    }
                    return ['payload' => [['id' => 3, 'name' => 'WhatsApp', 'timezone' => 'Atlantic/Canary',
                        'working_hours' => $week, 'message_templates' => []]]];
                }
                if ($path === '/conversations' && $method === 'GET') {
                    return ['data' => ['payload' => array_values($this->conversations)]];
                }
                if (preg_match('~^/conversations/(\d+)/messages$~', $path, $m)) {
                    return ['payload' => $this->messages[$m[1]] ?? []];
                }
                return [];
            }
        };
        directory::use_client($this->client);
    }

    /**
     * Back to the client and the calendar of the site.
     */
    protected function tearDown(): void {
        directory::use_client(null);
        closed::fake_for_tests(null);
        parent::tearDown();
    }

    /**
     * A moment of the example week.
     *
     * @param int $day Days after Monday 16 November.
     * @param int $hour The hour.
     * @param int $minute The minute.
     * @return int
     */
    private static function moment(int $day, int $hour, int $minute = 0): int {
        return self::MONDAY + $day * DAYSECS + $hour * HOURSECS + $minute * MINSECS;
    }

    /**
     * A week is read as people write it: days in several languages, alone or as ranges, and one span or several.
     */
    public function test_format(): void {
        $week = team_hours::parse_week('lun-vie 7:30-15:30');
        $this->assertSame([[], [[450, 930]], [[450, 930]], [[450, 930]], [[450, 930]], [[450, 930]], []], $week);
        $week = team_hours::parse_week('mon-thu 8:00-14:00, fri 8:00-13:00');
        $this->assertSame([[480, 840]], $week[4]);
        $this->assertSame([[480, 780]], $week[5]);
        $this->assertSame('Mon-Thu 08:00-14:00; Fri 08:00-13:00', team_hours::describe($week));
        $week = team_hours::parse_week('seg-sex 9-14 e 16-18');
        $this->assertSame([[540, 840], [960, 1080]], $week[3]);
        $this->assertSame('Mon-Fri 09:00-14:00,16:00-18:00', team_hours::describe($week));
        // Days one by one, «and» between them, accents and capitals, dots, and a range round the weekend.
        $week = team_hours::parse_week('Lunes, miércoles y viernes 9:00-14:00');
        $this->assertSame([1, 3, 5], array_keys(array_filter($week)));
        $this->assertSame([[570, 780]], team_hours::parse_week('Sáb. 9.30-13h')[6]);
        $this->assertSame([0, 1, 5, 6], array_keys(array_filter(team_hours::parse_week('vie-lun 10:00-12:00'))));
        $this->assertSame([1, 2, 3, 4, 5], array_keys(array_filter(team_hours::parse_week('الاثنين-الجمعة 9:00-14:00'))));
        // The Portuguese days whole, other dashes, and a day that ends at midnight.
        $week = team_hours::parse_week('segunda-feira – sexta-feira 8:00 – 24:00');
        $this->assertSame([[480, 1440]], $week[2]);
        $this->assertSame([], $week[6]);

        foreach (['lun-vie', '8:00-14:00', 'lunes 14:00-8:00', 'lun 9-14, 13-15', 'funday 9-14', 'lun 25:00-26:00', ' '] as $bad) {
            try {
                team_hours::parse_week($bad);
                $this->fail('Read as a week: ' . $bad);
            } catch (\invalid_parameter_exception $e) {
                $this->assertNotEmpty($e->debuginfo);
            }
        }

        $all = team_hours::parse("Comedor=lun-vie 7:30-15:30; Admisiones=lun-vie 9:00-14:00,16:00-18:00\n" .
            'Secretaría=lun-jue 8:00-14:00,vie 8:00-13:00;');
        $this->assertSame(['Comedor', 'Admisiones', 'Secretaría'], array_column($all, 0));
        foreach (['Comedor lun-vie 9-14', ' ; ', '=lun 9-14', 'Comedor=lun 9-14; Admisiones=hoy 9-14'] as $bad) {
            try {
                team_hours::parse($bad);
                $this->fail('Read as departments: ' . $bad);
            } catch (\invalid_parameter_exception $e) {
                $this->assertNotEmpty($e->debuginfo);
            }
        }
        try {
            team_hours::parse('Comedor=lun 9-14; Admisiones=hoy 9-14');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringStartsWith('Admisiones: ', $e->debuginfo);
        }
    }

    /**
     * The departments go by their name in Chatwoot, the name they are shown with or their number; a name the account
     * does not have, or one written twice, is not kept; empty, none has hours of its own.
     */
    public function test_set(): void {
        $weeks = team_hours::set(self::EXAMPLE);
        $this->assertSame([20, 21, 22], array_keys($weeks));
        $this->assertSame($weeks, team_hours::get());
        $this->assertSame([[480, 780]], $weeks[21][5]);

        set_config('chatteamnames', json_encode(['orientación' => 'Equipo de orientación']), 'local_oksigeniamarandu');
        directory::use_client($this->client);
        $this->assertSame([23], array_keys(team_hours::set('equipo de ORIENTACION=lun 9-10')));
        $this->assertSame([23], array_keys(team_hours::set('Orientación=lun 9-10')));

        foreach (['Cocina=lun 9-14' => 'Cocina', 'Comedor=lun 9-14; comedor=mar 9-14' => 'twice'] as $bad => $said) {
            try {
                team_hours::set($bad);
                $this->fail('Kept: ' . $bad);
            } catch (\invalid_parameter_exception $e) {
                $this->assertStringContainsString($said, $e->debuginfo);
            }
        }
        $this->assertSame([23], array_keys(team_hours::get()));
        $this->assertSame([], team_hours::set(''));
        $this->assertSame([], team_hours::get());
    }

    /**
     * Each department counts its own hours: the canteen, from 7:30 to 15:30; admissions, with a gap at midday; guidance,
     * without its own, those of the inbox; a department that is not there, or none, those of the inbox too.
     */
    public function test_wait_by_department(): void {
        team_hours::set(self::EXAMPLE);
        $site = hours::of_site(self::MONDAY);
        $canteen = hours::of_team(20, self::MONDAY);
        // From Monday 15:00 to Tuesday 8:00: the inbox counts 15:00 to 17:00 and 7:00 to 8:00; the canteen, half an hour
        // each day.
        $this->assertSame(3 * HOURSECS, $site->waited(self::moment(0, 15), self::moment(1, 8)));
        $this->assertSame(HOURSECS, $canteen->waited(self::moment(0, 15), self::moment(1, 8)));
        $this->assertFalse($canteen->is_open(self::moment(0, 16)));
        $this->assertTrue($site->is_open(self::moment(0, 16)));
        $this->assertSame(self::moment(1, 7, 30), $canteen->next_open(self::moment(0, 16)));
        // Admissions: Monday 13:00 to 17:00 counts 13:00 to 14:00 and 16:00 to 17:00, and between them it opens at 16:00.
        $admissions = hours::of_team(22, self::MONDAY);
        $this->assertSame(2 * HOURSECS, $admissions->waited(self::moment(0, 13), self::moment(0, 17)));
        $this->assertSame(self::moment(0, 16), $admissions->next_open(self::moment(0, 14, 30)));
        $this->assertSame(self::moment(1, 9), $admissions->opening('2026-11-17'));
        // The office closes at 13:00 on Friday; guidance keeps the inbox.
        $office = hours::of_team(21, self::MONDAY);
        $this->assertFalse($office->is_open(self::moment(4, 13, 30)));
        $this->assertSame(self::moment(7, 8), $office->next_open(self::moment(4, 13, 30)));
        $this->assertTrue(hours::of_team(23, self::MONDAY)->is_open(self::moment(4, 16)));
        $this->assertFalse(hours::of_team(23, self::MONDAY)->own);
        $this->assertTrue($canteen->own);
        $this->assertSame($site->export(), hours::of_team(0, self::MONDAY)->export());
        $this->assertSame($site->export(), hours::of_team(99, self::MONDAY)->export());
        // For the page: every span of each day, the first opening and the last closing.
        $day = $admissions->export()['week'][1];
        $this->assertSame(['09:00', '18:00', false, false], [$day['open'], $day['close'], $day['closed'], $day['allday']]);
        $this->assertSame([['open' => '09:00', 'close' => '14:00'], ['open' => '16:00', 'close' => '18:00']], $day['spans']);
        $this->assertSame('Mon-Fri 07:30-15:30', $canteen->describe());
    }

    /**
     * A closed day of all closes every department; «Department: closed» closes only that one, also over other hours of
     * the same day; «Department: 9:00-12:00» gives it other hours that day, also on a closed day of all.
     */
    public function test_closed_and_other_hours(): void {
        team_hours::set(self::EXAMPLE);
        set_config('chatcalendar', 'https://calendar.example.com/basic.ics', 'local_oksigeniamarandu');
        set_config('chatclosedwords', 'Cerrado,Festivo', 'local_oksigeniamarandu');
        $event = function (string $uid, string $from, string $to, string $name): string {
            return "BEGIN:VEVENT\r\nUID:$uid\r\nDTSTART;VALUE=DATE:$from\r\nDTEND;VALUE=DATE:$to\r\n" .
                "SUMMARY:$name\r\nEND:VEVENT\r\n";
        };
        closed::fake_for_tests("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" .
            $event('a', '20261118', '20261119', 'Festivo local') .
            $event('b', '20261118', '20261119', 'Secretaría: 9:00-12:00') .
            $event('c', '20261119', '20261120', '🍽 Comedor: cerrado') .
            $event('d', '20261119', '20261120', 'Comedor: 8:00-12:00') .
            $event('e', '20261120', '20261121', 'ADMISIONES: 10:00-13:00 (puertas abiertas)') .
            $event('f', '20261123', '20261205', 'Comedor: cerrado por obras') .
            $event('g', '20261117', '20261118', 'Reunión de claustro') .
            "END:VCALENDAR\r\n");
        $this->assertSame(['2026-11-18'], closed::days(self::MONDAY));
        $marks = closed::teams(self::MONDAY);
        $this->assertSame(['2026-11-18' => [[540, 720]]], $marks['secretaria']['hours']);
        $this->assertSame('2026-11-19', $marks['comedor']['closed'][0]);
        $this->assertCount(13, $marks['comedor']['closed']);
        $this->assertSame(['2026-11-20' => [[600, 780]]], $marks['admisiones']['hours']);
        $this->assertSame(['admisiones', 'comedor', 'secretaria'], array_keys($marks));

        $byteam = hours::by_team(self::MONDAY);
        // Wednesday 18, closed for all: the inbox and the canteen are closed; the office opens from 9:00 to 12:00.
        $this->assertFalse(hours::of_site(self::MONDAY)->is_open(self::moment(2, 10)));
        $this->assertFalse($byteam[20]->is_open(self::moment(2, 10)));
        $this->assertTrue($byteam[21]->is_open(self::moment(2, 10)));
        $this->assertFalse($byteam[21]->is_open(self::moment(2, 12, 30)));
        $this->assertFalse($byteam[23]->own);
        // Thursday 19: closed only the canteen, though it also had other hours that day.
        $this->assertFalse($byteam[20]->is_open(self::moment(3, 10)));
        $this->assertTrue($byteam[21]->is_open(self::moment(3, 10)));
        // Friday 20: admissions only from 10:00 to 13:00.
        $this->assertFalse($byteam[22]->is_open(self::moment(4, 9, 30)));
        $this->assertTrue($byteam[22]->is_open(self::moment(4, 10, 30)));
        $this->assertFalse($byteam[22]->is_open(self::moment(4, 16, 30)));
        $this->assertSame(self::moment(7, 9), $byteam[22]->next_open(self::moment(4, 13)));
        // The canteen is closed for two weeks: it opens again on Monday 7 December.
        $this->assertSame(self::moment(21, 7, 30), $byteam[20]->next_open(self::moment(4, 16)));
        // A wait over the days of each one.
        $this->assertSame(3 * HOURSECS, $byteam[21]->waited(self::moment(2, 0), self::moment(3, 0)));

        // For the page: the closed days of each one, and its days of other hours.
        $canteen = $byteam[20]->export();
        $this->assertContains('2026-11-18', $canteen['closed']);
        $this->assertContains('2026-11-19', $canteen['closed']);
        $this->assertSame([], $canteen['special']);
        $office = $byteam[21]->export();
        $this->assertNotContains('2026-11-18', $office['closed']);
        $this->assertSame([['day' => '2026-11-18', 'spans' => [['open' => '09:00', 'close' => '12:00']]]], $office['special']);
        $this->assertSame(['closed' => 0, 'special' => ['2026-11-18' => '09:00-12:00']], $byteam[21]->days_ahead('2026-11-16'));
        $this->assertSame(14, $byteam[20]->days_ahead('2026-11-16')['closed']);
    }

    /**
     * The department of a conversation decides: nobody of the canteen hears of a family that writes when it is closed
     * though the inbox is open; and «Your work» counts the wait in its hours, and says when the canteen opens.
     */
    public function test_notices_and_work_card(): void {
        global $DB;
        team_hours::set(self::EXAMPLE);
        $ana = $this->getDataGenerator()->create_user(['email' => 'ana@example.com', 'firstname' => 'Ana']);
        $sink = $this->redirectMessages();
        $row = (object)['cwid' => 1, 'status' => 'open', 'name' => 'Laura Martín', 'phone' => '34600000001', 'teamid' => 20,
            'assigneeid' => 0, 'preview' => 'Hola', 'previewkind' => 'incoming', 'lastmsgid' => 10];
        $old = clone $row;
        $old->waitsince = 0;
        $old->lastincoming = 0;
        // Monday 16:00: the inbox is open, the canteen is not.
        $row->waitsince = $row->lastincoming = self::moment(0, 16) - 60;
        notify::changed($old, $row, [], self::moment(0, 16));
        $this->runAdhocTasks(\local_oksigeniamarandu\task\chat_notify::class);
        $this->assertCount(0, $sink->get_messages());
        // Tuesday 10:00, open.
        $row->waitsince = $row->lastincoming = self::moment(1, 10) - 60;
        notify::changed($old, $row, [], self::moment(1, 10));
        $this->runAdhocTasks(\local_oksigeniamarandu\task\chat_notify::class);
        $this->assertCount(1, $sink->get_messages());
        $this->assertSame((int)$ana->id, (int)$sink->get_messages()[0]->useridto);
        $sink->close();

        // A family writes to the canteen on Monday at 15:00.
        $this->client->conversations = [1 => ['id' => 1, 'inbox_id' => 3, 'status' => 'open', 'updated_at' => '1.0',
            'last_activity_at' => time(), 'meta' => ['sender' => ['id' => 101, 'name' => 'Laura Martín',
            'phone_number' => '+34600000001'], 'team' => ['id' => 20], 'assignee' => null, 'assignee_type' => null]]];
        $this->client->messages = [1 => [['id' => 10, 'message_type' => 0, 'content' => '¿Mañana hay comedor?',
            'created_at' => self::moment(0, 15), 'private' => false, 'sender' => ['type' => 'contact', 'id' => 101]]]];
        mirror::sync();
        $DB->set_field(mirror::TABLE, 'waitsince', self::moment(0, 15), ['cwid' => 1]);
        // Tuesday at 9:00 it has waited two hours of the canteen (four of the inbox): not too long yet.
        $card = workcard::for_user((int)$ana->id, self::moment(1, 9));
        $this->assertNotContains('urgent', array_column($card['alerts'], 'level'));
        // At noon, five: too long.
        $card = workcard::for_user((int)$ana->id, self::moment(1, 12));
        $this->assertSame('urgent', $card['alerts'][0]['level']);
        $this->assertStringContainsString('Laura Martín', $card['alerts'][0]['text']);
        // On Monday at 16:00 the canteen of Ana is out of hours, though the inbox is not.
        $card = workcard::for_user((int)$ana->id, self::moment(0, 16));
        $closed = get_string('chat:work:closed', 'local_oksigeniamarandu', 'tomorrow at 7:30');
        $this->assertStringContainsString($closed, $card['status']);
        $this->assertStringNotContainsString('tomorrow', workcard::for_user((int)$ana->id, self::moment(1, 9))['status']);
    }

    /**
     * The bot of the menu learns whether each department attends now and when it opens, said as the site speaks:
     * today, tomorrow, a day of the week, or the date beyond a week.
     */
    public function test_teams_for_the_bot(): void {
        team_hours::set(self::EXAMPLE);
        set_config('chatinternal', '20', 'local_oksigeniamarandu');
        // Monday at 15:45.
        $teams = whatsapp_context::teams(self::moment(0, 15, 45));
        $this->assertSame(['Admisiones', 'Comedor', 'Orientación', 'Secretaría'], array_column($teams, 'name'));
        $this->assertSame(['id' => 22, 'name' => 'Admisiones', 'internal' => false, 'open' => false,
            'opensat' => self::moment(0, 16), 'opensattext' => 'today at 16:00'], $teams[0]);
        $this->assertSame(['id' => 20, 'name' => 'Comedor', 'internal' => true, 'open' => false,
            'opensat' => self::moment(1, 7, 30), 'opensattext' => 'tomorrow at 7:30'], $teams[1]);
        $this->assertSame([true, 0, ''], [$teams[2]['open'], $teams[2]['opensat'], $teams[2]['opensattext']]);
        $this->assertSame('tomorrow at 8:00', $teams[3]['opensattext']);
        // Friday at 13:30 the office has closed until Monday.
        $teams = whatsapp_context::teams(self::moment(4, 13, 30));
        $this->assertSame([self::moment(7, 8), 'on Monday at 8:00'], [$teams[3]['opensat'], $teams[3]['opensattext']]);
        $this->assertTrue($teams[1]['open']);
        // The canteen closed for two weeks: the date.
        closed::fake_for_tests("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:a\r\nDTSTART;VALUE=DATE:20261123\r\n" .
            "DTEND;VALUE=DATE:20261205\r\nSUMMARY:Comedor: closed\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        $teams = whatsapp_context::teams(self::moment(4, 16));
        $this->assertSame([self::moment(21, 7, 30), 'on 7 December at 7:30'], [$teams[1]['opensat'], $teams[1]['opensattext']]);

        // By the web service, as it declares it.
        $this->setAdminUser();
        $result = external_api::clean_returnvalue(whatsapp_context::execute_returns(), whatsapp_context::execute('+34600000001'));
        $this->assertCount(4, $result['teams']);
        $this->assertSame(['id', 'name', 'internal', 'open', 'opensat', 'opensattext'], array_keys($result['teams'][0]));
        // Without the conversations of the team, none.
        unset_config('chatwooturl', 'local_oksigeniamarandu');
        $this->assertSame([], whatsapp_context::teams(self::moment(0, 10)));
    }
}
