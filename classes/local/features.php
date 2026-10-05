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
 * Site-wide feature flags of the IED extension. All of them are disabled by default.
 *
 * gradingform plugins do not get a settings.php loaded by core, so these flags are
 * managed through settings_page.php (or forced in config.php / admin/cli/cfg.php).
 *
 * @package    gradingform_rubric_ranges
 * @copyright  2026 Tresipunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class features {

    /** @var string Plugin component used to store the flags. */
    const COMPONENT = 'gradingform_rubric_ranges';

    /** @var string Criteria weighting. */
    const WEIGHTING = 'enableweighting';

    /** @var string Numeric grade per criterion with automatic range and feedback. */
    const NUMERICGRADING = 'enablenumericgrading';

    /** @var string Academic validation of grades. */
    const VALIDATION = 'enablevalidation';

    /**
     * Returns the list of feature flag names.
     *
     * @return string[]
     */
    public static function all(): array {
        return [self::WEIGHTING, self::NUMERICGRADING, self::VALIDATION];
    }

    /**
     * Whether the given flag is enabled.
     *
     * @param string $name one of the class constants
     * @return bool
     */
    public static function is_enabled(string $name): bool {
        return (bool) get_config(self::COMPONENT, $name);
    }

    /**
     * Whether criteria weighting is enabled.
     *
     * @return bool
     */
    public static function weighting_enabled(): bool {
        return self::is_enabled(self::WEIGHTING);
    }

    /**
     * Whether numeric grading per criterion is enabled.
     *
     * @return bool
     */
    public static function numericgrading_enabled(): bool {
        return self::is_enabled(self::NUMERICGRADING);
    }

    /**
     * Whether academic validation is enabled.
     *
     * @return bool
     */
    public static function validation_enabled(): bool {
        return self::is_enabled(self::VALIDATION);
    }

    /**
     * Whether any of the extension features is enabled.
     *
     * @return bool
     */
    public static function any_enabled(): bool {
        foreach (self::all() as $name) {
            if (self::is_enabled($name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Stores the default value (disabled) for every flag that has no value yet.
     */
    public static function set_defaults(): void {
        foreach (self::all() as $name) {
            if (get_config(self::COMPONENT, $name) === false) {
                set_config($name, 0, self::COMPONENT);
            }
        }
    }
}
