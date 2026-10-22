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
 * Tests of the phone numbers. All numbers are made up.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\phone
 */
#[\PHPUnit\Framework\Attributes\CoversClass(phone::class)]
final class phone_test extends \basic_testcase {
    /**
     * A number as people write it becomes digits with the country code, or nothing.
     *
     * @dataProvider numbers_provider
     * @param string $raw The number as typed.
     * @param string|null $expected The digits, or null.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('numbers_provider')]
    public function test_normalise(string $raw, ?string $expected): void {
        $this->assertSame($expected, phone::normalise($raw));
    }

    /**
     * Numbers as people write them.
     *
     * @return array
     */
    public static function numbers_provider(): array {
        return [
            'plus and spaces' => ['+34 600 00 00 00', '34600000000'],
            'double zero and dashes' => ['0034 600-000-000', '34600000000'],
            'brackets, dots and slashes' => ['+1 (555) 010.00/00', '15550100000'],
            'spaces around' => ['  +595 981 000 000 ', '595981000000'],
            'without the country code' => ['600 00 00 00', null],
            'digits only, which could be any country' => ['34600000000', null],
            'letters' => ['+34 600 ABC 000', null],
            'too short' => ['+34 600', null],
            'too long' => ['+34 6000 0000 0000 00', null],
            'country code starting with 0' => ['+0 600 000 000', null],
            'two plus signs' => ['++34 600 000 000', null],
            'empty' => ['', null],
        ];
    }
}
