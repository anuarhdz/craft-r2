# Release readiness review

Reviewed on 2026-11-10.

Release preparation on 2026-11-10: the initial repository commit has been created, and the changelog is dated for tag `0.1.0`. Strict Composer validation, coding standards, static analysis, 25 unit tests (45 assertions), dependency audit, and the disposable Craft installation/lifecycle checks passed again. The review below records the pre-release assessment; live R2 validation and distribution service setup remain outstanding.

## Decision

The repository is suitable for an initial private GitHub repository after reviewing and committing the working tree. Public release remains conditional on a live R2 smoke test, a dated release/tag, working public documentation links, and the chosen distribution accounts.

This review does not certify the code as security audited or approved by Pixel & Tonic. Their Plugin Store approval checks listing and package requirements; their published approval process explicitly does not evaluate code quality or security.

## Changes made

- Added an independent DDEV package environment on PHP 8.2 and MariaDB 10.11, with a placeholder document root separate from source code.
- Added a disposable Craft installation check (`ddev test-install`) and a CI installation/lifecycle job.
- Added a plugin icon, package keywords, homepage, and raw Markdown changelog URL.
- Renamed the MIT license to `LICENSE.txt` and added the package author's copyright, preserving the existing Pixel & Tonic notice.
- Kept the package version implicit in Git tags. The changelog remains unreleased until an actual release is cut.
- Excluded skills, DDEV, CI, tests, local tooling, and internal review documents from release archives. Runtime code, icon, Composer manifest, README, changelog, and license remain included.
- Ignored environment files, Composer authentication files, distribution artifacts, and the disposable Craft application.
- Added EditorConfig, a combined Composer check command, read-only CI permissions, independent PHP matrix failures, dependency auditing, and an archive allowlist.
- Restricted endpoint construction to a 32-character hexadecimal Cloudflare account ID.
- Made root directory creation a no-op, required a valid stream from streamed reads, detected repeated pagination tokens, and distinguished an explicit `NoSuchBucket` response from missing objects.
- Set S3's metadata replacement directive when a copy explicitly supplies a MIME type; copies without that option preserve source metadata.
- Added IDs and validation error output to settings fields, plus environment-reference guidance for secrets.

## Local validation

The dependency graph was freshly resolved in DDEV on PHP 8.2.35. The previous ignored lock file contained dependencies requiring PHP 8.3/8.4; a backup was retained locally before regenerating it. No application dependencies outside this repository were changed.

| Check | Evidence |
| --- | --- |
| Composer manifest | `ddev composer check` passed strict validation |
| Craft coding standards | Official `craftcms/ecs` preset passed |
| Static analysis | Official `craftcms/phpstan` configuration, level 5, passed |
| Unit tests | 25 tests and 45 assertions passed using the AWS SDK mock transport |
| Dependency advisories | Fresh resolution and Composer audit reported no advisories or abandoned packages for the resolved dependencies |
| Consumer installation | Composer installed this package as a path dependency in a separate Craft application |
| Craft integration | Fresh fixture and database setup passed through `ddev test-install`; Craft 5.12.1 installed in the dedicated `craft_r2_test` database; plugin registration, server-side CP settings rendering, and environment-reference serialization passed |
| Plugin lifecycle | CLI uninstall/reinstall passed, followed by the same integration assertions |
| Release archive | A temporary Git index/tree produced a 12-entry archive containing only `src/`, `composer.json`, `README.md`, `CHANGELOG.md`, and `LICENSE.txt`; the real Git index was unchanged |
| Shell scripts | `bash -n` passed for fixture setup and the DDEV command |

The DDEV project is named `craft-r2`. Its test command grants the local development database user access only to the additional `craft_r2_test` database in this project's database container. The fixture is under `.test-app/`, ignored by Git. Re-running the command reuses the fixture without dropping its database. Never point it at a real site.

The integration assertion inspects server-rendered field containers. Craft's autosuggest inputs are hydrated by Vue; this check is not browser interaction or a visual accessibility review.

## Pixel & Tonic requirements

| Requirement | Status |
| --- | --- |
| Composer package type and entry point | `craft-plugin`; `anuarhdz\\r2\\Plugin` |
| Coherent package/name/handle | `anuarhdz/craft-r2`, Cloudflare R2, `r2` |
| Name must not begin with Craft | Passes |
| Craft version constraint | `^5.9.0`; PHP `>=8.2` |
| Unique plugin icon | `src/icon.svg` included |
| License | MIT manifest and `LICENSE.txt`; appropriate for a free plugin |
| Documentation | README prepared; GitHub URL becomes publicly usable after the repository is created and made public |
| Changelog | Root `CHANGELOG.md`; configure the same path in Craft Console; raw URL provided in Composer metadata |
| Public repository | Pending by design; required before Store submission |
| Store listing | Pending: categories, keywords, short/long descriptions, optional screenshots, pricing decision, and approval |
| Tagged release | Pending: date the first changelog heading and push an immutable semantic version tag |

Use the current publishing guide when it differs from the older approval article. The current guide permits MIT with `LICENSE.txt` and describes additional open source licenses; the older article lists a narrower license policy and refers to `LICENSE.md`.

## Remaining evidence before public release

1. **Live R2:** use a disposable bucket/prefix and bucket-scoped Object Read & Write S3 credentials. Exercise upload, download, streamed upload/download, metadata/MIME type, Unicode/spaces in copy sources, file copy/rename/delete, nested directory listing/rename/delete, and a listing with more than 1,000 objects. Verify public URLs through the intended custom domain, if enabled. Confirm failures do not remove source files. No real R2 account or credentials were used in this review.
2. **Compatibility:** local execution covered PHP 8.2 with Craft 5.12.1. CI is configured for PHP 8.2, 8.3, and 8.4 plus a real Craft install job, but those GitHub jobs have not run. The declared minimum Craft 5.9.0 and PHP versions beyond the CI matrix have not been separately tested. Run the desired minimum-version checks or narrow the constraint before promising that coverage.
3. **Consumer package:** local integration uses a symlinked path dependency and the release archive was checked separately. After the first tag, install the actual tagged dist in a clean Craft project to prove the GitHub/Packagist delivery path.
4. **Public metadata:** confirm repository ownership, name/handle availability, support URLs, and the documentation/changelog URLs after creating the GitHub repository. Availability was not reserved by this work.
5. **GitHub execution:** inspect the first CI run after pushing. Neither a local green check nor a workflow file proves GitHub execution.

## Private repository and publication sequence

1. Review the working tree, create the initial conventional commit, create the private GitHub repository under the intended owner, and push `main`. No remote repository, commit, tag, or publication was created by this review.
2. Use the README's authenticated Composer VCS instructions during private development. A private GitHub repository is distinct from a Craft "private plugin" (an underscore-prefixed handle). Keep `r2` if eventual Store publication is intended.
3. Complete the remaining validation above and inspect CI. Keep real credentials in application environments, outside Git.
4. When ready, make the GitHub repository public. Verify support/docs URLs and the raw changelog are accessible without authentication.
5. Replace the changelog's `Unreleased` heading with `## 0.1.0 - YYYY-MM-DD` using the actual release date. Commit the release changes, tag `0.1.0`, and push the commit and tag. Omit a Composer `version` field so tags remain authoritative.
6. Submit the public repository URL to Packagist.org and enable its GitHub update hook. Verify `https://repo.packagist.org/p2/anuarhdz/craft-r2.json` serves the intended version and source reference. Test a clean consumer install from the tagged dist.
7. If listing on the Plugin Store, connect GitHub to Craft Console, create/select the vendor organization, add the public repository, configure the listing and `CHANGELOG.md` path, and submit for approval. Packagist and Store approval are separate steps; Packagist is optional for Store listing.

Packagist.org distributes public packages. If the source must remain private permanently, use Private Packagist or an authenticated Composer VCS repository instead. Do not submit private code to Packagist.org expecting it to remain private.

## Sources

- [Craft plugin guide](https://craftcms.com/docs/5.x/extend/plugin-guide.html)
- [Publishing to the Plugin Store](https://craftcms.com/docs/5.x/extend/plugin-store.html)
- [Plugin Store approval process](https://craftcms.com/knowledge-base/plugin-store-approval-process)
- [Changelogs and updates](https://craftcms.com/docs/5.x/extend/changelogs-and-updates)
- [Craft coding guidelines](https://craftcms.com/docs/5.x/extend/coding-guidelines.html)
- [Packagist submission and private packages](https://packagist.org/about)
- [R2 S3 API compatibility](https://developers.cloudflare.com/r2/api/s3/api/)
- [R2 S3 authentication](https://developers.cloudflare.com/r2/api/tokens/)
