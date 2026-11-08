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
 * Tests of the calendar of the channel. The calendar and its events are made up.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\calendar
 */
#[\PHPUnit\Framework\Attributes\CoversClass(calendar::class)]
final class calendar_test extends \advanced_testcase {
    /** Friday 30 October 2026 at 10:00 in Madrid. */
    private const FRIDAY = 1793350800;

    /**
     * A site with its bot, its channel and a calendar, Madrid time.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Europe/Madrid');
        set_config('telegramtoken', \core\encryption::encrypt('123456789:' . str_repeat('A', 35)), 'local_oksigeniamarandu');
        set_config('telegrambot', 'example_school_bot', 'local_oksigeniamarandu');
        set_config('channelid', '-1001234567890', 'local_oksigeniamarandu');
        set_config('channeltitle', 'Escuela Ejemplo', 'local_oksigeniamarandu');
        set_config('calendarkeywords', 'No lectivo, Semana Santa, Navidad', 'local_oksigeniamarandu');
        set_config('calendarlink', 'https://example.org/calendar.ics', 'local_oksigeniamarandu');
        calendar::fake_for_tests($this->ical());
    }

    /**
     * The tests forget their calendar.
     */
    protected function tearDown(): void {
        calendar::fake_for_tests(null);
        parent::tearDown();
    }

    /**
     * A made-up calendar: a day without class, an event with its zone, one of several days, one in UTC, one that repeats
     * every Tuesday but one, with another day changed, one of three days, one cancelled and a holiday week.
     *
     * @param bool $withdayoff Whether it has the day without class of Monday 2.
     * @return string
     */
    private function ical(bool $withdayoff = true): string {
        $events = [
            ['UID:1', 'DTSTART;VALUE=DATE:20261102', 'DTEND;VALUE=DATE:20261103', 'SUMMARY:No lectivo'],
            ['UID:2', 'DTSTART;TZID=Europe/Madrid:20261104T170000', 'DTEND;TZID=Europe/Madrid:20261104T183000',
                'SUMMARY:Reunión de familias\\, aula 3'],
            ['UID:3', 'DTSTART;VALUE=DATE:20261104', 'DTEND;VALUE=DATE:20261107', 'SUMMARY:Semana de la ciencia'],
            ['UID:4', 'DTSTART:20261105T090000Z', 'DTEND:20261105T110000Z', 'SUMMARY:Infantil - Navidad en familia'],
            ['UID:5', 'DTSTART:20261006T170000', 'DTEND:20261006T180000', 'RRULE:FREQ=WEEKLY;BYDAY=TU;UNTIL=20261231T235959Z',
                'EXDATE:20261103T170000', 'SUMMARY:Club de lectura'],
            ['UID:5', 'RECURRENCE-ID:20261110T170000', 'DTSTART:20261111T170000', 'DTEND:20261111T180000',
                'SUMMARY:Club de lectura (cambiado)'],
            ['UID:6', 'DTSTART:20261112T080000Z', 'DTEND:20261112T100000Z', 'RRULE:FREQ=DAILY;COUNT=3', 'SUMMARY:Campamento'],
            ['UID:7', 'DTSTART;VALUE=DATE:20261105', 'SUMMARY:Excursión anulada', 'STATUS:CANCELLED'],
            ['UID:8', 'DTSTART;VALUE=DATE:20261109', 'DTEND;VALUE=DATE:20261114', 'SUMMARY:📆 Semana Santa'],
        ];
        if (!$withdayoff) {
            array_shift($events);
        }
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Example//Calendar//ES', 'X-WR-TIMEZONE:Europe/Madrid'];
        foreach ($events as $event) {
            $lines = array_merge($lines, ['BEGIN:VEVENT', 'DTSTAMP:20261001T000000Z'], $event, ['END:VEVENT']);
        }
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * A time of November 2026, in Madrid.
     *
     * @param string $when As 2026-11-02 08:00.
     * @return int
     */
    private function time_of(string $when): int {
        return (new \DateTime($when, new \DateTimeZone('Europe/Madrid')))->getTimestamp();
    }

    /**
     * The events: days without hours to the day before their end, times in their zone, in UTC and in the zone of the
     * calendar, repetitions without the days left out or changed, and nothing cancelled.
     */
    public function test_parse(): void {
        $events = calendar::events($this->time_of('2026-11-01 00:00'), $this->time_of('2026-12-01 00:00'));
        $by = [];
        foreach ($events as $event) {
            $by[$event->name][] = $event;
        }
        $this->assertSame(['2026-11-02', '2026-11-02', true], [$by['No lectivo'][0]->first, $by['No lectivo'][0]->last,
            $by['No lectivo'][0]->allday]);
        $this->assertSame(['2026-11-04', '2026-11-06'], [$by['Semana de la ciencia'][0]->first,
            $by['Semana de la ciencia'][0]->last]);
        $this->assertSame($this->time_of('2026-11-04 17:00'), $by['Reunión de familias, aula 3'][0]->start);
        $this->assertSame($this->time_of('2026-11-05 10:00'), $by['Infantil - Navidad en familia'][0]->start);
        $this->assertSame(
            [$this->time_of('2026-11-17 17:00'), $this->time_of('2026-11-24 17:00')],
            array_column($by['Club de lectura'], 'start')
        );
        $this->assertSame($this->time_of('2026-11-11 17:00'), $by['Club de lectura (cambiado)'][0]->start);
        $this->assertSame(['2026-11-12', '2026-11-13', '2026-11-14'], array_column($by['Campamento'], 'first'));
        $this->assertArrayNotHasKey('Excursión anulada', $by);
        $this->assertSame(['2026-11-09', '2026-11-13'], [$by['📆 Semana Santa'][0]->first, $by['📆 Semana Santa'][0]->last]);
    }

    /**
     * A day without class is an event whose name starts with one of the words, whatever goes before its first letter.
     */
    public function test_days_off(): void {
        $this->assertTrue(calendar::is_dayoff('📆 Semana Santa'));
        $this->assertTrue(calendar::is_dayoff('NO LECTIVO'));
        $this->assertTrue(calendar::is_dayoff('Navidad'));
        $this->assertFalse(calendar::is_dayoff('Infantil - Navidad en familia'));
        $this->assertFalse(calendar::is_dayoff('Festival de Navidad'));

        $events = calendar::events($this->time_of('2026-10-30 00:00'), $this->time_of('2026-12-01 00:00'));
        $announce = calendar::announcements($events, self::FRIDAY);
        $this->assertSame(['2026-11-02', '2026-11-09'], array_keys($announce));
        $this->assertSame([$this->time_of('2026-11-01 18:00'), '2026-11-03', ['No lectivo']], [$announce['2026-11-02']->eve,
            $announce['2026-11-02']->back, $announce['2026-11-02']->names]);
        $this->assertSame([$this->time_of('2026-11-08 18:00'), '2026-11-16'], [$announce['2026-11-09']->eve,
            $announce['2026-11-09']->back]);
    }

    /**
     * The lines of «This week», in order, with the day and the time of each event.
     */
    public function test_week(): void {
        $monday = $this->time_of('2026-11-02 00:00');
        $events = calendar::events($monday, $monday + WEEKSECS);
        $this->assertSame([
            'Monday 2 · No lectivo',
            'From Wednesday 4 to Friday 6 · Semana de la ciencia',
            'Wednesday 4, 17:00 · Reunión de familias, aula 3',
            'Thursday 5, 10:00 · Infantil - Navidad en familia',
        ], calendar::week($events, $monday));
    }

    /**
     * On Friday, «This week» for Monday morning and «No class tomorrow» for the eve of Monday, both to review; the
     * holiday week waits until a few days before its eve; and a day that has class again takes its post back.
     */
    public function test_prepare(): void {
        global $DB;
        $this->assertSame(['week' => 1, 'dayoff' => 1, 'withdrawn' => 0], calendar::prepare(self::FRIDAY));
        $week = $DB->get_record(channel::TABLE, ['kind' => 'week']);
        $content = json_decode($week->content, true);
        $this->assertSame(
            ['week-20261102', 'review', $this->time_of('2026-11-02 08:00'), $this->time_of('2026-11-03 00:00')],
            [$week->sourcekey, $week->status, (int)$week->timeplanned, (int)$week->timeexpires]
        );
        $this->assertSame(['This week', 'https://example.org/calendar.ics', 'Add to calendar', false], [$content['title'],
            $content['url'], $content['label'], $content['utm']]);
        $dayoff = $DB->get_record(channel::TABLE, ['kind' => 'dayoff']);
        $this->assertSame(['dayoff-20261102', $this->time_of('2026-11-01 18:00')], [$dayoff->sourcekey, (int)$dayoff->timeplanned]);
        $this->assertSame(
            ['No class tomorrow', ['No lectivo', 'Back on Tuesday 3 November.']],
            [json_decode($dayoff->content, true)['title'], json_decode($dayoff->content, true)['text']]
        );

        // Again, nothing new; then the day without class goes out of the calendar.
        $this->assertSame(['week' => 1, 'dayoff' => 1, 'withdrawn' => 0], calendar::prepare(self::FRIDAY + HOURSECS));
        $this->assertSame(2, $DB->count_records(channel::TABLE));
        calendar::fake_for_tests($this->ical(false));
        $counts = calendar::prepare(self::FRIDAY + DAYSECS);
        $this->assertSame(1, $counts['withdrawn']);
        $this->assertSame('cancelled', $DB->get_field(channel::TABLE, 'status', ['id' => $dayoff->id]));
        $changed = $DB->get_record(channel::TABLE, ['id' => $week->id]);
        $this->assertNotSame($week->token, $changed->token);
        $this->assertCount(3, json_decode($changed->content, true)['lines']);

        // On Thursday, the holiday week, with the day classes come back.
        calendar::prepare(self::FRIDAY + 6 * DAYSECS);
        $holiday = $DB->get_record(channel::TABLE, ['sourcekey' => 'dayoff-20261109']);
        $this->assertSame('Back on Monday 16 November.', json_decode($holiday->content, true)['text'][1]);

        // Without a channel, nothing.
        channel::disconnect();
        $this->assertSame(['week' => 0, 'dayoff' => 0, 'withdrawn' => 0], calendar::prepare(self::FRIDAY));
    }
}
