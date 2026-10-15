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
 * Tests of the final grade calculation.
 *
 * @package    gradingform_rubric_ranges
 * @category   test
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \gradingform_rubric_ranges\local\grade_calculator
 */
final class grade_calculator_test extends \basic_testcase {
    /**
     * C1: ranged, max 100, weight 3. C2: not ranged, max 10, weight 1.
     *
     * @return array
     */
    private static function criteria(): array {
        $level = fn($id, $score) => ['id' => $id, 'score' => $score, 'definition' => "L$score"];
        return [
            1 => ['isranged' => 1, 'weight' => 3,
                'levels' => [11 => $level(11, 50), 12 => $level(12, 60), 13 => $level(13, 70), 14 => $level(14, 100)]],
            2 => ['isranged' => 0, 'weight' => 1, 'levels' => [21 => $level(21, 5), 22 => $level(22, 10)]],
        ];
    }

    /**
     * C1 graded 65 (level 61-70), C2 graded 2 (level 0-5).
     *
     * @return array
     */
    private static function filling(): array {
        return ['criteria' => [1 => ['levelid' => 13, 'grade' => 65], 2 => ['levelid' => 21, 'grade' => 2]]];
    }

    /**
     * Independent reference of the upstream formula (gradingform_rubric_ranges_instance::get_grade at 2aece39).
     *
     * @param array $criteria
     * @param array $filling
     * @param bool $lock
     * @param array $range
     * @return float
     */
    private static function upstream(array $criteria, array $filling, bool $lock, array $range): float {
        $min = $max = $cur = 0;
        foreach ($criteria as $c) {
            $scores = array_column($c['levels'], 'score');
            $min += min($scores);
            $max += max($scores);
        }
        foreach ($filling['criteria'] as $id => $r) {
            $cur += $criteria[$id]['isranged'] ? $r['grade'] : $criteria[$id]['levels'][$r['levelid']]['score'];
        }
        sort($range);
        $mingrade = $range[0];
        $maxgrade = end($range);
        if ($lock) {
            return max($mingrade, $cur / $max * $maxgrade);
        }
        return ($cur - $min) / ($max - $min) * ($maxgrade - $mingrade) + $mingrade;
    }

    /**
     * Data provider for {@see test_calculate()}.
     *
     * @return array
     */
    public static function calculate_provider(): array {
        return [
            'weighting + numeric, lock (example)' => [true, true, 1, 53.75],
            'weighting + numeric, no lock (min 0)' => [true, true, 0, 53.75],
            'numeric only' => [false, true, 1, 6700 / 110],
            'weighting only, lock' => [true, false, 1, 61.25],
            'weighting only, no lock' => [true, false, 0, 22.5],
        ];
    }

    /**
     * Weighted and numeric calculations.
     *
     * @dataProvider calculate_provider
     * @param bool $weighting
     * @param bool $numeric
     * @param int $lock
     * @param float $expected
     */
    public function test_calculate(bool $weighting, bool $numeric, int $lock, float $expected): void {
        $grade = grade_calculator::calculate(
            self::criteria(),
            self::filling(),
            ['lockzeropoints' => $lock],
            range(0, 100),
            true,
            $weighting,
            $numeric
        );
        $this->assertEqualsWithDelta($expected, $grade, 0.001);
    }

    /**
     * With every feature disabled the result is the upstream formula.
     */
    public function test_all_off_is_upstream(): void {
        foreach ([1, 0] as $lock) {
            $this->assertEqualsWithDelta(
                self::upstream(self::criteria(), self::filling(), (bool) $lock, range(0, 100)),
                grade_calculator::calculate(
                    self::criteria(),
                    self::filling(),
                    ['lockzeropoints' => $lock],
                    range(0, 100),
                    true,
                    false,
                    false
                ),
                0.001
            );
        }
    }

    /**
     * Equal weights equal upstream only when every criterion has the same min and max.
     */
    public function test_equal_weights_equivalence(): void {
        $level = fn($id, $score) => ['id' => $id, 'score' => $score, 'definition' => "L$score"];
        $same = [
            1 => ['isranged' => 0, 'weight' => 4, 'levels' => [11 => $level(11, 0), 12 => $level(12, 50), 13 => $level(13, 100)]],
            2 => ['isranged' => 0, 'weight' => 4, 'levels' => [21 => $level(21, 0), 22 => $level(22, 100)]],
        ];
        $filling = ['criteria' => [1 => ['levelid' => 12, 'grade' => null], 2 => ['levelid' => 22, 'grade' => null]]];
        foreach ([1, 0] as $lock) {
            $this->assertEqualsWithDelta(
                self::upstream($same, $filling, (bool) $lock, range(0, 100)),
                grade_calculator::calculate($same, $filling, ['lockzeropoints' => $lock], range(0, 100), true, true, false),
                0.001
            );
        }
        $diffmin = $same;
        $diffmin[2]['levels'] = [21 => $level(21, 20), 22 => $level(22, 100)];
        $this->assertNotEqualsWithDelta(
            self::upstream($diffmin, $filling, false, range(0, 100)),
            grade_calculator::calculate($diffmin, $filling, ['lockzeropoints' => 0], range(0, 100), true, true, false),
            0.001
        );
    }

    /**
     * Rounding and scales work as upstream.
     */
    public function test_rounding_and_scales(): void {
        $this->assertEquals(54, grade_calculator::calculate(
            self::criteria(),
            self::filling(),
            ['lockzeropoints' => 1],
            range(0, 100),
            false,
            true,
            true
        ));
        $this->assertEquals(3, grade_calculator::calculate(
            self::criteria(),
            self::filling(),
            ['lockzeropoints' => 1],
            range(1, 5),
            false,
            true,
            true
        ));
        $this->assertEquals(3, grade_calculator::calculate(
            self::criteria(),
            self::filling(),
            ['lockzeropoints' => 0],
            range(1, 5),
            false,
            true,
            true
        ));
    }

    /**
     * Criteria that cannot be normalised are left out; criteria without filling count 0.
     */
    public function test_excluded_and_missing_criteria(): void {
        $base = grade_calculator::calculate(
            self::criteria(),
            self::filling(),
            ['lockzeropoints' => 0],
            range(0, 100),
            true,
            true,
            false
        );
        $flat = self::criteria();
        $flat[3] = ['isranged' => 0, 'weight' => 10, 'levels' => [31 => ['score' => 7], 32 => ['score' => 7]]];
        $filling = self::filling();
        $filling['criteria'][3] = ['levelid' => 31, 'grade' => null];
        $this->assertEqualsWithDelta($base, grade_calculator::calculate(
            $flat,
            $filling,
            ['lockzeropoints' => 0],
            range(0, 100),
            true,
            true,
            false
        ), 0.001);

        $missing = ['criteria' => [1 => self::filling()['criteria'][1]]];
        $this->assertEqualsWithDelta(48.75, grade_calculator::calculate(
            self::criteria(),
            $missing,
            ['lockzeropoints' => 1],
            range(0, 100),
            true,
            true,
            true
        ), 0.001);

        $this->assertEquals(-1, grade_calculator::calculate(
            [3 => ['isranged' => 0, 'weight' => 1,
            'levels' => [31 => ['score' => 0], 32 => ['score' => 0]]]],
            $filling,
            ['lockzeropoints' => 1],
            range(0, 100),
            true,
            true,
            false
        ));
        $this->assertEquals(-1, grade_calculator::calculate(
            self::criteria(),
            self::filling(),
            ['lockzeropoints' => 1],
            [],
            true,
            true,
            true
        ));
    }
}
