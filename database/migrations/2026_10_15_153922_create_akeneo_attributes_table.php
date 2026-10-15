<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('akeneo_attributes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('type');
            $table->json('labels')->nullable();
            $table->boolean('localizable')->default(false);
            $table->boolean('scopable')->default(false);
            $table->json('options')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->boolean('shopify_enabled')->default(false);
            $table->string('shopify_name')->nullable();
            $table->string('shopify_key', 64)->nullable()->unique();
            $table->string('shopify_type')->nullable();
            $table->string('shopify_definition_id')->nullable();
            $table->text('shopify_definition_error')->nullable();
            $table->timestamp('shopify_definition_checked_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('akeneo_attributes');
    }
};
