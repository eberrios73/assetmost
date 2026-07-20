<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LicenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_license_can_be_added_and_lands_under_its_vendor(): void
    {
        $company = Company::create(['name' => 'Acme', 'tag_prefix' => 'AC', 'tag_next' => 1001, 'active' => true]);
        // Vendors are company-scoped, not shared — the vendor belongs to Acme.
        $vendor = Vendor::create(['name' => 'Adobe', 'active' => true, 'company_id' => $company->id]);
        $product = Product::create(['vendor_id' => $vendor->id, 'name' => 'Firefly', 'active' => true]);
        $u = User::factory()->canLogin()->create(['company_id' => $company->id]);

        $this->actingAs($u)->postJson('/data/licenses', [
            'name' => 'Firefly — 5 seats',
            'vendor_id' => $vendor->id,
            'product_id' => $product->id,
            'seats_total' => 5,
            'amount' => 199,
        ])->assertCreated()->assertJsonPath('name', 'Firefly — 5 seats');

        // company_id came from the tenant scope, not the request.
        $this->assertDatabaseHas('licenses', [
            'name' => 'Firefly — 5 seats', 'company_id' => $company->id, 'product_id' => $product->id,
        ]);

        $this->actingAs($u)->getJson("/data/vendors/{$vendor->id}/licenses")
            ->assertOk()->assertJsonCount(1);
    }

    public function test_product_options_are_labelled_vendor_dash_product(): void
    {
        $company = Company::create(['name' => 'Acme', 'tag_prefix' => 'AC', 'tag_next' => 1001, 'active' => true]);
        $vendor = Vendor::create(['name' => 'Adobe', 'active' => true, 'company_id' => $company->id]);
        Product::create(['vendor_id' => $vendor->id, 'name' => 'Firefly', 'active' => true]);
        $u = User::factory()->canLogin()->create(['company_id' => $company->id]);

        $this->actingAs($u)->getJson('/data/product-options')
            ->assertOk()->assertJsonPath('0.label', 'Adobe — Firefly');
    }
}
