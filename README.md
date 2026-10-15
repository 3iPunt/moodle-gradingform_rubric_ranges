# IED patches for gradingform_rubric_ranges

This branch only holds the patch series of the IED fork of
[gradingform_rubric_ranges](https://github.com/catalyst/moodle-gradingform_rubric_ranges). The code lives in the
`ied/MOODLE_402_STABLE` branch; each release is tagged there as `ied-vX.Y.Z`.

| Release | Tag | Base (upstream) | Moodle | Plugin version | Patches |
|---|---|---|---|---|---|
| [v1.1.0](v1.1.0/) (latest) | `ied-v1.1.0` | `MOODLE_402_STABLE` @ `2aece39` (2024112200) | 4.5 LTS | `2024112202` | 10 (T0–T9) |
| [v1.0.0](v1.0.0/) | `ied-v1.0.0` | `MOODLE_402_STABLE` @ `2aece39` (2024112200) | 4.5 LTS | `2024112201` | 9 (T0–T8) |

Each release folder contains the patches, `PATCHES.md` (how to apply them, set up the features and rebase on a new
upstream version) and `SHA256SUMS`.

## What is new in v1.1.0

Change requested by the client after v1.0.0: publication of the grades with the marking workflow of mod_assign
(the student sees nothing until the validator releases the grade), corrections of the validator until publication,
and a guard that prevents releasing a grade before it is validated. See *Notes on academic validation* and
*Setting up the extension* in `v1.1.0/PATCHES.md`: the roles and the assignment settings must be reviewed.

## Quick start

New installation, from the plugin root (`grade/grading/form/rubric_ranges`) on upstream `2aece39`:

```bash
sha256sum -c SHA256SUMS
git am -3 /path/to/v1.1.0/*.patch
php admin/cli/upgrade.php
```

Upgrade from v1.0.0 (patches 0001–0009 are the same in both releases): apply only
`v1.1.0/0010-T9-validator-corrections-until-publication-and-publi.patch`, then run `php admin/cli/upgrade.php`.

Both `git am -3` and `patch -p1` (without git) were checked to apply cleanly on `2aece39` and to produce exactly the
tree of the release tag.
