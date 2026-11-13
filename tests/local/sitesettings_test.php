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
 * Tests of the settings with their value from the start.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\sitesettings
 */
#[\PHPUnit\Framework\Attributes\CoversClass(sitesettings::class)]
final class sitesettings_test extends \advanced_testcase {
    /**
     * The settings of the page of the account, as the administration has them.
     *
     * @return \admin_setting[]
     */
    private function settings(): array {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $page = admin_get_root(true, true)->locate('local_oksigeniamarandu_account');
        $this->assertNotNull($page);
        return array_values(array_filter((array)$page->settings, function ($setting): bool {
            return !$setting instanceof \admin_setting_heading;
        }));
    }

    /**
     * Without any value, every setting gets one: Moodle has nothing new to stop the administrator on.
     */
    public function test_settle_leaves_nothing_new(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $settings = $this->settings();
        $this->assertNotEmpty($settings);
        foreach ($settings as $setting) {
            unset_config($setting->name, $setting->plugin);
            $this->assertNull($setting->get_setting(), $setting->name);
        }
        sitesettings::settle();
        foreach ($this->settings() as $setting) {
            $this->assertNotNull($setting->get_setting(), $setting->name);
        }
        $this->assertSame(32, strlen(get_config('local_oksigeniamarandu', 'verifytoken')));
        $this->assertSame(keywords::DEFAULT_OPTIN, get_config('local_oksigeniamarandu', 'optinkeywords'));
        $this->assertSame('1', get_config('local_oksigeniamarandu', 'usefamily'));
    }

    /**
     * What the site set stays: the account, the token of the webhook and a family switch turned off.
     */
    public function test_settle_keeps_what_is_set(): void {
        $this->resetAfterTest();
        set_config('phonenumberid', '123456', 'local_oksigeniamarandu');
        set_config('verifytoken', 'mine', 'local_oksigeniamarandu');
        set_config('usefamily', '0', 'local_oksigeniamarandu');
        unset_config('wabaid', 'local_oksigeniamarandu');
        sitesettings::settle();
        $this->assertSame('123456', get_config('local_oksigeniamarandu', 'phonenumberid'));
        $this->assertSame('mine', get_config('local_oksigeniamarandu', 'verifytoken'));
        $this->assertSame('0', get_config('local_oksigeniamarandu', 'usefamily'));
        $this->assertSame('', get_config('local_oksigeniamarandu', 'wabaid'));
    }
}
