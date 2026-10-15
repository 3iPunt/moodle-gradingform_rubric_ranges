<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace gradingform_rubric_ranges\local;

/**
 * Tests of the ranges of the numeric grading.
 *
 * @package    gradingform_rubric_ranges
 * @category   test
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \gradingform_rubric_ranges\local\range_resolver
 */
final class range_resolver_test extends \basic_testcase {
    /** @var array Criterion with levels 50, 60, 70 and 100 (ids 11 to 14). */
    private const CRITERION = ['levels' => [
        11 => ['score' => 50, 'definition' => 'Insuf'],
        12 => ['score' => 60, 'definition' => 'Suf'],
        13 => ['score' => 70, 'definition' => 'Buen dominio conceptual'],
        14 => ['score' => 100, 'definition' => 'Exc'],
    ]];

    /**
     * Ranges follow the upstream semantics.
     */
    public function test_ranges_for_criterion(): void {
        $ranges = range_resolver::ranges_for_criterion(self::CRITERION);
        $this->assertSame([11, 12, 13, 14], array_keys($ranges));
        $this->assertSame(
            ['0-50', '51-60', '61-70', '71-100'],
            array_map(fn($r) => $r['min'] . '-' . $r['max'], array_values($ranges))
        );
    }

    /**
     * Ranges do not depend on the display order of the levels.
     */
    public function test_ranges_descending_order(): void {
        $criterion = self::CRITERION;
        $criterion['levels'] = array_reverse($criterion['levels'], true);
        $this->assertEquals(
            range_resolver::ranges_for_criterion(self::CRITERION),
            range_resolver::ranges_for_criterion($criterion)
        );
    }

    /**
     * Data provider for {@see test_level_for_grade()}.
     *
     * @return array
     */
    public static function level_for_grade_provider(): array {
        return [
            'zero' => [0, 11],
            'top of first range' => [50, 11],
            'bottom of second range' => [51, 12],
            'example 65' => [65, 13],
            'maximum' => [100, 14],
            'over the maximum' => [101, null],
        ];
    }

    /**
     * The level is the one whose range contains the grade.
     *
     * @dataProvider level_for_grade_provider
     * @param int $grade
     * @param int|null $expected
     */
    public function test_level_for_grade(int $grade, ?int $expected): void {
        $this->assertSame($expected, range_resolver::level_for_grade(self::CRITERION, $grade));
    }

    /**
     * Levels with decimal scores still get consistent ranges.
     */
    public function test_decimal_scores(): void {
        $criterion = ['levels' => [1 => ['score' => 2.5], 2 => ['score' => 5]]];
        $this->assertSame(1, range_resolver::level_for_grade($criterion, 2));
        $this->assertSame(2, range_resolver::level_for_grade($criterion, 3));
    }

    /**
     * Data provider for {@see test_is_valid_grade()}.
     *
     * @return array
     */
    public static function is_valid_grade_provider(): array {
        return [
            ['65', true], ['0', true], ['100', true], [' 65 ', true],
            ['65.5', false], ['101', false], ['-1', false], ['abc', false], ['', false],
        ];
    }

    /**
     * Only integers from 0 to the maximum are valid.
     *
     * @dataProvider is_valid_grade_provider
     * @param string $value
     * @param bool $expected
     */
    public function test_is_valid_grade(string $value, bool $expected): void {
        $this->assertSame($expected, range_resolver::is_valid_grade(self::CRITERION, $value));
    }

    /**
     * The stored grade counts when it is consistent with the stored level, the level score otherwise.
     */
    public function test_effective_grade(): void {
        $this->assertSame(65.0, range_resolver::effective_grade(self::CRITERION, ['levelid' => 13, 'grade' => 65]));
        // Fillings saved before the numeric grading was enabled.
        $this->assertSame(70.0, range_resolver::effective_grade(self::CRITERION, ['levelid' => 13, 'grade' => null]));
        $this->assertSame(70.0, range_resolver::effective_grade(self::CRITERION, ['levelid' => 13, 'grade' => 0]));
        $this->assertNull(range_resolver::effective_grade(self::CRITERION, ['levelid' => null, 'grade' => 65]));
    }

    /**
     * Every criterion goes from 0 to its maximum.
     */
    public function test_min_max_score(): void {
        $other = ['levels' => [21 => ['score' => 5], 22 => ['score' => 10]]];
        $this->assertEquals(['minscore' => 0, 'maxscore' => 110], range_resolver::min_max_score([self::CRITERION, $other]));
    }

    /**
     * Submitted fillings are validated per criterion; criteria without grade are validated as upstream.
     */
    public function test_validate_filling(): void {
        $other = ['levels' => [21 => ['score' => 5], 22 => ['score' => 10]]];
        $criteria = [1 => self::CRITERION, 2 => $other];
        $valid = fn(array $c1, array $c2) => range_resolver::validate_filling($criteria, ['criteria' => [1 => $c1, 2 => $c2]]);
        $this->assertTrue($valid(['grade' => '65'], ['grade' => '2']));
        $this->assertFalse($valid(['grade' => '101'], ['grade' => '2']));
        $this->assertTrue($valid(['grade' => '65'], ['levelid' => 22]));
        $this->assertFalse(range_resolver::validate_filling($criteria, ['criteria' => [1 => ['grade' => '65'], 2 => []]]));
        $this->assertFalse(range_resolver::validate_filling($criteria, ['criteria' => [1 => ['grade' => '65']]]));
    }

    /**
     * The server decides the level from the grade and empty grades never reach the integer column.
     */
    public function test_normalise_filling(): void {
        $criteria = [1 => self::CRITERION];
        $data = range_resolver::normalise_filling($criteria, ['criteria' => [1 => ['grade' => '65', 'levelid' => 14]]]);
        $this->assertSame(65, $data['criteria'][1]['grade']);
        $this->assertSame(13, $data['criteria'][1]['levelid']);
        $data = range_resolver::normalise_filling($criteria, ['criteria' => [1 => ['grade' => ' ', 'levelid' => 14]]]);
        $this->assertArrayNotHasKey('grade', $data['criteria'][1]);
    }
}
