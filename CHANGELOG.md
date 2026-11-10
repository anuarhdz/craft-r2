# Release Notes for Cloudflare R2

## 0.1.0 - 2026-11-10

### Added

- A standalone Cloudflare R2 filesystem plugin for Craft CMS 5.9 and later.
- Environment variable support for credentials, bucket, region, and subfolder settings.
- Paginated file and directory listings, streamed reads and uploads, and MIME type support.
- Encoded copy sources and directory renames that copy all objects before deleting sources.
- Guards against root deletion, root renaming, parent path segments, and malformed pagination.
- A plugin icon, isolated DDEV environment, Craft coding standards, static analysis, unit tests, and CI checks.

### Fixed

- Reject malformed account IDs before constructing the R2 endpoint.
- Avoid creating a slash object when asked to create the filesystem root.
- Apply explicitly supplied MIME types when copying objects.
- Report missing buckets as operational errors rather than missing files when R2 supplies that error code.
- Reject detached responses that cannot provide a readable stream.
- Exclude development files from release archives while retaining user documentation and licensing.
