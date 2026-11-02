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
 * Tests for what the panel tells. Made-up numbers and people.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\panel
 */
#[\PHPUnit\Framework\Attributes\CoversClass(panel::class)]
final class panel_test extends \advanced_testcase {
    /** A Wednesday at 11:00 in Madrid, out of the quiet hours. */
    private const WEDNESDAY = 1793181600;

    /**
     * A site with its account, Madrid time, the general notice switched on and quiet nights and weekends.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Europe/Madrid');
        set_config('phonenumberid', '1110000000', 'local_oksigeniamarandu');
        set_config('accesstoken', 'made-up', 'local_oksigeniamarandu');
        set_config('displayphone', '+34 600 000 000', 'local_oksigeniamarandu');
        set_config('enablednotices', 'local_oksigeniamarandu/notification', 'local_oksigeniamarandu');
        set_config('quietfrom', '20:00', 'local_oksigeniamarandu');
        set_config('quietto', '08:00', 'local_oksigeniamarandu');
        set_config('quietdays', '6,0', 'local_oksigeniamarandu');
    }

    /**
     * A number that joined for a made-up family.
     *
     * @param string $phone Its number.
     * @param int $itemid The family.
     * @return \stdClass The contact.
     */
    private function family(string $phone, int $itemid = 42): \stdClass {
        global $DB;
        consent::optin_from_message($phone, api::invite('local_example', $itemid), 'wamid.FAKE');
        $contact = $DB->get_record(consent::CONTACTS, ['phone' => $phone], '*', MUST_EXIST);
        // Its message of joining was long ago, whatever the day the tests run.
        $DB->set_field(consent::CONTACTS, 'lastinbound', self::WEDNESDAY - 3 * DAYSECS, ['id' => $contact->id]);
        return $DB->get_record(consent::CONTACTS, ['id' => $contact->id]);
    }

    /**
     * A row of the queue, as the queue leaves it.
     *
     * @param \stdClass $contact The contact.
     * @param array $fields What differs from a notice sent with its template at the time of the tests.
     * @return int Its id.
     */
    private function row(\stdClass $contact, array $fields = []): int {
        global $DB;
        static $token = 0;
        $token++;
        return $DB->insert_record(queue::TABLE, (object)($fields + [
            'contactid' => $contact->id,
            'component' => 'local_oksigeniamarandu',
            'notice' => 'notification',
            'params' => json_encode(['Colegio Ejemplo', 'Reunión el jueves']),
            'token' => 'T' . $token,
            'urgent' => 0,
            'status' => 'sent',
            'kind' => 'template',
            'wamid' => 'wamid.T' . $token,
            'attempts' => 0,
            'timequeued' => self::WEDNESDAY - HOURSECS,
            'timeexpires' => self::WEDNESDAY + DAYSECS,
            'timesent' => self::WEDNESDAY - MINSECS,
        ]));
    }

    /**
     * The state: no channel yet, running (and until when it is quiet), no notice switched on, stopped by the account, at
     * the limit, or without a channel any more; and how each channel is.
     */
    public function test_state(): void {
        $state = panel::state(self::WEDNESDAY);
        $this->assertSame(['ok', ['whatsapp' => 'ok'], true], [$state->kind, $state->channels, $state->others]);
        set_config('enablednotices', '', 'local_oksigeniamarandu');
        $this->assertSame('choose', panel::state(self::WEDNESDAY)->kind);
        set_config('enablednotices', 'local_oksigeniamarandu/notification', 'local_oksigeniamarandu');
        $night = self::WEDNESDAY + 10 * HOURSECS;
        $this->assertSame(self::WEDNESDAY + 21 * HOURSECS, panel::state($night)->next);

        foreach ([190 => 'token', 131042 => 'payment', 368 => 'restricted', 131045 => 'account'] as $code => $what) {
            set_config(
                'sendblocked',
                json_encode(['code' => $code, 'message' => 'Fake', 'time' => self::WEDNESDAY]),
                'local_oksigeniamarandu'
            );
            $state = panel::state(self::WEDNESDAY);
            $this->assertSame(['blocked', $what, 'whatsapp', 'stopped'], [$state->kind, $state->what, $state->channel,
                $state->channels['whatsapp']]);
        }
        unset_config('sendblocked', 'local_oksigeniamarandu');

        set_config('capmonth', 0.03, 'local_oksigeniamarandu');
        set_config('costpermessage', 0.02, 'local_oksigeniamarandu');
        $this->row($this->family('34600000001'));
        $this->assertSame('cap', panel::state(self::WEDNESDAY)->kind);

        $this->assertSame('cap', panel::state(self::WEDNESDAY)->channels['whatsapp']);

        // Telegram as well: when WhatsApp is at its limit, Telegram still goes out.
        set_config('telegramtoken', \core\encryption::encrypt('123456789:' . str_repeat('A', 35)), 'local_oksigeniamarandu');
        set_config('telegrambot', 'marandu_example_bot', 'local_oksigeniamarandu');
        $state = panel::state(self::WEDNESDAY);
        $this->assertSame(['cap', ['whatsapp' => 'cap', 'telegram' => 'ok'], true], [$state->kind, $state->channels,
            $state->others]);
        unset_config('telegramtoken', 'local_oksigeniamarandu');

        // There was a channel, and there is none now.
        set_config('accesstoken', '', 'local_oksigeniamarandu');
        $this->assertSame('disconnected', panel::state(self::WEDNESDAY)->kind);
    }

    /**
     * A brand-new site: no channel yet; the firsts are known when they happen.
     */
    public function test_fresh(): void {
        set_config('accesstoken', '', 'local_oksigeniamarandu');
        $this->assertSame('connect', panel::state(self::WEDNESDAY)->kind);
        $firsts = panel::firsts();
        $this->assertSame([0, 0], [$firsts->join, $firsts->sent]);

        $contact = $this->family('34600000001');
        $this->row($contact);
        $firsts = panel::firsts();
        $this->assertSame([(int)$contact->id, self::WEDNESDAY - MINSECS], [(int)$firsts->joincontact->id, $firsts->sent]);
        $this->assertSame('+34 6•• •• •• 01', panel::who($contact));
    }

    /**
     * Today counts what went out since midnight, what reached the phone, what was read and what failed; the month,
     * what it costs at this pace.
     */
    public function test_today_and_month(): void {
        set_config('costpermessage', 0.02, 'local_oksigeniamarandu');
        $contact = $this->family('34600000001');
        $this->row($contact);
        $this->row($contact, ['status' => 'delivered']);
        $this->row($contact, ['status' => 'read']);
        $this->row($contact, ['status' => 'read', 'kind' => 'text']);
        // Taken by Meta and not delivered: it went out all the same.
        $this->row($contact, ['status' => 'failed', 'errorcode' => 131026]);
        $this->row($contact, ['status' => 'failed', 'wamid' => null, 'errorcode' => 100]);
        $this->row($contact, ['timesent' => self::WEDNESDAY - DAYSECS]);

        $this->assertSame([
            'whatsapp' => ['sent' => 5, 'delivered' => 3, 'read' => 2, 'failed' => 2],
            'telegram' => ['sent' => 0, 'failed' => 0],
            'sent' => 5,
            'failed' => 2,
        ], panel::today(self::WEDNESDAY));

        // Day by day: the 5 of today and the one of yesterday, in their days of the month.
        $days = panel::days(self::WEDNESDAY);
        $today = (int)userdate(self::WEDNESDAY, '%d', 'Europe/Madrid');
        $this->assertSame([$today, 5, 1], [$days['today'], $days['whatsapp'][$today - 1], $days['whatsapp'][$today - 2]]);
        $this->assertSame(0, array_sum($days['telegram']));

        // The last one that went out, for the quiet hours.
        $last = panel::last();
        $this->assertSame(['whatsapp', self::WEDNESDAY - MINSECS], [$last->channel, $last->time]);

        $month = panel::month(self::WEDNESDAY);
        $this->assertSame([4, 1], [$month->templates, $month->free]);
        $this->assertEqualsWithDelta(0.08, $month->spent, 0.0001);
        $this->assertGreaterThan($month->spent, $month->likely);
        $this->assertFalse($month->full);
    }

    /**
     * What waits says why: trying again, quiet hours, a text Meta has not approved, or the account stopped.
     */
    public function test_waiting(): void {
        $contact = $this->family('34600000001');
        $this->row($contact, ['status' => 'queued', 'timeretry' => self::WEDNESDAY + 11 * HOURSECS, 'wamid' => null]);
        $this->row($contact, ['status' => 'queued', 'wamid' => null]);
        $this->row($contact, ['status' => 'queued', 'wamid' => null]);

        $groups = panel::waiting(self::WEDNESDAY);
        $this->assertSame(['retry', 'template'], array_column($groups, 'reason'));
        $this->assertSame([1, 2], array_column($groups, 'count'));
        $this->assertSame(self::WEDNESDAY + 11 * HOURSECS, $groups[0]->when);
        $notice = notices::get('local_oksigeniamarandu', 'notification');
        $this->assertSame(notices::text($notice), $groups[1]->name);

        $night = self::WEDNESDAY + 10 * HOURSECS;
        $groups = panel::waiting($night);
        $this->assertSame(['retry', 'quiet'], array_column($groups, 'reason'));
        $this->assertSame(self::WEDNESDAY + 21 * HOURSECS, $groups[1]->when);

        set_config(
            'sendblocked',
            json_encode(['code' => 190, 'message' => 'Fake', 'time' => self::WEDNESDAY]),
            'local_oksigeniamarandu'
        );
        $groups = panel::waiting(self::WEDNESDAY);
        $this->assertSame([['blocked', 3]], [[$groups[0]->reason, $groups[0]->count]]);
    }

    /**
     * What failed says why in plain words, newest first, with the number half hidden.
     */
    public function test_failed(): void {
        $contact = $this->family('34600000018');
        $this->row($contact, ['status' => 'failed', 'errorcode' => 131026, 'timesent' => self::WEDNESDAY - 4 * MINSECS]);
        // Busy until the last try.
        $this->row($contact, ['status' => 'failed', 'errorcode' => 130429, 'attempts' => 4,
            'timesent' => self::WEDNESDAY - 3 * MINSECS]);
        $this->row($contact, ['status' => 'failed', 'errorcode' => 99999, 'timesent' => self::WEDNESDAY - 2 * MINSECS]);
        $this->row($contact, ['status' => 'expired', 'timesent' => null, 'timeexpires' => self::WEDNESDAY - MINSECS]);

        $failed = panel::failed();
        $this->assertSame(['expired', 'generic', 'noanswer', 'nowhatsapp'], array_column($failed, 'why'));
        $this->assertSame('+34 6•• •• •• 18', $failed[0]->phone);
        $this->assertSame('+34 600 00 00 18', panel::spaced('34600000018'));
    }

    /**
     * Who was already active and joins again is no new move on the panel, though the log keeps it.
     */
    public function test_a_second_join_is_no_new_move(): void {
        global $DB;
        $contact = $this->family('34600000001');
        consent::optin_from_message('34600000001', null, 'wamid.AGAIN');
        $people = panel::people();
        $this->assertCount(1, $people->moves);
        $this->assertSame('optin', $people->moves[0]->action);

        // Leaving and coming back are moves; the log keeps all of them.
        consent::optout('34600000001', 'keyword', 'wamid.OUT');
        consent::optin_from_message('34600000001', null, 'wamid.BACK');
        $this->assertSame(['optin', 'optout', 'optin'], array_column(panel::people()->moves, 'action'));
        $this->assertSame(4, $DB->count_records(consent::LOG, ['contactid' => $contact->id]));
    }

    /**
     * Who joined and left, and how: by writing the word, or by hand on the site, by whom and why.
     */
    public function test_people(): void {
        $admin = get_admin();
        $this->family('34600000001');
        $this->family('34600000002', 43);
        consent::get_or_create('34600000003');
        consent::optout('34600000002', 'admin', $admin->id . ':Asked by phone');

        $people = panel::people();
        $this->assertSame([1, 1, 1], [$people->active, $people->pending, $people->optedout]);
        $this->assertCount(3, $people->moves);
        $this->assertSame('optout', $people->moves[0]->action);
        $this->assertSame(get_string(
            'panel:how:adminwhy',
            'local_oksigeniamarandu',
            (object)['who' => fullname($admin), 'why' => 'asked by phone']
        ), $people->moves[0]->how);
        $this->assertSame(get_string('panel:how:wrote', 'local_oksigeniamarandu', 'ALTA'), $people->moves[1]->how);
        // Nothing says whom the made-up plugin's family is: the number stands in, half hidden.
        $this->assertSame('+34 6•• •• •• 02', $people->moves[0]->who);
    }

    /**
     * Each notice switched on shows, with how many went out this month and what it is about.
     */
    public function test_bynotice(): void {
        $notice = notices::get('local_oksigeniamarandu', 'notification');
        $this->assertSame([[notices::text($notice), 0, 'general']], array_map(function ($item) {
            return [$item->name, $item->count, $item->icon];
        }, panel::bynotice(self::WEDNESDAY)->on));
        $this->assertSame([], panel::bynotice(self::WEDNESDAY)->off);
        $this->assertSame(get_string('marandu:notification_label', 'local_oksigeniamarandu'), notices::label($notice));

        $contact = $this->family('34600000001');
        $this->row($contact);
        $this->row($contact, ['status' => 'read']);
        $this->row($contact, ['status' => 'failed']);
        $item = panel::bynotice(self::WEDNESDAY)->on[0];
        $this->assertSame([2, 2, 0], [$item->count, $item->whatsapp, $item->telegram]);

        // Switched off for every channel and not sent this month: apart.
        set_config('enablednotices', '', 'local_oksigeniamarandu');
        $this->assertSame(1, count(panel::bynotice(self::WEDNESDAY + 40 * DAYSECS)->off));
    }

    /**
     * The limits hold for every channel: a site that never set them gets those of the wizard; saving without
     * WhatsApp leaves the money of the month as it was.
     */
    public function test_limits(): void {
        unset_config('quietfrom', 'local_oksigeniamarandu');
        unset_config('capfamily', 'local_oksigeniamarandu');
        limits::defaults();
        $this->assertSame(['20:00', '3', '08:00'], [get_config('local_oksigeniamarandu', 'quietfrom'),
            get_config('local_oksigeniamarandu', 'capfamily'), get_config('local_oksigeniamarandu', 'quietto')]);

        set_config('capmonth', 7, 'local_oksigeniamarandu');
        $_POST = ['capmonth' => '50', 'capfamily' => '2', 'quietfrom' => '21:30', 'quietto' => '07:00',
            'quietdays' => [0 => 1], 'urgent' => ['local_oksigeniamarandu/notification']];
        $this->assertSame([], limits::save(false));
        $this->assertSame(['7', '2', '21:30', '0', 'local_oksigeniamarandu/notification'], [
            get_config('local_oksigeniamarandu', 'capmonth'), get_config('local_oksigeniamarandu', 'capfamily'),
            get_config('local_oksigeniamarandu', 'quietfrom'), get_config('local_oksigeniamarandu', 'quietdays'),
            get_config('local_oksigeniamarandu', 'urgentnotices')]);
        $context = limits::context(false);
        $this->assertSame([false, 2, true], [$context['money'], $context['capfamily'], $context['urgent'][0]['on']]);
        $_POST = [];
    }

    /**
     * Each channel has its own notices switched on: a notice off for a channel does not go out by it.
     */
    public function test_notices_by_channel(): void {
        $key = 'local_oksigeniamarandu/notification';
        notices::set($key, 'telegram', true);
        $this->assertSame([$key], notices::enabled('telegram'));
        notices::set($key, 'whatsapp', false);
        $this->assertSame([], notices::enabled('whatsapp'));
        $this->assertTrue(notices::chosen('whatsapp'));
        notices::set('local_oksigeniamarandu/nonexistent', 'telegram', true);
        $this->assertSame([$key], notices::enabled('telegram'));
        // Only Telegram has it, and only WhatsApp is ready: nothing goes out.
        $this->assertFalse(notices::any_on());
        $this->assertSame('choose', panel::state(self::WEDNESDAY)->kind);
        $this->assertTrue(api::notices_on('local_oksigeniamarandu', 'telegram'));
        $this->assertFalse(api::notices_on('local_oksigeniamarandu', 'whatsapp'));
        $this->assertFalse(api::notices_on('local_example', 'telegram'));
        $this->assertFalse(api::notices_on('local_oksigeniamarandu', 'sms'));
        $group = notices::group('local_oksigeniamarandu');
        $this->assertSame(['Marandu', get_string('pluginname', 'local_oksigeniamarandu')], [$group->title, $group->name]);
    }

    /**
     * Times as people say them.
     */
    public function test_when(): void {
        $c = 'local_oksigeniamarandu';
        $now = self::WEDNESDAY;
        $this->assertSame(get_string('panel:when:today', $c, '10:00'), panel::when($now - HOURSECS, $now));
        $this->assertSame(get_string('panel:when:yesterday', $c, '11:00'), panel::when($now - DAYSECS, $now));
        $this->assertSame(
            get_string('panel:when:tomorrow', $c, '08:00'),
            panel::when(self::WEDNESDAY + 21 * HOURSECS, self::WEDNESDAY)
        );
        $this->assertStringEndsWith(', 08:00', panel::when(self::WEDNESDAY + 4 * DAYSECS + 21 * HOURSECS, self::WEDNESDAY));
        $this->assertSame(userdate($now - 9 * DAYSECS, '%d %b'), panel::when($now - 9 * DAYSECS, $now));
    }
}
