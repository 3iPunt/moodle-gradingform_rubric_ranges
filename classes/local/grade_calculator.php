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
 * Calculation of the grade pushed to the gradebook when weighting and/or numeric grading are enabled.
 *
 * Without weighting it applies the upstream formulas. With weighting every criterion is normalised to
 * [0, 1] and averaged with its weight:
 * - lockzeropoints on:  f = sum(w * s / max) / sum(w), grade = max(mingrade, f * maxgrade)
 * - lockzeropoints off: f = sum(w * (s - min) / (max - min)) / sum(w), grade = mingrade + f * (maxgrade - mingrade)
 *
 * Pure functions: data comes from the controller and instance APIs.
 *
 * @package    gradingform_rubric_ranges
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grade_calculator {

    /**
     * Calculates the grade of a filled rubric.
     *
     * @param array $criteria rubric criteria (criterionid => criterion with 'levels' and optionally 'weight')
     * @param array $filling rubric filling with 'criteria' (criterionid => ['levelid', 'grade'])
     * @param array $options rubric options
     * @param array $graderange valid grades of the activity
     * @param bool $allowdecimals whether the activity allows decimal grades
     * @param bool $weighting whether criteria weighting is enabled
     * @param bool $numeric whether numeric grading is enabled
     * @return float|int the grade, -1 when it cannot be calculated
     */
    public static function calculate(array $criteria, array $filling, array $options, array $graderange,
            bool $allowdecimals, bool $weighting, bool $numeric) {
        if (empty($graderange) || empty($criteria)) {
            return -1;
        }
        sort($graderange);
        $mingrade = $graderange[0];
        $maxgrade = $graderange[count($graderange) - 1];
        $lockzeropoints = !empty($options['lockzeropoints']);

        $rows = [];
        foreach ($criteria as $id => $criterion) {
            [$min, $max] = self::criterion_min_max($criterion, $numeric);
            $record = $filling['criteria'][$id] ?? null;
            $rows[$id] = [
                'min' => $min,
                'max' => $max,
                'score' => is_array($record) ? self::criterion_score($criterion, $record, $numeric) : null,
                'weight' => weights::clamp($criterion['weight'] ?? weights::DEFAULT),
            ];
        }

        if ($weighting) {
            $fraction = self::weighted_fraction($rows, $lockzeropoints);
            if ($fraction === null) {
                return -1;
            }
            if ($lockzeropoints) {
                $grade = max($mingrade, $fraction * $maxgrade);
                return $allowdecimals ? $grade : round($grade, 0);
            }
            $offset = $fraction * ($maxgrade - $mingrade);
            return ($allowdecimals ? $offset : round($offset, 0)) + $mingrade;
        }

        // Upstream formulas on the sums.
        $minscore = array_sum(array_column($rows, 'min'));
        $maxscore = array_sum(array_column($rows, 'max'));
        $curscore = array_sum(array_map(fn($row) => $row['score'] ?? 0, $rows));
        if ($maxscore <= $minscore) {
            return -1;
        }
        if ($lockzeropoints) {
            $grade = max($mingrade, $curscore / $maxscore * $maxgrade);
            return $allowdecimals ? $grade : round($grade, 0);
        }
        $offset = ($curscore - $minscore) / ($maxscore - $minscore) * ($maxgrade - $mingrade);
        return ($allowdecimals ? $offset : round($offset, 0)) + $mingrade;
    }

    /**
     * Weighted average of the normalised score of each criterion.
     *
     * Criteria that cannot be normalised (max 0 with lockzeropoints, max = min without it) are left out.
     * A criterion without filling contributes 0 with its weight.
     *
     * @param array $rows criterionid => ['min', 'max', 'score', 'weight']
     * @param bool $lockzeropoints
     * @return float|null null when no criterion can be normalised
     */
    private static function weighted_fraction(array $rows, bool $lockzeropoints): ?float {
        $sumweights = 0;
        $sum = 0;
        foreach ($rows as $row) {
            if ($lockzeropoints) {
                if ($row['max'] <= 0) {
                    continue;
                }
                $fraction = ($row['score'] ?? 0) / $row['max'];
            } else {
                if ($row['max'] <= $row['min']) {
                    continue;
                }
                $fraction = (($row['score'] ?? $row['min']) - $row['min']) / ($row['max'] - $row['min']);
            }
            $sumweights += $row['weight'];
            $sum += $row['weight'] * $fraction;
        }
        return $sumweights ? $sum / $sumweights : null;
    }

    /**
     * Minimum and maximum score of a criterion.
     *
     * @param array $criterion
     * @param bool $numeric
     * @return float[] [min, max]
     */
    private static function criterion_min_max(array $criterion, bool $numeric): array {
        if ($numeric) {
            return [0.0, range_resolver::max_grade($criterion)];
        }
        $scores = array_map(fn($level) => (float) $level['score'], $criterion['levels'] ?? []);
        return $scores ? [min($scores), max($scores)] : [0.0, 0.0];
    }

    /**
     * Score of a filled criterion.
     *
     * @param array $criterion
     * @param array $record filling of the criterion
     * @param bool $numeric
     * @return float|null null when no level is selected
     */
    private static function criterion_score(array $criterion, array $record, bool $numeric): ?float {
        if ($numeric) {
            return range_resolver::effective_grade($criterion, $record);
        }
        if (!empty($criterion['isranged'])) {
            return (float) ($record['grade'] ?? 0);
        }
        $levelid = $record['levelid'] ?? null;
        return isset($criterion['levels'][$levelid]) ? (float) $criterion['levels'][$levelid]['score'] : null;
    }
}
