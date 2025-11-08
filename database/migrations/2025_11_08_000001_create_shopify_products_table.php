<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('shopify_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopify_product_id')->unique()->comment('Shopify product ID');
            $table->unsignedInteger('bagisto_product_id')->comment('Bagisto product ID');
            $table->string('shopify_handle')->nullable()->comment('Shopify product handle');
            $table->string('sku')->nullable()->index()->comment('Product SKU');
            $table->timestamp('shopify_updated_at')->nullable()->comment('Last updated time in Shopify');
            $table->timestamp('last_synced_at')->nullable()->comment('Last sync time');
            $table->timestamps();

            // Foreign key to products table
            $table->foreign('bagisto_product_id')
                ->references('id')
                ->on('products')
                ->onDelete('cascade');

            // Index for faster lookups
            $table->index('shopify_product_id');
            $table->index('bagisto_product_id');
        });

        Schema::create('shopify_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopify_variant_id')->unique()->comment('Shopify variant ID');
            $table->unsignedBigInteger('shopify_product_id')->comment('Shopify product ID');
            $table->unsignedInteger('bagisto_product_id')->comment('Bagisto product ID (variant)');
            $table->string('sku')->nullable()->index()->comment('Variant SKU');
            $table->timestamp('shopify_updated_at')->nullable()->comment('Last updated time in Shopify');
            $table->timestamp('last_synced_at')->nullable()->comment('Last sync time');
            $table->timestamps();

            // Foreign key to products table
            $table->foreign('bagisto_product_id')
                ->references('id')
                ->on('products')
                ->onDelete('cascade');

            // Index for faster lookups
            $table->index('shopify_variant_id');
            $table->index('shopify_product_id');
            $table->index('bagisto_product_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('shopify_variants');
        Schema::dropIfExists('shopify_products');
    }
};

