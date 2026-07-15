<?php

namespace App\Console\Commands;

use App\Models\Login;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Data cleanup: most logins have a null user_id but the login_id encodes the
 * person (e.g. "lsudo.adbe@plutonicgames.com"). Match the login_id token
 * to a user and backfill user_id. Only links UNIQUE matches; reports the rest.
 */
class LinkLoginsToUsers extends Command
{
    protected $signature = 'iter:link-logins {--dry : report only, do not write}';
    protected $description = 'Backfill logins.user_id by matching the login_id to users';

    public function handle(): int
    {
        $dry = $this->option('dry');

        // domain -> company_id (to prefer same-company matches)
        $companyByDomain = \App\Models\Company::query()->withoutGlobalScopes()
            ->whereNotNull('domain')->pluck('id', 'domain')
            ->mapWithKeys(fn ($id, $d) => [strtolower(trim($d)) => $id])->all();

        $users = User::query()->withoutGlobalScopes()->get(['id', 'name', 'last', 'username', 'company_id']);

        // build lookup keys per user: username, first, first+last, f+last, first+l
        $index = []; // key => [company_id => [user_ids]]
        $add = function ($key, $u) use (&$index) {
            $key = strtolower(preg_replace('/[^a-z0-9]/i', '', $key));
            if ($key === '') return;
            $index[$key][$u->company_id ?? 0][] = $u->id;
        };
        foreach ($users as $u) {
            $first = $u->name; $last = $u->last;
            if ($u->username) $add($u->username, $u);
            if ($first) $add($first, $u);
            if ($first && $last) {
                $add($first . $last, $u);
                $add(substr($first, 0, 1) . $last, $u);
                $add($first . substr($last, 0, 1), $u);
            }
        }

        $linked = 0; $ambiguous = 0; $nomatch = 0;
        $logins = Login::query()->withoutGlobalScopes()
            ->whereNull('user_id')->whereNotNull('login_id')->where('login_id', '<>', '')->get();

        foreach ($logins as $l) {
            [$local, $domain] = array_pad(explode('@', strtolower($l->login_id), 2), 2, '');
            $token = preg_replace('/[^a-z0-9]/i', '', explode('.', $local)[0]);
            if ($token === '') { $nomatch++; continue; }

            $cid = $companyByDomain[trim($domain)] ?? $l->company_id ?? null;
            $buckets = $index[$token] ?? null;
            if (! $buckets) { $nomatch++; continue; }

            // prefer same-company candidates; else any
            $cands = $buckets[$cid] ?? array_merge(...array_values($buckets));
            $cands = array_values(array_unique($cands));

            if (count($cands) === 1) {
                if (! $dry) Login::withoutGlobalScopes()->whereKey($l->id)->update(['user_id' => $cands[0]]);
                $linked++;
            } elseif (count($cands) > 1) {
                $ambiguous++;
            } else {
                $nomatch++;
            }
        }

        $this->info(($dry ? '[dry] ' : '') . "Linked: $linked  |  Ambiguous (skipped): $ambiguous  |  No match: $nomatch  |  of {$logins->count()} unlinked logins");
        return self::SUCCESS;
    }
}
