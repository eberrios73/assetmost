<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * One-time port: legacy `neruda` DB -> clean `iter` schema.
 * Preserves original ids so cross-references stay intact. Seeds locations from
 * the free-text location values; maps company_id (string) -> bigint FK.
 */
class ImportFromNeruda extends Command
{
    protected $signature = 'iter:import {--fresh : wipe iter domain tables first}';
    protected $description = 'Import data from the legacy neruda database';

    public function handle(): int
    {
        // legacy connection
        // Legacy source DB — configure via env (NERUDA_DB_*). Never hardcode credentials.
        config(['database.connections.neruda' => [
            'driver' => 'mysql',
            'host' => env('NERUDA_DB_HOST', '127.0.0.1'),
            'port' => env('NERUDA_DB_PORT', '3306'),
            'database' => env('NERUDA_DB_DATABASE', 'neruda'),
            'username' => env('NERUDA_DB_USERNAME', 'root'),
            'password' => env('NERUDA_DB_PASSWORD', ''),
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
        ]]);
        $src = DB::connection('neruda');
        $dst = DB::connection();

        if ($this->option('fresh')) {
            $dst->statement('SET FOREIGN_KEY_CHECKS=0');
            foreach (['subscriptions','logins','company_vendor','vendors','device_user','devices','rooms','locations','companies'] as $t) {
                $dst->table($t)->truncate();
            }
            $dst->table('users')->where('id','>',0)->delete();
            $dst->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        // 1) companies (preserve id)
        foreach ($src->table('companies')->get() as $c) {
            $dst->table('companies')->insert([
                'id' => $c->id, 'name' => $c->name, 'email' => $c->email ?? null,
                'phone' => $c->phone ?? null, 'website' => $c->website ?? null, 'domain' => $c->domain ?? null,
                'contact_name' => $c->contact_name ?? null, 'address' => $c->address ?? null,
                'city' => $c->city ?? null, 'state' => $c->state ?? null, 'zip' => $c->zip ?? null,
                'logo' => $c->logo ?? null, 'signature_html' => $c->signature_html ?? null,
                'signature_logo_base64' => $c->signature_logo_base64 ?? null,
                'signature_logo_2_base64' => $c->signature_logo_2_base64 ?? null,
                'offboard_email_forward_to' => $c->offboard_email_forward_to ?? null,
                'notes' => $c->notes ?? null, 'active' => (int) ($c->active ?? 1),
                'created_at' => $c->created_at ?? now(), 'updated_at' => $c->updated_at ?? now(),
            ]);
        }
        $this->info('companies: ' . $dst->table('companies')->count());

        // 2) locations — distinct (company_id, location text) from users + devices
        $locMap = []; // [company_id][lower(text)] => location_id
        $seed = collect();
        foreach ($src->table('users')->select('company_id','location')->get() as $r) {
            if ($r->location) $seed->push([(int) $r->company_id, trim($r->location)]);
        }
        foreach ($src->table('devices')->select('company_id','location')->get() as $r) {
            if ($r->location) $seed->push([(int) $r->company_id, trim($r->location)]);
        }
        foreach ($seed->unique(fn ($p) => $p[0].'|'.mb_strtolower($p[1])) as [$cid, $text]) {
            if (! $cid || ! $dst->table('companies')->where('id', $cid)->exists()) continue;
            $id = $dst->table('locations')->insertGetId([
                'company_id' => $cid, 'name' => $text, 'active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $locMap[$cid][mb_strtolower($text)] = $id;
        }
        $this->info('locations: ' . $dst->table('locations')->count());

        $locFor = function ($cid, $text) use ($locMap) {
            $cid = (int) $cid;
            return $text ? ($locMap[$cid][mb_strtolower(trim($text))] ?? null) : null;
        };

        // 3) users (preserve id + existing password hash — raw insert, no re-hash)
        $companyIds = $dst->table('companies')->pluck('id')->all();
        foreach ($src->table('users')->get() as $u) {
            $cid = in_array((int) $u->company_id, $companyIds, true) ? (int) $u->company_id : null;
            $dst->table('users')->insert([
                'id' => $u->id, 'name' => $u->name, 'last' => $u->last ?? null,
                'email' => $u->email, 'username' => $u->username ?: null, 'personal_email' => $u->personal_email ?? null,
                'role' => $u->role ?: 'User', 'company_id' => $cid,
                'location_id' => $locFor($u->company_id, $u->location),
                'domain' => $u->domain ?? null, 'title' => $u->title ?? null, 'department' => $u->department ?? null,
                'cell' => $u->cell ?? null, 'workcell' => $u->workcell ?? null, 'ext' => $u->ext ?? null,
                'active' => (int) ($u->active ?? 1), 'restricted' => (int) ($u->restricted ?? 0),
                'force_password_change' => (int) ($u->force_password_change ?? 0),
                'password' => $u->password ?: '', 'email_verified_at' => $u->email_verified_at ?? null,
                'remember_token' => $u->remember_token ?? null,
                'created_at' => $u->created_at ?? now(), 'updated_at' => $u->updated_at ?? now(),
            ]);
        }
        $this->info('users: ' . $dst->table('users')->count());

        // 4) devices (preserve id via deviceID)
        foreach ($src->table('devices')->get() as $d) {
            $cid = in_array((int) $d->company_id, $companyIds, true) ? (int) $d->company_id : null;
            $dst->table('devices')->insert([
                'id' => $d->deviceID, 'company_id' => $cid, 'location_id' => $locFor($d->company_id, $d->location),
                'asset_tag' => $d->asset_tag ?? null, 'computer_name' => $d->computer_name ?? null,
                'type' => $d->type ?? null, 'brand' => $d->brand ?? null, 'model' => $d->model ?? null,
                'serial_num' => $d->serial_num ?? null, 'service_tag' => $d->service_tag ?? null,
                'cpu' => $d->cpu ?? null, 'ram' => $d->ram ?? null, 'hard_drive' => $d->hard_drive ?? null,
                'storage' => $d->storage ?? null, 'op_sys' => $d->op_sys ?? null, 'specs' => $d->specs ?? null,
                'ip_1' => $d->ip_1 ?? null, 'ip_2' => $d->ip_2 ?? null, 'domain' => $d->domain ?? null,
                'encryption' => $d->encryption ?? null, 'owner' => $d->owner ?? null, 'vendor' => $d->vendor ?? null,
                'invoice' => $d->invoice ?? null, 'inv_date' => $d->inv_date ?? null, 'price' => $d->price ?? null,
                'support_contract' => $d->support_contract ?? null, 'support_expiration' => $d->support_expiration ?? null,
                'support_number' => $d->support_number ?? null, 'eol' => $d->eol ?? null,
                'decommission_date' => $d->decommission_date ?? null, 'decommission_desc' => $d->decommission_desc ?? null,
                'ewaste' => (int) ($d->ewaste ?? 0), 'active' => (int) ($d->active ?? 1),
                'restricted' => (int) ($d->restricted ?? 0), 'notes' => $d->desc ?? null,
                'created_at' => $d->creation_timestamp ?? now(), 'updated_at' => $d->updated_at ?? now(),
            ]);
        }
        $this->info('devices: ' . $dst->table('devices')->count());

        // 5) device_user assignments
        $userIds = $dst->table('users')->pluck('id')->all();
        $devIds = $dst->table('devices')->pluck('id')->all();
        foreach ($src->table('device_users')->get() as $du) {
            if (! in_array((int) $du->deviceID, $devIds, true) || ! in_array((int) $du->user_id, $userIds, true)) continue;
            $dst->table('device_user')->insertOrIgnore([
                'device_id' => $du->deviceID, 'user_id' => $du->user_id,
                'created_at' => $du->created_at ?? now(), 'updated_at' => $du->updated_at ?? now(),
            ]);
        }
        $this->info('device_user: ' . $dst->table('device_user')->count());

        // 6) vendors (preserve id via vendorID) + company_vendor
        foreach ($src->table('vendors')->get() as $v) {
            $dst->table('vendors')->insert([
                'id' => $v->vendorID, 'name' => $v->name, 'contact_name' => $v->contact_name ?? null,
                'phone' => $v->phone ?? null, 'email' => $v->email ?? null, 'website' => $v->website ?? null,
                'serial_number' => $v->serial_number ?? null, 'notes' => $v->notes ?? null,
                'active' => (int) ($v->active ?? 1),
                'created_at' => $v->created_at ?? now(), 'updated_at' => $v->updated_at ?? now(),
            ]);
        }
        $vendorIds = $dst->table('vendors')->pluck('id')->all();
        foreach ($src->table('vendor_client')->get() as $vc) {
            if (! in_array((int) $vc->vendorID, $vendorIds, true) || ! in_array((int) $vc->client_id, $companyIds, true)) continue;
            $dst->table('company_vendor')->insertOrIgnore([
                'company_id' => $vc->client_id, 'vendor_id' => $vc->vendorID,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->info('vendors: ' . $dst->table('vendors')->count() . ' | company_vendor: ' . $dst->table('company_vendor')->count());

        // 7) logins (encrypt secret) — preserve id via loginID
        foreach ($src->table('logins')->get() as $l) {
            $cid = in_array((int) $l->company_id, $companyIds, true) ? (int) $l->company_id : null;
            $dst->table('logins')->insert([
                'id' => $l->loginID, 'company_id' => $cid,
                'vendor_id' => in_array((int) $l->vendorID, $vendorIds, true) ? $l->vendorID : null,
                'user_id' => in_array((int) $l->userID, $userIds, true) ? $l->userID : null,
                'login_name' => $l->login_name ?? null, 'login_id' => $l->login_id ?? null,
                'login_pass' => $l->login_pass ? Crypt::encryptString($l->login_pass) : null,
                'url' => $l->url ?? null, 'type' => $l->type ?? null,
                'pin' => $l->login_pin ?: ($l->loginpin ?? null),
                'is_active' => (int) ($l->is_active ?? 1), 'is_restricted' => (int) ($l->is_restricted ?? 0),
                'notes' => $l->notes ?? null,
                'created_at' => $l->created_at ?? now(), 'updated_at' => now(),
            ]);
        }
        $this->info('logins: ' . $dst->table('logins')->count());

        // 8) subscriptions
        $loginIds = $dst->table('logins')->pluck('id')->all();
        foreach ($src->table('subscriptions')->get() as $s) {
            $dst->table('subscriptions')->insert([
                'company_id' => null,
                'login_id' => in_array((int) $s->login_id, $loginIds, true) ? $s->login_id : null,
                'vendor_id' => in_array((int) $s->vendor_id, $vendorIds, true) ? $s->vendor_id : null,
                'user_id' => in_array((int) $s->user_id, $userIds, true) ? $s->user_id : null,
                'subscription_name' => $s->subscription_name ?? 'Subscription',
                'account_number' => $s->account_number ?? null, 'serial_number' => $s->serial_number ?? null,
                'amount' => $s->amount ?? null, 'renewal_date' => $s->renewal_date ?? null,
                'renewalfrequency' => $s->renewalfrequency ?? null, 'is_active' => (int) ($s->is_active ?? 1),
                'notes' => $s->notes ?? null,
                'created_at' => $s->created_at ?? now(), 'updated_at' => $s->updated_at ?? now(),
            ]);
        }
        // backfill subscription.company_id from its login
        $dst->statement('UPDATE subscriptions s JOIN logins l ON s.login_id=l.id SET s.company_id=l.company_id WHERE s.company_id IS NULL');
        $this->info('subscriptions: ' . $dst->table('subscriptions')->count());

        $this->info('Import complete.');
        return self::SUCCESS;
    }
}
