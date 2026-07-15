# AssetMost

**IT asset, identity, credential, and documentation management** for MSPs and small IT teams — one place for the people, devices, licenses, logins, and runbooks a company depends on.

Built with Laravel + Inertia + React. Multi-tenant, server-side scoped, with a Notion-style docs wiki, an encrypted credential vault, dark mode, and a clean single-component architecture.

> **Origin.** AssetMost is a ground-up rebuild of an earlier, AI-assisted Laravel app that had grown messy — duplicated CRUD stacks, thousands of lines of inline JS, and no server-side tenant isolation. This repo is the clean rebuild: one reusable component layer, enforced multi-tenancy, and a data model designed to merge with a documentation canvas.

---

## Highlights

- **Workspace model** — `People · Assets · Tasks · Docs`, with sub-tabs (Staff/Vendors/Onboarding, Devices/Locations/Rooms) at the top of each list.
- **Server-side multi-tenancy** — every record is scoped to a company by an Eloquent global scope + a `TenantResolver` the whole app depends on. No client-supplied `company_id`, ever.
- **Editions via one line** — `single` (one company) is the open core; `multi` (many companies + switcher) swaps in by binding a different resolver. `ASSETMOST_EDITION` picks it.
- **Reusable everything** — a single `EntityList` (search · sort · filter · infinite scroll), `RecordModal` (create/edit in a side drawer), and `Tabs` drive **every** entity. Adding an entity is a config block.
- **Credential vault** — login secrets encrypted at rest; gated, audit-logged reveal for copy; "text credentials to the user's cell" (opens Messages on macOS/iOS).
- **Docs wiki** — Notion/Docmost-style canvas: rich text with a `/` slash menu (TipTap), nested pages, autosave, per-company.
- **Onboarding wizards** — guided flows to bring a new person or a new asset into the system.
- **Dark mode** — class-based, persisted, no flash.

## Stack

Laravel 13 · Inertia · React 18 · Tailwind CSS · TipTap · MySQL/MariaDB · Vite

## Architecture notes

- `app/Support/Contracts/TenantResolver.php` — the tenancy contract. `CurrentCompany` (multi) and the single-tenant resolver implement it; a private multi-tenant module overrides the binding.
- `app/Models/Concerns/BelongsToCompany.php` — global scope that auto-filters every tenant-owned model.
- `resources/js/Components/EntityList.jsx`, `RecordModal.jsx`, `Tabs.jsx` — the shared UI primitives every screen composes.
- `resources/js/entities.jsx` — one config per entity (list/detail endpoints, columns, filters, editable fields).
- Data-hygiene commands: fuzzy `login → user` backfill from login-id patterns, device-type category normalization, per-user M365 login seeding.

## Run it locally

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate

# point .env at a MySQL/MariaDB database, then:
php artisan migrate

npm run build       # or: npm run dev
php artisan serve
```

Set `ASSETMOST_EDITION=single` (one company) or `multi` (company switcher) in `.env`.

## License

[MIT](LICENSE)
