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
 * Tests of the notices the plugins declare and which of them the site sends.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\notices
 */
#[\PHPUnit\Framework\Attributes\CoversClass(notices::class)]
final class notices_test extends \advanced_testcase {
    /** What a made-up canteen declares: a notice split in two, and one that would follow itself. */
    private const DECLARED = ['local_example' => [
        'menu_changed' => ['examples' => ['Ana', 'lunes 3']],
        'menu_missing' => ['examples' => ['Ana']],
        'day_off' => ['examples' => ['lunes 3'], 'follows' => 'menu_changed'],
        'day_back' => ['examples' => ['martes 4'], 'follows' => 'menu_changed'],
        'reminder' => ['examples' => ['Ana'], 'follows' => 'menu_missing'],
        'itself' => ['examples' => ['Ana'], 'follows' => 'itself'],
    ]];

    /**
     * The tests forget their notices.
     */
    protected function tearDown(): void {
        notices::declare_for_tests([]);
        parent::tearDown();
    }

    /**
     * A notice that takes over from another is switched on, once, in each channel where that one is on; if the site
     * switches it off afterwards, it stays off.
     */
    public function test_follows(): void {
        $this->resetAfterTest();
        set_config('enablednotices', 'local_example/menu_changed', 'local_oksigeniamarandu');
        set_config('telegramnotices', 'local_example/menu_changed,local_example/menu_missing', 'local_oksigeniamarandu');
        notices::declare_for_tests(self::DECLARED);
        $this->assertSame('menu_changed', notices::get('local_example', 'day_off')->follows);
        $this->assertSame('', notices::get('local_example', 'itself')->follows);
        $this->assertSame('', notices::get('local_example', 'menu_changed')->follows);

        $this->assertEqualsCanonicalizing(
            ['local_example/menu_changed', 'local_example/day_off', 'local_example/day_back'],
            notices::enabled('whatsapp')
        );
        $this->assertEqualsCanonicalizing(
            ['local_example/menu_changed', 'local_example/menu_missing', 'local_example/day_off', 'local_example/day_back',
                'local_example/reminder'],
            notices::enabled('telegram')
        );

        notices::set('local_example/day_off', 'telegram', false);
        // Another request, as after the next update of the plugin.
        notices::declare_for_tests(self::DECLARED);
        $this->assertNotContains('local_example/day_off', notices::enabled('telegram'));
        $this->assertContains('local_example/day_off', notices::enabled('whatsapp'));
    }

    /**
     * Notices that follow none never touch the settings: a site that has not chosen is still to choose.
     */
    public function test_others_leave_the_settings_alone(): void {
        $this->resetAfterTest();
        notices::declare_for_tests(['local_example' => ['menu_changed' => ['examples' => ['Ana']]]]);
        $this->assertSame([], notices::enabled('whatsapp'));
        $this->assertFalse(notices::chosen('whatsapp'));
        $this->assertFalse(get_config('local_oksigeniamarandu', 'followednotices'));

        // One that follows a notice that is off is just written down.
        notices::declare_for_tests(self::DECLARED);
        $this->assertSame([], notices::enabled('telegram'));
        $this->assertFalse(notices::chosen('telegram'));
    }
}
