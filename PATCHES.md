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

## Upstream fixes included

- `update_definition()` read `rubric['regrade']` instead of `rubricranges['regrade']`, so instances were never
  marked for regrade. Same field name fix in `edit.php` for the `lockzeropoints` warning.

## Data access

Tables added by the fork are accessed only through `\core\persistent` classes (`classes/persistent`);
business rules live in `classes/local`.

## Capability

`gradingform/rubric_ranges:validate` (module context). It is not granted to any archetype: grant it to the validator role, which must also be able to grade the activity (e.g. a copy of the teacher or manager role).
