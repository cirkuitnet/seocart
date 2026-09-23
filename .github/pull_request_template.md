<!--
This checklist is the project's definition of done, and this file is the only copy of it
in the repository. CONTRIBUTING.md and docs/testing.md link here instead of repeating it.

Check every row before you request review. A row that does not apply is checked and
marked "n/a" with one clause of why. An unexplained blank is an unfinished checklist.
-->

## Summary

<!-- What changes, and why. Link the public issue, if there is one. -->

## How to test

<!-- The commands you ran and what a reviewer should see. -->

## Definition of done

### Contract and tests

- [ ] The goal and the acceptance criteria were written down before implementation started.
- [ ] Tests exist for the contract. They were written first or alongside the code, not after.
- [ ] The implementation is complete. Domain code makes no direct call to a WordPress function or global, unless that is architecturally justified and noted in this pull request.

### Static checks and automated tests

- [ ] `composer lint:php` passes.
- [ ] `composer validate --strict` passes.
- [ ] `composer cs` passes with zero new warnings.
- [ ] `composer stan` passes at the current level with zero new baseline entries.
- [ ] `composer test:unit` passes.
- [ ] `composer test:tools` passes, if the change touches `tools/` or `bin/`.
- [ ] `composer test:integration` passes against a real MySQL database.
- [ ] `composer test:contracts` passes (the DRY derivation checks).
- [ ] `composer docs:check` passes: `docs/openapi.json` and `docs/reference/{abilities,cli,errors,hooks}.md` are regenerated, committed and free of drift, and no generator skipped anything unexpectedly.
- [ ] `npm run lint` passes (`lint:js`, `lint:css` and `format:check`), if the change touches JavaScript, CSS, JSON, YAML or Markdown.
- [ ] `npm run test:unit` passes, if the change touches JavaScript.
- [ ] `npm run test:e2e` passes, if the change touches a user-facing flow. There is one cart and checkout flow to target.
- [ ] `composer test:international` passes, if the change touches money, tax, currency, locale, caching or the catalog.
- [ ] `composer test:multilingual-conformance` passes on a disposable site with Polylang (free) active, if the change touches Catalog, `product_posts`, search or Notification.

### WordPress checks

- [ ] A WordPress smoke pass on a disposable site: the plugin activates cleanly, the feature is exercised once end to end, the plugin deactivates cleanly, and there are no PHP notices under `WP_DEBUG`.
- [ ] Action Scheduler coexistence was exercised with WooCommerce active on a disposable site, if the change touches Jobs or anything it schedules.
- [ ] WordPress.org gates: Plugin Check is clean; `composer wporg:check` and `composer licenses:check` pass; any new outbound endpoint is registered and appears in the generated External services section of `readme.txt`; there is no new sitewide notice that cannot be dismissed; there is no third-party iframe on an admin screen.

### DRY — the fifteen rules, mandatory in every phase

- [ ] 1. Every new REST route, Ability or WP-CLI command resolves to one `OperationDefinition` id — or is justified in this pull request as a single-consumer service that deliberately has no definition (rule 15, "the brake").
- [ ] 2. No JSON Schema literal was added outside `Support\Schema`.
- [ ] 3. Each dialect is produced by the one compiler call site, not by hand.
- [ ] 4. No class was added whose purpose is to mirror another surface.
- [ ] 5. Generated artifacts were regenerated and committed in this same change.
- [ ] 6. No generator silently skipped anything (skips are an asserted set).
- [ ] 7. No `Money` arithmetic was added under `Interfaces\*`.
- [ ] 8. Every new error code has exactly one row in the one error table.
- [ ] 9. Every new mutating route went through the shared permission factory.
- [ ] 10. Every new field carries a privacy class; `pii` fields have exporter and eraser coverage.
- [ ] 11. No new parallel identifier list without a set-equality test.
- [ ] 12. New declarations are data: the registry still builds with WordPress stubbed out.
- [ ] 13. Every new declared example validates against its own compiled schema.
- [ ] 14. No hand-written region of a generated file grew.
- [ ] 15. Any new shared abstraction names, in its docblock, the one fact it owns.

### Review and documentation

- [ ] Independent review by someone who did not write the change. A second, senior WordPress review is required for a module's first slice, for any change to the platform kernel, and for any deviation from the agreed architecture. Every reviewer checks the fifteen DRY rows above.
- [ ] Public contracts are documented with docblocks in WordPress core style. A newly public hook appears in `docs/reference/hooks.md`; that file is generated, so the change is to the declaration, not to the document.
- [ ] A consequential, hard-to-reverse design decision in this change was agreed with a maintainer before it was built; otherwise it is noted here that there is none.
- [ ] `CHANGELOG.md` has an entry under `[Unreleased]`.
