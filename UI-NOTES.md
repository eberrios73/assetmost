# ITer — UI / layout notes (from owner)

## Global layout
- **Add a global header** (top row) across the whole app — currently there is none.
- Structure becomes:
  - **Row 1:** header (full width)
  - **Row 2:** two columns (the existing two-column content — list on left, detail on right)
- (Current app is sidebar + main with no header; the header is the new piece.)

```
┌─────────────────────────────────────────────┐
│ HEADER (full width)                          │
├───────────────┬─────────────────────────────┤
│ left column   │ right column                 │
│ (list/nav)    │ (detail)                     │
└───────────────┴─────────────────────────────┘
```

## Detail-panel tabs
Order: **Logins · Licenses · Devices · Subscriptions** (Devices is the new 3rd tab).

**Licenses and Subscriptions are ONE table** (`subscriptions`) — not two. They're shown as
two labels/views over the same data (can split by a filter/type later if desired), not
separate storage.

## Header
- **Company (tenant) switcher** lives in the header — done. Server-validated; filters all
  data via the tenancy scope. "All companies" available to SuperAdmin/IT Admin.
- **User menu** lives in the header — done (avatar, email, role, Profile, Log out).
