# IED patches for gradingform_rubric_ranges

This branch only holds the patch series of the IED fork of
[gradingform_rubric_ranges](https://github.com/catalyst/moodle-gradingform_rubric_ranges). The code lives in the
`ied/MOODLE_402_STABLE` branch; each release is tagged there as `ied-vX.Y.Z`.

| Release | Tag | Base (upstream) | Moodle | Patches |
|---|---|---|---|---|
| [v1.0.0](v1.0.0/) | `ied-v1.0.0` | `MOODLE_402_STABLE` @ `2aece39` (2024112200) | 4.5 LTS | 9 (T0–T8) |

Each release folder contains the patches, `PATCHES.md` (how to apply them, set up the features and rebase on a new
upstream version) and `SHA256SUMS`.

Quick start, from the plugin root (`grade/grading/form/rubric_ranges`) on upstream `2aece39`:

```bash
sha256sum -c SHA256SUMS
git am -3 /path/to/v1.0.0/*.patch
php admin/cli/upgrade.php
```

Both `git am -3` and `patch -p1` (without git) were checked to apply cleanly on `2aece39` and to produce exactly the
tree of `ied-v1.0.0`.
