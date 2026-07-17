<?php

namespace App\Support;

/**
 * The SOPs that ship with the product, so a fresh install runs its first
 * onboarding without writing anything. Adopting one copies it into the
 * company's templates — from then on it's theirs to edit; upgrades never
 * touch adopted copies. Pasting your own SOP remains the other door.
 *
 * offset_days is relative to the run's anchor date: DOH for onboarding,
 * LAST DAY for offboarding, and day-of for imaging.
 */
class StarterTemplates
{
    public const KINDS = ['onboarding' => 'Employee onboarding', 'offboarding' => 'Employee offboarding', 'imaging' => 'Workstation imaging'];

    public static function get(string $kind): ?array
    {
        return match ($kind) {
            'onboarding' => self::onboarding(),
            'offboarding' => self::offboarding(),
            'imaging' => self::imaging(),
            default => null,
        };
    }

    private static function step(string $id, string $title, string $cat, int $offset, string $instr = '', array $subs = [], bool $auto = false): array
    {
        return ['id' => $id, 'title' => $title, 'category' => $cat, 'offset_days' => $offset,
            'instructions' => $instr, 'automatable' => $auto, 'subtasks' => $subs];
    }

    private static function onboarding(): array
    {
        $s = fn (...$a) => self::step(...$a);
        return ['version' => 1, 'steps' => [
            $s('accounts', 'Create accounts', 'accounts', -2,
                'Pre-arrival — complete at least 2 days before {start_date}. Vendor accounts picked in the wizard are created in the registry automatically; create them in each console using the stored credentials.'),
            $s('hardware', 'Prepare hardware', 'machine', -2, 'Workstation ready at their desk before day one.', [
                $s('hw-station', 'Prepare workstation, peripherals and headset', 'machine', -2),
                $s('hw-config', 'Configure device (OS updates, antivirus, disk encryption)', 'machine', -2),
            ]),
            $s('software', 'Install software', 'machine', -2, 'Standard set plus role-specific tools — list them here.'),
            $s('security', 'Security setup (MFA, SSO)', 'access', -2, 'MFA enrollment finishes on day one with the hire present.'),
            $s('network', 'Configure network access', 'access', -2, 'Office and remote access; restrict where applicable.'),
            $s('orientation', 'Welcome orientation', 'training', 0, 'Review IT policies and get a signature; explain password and phishing basics.'),
            $s('handoff', 'Device hand-off and first login', 'machine', 0, 'At their desk: test network, guide through first login. Credentials come from the registry — never a sticky note.'),
            $s('walkthrough', 'System access walkthrough', 'training', 0, 'Email, collaboration tools, project systems, where to get IT help.'),
            $s('sec-training', 'Security training', 'training', 1, 'Security video watched and policies acknowledged in writing.'),
            $s('followup', 'One-week follow-up', 'other', 7, 'Confirm everything works; collect feedback for the next run.'),
        ]];
    }

    private static function offboarding(): array
    {
        $s = fn (...$a) => self::step(...$a);
        return ['version' => 1, 'steps' => [
            $s('inventory', 'Inventory their access', 'accounts', -2,
                'Before the last day: open the person in AssetMost — their logins, floating accounts, devices and license seats ARE the checklist for everything below.'),
            $s('disable', 'Disable sign-ins', 'accounts', 0, 'LAST DAY — in this order, before they leave the building.', [
                $s('off-ad', 'Disable the Domain/AD account', 'accounts', 0),
                $s('off-m365', 'Block Microsoft 365 sign-in and revoke active sessions', 'accounts', 0, '', [], true),
                $s('off-vpn', 'Revoke VPN and remote access', 'access', 0),
            ]),
            $s('floating', 'Rotate floating accounts they held', 'accounts', 0,
                'Every pooled/shared account they held gets a new password TODAY — reassign or return seats in the registry. A departed person with a living shared password is a breach waiting.'),
            $s('collect', 'Collect hardware', 'machine', 0, '', [
                $s('col-laptop', 'Laptop/workstation, chargers, peripherals', 'machine', 0),
                $s('col-badge', 'Badge, keys, access cards', 'access', 0),
            ]),
            $s('mail', 'Mail and voicemail routing', 'accounts', 0,
                'Set mailbox forwarding/auto-reply per policy; clear their voicemail forwarding.'),
            $s('seats', 'Reclaim license seats', 'accounts', 1, 'Free their seats in the registry so the counts are honest.'),
            $s('archive', 'Archive mailbox and files', 'other', 3, 'Per your retention policy; confirm before removal from groups.'),
            $s('deactivate', 'Deactivate in the directory', 'other', 3, 'Mark the person inactive in AssetMost — their history stays.'),
            $s('audit', 'Final access audit', 'other', 7,
                'Re-open their page: zero active logins, zero floating accounts held, zero devices assigned. Anything left is a finding.'),
        ]];
    }

    private static function imaging(): array
    {
        $s = fn (...$a) => self::step(...$a);
        return ['version' => 1, 'steps' => [
            $s('intake', 'Intake and inventory', 'machine', 0,
                'Record the machine in AssetMost (Assets > Onboard) — the asset tag it issues becomes the hostname.'),
            $s('image', 'Apply the standard image', 'machine', 0, '', [
                $s('img-os', 'Install/apply standard build (OS version per current baseline)', 'machine', 0),
                $s('img-updates', 'All OS and firmware updates', 'machine', 0),
                $s('img-drivers', 'Drivers and vendor tools', 'machine', 0),
            ]),
            $s('configure', 'Configure and join', 'machine', 0, '', [
                $s('cfg-hostname', 'Set hostname to the asset tag', 'machine', 0),
                $s('cfg-domain', 'Join to the domain', 'machine', 0),
                $s('cfg-security', 'Antivirus, disk encryption, management agent', 'machine', 0),
            ]),
            $s('software', 'Install the standard software set', 'machine', 0, 'List the standard set here; add role-specific tools per deployment.'),
            $s('qa', 'QA before hand-off', 'machine', 0, '', [
                $s('qa-login', 'Test domain login, network, printers, peripherals', 'machine', 0),
                $s('qa-assign', 'Assign to its user/location in AssetMost', 'machine', 0),
            ]),
        ]];
    }
}
