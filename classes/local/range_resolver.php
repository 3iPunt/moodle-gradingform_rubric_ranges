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
 * Business rules of the numeric grading: ranges of each level and grade of each criterion.
 *
 * Uses the same ranges as upstream (renderer::display_range_score): the lowest level goes from 0 to
 * its score and every other level from the previous score (exclusive) to its own score. Ranges are
 * always computed on the scores sorted ascending, so they do not depend on the display order.
 *
 * Pure functions: criteria and fillings come from the controller and instance APIs.
 *
 * @package    gradingform_rubric_ranges
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class range_resolver {

    /**
     * Returns the range of every level of a criterion.
     *
     * 'min' is the lowest integer grade of the range and 'max' the level score. A grade g belongs to
     * the level when min <= g <= max.
     *
     * @param array $criterion criterion data with 'levels' (levelid => ['score' => ...])
     * @return array levelid => ['min' => int, 'max' => float], sorted by score ascending
     */
    public static function ranges_for_criterion(array $criterion): array {
        $scores = [];
        foreach ($criterion['levels'] ?? [] as $levelid => $level) {
            $scores[$levelid] = (float) $level['score'];
        }
        asort($scores);

        $ranges = [];
        $previous = null;
        foreach ($scores as $levelid => $score) {
            $ranges[$levelid] = [
                'min' => $previous === null ? 0 : (int) floor($previous) + 1,
                'max' => $score,
            ];
            $previous = $score;
        }
        return $ranges;
    }

    /**
     * Returns the maximum grade of a criterion.
     *
     * @param array $criterion
     * @return float
     */
    public static function max_grade(array $criterion): float {
        if (empty($criterion['levels'])) {
            return (float) ($criterion['points'] ?? 0);
        }
        $max = 0;
        foreach ($criterion['levels'] as $level) {
            $max = max($max, (float) $level['score']);
        }
        return $max;
    }

    /**
     * Returns the level whose range contains the grade.
     *
     * @param array $criterion
     * @param int $grade
     * @return int|null levelid, null when the grade is out of range
     */
    public static function level_for_grade(array $criterion, int $grade): ?int {
        foreach (self::ranges_for_criterion($criterion) as $levelid => $range) {
            if ($grade >= $range['min'] && $grade <= $range['max']) {
                return (int) $levelid;
            }
        }
        return null;
    }

    /**
     * Whether a submitted value is a valid grade: an integer from 0 to the maximum of the criterion.
     *
     * @param array $criterion
     * @param mixed $value
     * @return bool
     */
    public static function is_valid_grade(array $criterion, $value): bool {
        $value = trim((string) $value);
        if (!preg_match('/^\d+$/', $value)) {
            return false;
        }
        return (int) $value <= self::max_grade($criterion) && self::level_for_grade($criterion, (int) $value) !== null;
    }

    /**
     * Returns the grade of a filled criterion used in the calculation.
     *
     * The stored grade is used when it is consistent with the stored level. Otherwise (fillings saved
     * before the numeric grading was enabled) the score of the level is used, as upstream does.
     *
     * @param array $criterion
     * @param array $filling filling of the criterion with 'levelid' and 'grade'
     * @return float|null null when no level is selected
     */
    public static function effective_grade(array $criterion, array $filling): ?float {
        $levelid = $filling['levelid'] ?? null;
        if (empty($levelid) || !isset($criterion['levels'][$levelid])) {
            return null;
        }
        $grade = $filling['grade'] ?? null;
        if ($grade !== null && $grade !== '' && is_numeric($grade)
                && self::level_for_grade($criterion, (int) $grade) === (int) $levelid) {
            return (float) $grade;
        }
        return (float) $criterion['levels'][$levelid]['score'];
    }

    /**
     * Returns the grade to display in the grade input of a criterion.
     *
     * @param array $criterion
     * @param array|null $value filling or submitted data of the criterion
     * @return string grade, or '' when there is none
     */
    public static function display_grade(array $criterion, ?array $value): string {
        if (empty($value)) {
            return '';
        }
        $effective = self::effective_grade($criterion, $value);
        if ($effective !== null) {
            return (string) (int) $effective;
        }
        // Submitted data not validated yet: show what was typed.
        return isset($value['grade']) ? trim((string) $value['grade']) : '';
    }

    /**
     * Minimum and maximum score of a rubric with numeric grading: every criterion goes from 0 to its maximum.
     *
     * @param array $criteria
     * @return array ['minscore' => 0, 'maxscore' => float]
     */
    public static function min_max_score(array $criteria): array {
        $max = 0;
        foreach ($criteria as $criterion) {
            $max += self::max_grade($criterion);
        }
        return ['minscore' => 0, 'maxscore' => $max];
    }

    /**
     * Validates a submitted rubric filling with numeric grading.
     *
     * Criteria submitted without grade (e.g. from the grader panel, out of scope) are validated as upstream.
     *
     * @param array $criteria
     * @param array $elementvalue submitted value of the grading element
     * @return bool
     */
    public static function validate_filling(array $criteria, $elementvalue): bool {
        if (!isset($elementvalue['criteria']) || !is_array($elementvalue['criteria'])) {
            return false;
        }
        foreach ($criteria as $id => $criterion) {
            $submitted = $elementvalue['criteria'][$id] ?? null;
            if (!is_array($submitted)) {
                return false;
            }
            if (array_key_exists('grade', $submitted)) {
                if (!self::is_valid_grade($criterion, $submitted['grade'])) {
                    return false;
                }
            } else if (!isset($submitted['levelid']) || !array_key_exists($submitted['levelid'], $criterion['levels'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Normalises a submitted filling: the server decides the level from the grade.
     *
     * @param array $criteria
     * @param array $data submitted data with 'criteria'
     * @return array
     */
    public static function normalise_filling(array $criteria, array $data): array {
        foreach ($data['criteria'] ?? [] as $id => $submitted) {
            if (!isset($criteria[$id]) || !is_array($submitted) || !array_key_exists('grade', $submitted)) {
                continue;
            }
            if (trim((string) $submitted['grade']) === '') {
                // An empty grade must not reach the integer column.
                unset($data['criteria'][$id]['grade']);
                continue;
            }
            if (self::is_valid_grade($criteria[$id], $submitted['grade'])) {
                $grade = (int) $submitted['grade'];
                $data['criteria'][$id]['grade'] = $grade;
                $data['criteria'][$id]['levelid'] = self::level_for_grade($criteria[$id], $grade);
            }
        }
        return $data;
    }
}
