# Contributing

This public repository is a publication mirror of private source. Open an issue
here for a bug or proposal; include a reproduction and, if helpful, a patch.
Maintainers apply accepted changes in source and publish a mirror release.
Direct mirror pull requests do not update source. See the
[organization contribution guide](https://github.com/nvl-laravel-suite/.github/blob/main/CONTRIBUTING.md).

Keep Comments generic, headless, string-key-compatible, and independent of a
specific user or tenant model. Put transaction and authorization boundaries in
Actions, use DTOs for inputs and output, keep models relationship-focused, and
emit external effects only after commit.

Preserve the canonical bundled-migration layout, byte-exact identity and
classification fingerprints, and their indexes. Test text-collation edge cases
on MySQL/MariaDB as well as PostgreSQL and SQLite. For cross-connection targets,
cover lazy/eager reads, explicit existence-query rejection, post-commit creation,
and orphan-target diagnosis.

Add Pest coverage for target/audience isolation, tombstone privacy, nesting,
cycles, authorization and trusted query scopes, creation idempotency, stale
revisions, lifecycle races, report transitions, moderation/reconciliation,
attachment ownership and lock order, constant query counts, route contracts,
after-commit events, and all supported databases.

From a standalone checkout of the public Comments repository:

```bash
composer install
composer quality
```

In a consuming Laravel application, check the configured integration:

```bash
php artisan nvl:comments:doctor --strict --format=json
php artisan nvl:comments:reconcile --strict --format=json
```

The development suite requires `ext-pcntl` and a Unix-like environment for its
forked concurrency checks. Runtime consumers installing with `--no-dev` do not
need that extension.

Update documentation and the packaged skill whenever a command, config key,
schema, route, contract, or operational behavior changes.
