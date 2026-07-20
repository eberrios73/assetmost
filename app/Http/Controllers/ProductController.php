<?php

namespace App\Http\Controllers;

use App\Models\License;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Products are a vendor's catalog: Adobe ▸ Creative Cloud, Firefly, Substance.
 *
 * Catalog only. What you OWN is a licence, and those live on the Licences screen filtered
 * by product — a vendor can list hundreds of SKUs, so nesting your licences under them
 * would bury the handful you actually pay for.
 */
class ProductController extends Controller
{
    /** One vendor's catalog, with how many licences you hold of each. */
    public function forVendor(Vendor $vendor): JsonResponse
    {
        return response()->json(
            Product::query()->where('vendor_id', $vendor->getKey())
                ->orderBy('name')->get()->map(fn (Product $p) => $this->row($p))
        );
    }

    /** Add a product to a vendor's catalog. */
    public function store(Request $request, Vendor $vendor): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $data = $request->validate([
            // Unique per vendor, not globally: "Standard" can exist under several vendors,
            // and two Adobe "Firefly" rows would re-create the fragmentation we just undid.
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('products', 'name')->where('vendor_id', $vendor->getKey()),
            ],
            'sku' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $product = Product::create($data + ['vendor_id' => $vendor->getKey(), 'active' => true]);

        return response()->json($this->row($product), 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $data = $request->validate([
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('products', 'name')
                    ->where('vendor_id', $product->vendor_id)
                    ->ignore($product->id),
            ],
            'sku' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'active' => 'nullable|boolean',
        ]);
        // Untouched checkbox arrives null — leave the stored flag alone.
        if (array_key_exists('active', $data) && $data['active'] === null) unset($data['active']);
        $product->update($data);

        return response()->json($this->row($product->fresh()));
    }

    /**
     * Remove a product from the catalog. Refused while licences point at it — deleting it
     * would orphan what you're paying for, and a stale catalog entry is cheaper than a
     * licence that belongs to nothing. Deactivate instead.
     */
    public function destroy(Product $product): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $inUse = License::query()->where('product_id', $product->id)->count();
        if ($inUse > 0) {
            return response()->json([
                'errors' => ['name' => ["{$inUse} license(s) use this product. Mark it inactive instead."]],
            ], 422);
        }
        $product->delete();

        return response()->json(['ok' => true]);
    }

    /** {id, label} for the Licences screen's product filter and drawer. */
    public function options(): JsonResponse
    {
        return response()->json(
            Product::query()->with('vendor:vendorID,name')->where('active', true)
                ->orderBy('name')->get()
                ->map(fn ($p) => ['id' => $p->id, 'label' => $p->vendor?->name ? "{$p->vendor->name} — {$p->name}" : $p->name])
        );
    }

    private function row(Product $p): array
    {
        $licenses = License::query()->where('product_id', $p->id)->get();

        return [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => $p->sku,
            'notes' => $p->notes,
            'licenses' => $licenses->count(),
            'annual' => round((float) $licenses->sum('amount'), 2),
            'active' => (bool) $p->active,
        ];
    }
}
