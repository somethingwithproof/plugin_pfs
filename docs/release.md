# Release process

How a version of the plugin is cut. No release has been made; 0.1.0 is prepared but not tagged.

## Versioning

- [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Phase 1 is 0.1.0. Until 1.0.0, a minor version may change the schema or templates; the upgrade path handles both.
- `INFO` carries the version of the next release. `plugin_pfs_version()` reads it, and Cacti shows it on the Plugins page.
- [CHANGELOG.md](../CHANGELOG.md) follows Keep a Changelog. Each release section is dated when it is tagged; until then it reads "Unreleased".

## Archive layout

The release archive unpacks to a directory named `pfs`, which is what Cacti expects under `plugins/`:

```
pfs/
  INFO  LICENSE  LICENSES/  README.md  CHANGELOG.md  SECURITY.md  CONTRIBUTING.md  CODE_OF_CONDUCT.md  REUSE.toml
  setup.php  index.php  pfs.php  pfs_admin.php  pfs_health.php  poller_pfs.php
  include/  lib/  scripts/  resource/script_server/  templates/  locales/  node/  docs/
```

Tests, CI configuration, and analysis configuration are left out by `export-ignore` in `.gitattributes`.

## Steps

1. On a branch, set the version in `INFO`, date the CHANGELOG section, and regenerate `locales/po/cacti.pot` with `sh locales/build_gettext.sh`. Do this on every version change, not only when strings change: the template's `Project-Id-Version` header carries the version from `INFO`, so `sh locales/build_gettext.sh --check` in CI fails until it is regenerated.
2. Merge after CI passes on every matrix entry. The end-to-end runs in `tests/bin` (device flow, target churn, restore, failure, scale, and the Thold and Maint replay) are not in CI yet; run them against a disposable Cacti 1.2.31 and the 1.2.x branch and attach the output to the release pull request.
3. Tag the merge commit with a signed tag, `git tag -s v<version> -m "plugin_pfs <version>"`.
4. Build the archive from the tag and check its contents and its REUSE compliance:

   ```sh
   git archive --format=tar.gz --prefix=pfs/ -o pfs-<version>.tar.gz v<version>
   tar -tzf pfs-<version>.tar.gz | head
   ```

5. Publish the archive and its SHA-256 on the GitHub release for the tag, with the CHANGELOG section as the notes.

A GitHub source download unpacks as `plugin_pfs-<version>/` and must be renamed to `pfs`; the operator guide says so.
