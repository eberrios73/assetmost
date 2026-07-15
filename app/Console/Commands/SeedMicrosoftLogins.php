<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Login;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Console\Command;

/**
 * Every user has a Microsoft account = their email. Create a "Microsoft" login
 * per user (login_id = email) so M365 licensing can be tracked against it.
 * A login is not a license — this just establishes the account; seats/subscriptions
 * attach separately. Idempotent: skips users that already have a Microsoft login.
 */
class SeedMicrosoftLogins extends Command
{
    protected $signature = 'iter:seed-microsoft-logins';
    protected $description = 'Create a Microsoft (M365) login for each user, keyed on their email';

    public function handle(): int
    {
        $vendor = Vendor::withoutGlobalScopes()->firstOrCreate(
            ['name' => 'Microsoft'],
            ['website' => 'https://microsoft.com', 'active' => true]
        );

        $created = 0; $skipped = 0;
        $companyIds = [];

        $users = User::query()->withoutGlobalScopes()
            ->whereNotNull('email')->where('email', '<>', '')->get();

        foreach ($users as $u) {
            $exists = Login::withoutGlobalScopes()
                ->where('vendor_id', $vendor->id)->where('user_id', $u->id)->exists();
            if ($exists) { $skipped++; continue; }

            Login::withoutGlobalScopes()->create([
                'company_id' => $u->company_id,
                'vendor_id' => $vendor->id,
                'user_id' => $u->id,
                'login_name' => 'Microsoft 365',
                'login_id' => $u->email,
                'type' => 'Microsoft 365',
                'url' => 'https://portal.office.com',
                'is_active' => true,
            ]);
            $created++;
            if ($u->company_id) $companyIds[$u->company_id] = true;
        }

        // link the Microsoft vendor to the companies it now serves (for scoped vendor lists)
        foreach (array_keys($companyIds) as $cid) {
            if (Company::withoutGlobalScopes()->whereKey($cid)->exists()) {
                $vendor->companies()->syncWithoutDetaching([$cid]);
            }
        }

        $this->info("Microsoft logins created: $created  |  already existed: $skipped");
        return self::SUCCESS;
    }
}
