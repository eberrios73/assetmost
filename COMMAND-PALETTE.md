# Command Palette + HUD — Plan

## Thesis
A **focus-preserving action layer** over the whole app. Command in (palette), result out
(HUD), you never leave your place. Recognition over recall — you don't memorize syntax, you
type `/` and pick. The differentiator: **do, see, and connect without context-switching.**
Nobody in IT tooling has this.

## The two intents
Every command is one of two things, and the verb decides which:
- **"Take me there."** Navigate (jump to 501) or connect (`/rdp`, `/ssh`). You *want* to leave.
- **"Do it / tell me — I'm staying."** Capture, query, act. The result comes *to you* in the
  HUD. You never move.

## Trigger
`Cmd+.` / `Ctrl+.` (and `Cmd+K` as the muscle-memory default) opens the palette from any
screen. Type → arrow → enter. `Esc` closes.

## Target resolution
Type a number/name and it resolves against inventory: `501` → `PG-WS501`, no need to say
"workstation." People resolve by name/email. Scoped to the companies the user can see.
- **Address for connecting** = `asset_tag` (doubles as hostname) + company `local_domain`
  → FQDN `PG-WS501.acme.local`. Resolves for domain-joined machines on the LAN.
- **Gaps:** non-domain gear, off-LAN laptops, no DNS → would need a stored IP (later).

## Command tiers — grouped by what each actually needs
**Tier 0 — pure data (the app already has it). Instant, zero risk. → HUD card.**
- `/info 501` — specs, assigned user, location, MDM status
- `/who 501` — who holds it · `/where 501` — location

**Tier 1 — launch an existing client; the app only supplies the address.**
No agent, no app-side credentials — the client authenticates. **→ hands off.**
- `/rdp 501` — pre-filled `.rdp`; mstsc / Remote Desktop opens and prompts for creds itself
- `/rdp {user}` — resolve person → their assigned device → connect
- `/vnc 501` · `/ssh 501` · `/web 501` (device's own web UI) · `/console 501` (Jamf/Intune for it)

**Tier 1.5 — capture/act in place, no target device. → HUD receipt.**
- `/task new buy paper towels` — create a task from anywhere, stay where you are
- `/note …`, `/log …` (later)

**Tier 2 — the app actually executes remotely. → HUD output.**
Needs SSH (phpseclib, not sshpass) + admin password entered per-command (sudo-style, nothing
stored) + an audit log entry per command. **Small, deliberate, and LAST.**
- `/reboot 501` · `/tail errors 501` · live status over SSH

## The HUD
- **A single glass card** — frosted/translucent background (`backdrop-blur`), thin border,
  soft shadow, super simple text. Looks high-tech because it's minimal.
- **Ambient, not modal** — never covers your work or steals focus. You glance at it.
- **App-level** (lives in AppShell) — the *same* HUD on every page, persists across navigation.
- Collapsed to a small pill by default; expands on demand or when something lands.
- **Collects:** command receipts (`✓ task: buy paper towels`), query answers (a compact device
  card for `/info 501`), Tier-2 output (streams in), and it's the natural home for the
  onboarding/audit notifications we already built.

## Why it matters (positioning)
- **No one in IT tooling has a focus-preserving command console like this.**
- Uses **existing infrastructure** (RDP / SSH / VNC / device web UIs / MDM consoles) — the app
  is connective tissue, not a new agent.
- Unifies device actions + task capture + queries into ONE feature whose whole job is: *don't
  lose focus.*

## Build sequence
1. **Chassis** — palette (`Cmd+.`) + resolver (`501 → PG-WS501`) + navigate/jump. Safe, testable now.
2. **HUD shell** — the glass card in AppShell + a simple event bus (commands push results/receipts).
3. **Tier 0** — `/info`, `/who`, `/where` render into the HUD.
4. **Tier 1.5** — `/task new …` → HUD receipt.
5. **Tier 1 connect** — `/rdp` first (`.rdp` handoff), then the rest.
6. **Tier 2** — `/reboot`, `/tail` → SSH + per-exec creds + audit. Verify against a reachable host first.

## Open questions
- **Address gaps:** store device IPs for non-domain/off-LAN, or accept LAN+DNS only for v1?
- **Connect/exec username:** prompt each time (nothing stored) vs. store username per company?
- **HUD history:** ephemeral (this session) or a persisted activity log?
- **Daily-driver verb:** which one do you live in? That sets the priority order.
