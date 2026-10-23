# Building self-host update packages

This is build/release-host tooling. Installed sites upload the resulting `.seoupdate` file through the browser; they do not need Composer, Node/npm, SSH, a shell, ZIP/Phar, native archivers or network access to apply it.

## Inputs

Start with an already-built clean runtime tree produced by `scripts/release/build-release.php`, including production-only Composer dependencies, prebuilt Vite assets and bundled open fonts. Do not run `config:cache`, `route:cache` or `event:cache` on a distributable tree: those are installation-specific and cached config can contain build-host secrets. Such cached bootstrap state is rejected.

Provide all of these deliberately:

- exact allowed source ProductVersion;
- source application schema fingerprint;
- expected target application schema fingerprint;
- stable/beta/development channel;
- target runtime requirements;
- explicit UTC creation time for reproducible API builds.

The target ProductVersion comes from the built tree's `VERSION`. A same-version update or downgrade is rejected. Source compatibility is never inferred from the build-host database or Git history. A beta/development channel never relaxes compatibility checks.

## Build-host command

```bash
SOURCE_DATE_EPOCH=1792713600 php scripts/release/build-update-package.php \
  /absolute/target-runtime-tree \
  /absolute/output/release.seoupdate \
  0.1.0-alpha.1 \
  <source-schema-sha256> \
  <target-schema-sha256> \
  development
```

Replace both fingerprint placeholders with actual 64-character lowercase SHA-256 values from the verified source/target schemas. The command defaults to `stable` if channel is omitted. It uses the supported PHP 8.4.1 floor and explicit baseline extensions; the `BuildSystemUpdatePackage::build` API accepts runtime requirements in compatibility metadata for release automation. `SOURCE_DATE_EPOCH` fixes the timestamp; without it the command uses build time.

Output must be outside the target tree, in an existing directory, and must not already exist. The builder writes a private temporary file and fully reads the package back through the production verifier before publishing. Failed builds clean their owned temporary file and preserve existing output. Source symlinks, development/secret root files, private runtime state and stable public launchers are not valid update payloads. Live storage is never read into the bundle.

## Determinism and resource bounds

Files are sorted by relative path with `SORT_STRING`. Payloads are streamed through the existing `seo-update/1` writer, preserving its exact byte format, entry/content SHA-256 checks and size/count limits. File inputs open lazily and close after each pass; the builder does not hold an entire vendor tree open or buffer it in memory. Existing caller-owned writer streams are never closed by the writer.

The migration-tree digest is SHA-256 of `UpdatePackageFormat::canonicalMetadataJson` applied to the sorted list of migration entry metadata, with exactly `path`, `size`, `sha256`, `media_type`; media type is `application/octet-stream`. The emitted manifest's actual migration entries must reproduce that digest before publication.

## Runtime acceptance

After constructing a no-dev runtime tree on the build host:

```bash
php scripts/release/smoke-update-runtime.php /absolute/built-runtime-tree
```

This creates disposable fixtures and real HTTP processes, builds and verifies a complete package, stages it with the production stager, serves source -> private target slot -> source in separate requests, and checks stable environment/storage binding and source/target hashed public assets. It is a permanent CI verification step. Process use is confined to build/CI acceptance; production updater code does not invoke it.

This smoke does not prove migration-coordinator or MariaDB interruption recovery. Those remain separate permanent acceptance gates in the Update/Rollback plan.

## Package trust

A package contains executable application/vendor code. Manual upload is a trusted-code installation operation. SHA-256 checks prove integrity, not publisher identity; release-signing infrastructure remains unverified and is required before public/commercial automatic distribution. No “publisher verified” claim is made by this tooling.
