<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The clean ITer domain model.
 * Company ▸ Location ▸ Room ; devices are PLACED (location/room) and ASSIGNED (device_user).
 * All tenant-owned tables carry a bigint company_id FK (the shared tenant key).
 */
return new class extends Migration {
    public function up(): void
    {
        // Tenant
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name')->index();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('website')->nullable();
            $t->string('domain')->nullable();
            $t->string('contact_name', 100)->nullable();
            $t->string('address')->nullable();
            $t->string('city')->nullable();
            $t->string('state', 2)->nullable();
            $t->string('zip', 10)->nullable();
            $t->string('logo')->nullable();
            $t->longText('signature_html')->nullable();
            $t->longText('signature_logo_base64')->nullable();
            $t->longText('signature_logo_2_base64')->nullable();
            $t->string('offboard_email_forward_to')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        // Physical sites — Company ▸ Location
        Schema::create('locations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name');                       // "Los Angeles", "Texas"
            $t->string('type')->nullable();           // office / warehouse / datacenter / remote
            $t->string('address')->nullable();
            $t->string('city')->nullable();
            $t->string('state', 2)->nullable();
            $t->string('zip', 10)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->index(['company_id', 'active']);
        });

        // Location ▸ Room (real table — replaces the fake-user rooms)
        Schema::create('rooms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('location_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('room_type')->nullable();      // conference_room / meeting_room / office / …
            $t->string('room_number')->nullable();
            $t->integer('capacity')->nullable();
            $t->text('equipment')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->index('location_id');
        });

        // People — a user is set up in a location; rooms derive from that location
        Schema::table('users', function (Blueprint $t) {
            $t->string('last')->nullable()->after('name');
            $t->string('username')->nullable()->unique()->after('email');
            $t->string('personal_email')->nullable();
            $t->string('role', 20)->default('User')->index();
            $t->foreignId('company_id')->nullable()->after('role')->constrained()->nullOnDelete();
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->string('domain')->nullable();
            $t->string('title')->nullable();
            $t->string('department')->nullable()->index();
            $t->string('cell', 30)->nullable();
            $t->string('workcell', 30)->nullable();
            $t->string('ext', 11)->nullable();
            $t->boolean('active')->default(true)->index();
            $t->boolean('restricted')->default(false);
            $t->boolean('force_password_change')->default(false);
            $t->boolean('samba_synced')->default(false);
            $t->timestamp('samba_last_sync')->nullable();
            $t->string('samba_dn')->nullable();
            $t->json('onboarding_data')->nullable();
            $t->boolean('onboarding_complete')->default(false);
            $t->timestamp('offboarded_at')->nullable();
            $t->json('offboarding_data')->nullable();
            $t->softDeletes();
        });

        // Assets — PLACED at a location/room, ASSIGNED to users separately
        Schema::create('devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->string('asset_tag', 25)->nullable()->index();
            $t->string('computer_name')->nullable()->index();
            $t->string('type')->nullable()->index();
            $t->string('brand')->nullable();
            $t->string('model')->nullable();
            $t->string('serial_num')->nullable();
            $t->string('service_tag')->nullable();
            $t->string('cpu')->nullable();
            $t->string('ram')->nullable();
            $t->string('hard_drive')->nullable();
            $t->string('storage')->nullable();
            $t->string('op_sys')->nullable();
            $t->text('specs')->nullable();
            $t->string('ip_1')->nullable();
            $t->string('ip_2')->nullable();
            $t->string('domain')->nullable();
            $t->string('encryption')->nullable();
            $t->string('owner')->nullable();
            $t->string('vendor')->nullable();
            $t->string('invoice')->nullable();
            $t->date('inv_date')->nullable();
            $t->string('price')->nullable();
            $t->string('support_contract')->nullable();
            $t->string('support_expiration')->nullable();
            $t->string('support_number')->nullable();
            $t->string('eol')->nullable();
            $t->string('decommission_date')->nullable();
            $t->text('decommission_desc')->nullable();
            $t->boolean('ewaste')->default(false);
            $t->boolean('active')->default(true)->index();
            $t->boolean('restricted')->default(false);
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        // Device ▸ User assignment (assignment only — placement lives on devices)
        Schema::create('device_user', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['device_id', 'user_id']);
        });

        // Vendors + company link (m2m)
        Schema::create('vendors', function (Blueprint $t) {
            $t->id();
            $t->string('name')->index();
            $t->string('contact_name')->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->string('website')->nullable();
            $t->string('serial_number')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('active')->default(true)->index();
            $t->timestamps();
        });
        Schema::create('company_vendor', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'vendor_id']);
        });

        // Credentials
        Schema::create('logins', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('login_name')->nullable()->index();
            $t->string('login_id')->nullable();
            $t->text('login_pass')->nullable();       // encrypted at the model layer
            $t->string('url')->nullable();
            $t->string('type')->nullable();
            $t->string('pin', 50)->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->boolean('is_restricted')->default(false);
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        // Licenses / subscriptions
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('login_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('subscription_name');
            $t->string('account_number')->nullable();
            $t->string('serial_number')->nullable();
            $t->decimal('amount', 10, 2)->nullable();
            $t->date('renewal_date')->nullable();
            $t->integer('renewalfrequency')->nullable();
            $t->boolean('is_active')->default(true);
            $t->text('notes')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('logins');
        Schema::dropIfExists('company_vendor');
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('device_user');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('companies');
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['last','username','personal_email','role','domain','title','department',
                'cell','workcell','ext','active','restricted','force_password_change',
                'samba_synced','samba_last_sync','samba_dn','onboarding_data','onboarding_complete',
                'offboarded_at','offboarding_data','deleted_at']);
            $t->dropConstrainedForeignId('company_id');
            $t->dropConstrainedForeignId('location_id');
        });
    }
};
