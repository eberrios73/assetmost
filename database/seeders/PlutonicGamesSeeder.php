<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceType;
use App\Models\License;
use App\Models\Location;
use App\Models\Login;
use App\Models\Product;
use App\Models\Room;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Plutonic Games — a small game studio, seeded coherently across all four
 * pillars so every screen has something real to show and every connection
 * (person ⇄ device ⇄ credential ⇄ seat) can be followed end to end.
 *
 * Sign in as sam@plutonicgames.com / password (SuperAdmin).
 */
class PlutonicGamesSeeder extends Seeder
{
    public function run(): void
    {
        $co = Company::create([
            'name' => 'Plutonic Games', 'tag_prefix' => 'PG', 'tag_next' => 1001,
            'domain' => 'plutonicgames.com', 'local_domain' => 'plutonic.local',
            'website' => 'https://plutonicgames.com', 'email' => 'info@plutonicgames.com',
            'phone' => '818-555-0170', 'address' => '2200 W Empire Ave', 'city' => 'Burbank',
            'state' => 'CA', 'zip' => '91504', 'contact_name' => 'Sam Reyes', 'active' => true,
        ]);

        // ---------- Places ----------
        $studio = Location::create(['company_id' => $co->id, 'name' => 'Burbank Studio', 'type' => 'Office',
            'address' => '2200 W Empire Ave', 'city' => 'Burbank', 'state' => 'CA', 'zip' => '91504', 'active' => true]);
        $rooms = [];
        foreach ([
            ['101', 'Art Bullpen', 'Open office', 10],
            ['102', 'Engineering', 'Open office', 8],
            ['104B', 'Server Room', 'Server room', 2],
            ['110', 'Mocap Stage', 'Studio', 6],
            ['100', 'Front Desk', 'Reception', 2],
        ] as [$num, $name, $type, $cap]) {
            $rooms[$num] = Room::create(['location_id' => $studio->id, 'name' => $name,
                'room_number' => $num, 'room_type' => $type, 'capacity' => $cap, 'active' => true]);
        }

        // ---------- People ----------
        $mk = fn (array $a) => User::create($a + [
            'company_id' => $co->id, 'location_id' => $studio->id, 'domain' => 'plutonicgames.com',
            'role' => 'User', 'can_login' => false, 'password' => null, 'active' => true,
        ]);
        $sam = User::create([
            'name' => 'Sam', 'last' => 'Reyes', 'username' => 'sreyes', 'email' => 'sam@plutonicgames.com',
            'title' => 'IT Manager', 'department' => 'IT', 'company_id' => $co->id, 'location_id' => $studio->id,
            'domain' => 'plutonicgames.com', 'role' => 'SuperAdmin', 'can_login' => true,
            'password' => Hash::make('password'), 'active' => true,
        ]);
        $maya   = $mk(['name' => 'Maya',   'last' => 'Chen',      'username' => 'mchen',    'email' => 'maya@plutonicgames.com',   'title' => 'Art Director',       'department' => 'Art']);
        $diego  = $mk(['name' => 'Diego',  'last' => 'Fuentes',   'username' => 'dfuentes', 'email' => 'diego@plutonicgames.com',  'title' => 'Environment Artist', 'department' => 'Art']);
        $priya  = $mk(['name' => 'Priya',  'last' => 'Natarajan', 'username' => 'priyan',   'email' => 'priya@plutonicgames.com',  'title' => 'Character Artist',   'department' => 'Art']);
        $marcus = $mk(['name' => 'Marcus', 'last' => 'Lee',       'username' => 'mlee',     'email' => 'marcus@plutonicgames.com', 'title' => 'Technical Artist',   'department' => 'Art']);
        $tom    = $mk(['name' => 'Tom',    'last' => 'Okafor',    'username' => 'tokafor',  'email' => 'tom@plutonicgames.com',    'title' => 'Gameplay Engineer',  'department' => 'Engineering']);
        $lena   = $mk(['name' => 'Lena',   'last' => 'Kovacs',    'username' => 'lkovacs',  'email' => 'lena@plutonicgames.com',   'title' => 'Engine Programmer',  'department' => 'Engineering']);
        $jordan = $mk(['name' => 'Jordan', 'last' => 'Blake',     'username' => 'jblake',   'email' => 'jordan@plutonicgames.com', 'title' => 'Producer',           'department' => 'Production']);
        $aisha  = $mk(['name' => 'Aisha',  'last' => 'Williams',  'username' => 'awilliams','email' => 'aisha@plutonicgames.com',  'title' => 'QA Lead',            'department' => 'QA']);
        $erin   = $mk(['name' => 'Erin',   'last' => "O'Neil",    'username' => 'eoneil',   'email' => 'erin@plutonicgames.com',   'title' => 'Office Manager',     'department' => 'Operations']);

        // The rest of a ~30-person studio. [first, last, username, title, department]
        $roster = [
            ['Noor',   'Haddad',    'nhaddad',   'Concept Artist',      'Art'],
            ['Felix',  'Grant',     'fgrant',    'Animator',            'Art'],
            ['Yuki',   'Tanaka',    'ytanaka',   'Animator',            'Art'],
            ['Carla',  'Mendes',    'cmendes',   'UI Artist',           'Art'],
            ['Ben',    'Carter',    'bcarter',   'Graphics Programmer', 'Engineering'],
            ['Ivy',    'Zhou',      'izhou',     'Tools Programmer',    'Engineering'],
            ['Owen',   'Park',      'opark',     'Tools Programmer',    'Engineering'],
            ['Dmitri', 'Volkov',    'dvolkov',   'Backend Engineer',    'Engineering'],
            ['Hana',   'Suzuki',    'hsuzuki',   'Build Engineer',      'Engineering'],
            ['Ravi',   'Shah',      'rshah',     'Lead Game Designer',  'Design'],
            ['Tessa',  'Morgan',    'tmorgan',   'Level Designer',      'Design'],
            ['Cole',   'Bennett',   'cbennett',  'Game Designer',       'Design'],
            ['Luis',   'Herrera',   'lherrera',  'QA Tester',           'QA'],
            ['Ingrid', 'Bergström', 'ibergstrom','QA Tester',           'QA'],
            ['Kofi',   'Mensah',    'kmensah',   'QA Tester',           'QA'],
            ['Dana',   'Whitfield', 'dwhitfield','Associate Producer',  'Production'],
            ['Theo',   'Laurent',   'tlaurent',  'Sound Designer',      'Audio'],
            ['June',   'Park',      'jpark',     'Composer',            'Audio'],
            ['Zoe',    'Castillo',  'zcastillo', 'Community Manager',   'Marketing'],
            ['Adam',   'Novak',     'anovak',    'Marketing Manager',   'Marketing'],
        ];
        $team = [];   // username => User, for the wider roster
        foreach ($roster as [$first, $lastName, $uname, $title, $dept]) {
            $team[$uname] = $mk(['name' => $first, 'last' => $lastName, 'username' => $uname,
                'email' => "{$uname}@plutonicgames.com", 'title' => $title, 'department' => $dept]);
        }

        // ---------- Vendors & products ----------
        $vendor = [];
        foreach (['Adobe', 'Autodesk', 'Microsoft', 'Perforce', 'JetBrains', 'Epic Games', 'Ubiquiti'] as $v) {
            $vendor[$v] = Vendor::create(['company_id' => $co->id, 'name' => $v, 'active' => true]);
        }
        $product = [];
        foreach ([
            ['Adobe', 'Creative Cloud All Apps'], ['Adobe', 'Substance 3D'],
            ['Autodesk', 'Maya'], ['Autodesk', '3ds Max'],
            ['Microsoft', 'Microsoft 365 Business Standard'],
            ['Perforce', 'Helix Core'], ['JetBrains', 'Rider'],
            ['Epic Games', 'Unreal Engine'], ['Ubiquiti', 'UniFi Network'],
        ] as [$v, $p]) {
            $product[$p] = Product::create(['vendor_id' => $vendor[$v]->id, 'name' => $p, 'active' => true]);
        }

        // ---------- Licenses (seats) ----------
        $lic = fn (string $prod, string $name, ?int $seats, ?float $amount, ?string $renews, int $freq = 12) => License::create([
            'company_id' => $co->id, 'vendor_id' => $product[$prod]->vendor_id, 'product_id' => $product[$prod]->id,
            'name' => $name, 'seats_total' => $seats, 'amount' => $amount,
            'renewal_date' => $renews, 'renewalfrequency' => $freq, 'is_active' => true,
        ]);
        $licCC    = $lic('Creative Cloud All Apps', 'Creative Cloud — studio pool', 6, 2159.28, '2027-01-15');
        $licMaya  = $lic('Maya', 'Maya — art team', 4, 7340.00, '2026-11-01');
        $licM365  = $lic('Microsoft 365 Business Standard', 'M365 Business Standard', 35, 437.50, '2026-08-01', 1);
        $licHelix = $lic('Helix Core', 'Helix Core — 20 users', 20, 10780.00, '2027-03-01');
        $licRider = $lic('Rider', 'Rider — engineering', 3, 447.00, '2026-10-12');
        $lic('Substance 3D', 'Substance 3D — texturing', 2, 549.88, '2027-01-15');

        // ---------- Floating accounts (assignable credential identities) ----------
        $acct = fn (string $ident, string $sharing, ?string $notes = null) => Account::create([
            'company_id' => $co->id, 'identifier' => $ident, 'sharing' => $sharing, 'is_active' => true, 'notes' => $notes,
        ]);
        $a1 = $acct('artist001', 'pooled', 'Floating art seat — reassign as contractors rotate.');
        $a2 = $acct('artist002', 'pooled');
        $a3 = $acct('artist003', 'pooled');
        $a4 = $acct('artist004', 'pooled', 'Open seat.');
        $bot = $acct('buildbot', 'service', 'CI identity — runs the build farm. Held by nobody on purpose.');
        $a1->holders()->attach($diego->id);
        $a2->holders()->attach($priya->id);
        $a3->holders()->attach($marcus->id);
        $bot; $a4; // artist004 stays unassigned — an available seat is a feature, not a gap.

        // ---------- Devices (tags auto-issue: PG-WS-1001…) ----------
        $type = DeviceType::query()->pluck('id', 'code');
        $dev = fn (array $a) => Device::create($a + ['company_id' => $co->id, 'location_id' => $studio->id, 'active' => true]);
        $ws = [];
        foreach ([[$maya, '101'], [$diego, '101'], [$priya, '101'], [$marcus, '101'], [$tom, '102'], [$lena, '102']] as [$person, $room]) {
            $d = $dev(['device_type_id' => $type['WS'], 'room_id' => $rooms[$room]->id, 'type' => 'Workstation',
                'brand' => 'Puget Systems', 'model' => 'Threadripper 7970X', 'ram' => '128 GB', 'op_sys' => 'Windows 11 Pro']);
            $d->users()->attach($person->id);
            $ws[$person->id] = $d;
        }
        foreach ([$sam, $jordan, $aisha, $erin] as $person) {
            $d = $dev(['device_type_id' => $type['LT'], 'type' => 'Laptop',
                'brand' => 'Apple', 'model' => 'MacBook Pro 14 M4', 'ram' => '32 GB', 'op_sys' => 'macOS 15']);
            $d->users()->attach($person->id);
        }
        // Makers get workstations; everyone else a laptop.
        $wsRoom = ['Art' => '101', 'Engineering' => '102', 'Design' => '102', 'Audio' => '110'];
        foreach ($team as $person) {
            if (isset($wsRoom[$person->department])) {
                $d = $dev(['device_type_id' => $type['WS'], 'room_id' => $rooms[$wsRoom[$person->department]]->id,
                    'type' => 'Workstation', 'brand' => 'Puget Systems', 'model' => 'Threadripper 7970X',
                    'ram' => '128 GB', 'op_sys' => 'Windows 11 Pro']);
            } else {
                $d = $dev(['device_type_id' => $type['LT'], 'type' => 'Laptop',
                    'brand' => 'Apple', 'model' => 'MacBook Pro 14 M4', 'ram' => '32 GB', 'op_sys' => 'macOS 15']);
            }
            $d->users()->attach($person->id);
        }
        $p4srv = $dev(['device_type_id' => $type['SR'], 'room_id' => $rooms['104B']->id, 'computer_name' => 'PG-P4-01',
            'type' => 'Server', 'brand' => 'Dell', 'model' => 'PowerEdge R7625', 'op_sys' => 'Ubuntu 24.04', 'notes' => 'Helix Core (Perforce) depot.']);
        $buildsrv = $dev(['device_type_id' => $type['SR'], 'room_id' => $rooms['104B']->id, 'computer_name' => 'PG-BUILD-01',
            'type' => 'Server', 'brand' => 'Supermicro', 'model' => 'AS-2015', 'op_sys' => 'Windows Server 2025', 'notes' => 'Build farm controller.']);
        $gw = $dev(['device_type_id' => $type['NT'], 'room_id' => $rooms['104B']->id, 'computer_name' => 'PG-GW-01',
            'type' => 'Network', 'brand' => 'Ubiquiti', 'model' => 'UniFi Dream Machine Pro', 'notes' => 'Studio gateway + controller.']);

        // ---------- Credentials (all five sharing kinds) ----------
        $login = fn (array $a) => Login::create($a + ['company_id' => $co->id, 'is_active' => true]);

        // pooled: the floating Adobe seats ride the artistNNN accounts and consume CC license seats
        foreach ([[$a1, $diego], [$a2, $priya], [$a3, $marcus], [$a4, null]] as [$acctRow, $holder]) {
            $l = $login(['login_name' => "Adobe CC — {$acctRow->identifier}", 'login_id' => "{$acctRow->identifier}@plutonicgames.com",
                'login_pass' => 'Fixture-CC-' . $acctRow->identifier, 'vendor_id' => $vendor['Adobe']->id,
                'product_id' => $product['Creative Cloud All Apps']->id, 'sharing' => 'pooled', 'account_id' => $acctRow->id,
                'url' => 'https://account.adobe.com']);
            if ($holder) { $l->holders()->attach($holder->id); }
            $licCC->logins()->attach($l->id);
        }

        // personal: M365 sign-ins, one human each, consuming M365 seats
        foreach (array_merge([$sam, $maya, $diego, $priya, $marcus, $tom, $lena, $jordan, $aisha, $erin], array_values($team)) as $person) {
            $l = $login(['login_name' => "M365 — {$person->name} {$person->last}", 'login_id' => $person->email,
                'login_pass' => 'Fixture-M365-' . $person->username, 'vendor_id' => $vendor['Microsoft']->id,
                'product_id' => $product['Microsoft 365 Business Standard']->id, 'sharing' => 'personal',
                'url' => 'https://portal.office.com']);
            $l->holders()->attach($person->id);
            $licM365->logins()->attach($l->id);
        }

        // shared: one mailbox, many humans — the thing per-user tools can't model
        $info = $login(['login_name' => 'info@ mailbox', 'login_id' => 'info@plutonicgames.com',
            'login_pass' => 'Fixture-Shared-Info', 'vendor_id' => $vendor['Microsoft']->id,
            'product_id' => $product['Microsoft 365 Business Standard']->id, 'sharing' => 'shared',
            'notes' => 'Front-of-house mailbox. Everyone in ops watches it.', 'url' => 'https://outlook.office.com']);
        $info->holders()->attach([$sam->id, $erin->id, $jordan->id]);
        $licM365->logins()->attach($info->id);

        // service: credentials that RUN the studio — held by nobody, tied to hardware
        $p4 = $login(['login_name' => 'Helix Core — p4service', 'login_id' => 'p4service',
            'login_pass' => 'Fixture-Svc-P4', 'vendor_id' => $vendor['Perforce']->id,
            'product_id' => $product['Helix Core']->id, 'device_id' => $p4srv->id, 'sharing' => 'service',
            'is_restricted' => true, 'notes' => 'Depot superuser. Rotate on IT staff change.']);
        $licHelix->logins()->attach($p4->id);
        $login(['login_name' => 'Build farm — buildbot', 'login_id' => 'buildbot',
            'login_pass' => 'Fixture-Svc-Build', 'device_id' => $buildsrv->id, 'sharing' => 'service',
            'account_id' => $bot->id, 'is_restricted' => true, 'notes' => 'CI runner identity on PG-BUILD-01.']);
        $login(['login_name' => 'UniFi controller admin', 'login_id' => 'admin',
            'login_pass' => 'Fixture-Svc-UniFi', 'vendor_id' => $vendor['Ubiquiti']->id,
            'product_id' => $product['UniFi Network']->id, 'device_id' => $gw->id, 'sharing' => 'service',
            'is_restricted' => true, 'url' => 'https://unifi.plutonic.local']);

        // breakglass: sealed emergency access — revealing it is the audit event that matters
        $login(['login_name' => 'Domain Admin — BREAK GLASS', 'login_id' => 'PLUTONIC\\breakglass',
            'login_pass' => 'Fixture-BreakGlass-Sealed', 'sharing' => 'breakglass', 'is_restricted' => true,
            'notes' => 'Sealed envelope in the 104B safe mirrors this. Use = incident.']);

        // Perforce user seats for everyone who touches the depot (consume Helix seats, personal)
        $depotUsers = array_merge([$diego, $priya, $marcus, $tom, $lena, $maya],
            array_values(array_filter($team, fn ($p) => in_array($p->department, ['Art', 'Engineering', 'Design'], true))));
        foreach ($depotUsers as $person) {
            $l = $login(['login_name' => "Helix — {$person->username}", 'login_id' => $person->username,
                'login_pass' => 'Fixture-P4-' . $person->username, 'vendor_id' => $vendor['Perforce']->id,
                'product_id' => $product['Helix Core']->id, 'sharing' => 'personal']);
            $l->holders()->attach($person->id);
            $licHelix->logins()->attach($l->id);
        }
        // Rider seats — 3 of 3 consumed: an exhausted license is a demo state worth having
        foreach ([$tom, $lena, $team['bcarter']] as $person) {
            $l = $login(['login_name' => "Rider — {$person->username}", 'login_id' => $person->email,
                'login_pass' => 'Fixture-Rider-' . $person->username, 'vendor_id' => $vendor['JetBrains']->id,
                'product_id' => $product['Rider']->id, 'sharing' => 'personal']);
            $l->holders()->attach($person->id);
            $licRider->logins()->attach($l->id);
        }

        // ---------- Work (weekly board + a project) ----------
        $week = Carbon::now()->startOfWeek()->toDateString();
        $lastWeek = Carbon::now()->subWeek()->startOfWeek()->toDateString();
        $t = fn (array $a) => Task::create($a + ['company_id' => $co->id, 'week' => $week, 'origin' => $week,
            'done' => false, 'pct' => 0, 'pri' => 0, 'is_project' => false, 'ord' => 0]);

        $move = $t(['title' => 'Studio move to Stage 4', 'is_project' => true, 'pri' => 2, 'assigned_to' => $sam->id,
            'origin' => $lastWeek, 'week' => $lastWeek, 'planned_start' => $lastWeek,
            'due_date' => Carbon::now()->addWeeks(3)->toDateString(),
            'details' => 'Art + mocap consolidate onto Stage 4. Network first, people last.']);
        $t(['title' => 'Label patch panel + drops', 'parent_id' => $move->id, 'assigned_to' => $sam->id,
            'done' => true, 'pct' => 100, 'completed_at' => Carbon::now()->subDays(3), 'week' => $lastWeek, 'origin' => $lastWeek]);
        $drops = $t(['title' => 'Run drops to Mocap Stage (110)', 'parent_id' => $move->id, 'pri' => 2,
            'assigned_to' => $sam->id, 'pct' => 40, 'planned_start' => $week,
            'due_date' => Carbon::now()->addDays(4)->toDateString()]);
        $t(['title' => 'Mount UniFi APs on Stage 4', 'parent_id' => $move->id, 'pri' => 1,
            'depends_on_id' => $drops->id, 'assigned_to' => $sam->id]);

        $t(['title' => 'Onboard 2 summer interns', 'pri' => 2, 'assigned_to' => $sam->id, 'pct' => 25,
            'planned_start' => $week, 'due_date' => Carbon::now()->addDays(5)->toDateString(),
            'notes' => 'artist004 seat is open — assign it to the art intern. M365 seats: 6 of 12 used.']);
        $t(['title' => 'Maya renewal quote from Autodesk', 'pri' => 3, 'assigned_to' => $sam->id,
            'due_date' => '2026-10-15', 'notes' => '4 seats, renews 2026-11-01. Ask about 3-yr lock.']);
        $t(['title' => 'Decommission old render nodes', 'pri' => 1, 'assigned_to' => $sam->id,
            'notes' => 'Three R620s in 104B — wipe, tag as e-waste.']);
        $t(['title' => 'Replace UPS battery in 104B', 'assigned_to' => $sam->id, 'done' => true, 'pct' => 100,
            'week' => $lastWeek, 'origin' => $lastWeek, 'completed_at' => Carbon::now()->subDays(5)]);
    }
}
