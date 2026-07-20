<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocPage extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    /** Re-register the tenancy scope WITH sharing: a page is visible to the
     *  company that owns it and to every company it's shared with (parent and
     *  child companies run one playbook, not two copies). */
    protected static function booted(): void
    {
        static::addGlobalScope('company', function ($query) {
            $ids = app(\App\Support\Contracts\TenantResolver::class)->scopeIds();
            if ($ids !== null) {
                $query->where(fn ($w) => $w->whereIn('doc_pages.company_id', $ids)
                    ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('doc_page_company')
                        ->whereColumn('doc_page_company.doc_page_id', 'doc_pages.id')
                        ->whereIn('doc_page_company.company_id', $ids)));
            }
        });
    }

    /** Same visibility test for explicit company checks (refs, script warnings). */
    public function scopeVisibleToCompany($query, int $companyId)
    {
        return $query->where(fn ($w) => $w->where('doc_pages.company_id', $companyId)
            ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('doc_page_company')
                ->whereColumn('doc_page_company.doc_page_id', 'doc_pages.id')
                ->where('doc_page_company.company_id', $companyId)));
    }

    public function sharedCompanies() { return $this->belongsToMany(Company::class, 'doc_page_company')->withTimestamps(); }
    public function parent(): BelongsTo { return $this->belongsTo(DocPage::class, 'parent_id'); }
    public function children(): HasMany { return $this->hasMany(DocPage::class, 'parent_id')->orderBy('position')->orderBy('title'); }
    public function editor(): BelongsTo { return $this->belongsTo(User::class, 'updated_by'); }
}
