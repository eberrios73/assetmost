# river → main merge-back map

`main` is deliberately frozen (last commit `8b664a9`); all product work continues on
`river`, which is `main` + River-schema adaptations + everything since. When we bring
river home, this is the recipe. Update this file with every substantive river commit.

## The one structural difference

River adapts models to the LIVE River DB; main assumes AssetMost's own schema. The
adaptations are **confined to**:

| Concern | river | main |
|---|---|---|
| License table | `subscriptions` (+ `name` ⇄ `subscription_name` accessor) | `licenses` |
| Login PK / FKs | `loginID`, `vendorID`, `deviceID`, `userID` (legacy, kept for ITer) | `id`, standard FKs |
| Vendor PK | `vendorID`, pivot `vendor_client` | `id`, `company_vendor` |
| Device PK | `deviceID`, `creation_timestamp`, pivot `device_users` | `id`, standard |
| Credential storage | plaintext `login_pass` (matches ITer) | `encrypted` cast |
| Validation strings | `exists:vendors,vendorID` etc. | `exists:vendors,id` |

**Merge rule: take river's feature code wholesale, then re-standardize ONLY the key
names / table names / casts above.** Everything else (components, controllers,
routes, migrations) ports as-is.

## Commits on river ahead of main (oldest first)

| Commit | What | Port action |
|---|---|---|
| 31a008b..09ed59c | Tasks polish, ghost assignee, phantom-column fix, VITE_APP_NAME, reveal-in-drawer | cherry-pick clean |
| e10aa06 | River licensing/identity schema application | **skip** (river-only; main has its own migrations for the same shape) |
| 61a0981 | Locations shared (company_id nullable) | port the migration to main |
| 7327c2c | Products under vendors (ProductController, VendorProducts) | cherry-pick; fix vendor PK refs |
| d5f7d47 | Merge of main 8b664a9 | already common history |
| 6ab7ca3 | **ui/DataTable + ui/AddButton everywhere**; EntityList column-header sort | cherry-pick clean (pure JSX) |
| 2eabc23 | License ⇄ logins attach (login_ids, /data/login-options) | cherry-pick; `exists:logins,loginID` → `id` |
| abe3a45 | MultiPicker search affordance | clean |
| d9ed64c | Rooms chained under Location; Rooms tab removed; RecordModal `extra` | clean |
| 6bcb90b..25e9946 | Accounts tab evolution (superseded by c35c754) | **squash-skip** — take c35c754 instead |
| c35c754 | **First-class `accounts` + `account_user` + `logins.account_id`** (migration 2026_07_17_150000) | port migration + Account model; backfill query included in commit message |
| 4380564 | Service/Device fused column; **PasswordGate + ConfirmAccountsAccess** (423 + throttle + audit) | clean |
| 9550dfd | Tasks Age column (origin-based aging) | clean |
| caf3bc9 | Future-week roll-in preview | clean |

## Data-only operations applied to prod River (no code; main needs NOTHING)

- Baselined pre-applied migrations in `migrations` table (batch inserts).
- `users.can_login` backfill: only active IT Admin/SuperAdmin with passwords.
- Sharing flags: itmgr-held + role-named logins → `shared`; artist00N pooled.
- Accounts backfill: 34 identities, 82 linked logins, 40 assignments; artist00N
  Domain+Microsoft pairs merged; typo'd `itmgr@clientdomain.com` account folded in.
- Typos normalized in `logins.login_id` (clientdomain, clientdomain, clientdomain…,
  pictuerplaen…, bare `@pictureplane`, login_name `Microsot`).
- 11 duplicate login rows deactivated `[duplicate of #id]`.
- PENDING (user runs): locations dedupe (keep LA id 1 + site id 4 as shared);
  deactivate 32 orphaned onboarding stubs.

## Still to build (both branches eventually)

- Onboarding wizard v2 (draft-first, atomic, per-company service catalog) — design
  agreed 2026-07-17; old wizard autopsy in session notes.
- Assignment history for floating accounts (who held what, when).
- Tasks: parent project link + Timeline (Gantt) — in progress on river.
