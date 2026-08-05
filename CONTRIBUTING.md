# Contributing

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

From the monorepo root:

```bash
vendor/bin/pint --format agent packages/nvl/comments
vendor/bin/phpstan analyse \
    packages/nvl/comments/src \
    packages/nvl/comments/tests/Fixtures \
    --level=max \
    --memory-limit=2G
vendor/bin/pest \
    --test-directory=packages/nvl/comments/tests \
    --configuration=packages/nvl/comments/phpunit.xml.dist \
    --bootstrap=vendor/autoload.php \
    --compact \
    packages/nvl/comments/tests
php artisan nvl:comments:doctor --strict --format=json
php artisan nvl:comments:reconcile --strict --format=json
```

From a standalone package checkout:

```bash
composer install
composer quality
```

The development suite requires `ext-pcntl` and a Unix-like environment for its
forked concurrency checks. Runtime consumers installing with `--no-dev` do not
need that extension.

Update documentation and the packaged skill whenever a command, config key,
schema, route, contract, or operational behavior changes.
