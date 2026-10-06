# IED fork of gradingform_rubric_ranges

This branch (`ied/MOODLE_402_STABLE`) extends the upstream Catalyst plugin
(<https://github.com/catalyst/moodle-gradingform_rubric_ranges>) with:

- criteria weighting,
- numeric grading per criterion with automatic range detection and feedback,
- academic validation with history,
- weighted final grade.

Every feature is **disabled by default**. With all of them disabled the plugin behaves exactly like upstream.

## Base version

| | |
|---|---|
| Upstream branch | `MOODLE_402_STABLE` |
| Upstream commit | `2aece39` (release `2024112200`) |
| Fork version | `2024112201` / `2024112200-ied1` |
| Moodle | 4.5 LTS |

## Patch series

One commit per task, applied in order:

| Patch | Content |
|---|---|
| `0001-T0-…` | Infrastructure: settings page, validate capability, weights and validation history tables with their persistent classes |
| `0002-T1-…` | Criteria weighting (editor, weight display, persistence, regrade on weight change) and upstream regrade fix |
| `0003-T2-…` | Numeric grading per criterion with automatic level and feedback |
| `0004-T3-…` | Academic validation with history and `grade_validated` event |
| `0005-T4-…` | Weighted final grade |
| `0006-T5-…` | Backup and restore of weights and validation history |
| `0007-T6-…` | Privacy provider for the numeric grade, the validation history and the weights |

Further patches are added as each task is delivered.

## Generating the patches

```bash
git format-patch 2aece39..ied/MOODLE_402_STABLE -o ../rubric_ranges_patches
```

The patch files are not committed in this branch; they are published with each `ied-vX.Y.Z` tag.

## Applying the patches on a clean upstream copy

From the plugin root (`grade/grading/form/rubric_ranges`), on upstream commit `2aece39`:

```bash
git am -3 /path/to/patches/*.patch
```

Without git:

```bash
for p in /path/to/patches/*.patch; do patch -p1 < "$p"; done
```

Then run `php admin/cli/upgrade.php` from the Moodle root.

## Rebasing on a new upstream version

```bash
git fetch upstream
git rebase upstream/MOODLE_402_STABLE ied/MOODLE_402_STABLE
# resolve conflicts, run the PHPUnit suite, then regenerate the series
```

Versioning rule after a rebase:

- `$plugin->version` = upstream version + 1,
- `$plugin->release` = `<upstream release>-iedN`.

The fork upgrade steps in `db/upgrade.php` are idempotent (`table_exists` checks) so they are safe if upstream ever reuses a version number.

## Settings

Core does not load `settings.php` for `gradingform` plugins, so the feature flags are managed in a dedicated page:

- URL: `/grade/grading/form/rubric_ranges/settings_page.php` (requires `moodle/site:config`).
- Link: "Ranged rubric settings" in the activity navigation of a ranged rubric, visible to site administrators.
- CLI: `php admin/cli/cfg.php --component=gradingform_rubric_ranges --name=enableweighting --set=1`
- `config.php`: `$CFG->forced_plugin_settings['gradingform_rubric_ranges']['enableweighting'] = 1;`

Flags: `enableweighting`, `enablenumericgrading`, `enablevalidation`. Changes made in the page are recorded in the config changes report.

## Notes on criteria weighting

- Criteria without a stored weight weigh 1, so existing rubrics need no migration.
- Weights are only saved when they are submitted: disabling the feature keeps the configured weights.
- Changing a weight in a rubric already used for grading requires regrading.
- Enabling or disabling the feature does **not** recalculate grades already sent to the gradebook; it only
  applies to grades saved afterwards.
- Weights are only shown to graders and rubric managers, never to students.

## Notes on the final grade

- With weighting or numeric grading enabled the grade is calculated by `local\grade_calculator`; with both
  disabled the upstream `get_grade()` code runs unchanged.
- Without weighting the upstream formulas apply (with numeric grading every criterion goes from 0 to its maximum).
- With weighting every criterion is normalised and averaged with its weight:
  - `lockzeropoints` on: `f = Σ wᵢ·sᵢ/maxᵢ / Σ wᵢ`, grade = `max(min grade, f · max grade)`;
  - `lockzeropoints` off: `f = Σ wᵢ·(sᵢ − minᵢ)/(maxᵢ − minᵢ) / Σ wᵢ`, grade = `min grade + f · (max grade − min grade)`.
- Example: C1 (max 100, weight 3, grade 65) and C2 (max 10, weight 1, grade 2) give 53.75 / 100
  (60.91 without weighting).
- With equal weights the result equals upstream only when every criterion has the same maximum
  (`lockzeropoints` on) or the same minimum and maximum (`lockzeropoints` off).
- Criteria that cannot be normalised (maximum 0, or maximum equal to minimum) are left out; a criterion without
  filling counts 0 with its weight. Rounding and scales work as upstream.
- With numeric grading `get_min_max_score()` returns a minimum of 0, so the "minimum score is not 0" warning and
  the grade mapping explanation are consistent with the ranges.

## Notes on numeric grading

- Every criterion gets an integer grade from 0 to its maximum. Ranges are the upstream ones: the lowest
  level goes from 0 to its score and every other level from the previous score + 1 to its own score.
- The server always decides the level from the grade (the JS only gives instant feedback).
- The definition of the level is proposed as the criterion remark; a remark edited by the grader is never
  overwritten. This needs "Allow grader to add text remarks for each criterion": the rubric editor warns
  when it is disabled.
- With numeric grading the minimum score of each criterion is 0 for the grade calculation.
- Fillings saved before enabling it keep their level score when the stored grade does not match the level.
- Not supported in the grader side panel (out of scope); criteria submitted without grade are handled as upstream.
- Known upstream limitation kept as is: in `DISPLAY_EVAL_FROZEN` the points cell is not rendered, so only the
  hidden level travels (the stored grade is kept).

## Notes on academic validation

- A grade (grading item) can be validated **once**, optionally, by a user with
  `gradingform/rubric_ranges:validate` who is not the grader of the current grade (no self validation).
- Site administrators are **not** validators by default: the capability is checked without "do anything",
  so they need a role that grants it explicitly.
- Flow: the teacher grades; the validator opens the grading form, sees the teacher grade read only and clicks
  "Validate"; the validator grade and feedback replace the teacher ones and the original teacher grade, level
  and feedback are kept in `gradingform_rubric_ranges_h` with the validator and the date.
- A grade is validated when it has history. Then it is read only for everybody: any submission is ignored and
  the current grade is kept (an empty submission never wipes it).
- A validator cannot give the first grade: when the teacher has not graded yet, the rubric is read only.
- Saving without clicking "Validate" keeps the teacher grade.
- Graders see "Grade validated by…" and the original grade of each criterion; students do not see the validation.
- The `\gradingform_rubric_ranges\event\grade_validated` event identifies the graded item by `other.itemid`
  (there is no generic API to get the student from it, so `relateduserid` is not set).
- JavaScript is required to validate. Validation from the grader side panel is out of scope, but the lock
  also applies there.
- With `enablevalidation` disabled the plugin behaves as upstream, even for grades validated before.

## Notes on backup and restore

- Weights hang from each criterion (`rubric_ranges_weight`): they are copied with or without user data.
- The validation history hangs from each grading instance (`rubric_ranges_validations`): like the grades, it is
  only copied with user data. Teacher and validator are annotated as users of the backup. The persistent
  technical fields are regenerated on restore; `timevalidated` is kept.
- On restore, instance, criterion, levels, graded item and users are remapped. A row whose criterion or graded
  item cannot be mapped is skipped. When the teacher or the validator cannot be mapped the row is kept with an
  empty user, so the grade stays validated and locked.
- Backups made before the fork restore with weight 1 and no validation. Backups made with the fork restore as
  upstream in a site with the upstream plugin (unknown elements are ignored). Duplicate and import (no user
  data) copy the weights but not the history.

## Notes on privacy

- Graded user (called by `core_grading` through `gradingform_provider_v2`): the export includes the numeric
  grade of each criterion, criteria without level, and the validation history (original and validated grade,
  level and feedback, and the date) **without** the identity of the teacher or the validator. Deleting the
  user's data deletes the fillings and the validation history of the instances.
- Teacher and validator: the plugin is also a `plugin\provider` and `core_userlist_provider` on its own (no
  precedent in core for combining it with `gradingform_provider_v2`, but the privacy manager allows it and the
  component is reported compliant). Their export lists the validations they did or that affected their grades
  (role, item, criterion, grades, date and only the feedback they wrote). On deletion they are **anonymised**
  (`validatorid` 0, `teacherid` null): the rows are kept so the grade stays validated.
- `gradingform_rubric_ranges_w.usermodified` is declared and treated like core treats the grading
  definitions (not exported apart nor deleted).
- Upstream fix: the export used a `JOIN` on levels, so criteria without level were lost.
- Known upstream limitation kept as is: the export is keyed by the criterion description, so criteria with the
  same description are merged. Changing the key would change the export contract (and the upstream test).

## Upstream fixes included

- `update_definition()` read `rubric['regrade']` instead of `rubricranges['regrade']`, so instances were never
  marked for regrade. Same field name fix in `edit.php` for the `lockzeropoints` warning.
- With numeric grading enabled, the PHP warning for the undefined `$currentgrade` in `criterion_template()`
  (remarks disabled, `DISPLAY_EVAL_FROZEN`) no longer happens.

## Data access

Tables added by the fork are accessed only through `\core\persistent` classes (`classes/persistent`);
business rules live in `classes/local`.

## Capability

`gradingform/rubric_ranges:validate` (module context). It is not granted to any archetype: grant it to the validator role, which must also be able to grade the activity (e.g. a copy of the teacher or manager role).
