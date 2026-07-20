<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc sharing across companies: a page stays OWNED by the company it was
 * created in (company_id) and becomes VISIBLE in every company listed here —
 * parent/child companies (a parent company ↔ its sister studio) run one playbook, not
 * two copies. Purely additive.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('doc_page_company', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('doc_page_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->unique(['doc_page_id', 'company_id']);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_page_company');
    }
};
