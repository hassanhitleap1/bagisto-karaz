<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ShopifyScraperCommand extends Command
{
    protected $signature = 'import:shopify-products 
                           {--url= : Shopify store URL}
                           {--per-page=250 : Items per page}
                           {--max-pages= : Maximum pages to import}
                           {--current-page=1 : Current page to start from}';

    protected $description = 'Scrape Shopify products and import to Bagisto';

    protected string $baseUrl;
    protected int $perPage;
    protected int $currentPage;
    protected ?int $maxPages;
    
    // Caches to improve performance
    protected array $attributeCache = [];
    protected array $brandCache = [];
    protected array $categoryCache = [];
    protected array $attributeFamilyCache = [];

    public function handle(): int
    {
        ini_set('max_execution_time', 0);
        set_time_limit(0);

        // Get command options
        $this->baseUrl = rtrim($this->option('url') ?: $this->ask('Enter Shopify store URL'), '/');
        $this->perPage = (int) $this->option('per-page');
        $this->currentPage = (int) $this->option('current-page');
        $this->maxPages = $this->option('max-pages') ? (int) $this->option('max-pages') : null;

        if (empty($this->baseUrl)) {
            $this->error('Please provide a Shopify store URL');
            return 1;
        }

        $this->info("Starting Shopify import from: {$this->baseUrl}");
        $this->info("Configuration: {$this->perPage} products per page, starting at page {$this->currentPage}");

        $totalImported = 0;
        $pagesProcessed = 0;

        // Process pages
        while (true) {
            try {
                // Check max pages limit
                if ($this->maxPages && $pagesProcessed >= $this->maxPages) {
                    $this->info("Reached maximum pages limit ({$this->maxPages})");
                    break;
                }

                $this->info("\n--- Processing Page {$this->currentPage} ---");
                
                $response = $this->fetchProductsPage($this->currentPage);

                if ($response->failed()) {
                    $this->error("Failed to fetch page {$this->currentPage}: HTTP {$response->status()}");
                    break;
                }

                $data = $response->json();

                if (empty($data['products'])) {
                    $this->info("No more products found. Import completed.");
                    break;
                }

                $productsCount = count($data['products']);
                $this->info("Found {$productsCount} products on page {$this->currentPage}");

                // Process each product
                foreach ($data['products'] as $index => $productData) {
                    $this->info("\nProcessing product " . ($index + 1) . "/{$productsCount}: {$productData['title']}");
                    
                    try {
                        $this->processProduct($productData);
                        $totalImported++;
                        $this->info("✓ Successfully imported: {$productData['title']}");
                    } catch (Exception $e) {
                        $this->error("✗ Failed to import {$productData['title']}: {$e->getMessage()}");
                        $this->error($e->getTraceAsString());
                    }
                }

                $pagesProcessed++;
                $this->currentPage++;

                // Break if we got fewer products than requested (last page)
                if ($productsCount < $this->perPage) {
                    $this->info("Reached last page of products");
                    break;
                }

                // Small delay between pages to be respectful
                sleep(1);

            } catch (Exception $e) {
                $this->error("Error on page {$this->currentPage}: {$e->getMessage()}");
                break;
            }
        }

        $this->info("\n=== Import Summary ===");
        $this->info("Total products imported: {$totalImported}");
        $this->info("Pages processed: {$pagesProcessed}");

        return 0;
    }

    protected function fetchProductsPage(int $page)
    {
        $apiUrl = "{$this->baseUrl}/products.json?limit={$this->perPage}&page={$page}";
        
        return Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept' => 'application/json',
        ])->timeout(30)->retry(3, 1000)->get($apiUrl);
    }

    protected function processProduct(array $productData): void
    {
        DB::transaction(function () use ($productData) {
            // 1. Get or create brand
            $brand = null;
            if (!empty($productData['vendor'])) {
                $brand = $this->getOrCreateBrand($productData['vendor']);
            }

            // 2. Get or create category
            $category = null;
            if (!empty($productData['product_type'])) {
                $category = $this->getOrCreateCategory($productData['product_type']);
            }

            // 3. Get or create attribute family
            $attributeFamily = $this->getOrCreateAttributeFamily();

            // 4. Process product attributes/options
            $attributes = [];
            if (!empty($productData['options'])) {
                $attributes = $this->processProductOptions($productData['options']);
            }

            // 5. Create main product
            $product = $this->createProduct($productData, $attributeFamily->id);

            // 6. Assign brand
            if ($brand) {
                $this->assignBrandToProduct($product, $brand);
            }

            // 7. Assign categories
            if ($category) {
                $this->assignCategoriesToProduct($product, [$category]);
            }

            // 8. Process and assign main product images
            if (!empty($productData['images'])) {
                $this->processProductImages($product, $productData['images']);
            }

            // 9. Process variants
            if (!empty($productData['variants']) && count($productData['variants']) > 1) {
                $this->processVariants($product, $productData['variants'], $productData['images'] ?? [], $attributes);
            }

            // 10. Assign tags as additional categories if present
            if (!empty($productData['tags'])) {
                $this->processTags($product, $productData['tags']);
            }
        });
    }

    protected function getOrCreateBrand(string $vendorName)
    {
        if (isset($this->brandCache[$vendorName])) {
            return $this->brandCache[$vendorName];
        }

        $brand = DB::table('brands')->where('name', $vendorName)->first();

        if (!$brand) {
            $slug = Str::slug($vendorName);
            
            $brandId = DB::table('brands')->insertGetId([
                'name' => $vendorName,
                'slug' => $slug,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $brand = DB::table('brands')->find($brandId);
            $this->info("Created brand: {$vendorName}");
        }

        $this->brandCache[$vendorName] = $brand;
        return $brand;
    }

    protected function getOrCreateCategory(string $categoryName)
    {
        if (isset($this->categoryCache[$categoryName])) {
            return $this->categoryCache[$categoryName];
        }

        $category = DB::table('categories')->where('name', $categoryName)->first();

        if (!$category) {
            $slug = Str::slug($categoryName);
            
            $categoryId = DB::table('categories')->insertGetId([
                'name' => $categoryName,
                'slug' => $slug,
                'status' => 1,
                'display_mode' => 'products_only',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Create category translation
            DB::table('category_translations')->insert([
                'locale' => 'en',
                'name' => $categoryName,
                'slug' => $slug,
                'description' => $categoryName,
                'category_id' => $categoryId,
            ]);

            $category = DB::table('categories')->find($categoryId);
            $this->info("Created category: {$categoryName}");
        }

        $this->categoryCache[$categoryName] = $category;
        return $category;
    }

    protected function getOrCreateAttributeFamily()
    {
        $familyCode = 'default';
        
        if (isset($this->attributeFamilyCache[$familyCode])) {
            return $this->attributeFamilyCache[$familyCode];
        }

        $family = DB::table('attribute_families')->where('code', $familyCode)->first();

        if (!$family) {
            $familyId = DB::table('attribute_families')->insertGetId([
                'code' => $familyCode,
                'name' => 'Default',
                'status' => 1,
                'is_user_defined' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $family = DB::table('attribute_families')->find($familyId);
        }

        $this->attributeFamilyCache[$familyCode] = $family;
        return $family;
    }

    protected function processProductOptions(array $options): array
    {
        $processedAttributes = [];

        foreach ($options as $option) {
            $attributeName = $option['name'];
            $attributeCode = Str::slug($attributeName, '_');

            // Get or create attribute
            $attribute = $this->getOrCreateAttribute($attributeCode, $attributeName, $option['values']);
            
            $processedAttributes[$option['position']] = [
                'attribute' => $attribute,
                'values' => $option['values'],
            ];
        }

        return $processedAttributes;
    }

    protected function getOrCreateAttribute(string $code, string $name, array $values)
    {
        if (isset($this->attributeCache[$code])) {
            return $this->attributeCache[$code];
        }

        $attribute = DB::table('attributes')->where('code', $code)->first();

        if (!$attribute) {
            $attributeId = DB::table('attributes')->insertGetId([
                'code' => $code,
                'admin_name' => $name,
                'type' => 'select',
                'validation' => null,
                'position' => 1,
                'is_required' => 0,
                'is_unique' => 0,
                'value_per_locale' => 0,
                'value_per_channel' => 0,
                'is_filterable' => 1,
                'is_configurable' => 1,
                'is_user_defined' => 1,
                'is_visible_on_front' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Create attribute translation
            DB::table('attribute_translations')->insert([
                'locale' => 'en',
                'name' => $name,
                'attribute_id' => $attributeId,
            ]);

            // Create attribute options
            foreach ($values as $index => $value) {
                $optionId = DB::table('attribute_options')->insertGetId([
                    'admin_name' => $value,
                    'sort_order' => $index,
                    'attribute_id' => $attributeId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('attribute_option_translations')->insert([
                    'locale' => 'en',
                    'label' => $value,
                    'attribute_option_id' => $optionId,
                ]);
            }

            $attribute = DB::table('attributes')->find($attributeId);
            $this->info("Created attribute: {$name} with " . count($values) . " options");
        }

        $this->attributeCache[$code] = $attribute;
        return $attribute;
    }

    protected function createProduct(array $productData, int $attributeFamilyId)
    {
        $firstVariant = $productData['variants'][0] ?? [];
        $sku = $firstVariant['sku'] ?? 'SKU-' . Str::random(10);

        $productId = DB::table('products')->insertGetId([
            'type' => count($productData['variants'] ?? []) > 1 ? 'configurable' : 'simple',
            'attribute_family_id' => $attributeFamilyId,
            'sku' => $sku,
            'parent_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create product flat entry
        DB::table('product_flat')->insert([
            'product_id' => $productId,
            'sku' => $sku,
            'type' => count($productData['variants'] ?? []) > 1 ? 'configurable' : 'simple',
            'name' => $productData['title'],
            'short_description' => $this->stripHtml($productData['body_html'] ?? ''),
            'description' => $productData['body_html'] ?? '',
            'url_key' => Str::slug($productData['title']),
            'status' => 1,
            'visible_individually' => 1,
            'price' => (float) ($firstVariant['price'] ?? 0),
            'special_price' => isset($firstVariant['compare_at_price']) ? (float) $firstVariant['price'] : null,
            'weight' => (float) ($firstVariant['weight'] ?? 0),
            'locale' => 'en',
            'channel' => 'default',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create product inventory
        DB::table('product_inventories')->insert([
            'product_id' => $productId,
            'inventory_source_id' => 1,
            'vendor_id' => 0,
            'qty' => (int) ($firstVariant['inventory_quantity'] ?? 0),
        ]);

        return (object) ['id' => $productId, 'sku' => $sku];
    }

    protected function assignBrandToProduct($product, $brand): void
    {
        DB::table('product_flat')
            ->where('product_id', $product->id)
            ->update(['brand' => $brand->name]);
    }

    protected function assignCategoriesToProduct($product, array $categories): void
    {
        foreach ($categories as $category) {
            DB::table('product_categories')->insertOrIgnore([
                'product_id' => $product->id,
                'category_id' => $category->id,
            ]);
        }
    }

    protected function processProductImages($product, array $images): void
    {
        foreach ($images as $index => $image) {
            try {
                $imagePath = $this->downloadImage($image['src'], $product->id);
                
                if ($imagePath) {
                    DB::table('product_images')->insert([
                        'product_id' => $product->id,
                        'path' => $imagePath,
                        'position' => $index,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Set first image as main product image
                    if ($index === 0) {
                        DB::table('product_flat')
                            ->where('product_id', $product->id)
                            ->update(['base_image' => $imagePath]);
                    }
                }
            } catch (Exception $e) {
                $this->warn("Failed to download image: {$e->getMessage()}");
            }
        }
    }

    protected function processVariants($product, array $variants, array $images, array $attributes): void
    {
        // Create super attributes
        foreach ($attributes as $attrData) {
            DB::table('product_super_attributes')->insertOrIgnore([
                'product_id' => $product->id,
                'attribute_id' => $attrData['attribute']->id,
            ]);
        }

        foreach ($variants as $index => $variant) {
            $variantSku = $variant['sku'] ?? $product->sku . '-V' . ($index + 1);

            // Create variant product
            $variantProductId = DB::table('products')->insertGetId([
                'type' => 'simple',
                'attribute_family_id' => DB::table('products')->where('id', $product->id)->value('attribute_family_id'),
                'sku' => $variantSku,
                'parent_id' => $product->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Create variant flat
            DB::table('product_flat')->insert([
                'product_id' => $variantProductId,
                'sku' => $variantSku,
                'type' => 'simple',
                'name' => $variant['title'],
                'url_key' => Str::slug($variant['title']),
                'status' => 1,
                'visible_individually' => 0,
                'price' => (float) ($variant['price'] ?? 0),
                'special_price' => isset($variant['compare_at_price']) ? (float) $variant['price'] : null,
                'weight' => (float) ($variant['weight'] ?? 0),
                'locale' => 'en',
                'channel' => 'default',
                'parent_id' => $product->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Create variant inventory
            DB::table('product_inventories')->insert([
                'product_id' => $variantProductId,
                'inventory_source_id' => 1,
                'vendor_id' => 0,
                'qty' => (int) ($variant['inventory_quantity'] ?? 0),
            ]);

            // Assign variant image
            if (!empty($variant['image_id'])) {
                $variantImage = collect($images)->firstWhere('id', $variant['image_id']);
                if ($variantImage) {
                    $imagePath = $this->downloadImage($variantImage['src'], $variantProductId);
                    if ($imagePath) {
                        DB::table('product_flat')
                            ->where('product_id', $variantProductId)
                            ->update(['base_image' => $imagePath]);
                    }
                }
            }

            // Link variant attributes
            for ($i = 1; $i <= 3; $i++) {
                $optionKey = "option{$i}";
                if (!empty($variant[$optionKey])) {
                    $this->linkVariantAttribute($variantProductId, $variant[$optionKey]);
                }
            }
        }
    }

    protected function linkVariantAttribute(int $variantProductId, string $optionValue): void
    {
        // Find the attribute option
        $option = DB::table('attribute_option_translations')
            ->where('label', $optionValue)
            ->first();

        if ($option) {
            $attributeOption = DB::table('attribute_options')->find($option->attribute_option_id);
            
            if ($attributeOption) {
                DB::table('product_attribute_values')->insertOrIgnore([
                    'product_id' => $variantProductId,
                    'attribute_id' => $attributeOption->attribute_id,
                    'value' => $option->attribute_option_id,
                    'channel' => 'default',
                    'locale' => 'en',
                ]);
            }
        }
    }

    protected function processTags($product, $tags): void
    {
        $tagArray = is_array($tags) ? $tags : array_map('trim', explode(',', $tags));

        foreach ($tagArray as $tagName) {
            $tagName = trim($tagName);
            if (empty($tagName)) continue;

            $category = $this->getOrCreateCategory($tagName);
            $this->assignCategoriesToProduct($product, [$category]);
        }
    }

    protected function downloadImage(string $url, int $productId): ?string
    {
        try {
            $response = Http::timeout(20)->retry(2, 1000)->get($url);

            if (!$response->successful()) {
                return null;
            }

            $imageData = $response->body();
            $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
            $fileName = 'product-' . $productId . '-' . Str::random(10) . '.' . $extension;
            $path = "product/{$productId}/{$fileName}";

            Storage::disk('public')->put($path, $imageData);

            return $path;
        } catch (Exception $e) {
            $this->warn("Image download failed: {$e->getMessage()}");
            return null;
        }
    }

    protected function stripHtml(?string $html): string
    {
        if (!$html) return '';
        return strip_tags(html_entity_decode($html));
    }
}
