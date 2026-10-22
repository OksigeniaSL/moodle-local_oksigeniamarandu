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
 * Tests of the words to join and leave.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\keywords
 */
#[\PHPUnit\Framework\Attributes\CoversClass(keywords::class)]
final class keywords_test extends \advanced_testcase {
    /**
     * What people write, with the default words.
     *
     * @dataProvider messages_provider
     * @param string $text What the family wrote.
     * @param array|null $expected What it asks for.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('messages_provider')]
    public function test_match(string $text, ?array $expected): void {
        $this->assertSame($expected, keywords::match($text));
    }

    /**
     * Messages and what they ask for.
     *
     * @return array
     */
    public static function messages_provider(): array {
        return [
            'opt-in alone' => ['ALTA', ['action' => 'optin', 'code' => null]],
            'opt-in with code, as the link writes it' => ['ALTA K7M2Q9XA', ['action' => 'optin', 'code' => 'K7M2Q9XA']],
            'opt-in in lower case with a code' => ['  alta k7m2q9xa ', ['action' => 'optin', 'code' => 'K7M2Q9XA']],
            'opt-in in English' => ['Join', ['action' => 'optin', 'code' => null]],
            'opt-out with a full stop' => ['Baja.', ['action' => 'optout']],
            'opt-out in English' => ['stop', ['action' => 'optout']],
            'opt-out with an accent' => ['BÁJA', ['action' => 'optout']],
            'opt-out inside a sentence' => ['No quiero darme de baja', null],
            'a long message that starts like an opt-in' => ['Alta la fiebre del niño hoy', null],
            'a question' => ['¿Mañana hay comedor?', null],
            'empty' => ['', null],
        ];
    }

    /**
     * The site can set its own words; the first one is the one that goes in links and replies.
     */
    public function test_words_of_the_site(): void {
        $this->resetAfterTest();
        set_config('optinkeywords', 'sí, Avisos', 'local_oksigeniamarandu');
        set_config('optoutkeywords', ' basta ,stop', 'local_oksigeniamarandu');

        $this->assertSame(['SI', 'AVISOS'], keywords::list('optinkeywords'));
        $this->assertSame('SI', keywords::first('optinkeywords'));
        $this->assertSame('BASTA', keywords::first('optoutkeywords'));
        $this->assertSame(['action' => 'optin', 'code' => null], keywords::match('Avisos'));
        $this->assertNull(keywords::match('ALTA'));
        $this->assertSame(['action' => 'optout'], keywords::match('Basta'));
    }

    /**
     * Codes use the letters that are hard to mix up.
     */
    public function test_new_code(): void {
        $code = keywords::new_code();
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/', $code);
        $this->assertNotSame($code, keywords::new_code());
    }
}
