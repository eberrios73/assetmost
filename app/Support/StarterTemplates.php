<?php

namespace App\Support;

/**
 * The SOPs that ship with the product. Adopting one copies it into the company's
 * templates  -  theirs to edit; upgrades never touch adopted copies.
 *
 * Steps use the playbook format:
 *   why         -  one line: what breaks if this is skipped
 *   instructions (How)  -  verbatim mechanics: paths, commands, GPO names
 *   done_when   -  an OBSERVABLE completion criterion (kills checkbox theater)
 *   record      -  what enters the registry/inventory (the app can hold you to it)
 *
 * Process model: preparation SOPs (workstation setup) act on ASSETS and keep a
 * ready pool stocked; employee onboarding ASSIGNS from pools (machines, floating
 * accounts, license seats) and never contains preparation steps.
 *
 * offset_days anchors: DOH for onboarding, LAST DAY for offboarding, day-of for imaging.
 */
class StarterTemplates
{
    public const KINDS = ['onboarding' => 'Employee onboarding', 'freelancer' => 'Freelancer onboarding', 'offboarding' => 'Employee offboarding', 'imaging' => 'Workstation setup', 'eprotection' => 'Endpoint protection'];

    /**
     * The shipped workflow baselines — installed with the product as real Docs
     * pages (workflow_shipped=1). Placeholders that teach what each procedure is;
     * companies edit, deactivate, or duplicate them (Other Device -> Access Point).
     * The slug is canonical: it's the /ref token and the seeder's idempotency key.
     */
    public const CATALOG = [
        // People — the run wizard creates the person + credentials + task project.
        'onboarding' => ['title' => 'Employee onboarding', 'type' => 'people', 'form_factor' => null, 'wizard' => true],
        'freelancer' => ['title' => 'Freelancer onboarding', 'type' => 'people', 'form_factor' => null, 'wizard' => true],
        'offboarding' => ['title' => 'Employee offboarding', 'type' => 'people', 'form_factor' => null, 'wizard' => false],
        // Devices — the machine wizard + bootstrap script read these.
        'windows-workstation' => ['title' => 'Windows Workstation', 'type' => 'device', 'form_factor' => 'Windows Workstation', 'wizard' => false],
        'windows-laptop' => ['title' => 'Windows Laptop', 'type' => 'device', 'form_factor' => 'Windows Laptop', 'wizard' => false],
        'mac-workstation' => ['title' => 'Mac Workstation', 'type' => 'device', 'form_factor' => 'Mac Workstation', 'wizard' => false],
        'mac-laptop' => ['title' => 'Mac Laptop', 'type' => 'device', 'form_factor' => 'Mac Laptop', 'wizard' => false],
        'windows-server' => ['title' => 'Windows Server', 'type' => 'device', 'form_factor' => 'Windows Server', 'wizard' => false],
        'linux-server' => ['title' => 'Linux Server', 'type' => 'device', 'form_factor' => 'Linux Server', 'wizard' => false],
        'mobile-ios' => ['title' => 'Mobile Device - iOS', 'type' => 'device', 'form_factor' => 'Mobile - iOS', 'wizard' => false],
        'mobile-android' => ['title' => 'Mobile Device - Android', 'type' => 'device', 'form_factor' => 'Mobile - Android', 'wizard' => false],
        'other-device' => ['title' => 'Other Device', 'type' => 'device', 'form_factor' => 'Other Device', 'wizard' => false],
        // Referenced by other runbooks as /eprotection; no form factor of its own.
        'eprotection' => ['title' => 'Endpoint protection', 'type' => 'device', 'form_factor' => null, 'wizard' => false],
    ];

    /** Steps for a catalog slug (null if unknown). */
    public static function workflow(string $slug): ?array
    {
        return match ($slug) {
            'onboarding' => self::onboarding(),
            'freelancer' => self::freelancer(),
            'offboarding' => self::offboarding(),
            'windows-workstation' => self::imaging('Windows'),
            'windows-laptop' => self::laptop(self::imaging('Windows')),
            'mac-workstation' => self::imaging('Mac'),
            'mac-laptop' => self::laptop(self::imaging('Mac')),
            'windows-server' => self::imaging('Server'),
            'linux-server' => self::linuxServer(),
            'mobile-ios' => self::mobile('iOS'),
            'mobile-android' => self::mobile('Android'),
            'other-device' => self::otherDevice(),
            'eprotection' => self::eprotection(),
            default => null,
        };
    }

    public static function get(string $kind, string $variant = ''): ?array
    {
        return match ($kind) {
            'onboarding' => self::onboarding(),
            'freelancer' => self::freelancer(),
            'offboarding' => self::offboarding(),
            'imaging' => self::imaging($variant),
            'eprotection' => self::eprotection(),
            default => null,
        };
    }

    /** A laptop is the workstation runbook + mobility: VPN and off-LAN readiness before QA. */
    private static function laptop(array $tpl): array
    {
        $s = fn (...$a) => self::step(...$a);
        $mobility = $s('mobility', 'Mobility: VPN and off-network readiness', 'access', 0, [
            'why' => 'A laptop that only works on the office LAN is a desktop with a battery.',
            'how' => 'Pull the VPN profile at the bench - type /vpn in this SOP to pick it from the installers share. Confirm email and core tools work OFF the office network (hotspot test).',
            'done' => 'VPN connects from an outside network and the daily tools load through it.',
            'record' => 'VPN profile name noted on the device record.',
        ]);
        // Insert before QA (the last step) so the pool-readiness gate stays last.
        $steps = $tpl['steps'];
        array_splice($steps, count($steps) - 1, 0, [$mobility]);
        return ['version' => 1, 'steps' => $steps];
    }

    private static function linuxServer(): array
    {
        $s = fn (...$a) => self::step(...$a);
        return ['version' => 1, 'steps' => [
            $s('intake', 'Intake and inventory', 'machine', 0, [
                'why' => 'A server that is not in inventory does not exist when it goes missing.',
                'how' => 'Record it in AssetMost (Assets > Devices > Add). Hostname = asset tag.',
                'done' => 'Asset tag issued; hostname set.',
                'record' => 'Device in inventory with tag, serial, model.',
            ]),
            $s('provision', 'Provision', 'machine', 0, [], [
                $s('prov-ip', 'Static IP and DNS record', 'machine', 0, [
                    'done' => 'Forward and reverse lookups resolve.',
                ]),
                $s('prov-roles', 'Roles/services installed and documented', 'machine', 0, [
                    'record' => 'What this server DOES is written on its device record.',
                ]),
            ]),
            $s('hardening', 'Hardening', 'access', 0, [], [
                $s('hard-ssh', 'SSH: key auth only, root login off', 'access', 0, [
                    'done' => 'Password auth refused; a key-based login works.',
                ]),
                $s('hard-fw', 'Firewall on with only the needed ports', 'access', 0),
                $s('hard-updates', 'Unattended security updates enabled', 'machine', 0),
            ]),
            $s('svc-accounts', 'Service accounts into the registry', 'accounts', 0, [
                'why' => 'A server born with undocumented admin credentials is a future breach with a hostname.',
                'how' => 'Every admin/service credential enters the registry as a SERVICE account (held by nobody - it runs the system).',
                'done' => 'Each credential resolves in the registry; none exist only in someone\'s head.',
                'record' => 'Service accounts linked to this device.',
            ]),
            $s('protect', 'Protection', 'machine', 0, [], [
                $s('prot-monitor', 'Monitoring agent reporting', 'machine', 0, [
                    'done' => 'Server visible in the monitoring console with alerts armed.',
                ]),
                $s('prot-backup', 'Backup agent + first successful restore test', 'machine', 0, [
                    'done' => 'A restore test of one file succeeds.',
                ]),
            ]),
            $s('qa', 'QA before it enters service', 'machine', 0, [
                'how' => 'Reboot; confirm services come back on their own.',
                'done' => 'All roles survive a reboot unattended.',
                'record' => 'Device marked READY in inventory.',
            ]),
        ]];
    }

    private static function mobile(string $os): array
    {
        $s = fn (...$a) => self::step(...$a);
        $enroll = $os === 'iOS'
            ? 'Supervised via ABM/DEP when company-owned; otherwise the enrollment profile.'
            : 'Android Enterprise (work profile for BYOD, fully managed for company-owned).';
        return ['version' => 1, 'steps' => [
            $s('intake', 'Intake and inventory', 'machine', 0, [
                'how' => 'Record it in AssetMost with serial and IMEI; issue the asset tag.',
                'record' => 'Device in inventory with tag, serial, IMEI, carrier/number if any.',
            ]),
            $s('enroll', 'MDM enrollment', 'machine', 0, [
                'why' => 'An unmanaged phone with company mail is a breach in a pocket.',
                'how' => "Type /mdm in this SOP to name your MDM. {$enroll}",
                'done' => 'Device appears in the MDM console and receives policy.',
            ]),
            $s('policy', 'Passcode and encryption policy applied', 'access', 0, [
                'done' => 'MDM shows passcode set and encryption on; remote wipe armed.',
            ]),
            $s('apps', 'Company apps via MDM', 'machine', 0, [
                'how' => 'Push mail, chat, and the role\'s apps through the MDM - no personal store sign-ins for company apps.',
            ]),
            $s('handoff', 'Hand-off', 'other', 0, [
                'how' => 'Assign to the person in AssetMost; confirm mail and MFA prompts work on the device.',
                'done' => 'The user - not the tech - signs into mail and passes an MFA prompt.',
                'record' => 'Device shows its holder; number recorded.',
            ]),
        ]];
    }

    /** The generic device runbook - duplicate it per class: access point, switch, printer, camera... */
    private static function otherDevice(): array
    {
        $s = fn (...$a) => self::step(...$a);
        return ['version' => 1, 'steps' => [
            $s('intake', 'Intake and inventory', 'machine', 0, [
                'why' => 'This is the generic device runbook - DUPLICATE it for each device class you run (access point, switch, printer, camera) and tailor the steps.',
                'how' => 'Record it in AssetMost (Assets > Devices > Add); issue the asset tag and label the unit.',
                'record' => 'Device in inventory with tag, serial, model, location.',
            ]),
            $s('network', 'Network', 'machine', 0, [], [
                $s('net-ip', 'Static IP or DHCP reservation + DNS record', 'machine', 0, [
                    'done' => 'The device answers at a name, not a mystery IP.',
                ]),
            ]),
            $s('creds', 'Admin credential into the registry', 'accounts', 0, [
                'why' => 'Default passwords on infrastructure are how guests become admins.',
                'how' => 'Change the default admin password; store it in the registry as a SERVICE account linked to this device.',
                'done' => 'The default password no longer works; the registry credential does.',
            ]),
            $s('firmware', 'Firmware current', 'machine', 0, [
                'done' => 'Running the latest stable firmware; auto-update on if supported.',
            ]),
            $s('hardening', 'Hardening', 'access', 0, [
                'how' => 'Disable unused services (telnet, UPnP, WPS...); management UI restricted to the admin VLAN if possible.',
            ]),
            $s('record', 'Record and monitor', 'other', 0, [
                'how' => 'Location and what it serves on the device record; add to monitoring if it speaks SNMP/ping.',
                'done' => 'Someone new could find and understand this device from AssetMost alone.',
            ]),
        ]];
    }

    /**
     * Freelancer/contractor onboarding — lighter than an employee: no domain
     * account by default, time-boxed access, contractor agreement. Placeholder
     * steps only; edit into your real SOP like any adopted template.
     */
    private static function freelancer(): array
    {
        $s = fn (...$a) => self::step(...$a);
        return ['version' => 1, 'steps' => [
            $s('scope', 'Confirm scope and contract dates', 'other', 0),
            $s('agreement', 'Contractor agreement / NDA signed', 'other', 0),
            $s('access', 'Grant limited, time-boxed access', 'access', 0),
            $s('accounts', 'Create the accounts their role needs', 'accounts', 0),
            $s('remote', 'Remote access if required', 'access', 0),
            $s('review', 'Access review at contract end', 'other', 0),
        ]];
    }

    private static function step(string $id, string $title, string $cat, int $offset, array $f = [], array $subs = []): array
    {
        return ['id' => $id, 'title' => $title, 'category' => $cat, 'offset_days' => $offset,
            'why' => $f['why'] ?? '', 'instructions' => $f['how'] ?? '',
            'done_when' => $f['done'] ?? '', 'record' => $f['record'] ?? '',
            'automatable' => $f['auto'] ?? false, 'subtasks' => $subs];
    }

    private static function onboarding(): array
    {
        $s = fn (...$a) => self::step(...$a);
        return ['version' => 1, 'steps' => [
            $s('accounts', 'Create accounts', 'accounts', -2, [
                'why' => 'A hire without working accounts on day one starts their job watching IT type.',
                'how' => 'Vendors picked in the wizard already have credentials generated in the registry. Create each account in its console using the stored credential.',
                'done' => 'Every account signs in successfully with the registry credential.',
                'record' => 'Credentials live in the registry only  -  never in email, chat, or on paper.',
            ]),
            $s('workstation', 'Assign a prepared workstation', 'machine', -2, [
                'why' => 'Machines are prepared AHEAD by the Workstation setup SOP  -  onboarding assigns from the ready pool, it never images.',
                'how' => 'Pick a ready machine from Assets and assign it to the hire. If the pool is empty, run the Workstation setup SOP now and chain this project behind it.',
                'done' => 'A machine with a current image is assigned to the person in Assets.',
                'record' => 'Device shows the hire as its user; asset tag matches the hostname.',
            ]),
            $s('access', 'Grant access', 'access', -2, [], [
                $s('access-network', 'Network and remote access (office/VPN as their role requires)', 'access', -2, [
                    'done' => 'They can reach what their role needs and nothing more.',
                ]),
                $s('access-mfa', 'MFA enrolled on every account that supports it', 'access', 0, [
                    'why' => 'An account without MFA is a password away from being someone else.',
                    'done' => 'Each console shows MFA active; enrollment finishes day one with the hire present.',
                ]),
            ]),
            $s('orientation', 'Welcome orientation', 'training', 0, [
                'how' => 'Review IT policies (acceptable use, data protection); explain password and phishing basics.',
                'done' => 'Signed policy acknowledgment on file.',
            ]),
            $s('handoff', 'Device hand-off and first login', 'machine', 0, [
                'how' => 'At their desk: first login with the registry credential, test network, printers, and the tools their role uses.',
                'done' => 'The hire  -  not the tech  -  completes a full login and opens their daily tools.',
            ]),
            $s('walkthrough', 'System access walkthrough', 'training', 0, [
                'how' => 'Email, collaboration tools, project systems, where to get IT help.',
            ]),
            $s('sec-training', 'Security training', 'training', 1, [
                'done' => 'Security video watched; policies acknowledged in writing.',
            ]),
            $s('followup', 'One-week follow-up', 'other', 7, [
                'why' => 'Problems a hire has stopped reporting by week two become permanent workarounds.',
                'done' => 'Hire confirms everything works; issues found became tasks.',
            ]),
        ]];
    }

    /**
     * Referenced by other runbooks as /eprotection  -  deliberately its own document
     * because endpoint tooling changes often; references always resolve to the
     * CURRENT version, so nothing else needs editing when the agent changes.
     */
    private static function eprotection(): array
    {
        $s = fn (...$a) => self::step(...$a);
        return ['version' => 1, 'steps' => [
            $s('agent', 'Install the endpoint agent (current standard: Wazuh)', 'machine', 0, [
                'why' => 'A machine the fleet monitor cannot see is where a compromise lives unnoticed.',
                'how' => 'Install the current agent for this platform; point it at the manager. Update THIS runbook when the tooling changes  -  everything referencing /eprotection follows automatically.',
                'done' => 'The machine appears in the monitoring console and reports events.',
            ]),
            $s('av', 'Antivirus active', 'machine', 0, [
                'how' => 'Platform standard (Defender policy on Windows; ClamXav on Mac). Confirm real-time protection is ON, not just installed.',
                'done' => 'A test EICAR file is detected.',
            ]),
            $s('updates', 'Agent auto-update confirmed', 'machine', 0, [
                'why' => 'A stale agent is a false sense of security with a version number.',
                'done' => 'Auto-update enabled, or the update task exists in the maintenance schedule.',
            ]),
        ]];
    }

    private static function offboarding(): array
    {
        $s = fn (...$a) => self::step(...$a);
        return ['version' => 1, 'steps' => [
            $s('inventory', 'Inventory their access', 'accounts', -2, [
                'why' => 'You cannot revoke what you have not listed.',
                'how' => 'Open the person in AssetMost  -  their logins, floating accounts, devices and seats ARE this checklist.',
                'done' => 'Their access list is printed/open and every item below maps to it.',
            ]),
            $s('disable', 'Disable sign-ins', 'accounts', 0, [
                'why' => 'Order matters: identity first, then sessions, then remote paths  -  before they leave the building.',
            ], [
                $s('off-ad', 'Disable the Domain/AD account', 'accounts', 0, [
                    'done' => 'Account shows disabled in ADUC; a test bind fails.',
                ]),
                $s('off-m365', 'Block Microsoft 365 sign-in and revoke active sessions', 'accounts', 0, [
                    'done' => 'Sign-in blocked AND sessions revoked in admin center  -  blocking alone leaves live tokens.', 'auto' => true,
                ]),
                $s('off-vpn', 'Revoke VPN and remote access', 'access', 0, [
                    'done' => 'A connection attempt with their profile is refused.',
                ]),
            ]),
            $s('floating', 'Rotate floating accounts they held', 'accounts', 0, [
                'why' => 'A departed person with a living shared password is a breach with a start date.',
                'how' => 'Every pooled/shared account they held gets a new password TODAY; reassign or return seats in the registry.',
                'done' => 'No floating account they held still accepts its old password.',
                'record' => 'New passwords stored in the registry; holder lists updated.',
            ]),
            $s('collect', 'Collect hardware', 'machine', 0, [], [
                $s('col-laptop', 'Laptop/workstation, chargers, peripherals', 'machine', 0, [
                    'record' => 'Devices return to the pool marked "returned  -  needs reimage".',
                ]),
                $s('col-badge', 'Badge, keys, access cards', 'access', 0),
            ]),
            $s('mail', 'Mail and voicemail routing', 'accounts', 0, [
                'how' => 'Mailbox forwarding/auto-reply per policy; clear their voicemail forwarding.',
            ]),
            $s('seats', 'Reclaim license seats', 'accounts', 1, [
                'why' => 'Ghost seats are money; they also hide real availability from the next onboarding.',
                'done' => 'License counts reflect the freed seats.',
            ]),
            $s('archive', 'Archive mailbox and files', 'other', 3, [
                'how' => 'Per retention policy; confirm before removal from groups.',
            ]),
            $s('deactivate', 'Deactivate in the directory', 'other', 3, [
                'record' => 'Person marked inactive in AssetMost  -  history stays.',
            ]),
            $s('audit', 'Final access audit', 'other', 7, [
                'why' => 'The audit is the offboarding; everything before it was preparation.',
                'how' => 'Re-open their page in AssetMost.',
                'done' => 'Zero active logins, zero floating accounts held, zero devices assigned. Anything left is a finding, not a shrug.',
            ]),
        ]];
    }

    private static function imaging(string $variant = ''): array
    {
        $s = fn (...$a) => self::step(...$a);

        $intake = $s('intake', 'Intake and inventory', 'machine', 0, [
            'why' => 'A machine that is not in inventory does not exist when it goes missing.',
            'how' => 'Record it in AssetMost (Assets > Devices > Add).',
            'done' => 'Asset tag issued; it becomes the hostname.',
            'record' => 'Device in inventory with tag, serial, model.',
        ]);
        $qa = fn (string $extra = '') => $s('qa', 'QA before it enters the ready pool', 'machine', 0, [
            'why' => 'The pool only works if "ready" means ready  -  onboarding trusts it blindly.',
            'how' => 'Test login, network, printers, peripherals.' . ($extra ? ' ' . $extra : ''),
            'done' => 'A test user session works end to end.',
            'record' => 'Device marked READY in inventory  -  it can now be assigned by onboarding.',
        ]);

        if (strcasecmp($variant, 'Windows') === 0) {
            return ['version' => 1, 'steps' => [
                $intake,
                $s('base', 'Clean current OS', 'machine', 0, [
                    'why' => 'Golden images are stale five days after you make them  -  build live from a clean, fully-updated OS instead; configuration comes from the domain and the checklist, not a stamp.',
                ], [
                    $s('base-os', 'Clean install / OOBE with all OS and firmware updates', 'machine', 0),
                    $s('base-drivers', 'Drivers and vendor tools', 'machine', 0),
                ]),
                $s('join', 'Name and join', 'machine', 0, [], [
                    $s('join-host', 'Hostname = asset tag', 'machine', 0, [
                        'done' => 'hostname prints the tag.',
                    ]),
                    $s('join-domain', 'Join to the domain', 'machine', 0, [
                        'done' => 'Machine object appears in ADUC; GPOs apply on gpupdate.',
                    ]),
                ]),
                $s('bitlocker', 'Enable BitLocker', 'access', 0, [
                    'why' => 'Lost or stolen disks are unreadable; encryption at rest is policy.',
                    'how' => 'Applies via GPO on domain join. Manual fallback: Control Panel > BitLocker > Turn On.',
                    'done' => 'Recovery key VISIBLE IN AD (computer object > BitLocker Recovery tab)  -  not when the progress bar finishes; GPO escrow silently fails sometimes.',
                    'record' => 'Escrow the key into the registry against the asset tag anyway  -  AD is the copy that sometimes does not save.',
                ]),
                $s('agents', 'Endpoint agents', 'machine', 0, [
                    'done' => 'Machine reports in each console (AV, monitoring, management).',
                ]),
                $s('software', 'Standard software set', 'machine', 0, [
                    'how' => 'Use /install at the bench  -  it reads the installers share for this platform; 32/64-bit stays coherent with the machine.',
                ]),
                $qa(),
            ]];
        }

        if (strcasecmp($variant, 'Mac') === 0) {
            return ['version' => 1, 'steps' => [
                $intake,
                $s('base', 'Clean current macOS', 'machine', 0, [
                    'why' => 'Golden images rot  -  build live: clean OS, then MDM profiles and the checklist do the configuring.',
                ], [
                    $s('base-os', 'Clean install or factory + all macOS updates', 'machine', 0),
                ]),
                $s('mdm', 'MDM enrollment', 'machine', 0, [
                    'why' => 'Macs are managed by MDM, not domain join  -  profiles carry the config an image used to.',
                    'how' => 'Zero-touch (DEP/ABM) if enrolled; otherwise manual enrollment.',
                    'done' => 'Device appears in the MDM console (e.g. Jamf) and receives profiles.',
                ]),
                $s('filevault', 'Enable FileVault', 'access', 0, [
                    'why' => 'Lost or stolen disks are unreadable; encryption at rest is policy.',
                    'how' => 'System Settings > Privacy & Security > FileVault > Turn On. Recovery key, NOT iCloud.',
                    'done' => 'FileVault status reads On and a recovery key was displayed.',
                    'record' => 'Recovery key escrowed to the registry against the asset tag  -  it is a credential.',
                ]),
                $s('hardening', 'Hardening', 'access', 0, [], [
                    $s('hard-fw', 'Firewall on; screen lock at 5 minutes; password required after screen saver', 'access', 0),
                    $s('hard-banner', 'Policy/login banner installed', 'access', 0),
                ]),
                $s('agents', 'Endpoint agents', 'machine', 0, [
                    'done' => 'Machine reports in each console (AV, monitoring, MDM).',
                ]),
                $s('software', 'Standard software set', 'machine', 0, [
                    'how' => 'Use /install at the bench  -  it reads the Mac folder of the installers share; smb:// links open in Finder.',
                ]),
                $qa(),
            ]];
        }

        if (strcasecmp($variant, 'Server') === 0) {
            return ['version' => 1, 'steps' => [
                $intake,
                $s('provision', 'Provision', 'machine', 0, [], [
                    $s('prov-ip', 'Static IP and DNS record', 'machine', 0, [
                        'done' => 'Forward and reverse lookups resolve.',
                    ]),
                    $s('prov-roles', 'Roles/services installed and documented', 'machine', 0, [
                        'record' => 'What this server DOES is written on its device record  -  the next admin should not have to guess.',
                    ]),
                ]),
                $s('svc-accounts', 'Service accounts into the registry', 'accounts', 0, [
                    'why' => 'A server born with undocumented admin credentials is a future breach with a hostname.',
                    'how' => 'Every admin/service credential this server runs on enters the registry as a SERVICE account (held by nobody  -  it runs the system).',
                    'done' => 'Each credential resolves in the registry; none exist only in someone\'s head.',
                    'record' => 'Service accounts linked to this device.',
                ]),
                $s('protect', 'Protection', 'machine', 0, [], [
                    $s('prot-monitor', 'Monitoring agent reporting', 'machine', 0, [
                        'done' => 'Server visible in the monitoring console with alerts armed.',
                    ]),
                    $s('prot-backup', 'Backup agent + first successful backup', 'machine', 0, [
                        'done' => 'A restore test of one file succeeds  -  a backup that never restored is a hope, not a backup.',
                    ]),
                ]),
                $qa('For servers: confirm services survive a reboot.'),
            ]];
        }

        // Generic fallback (no platform variant chosen).
        return ['version' => 1, 'steps' => [
            $intake,
            $s('base', 'Clean current OS', 'machine', 0, [
                'why' => 'Golden images are stale on arrival  -  build live from a clean, updated OS; the checklist is the configuration.',
            ], [
                $s('base-os', 'Clean install with all updates', 'machine', 0),
                $s('base-drivers', 'Drivers and vendor tools', 'machine', 0),
            ]),
            $s('configure', 'Configure and protect', 'machine', 0, [], [
                $s('cfg-hostname', 'Hostname = asset tag', 'machine', 0),
                $s('cfg-encrypt', 'Disk encryption on; key escrowed to the registry', 'access', 0, [
                    'done' => 'Key verifiably stored  -  check the escrow location, not the progress bar.',
                ]),
                $s('cfg-agents', 'AV / monitoring / management agents reporting', 'machine', 0),
            ]),
            $s('software', 'Standard software set', 'machine', 0, [
                'how' => 'Use /install at the bench  -  it reads the installers share for this platform.',
            ]),
            $qa(),
        ]];
    }
}
