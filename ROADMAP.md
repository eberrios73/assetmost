# AssetMost — Roadmap / Ideas

Not committing to timelines — a running list of things to build, and notes carried
over from the legacy app (which does some of these, less elegantly).

## Remote access (keep in mind, don't build yet)
- **Tie in RustDesk** for remote desktop into a device straight from its record.
  RustDesk now has a **native terminal** (like ScreenConnect), so we can build
  remote-in *into* AssetMost — no need to launch RustDesk separately.
- Optionally support native **Windows RDP / macOS Screen Sharing** as a per-device
  or per-company **setting** (a toggle, not a hardcoded path).
- Anchor: this lives on the **device** record (computers/laptops), next to the
  asset details.

## Credentials (login) parity with the legacy app
- Row actions the old app had and users rely on: **copy username, copy password,
  share, edit** — plus delete. (Password copy needs a gated, audited reveal
  endpoint since secrets are encrypted at rest.)
- The **Vendor** column is redundant on a person's login list (Name is almost
  always the vendor) — drop it there.

## Tasks
- Wire the **Tasks** tab to the existing task DB (needs its schema).

## Editions / productization
- Single-tenant (one company) = open core, $99. Multi-tenant (many companies) =
  private module, $199 hosted-only. Tenancy is already behind the `TenantResolver`
  contract — the multi module is a one-line binding override.

## Pre-open-source cleanup
- Remove leftover Breeze scaffold (`Welcome.jsx`, `AuthenticatedLayout.jsx`,
  Profile partials) not used by AssetMost.
- README with the story (messy AI build → clean multi-tenant Inertia/React),
  screenshots, run steps; MIT license; `.env.example` is already present.
- Sanitize/remove the one-off `iter:import` (legacy DB creds now via env).
