# Branch map

**The merge-back happened (2026-07-20).** `main` is the product line again: river's
166 commits of feature work came home as one squash (`73519ee`), followed by the
de-River standardization (`d23f1a8`) — standard `id` PKs, `licenses` table,
`company_vendor`/`device_user` pivots, encrypted `login_pass`, `tasks` table.
Full suite green on a fresh-migrated database (40/40).

## Branches now

| Branch | Where | Role |
|---|---|---|
| `main` | GitHub (public) + local | THE product line. All new work lands here. |
| `river` | local + `box` remote ONLY | Frozen handoff of the River-integrated build (tag `river-handoff-2026-07-20`). Runs against the live River MariaDB on ITRack. **Never push to GitHub** — its history contains internal env backups and task data. |

## If river ever needs a fix

Patch it on `river`, push to `box`. Don't merge river into main (its history must
not reach the public repo); if a river fix belongs in the product, re-apply it on
main with standard keys. The old schema-adaptation inventory lives in this file's
history (`git log -p BRANCH-MAP.md`) if you need the mapping again.
