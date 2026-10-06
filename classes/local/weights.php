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

use gradingform_rubric_ranges\persistent\criterion_weight;

/**
 * Business rules for the weight of each criterion.
 *
 * Criteria without a stored weight (e.g. rubrics created before the extension) weigh DEFAULT.
 * Callers only save a weight when it comes in the submitted data, so disabling the feature never
 * resets the configured weights.
 *
 * @package    gradingform_rubric_ranges
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class weights {

    /** @var int Minimum weight. */
    const MIN = 1;

    /** @var int Maximum weight. */
    const MAX = 10;

    /** @var int Weight of a criterion without stored weight. */
    const DEFAULT = 1;

    /**
     * Normalises any value to a valid weight.
     *
     * @param mixed $value
     * @return int
     */
    public static function clamp($value): int {
        if (!is_numeric($value)) {
            return self::DEFAULT;
        }
        return max(self::MIN, min(self::MAX, (int) $value));
    }

    /**
     * Loads the weights of the given criteria.
     *
     * @param int[] $criterionids
     * @return int[] criterionid => weight, DEFAULT for criteria without stored weight
     */
    public static function load(array $criterionids): array {
        if (empty($criterionids)) {
            return [];
        }
        $weights = array_fill_keys($criterionids, self::DEFAULT);
        foreach (self::get_persistents($criterionids) as $persistent) {
            $weights[$persistent->get('criterionid')] = (int) $persistent->get('weight');
        }
        return $weights;
    }

    /**
     * Stores the weight of a criterion.
     *
     * @param int $criterionid
     * @param mixed $weight
     */
    public static function save(int $criterionid, $weight): void {
        $weight = self::clamp($weight);
        $persistent = criterion_weight::get_record(['criterionid' => $criterionid]);
        if ($persistent) {
            if ((int) $persistent->get('weight') !== $weight) {
                $persistent->set('weight', $weight);
                $persistent->update();
            }
        } else {
            $persistent = new criterion_weight(0, (object) ['criterionid' => $criterionid, 'weight' => $weight]);
            $persistent->create();
        }
    }

    /**
     * Deletes the weights of the given criteria.
     *
     * @param int[] $criterionids
     */
    public static function delete_for_criteria(array $criterionids): void {
        foreach (self::get_persistents($criterionids) as $persistent) {
            $persistent->delete();
        }
    }

    /**
     * Calculates the percentage of each criterion over the total weight.
     *
     * @param array $criteria criterionid => criterion data (optionally with 'weight')
     * @return float[] criterionid => percentage rounded to 1 decimal
     */
    public static function percentages(array $criteria): array {
        $weights = [];
        foreach ($criteria as $id => $criterion) {
            $weights[$id] = self::clamp($criterion['weight'] ?? self::DEFAULT);
        }
        $total = array_sum($weights);
        $percentages = [];
        foreach ($weights as $id => $weight) {
            $percentages[$id] = $total ? round($weight / $total * 100, 1) : 0.0;
        }
        return $percentages;
    }

    /**
     * Returns the stored weight persistents of the given criteria.
     *
     * @param int[] $criterionids
     * @return criterion_weight[]
     */
    private static function get_persistents(array $criterionids): array {
        global $DB;

        $criterionids = array_filter(array_map('intval', $criterionids));
        if (empty($criterionids)) {
            return [];
        }
        // Only builds the SQL fragment, the query is run by the persistent.
        [$insql, $params] = $DB->get_in_or_equal($criterionids, SQL_PARAMS_NAMED);
        return criterion_weight::get_records_select("criterionid $insql", $params);
    }
}
