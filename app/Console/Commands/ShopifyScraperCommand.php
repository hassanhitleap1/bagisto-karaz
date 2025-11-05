<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Attribute\Repositories\AttributeOptionRepository;
use Webkul\Attribute\Repositories\AttributeFamilyRepository;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Repositories\ProductImageRepository;
use Webkul\Product\Repositories\ProductInventoryRepository;
use Webkul\Product\Repositories\ProductAttributeValueRepository;

class ShopifyScraperCommand extends Command
{
    protected $signature = 'import:shopify-products
                           {--url= : Shopify store URL}
                           {--per-page=250 : Items per page}
                           {--max-pages= : Maximum pages to import}
                           {--current-page=1 : Current page to start from}';

    protected $description = 'Scrape Shopify products and import to Bagisto with full attributes, variants, images, brands, and categories';

    protected string $baseUrl;
    protected int $perPage;
    protected int $currentPage;
    protected ?int $maxPages;

    // Caches to improve performance
    protected array $attributeCache = [];
    protected array $attributeOptionCache = [];
    protected array $brandCache = [];
    protected array $categoryCache = [];
    protected array $categoryImageCache = [];
    protected array $brandImageCache = [];
    protected array $attributeFamilyCache = [];
    protected array $skuCache = [];

    // Repositories
    protected AttributeRepository $attributeRepository;
    protected AttributeOptionRepository $attributeOptionRepository;
    protected AttributeFamilyRepository $attributeFamilyRepository;
    protected CategoryRepository $categoryRepository;
    protected ProductRepository $productRepository;
    protected ProductImageRepository $productImageRepository;
    protected ProductInventoryRepository $productInventoryRepository;
    protected ProductAttributeValueRepository $productAttributeValueRepository;

    // Default values
    protected string $defaultLocale = 'en';
    protected string $defaultChannel = 'default';
    protected int $defaultInventorySourceId = 1;
    protected array $availableLocales = [];

    /**
     * Constructor
     */
    public function __construct(
        AttributeRepository $attributeRepository,
        AttributeOptionRepository $attributeOptionRepository,
        AttributeFamilyRepository $attributeFamilyRepository,
        CategoryRepository $categoryRepository,
        ProductRepository $productRepository,
        ProductImageRepository $productImageRepository,
        ProductInventoryRepository $productInventoryRepository,
        ProductAttributeValueRepository $productAttributeValueRepository
    ) {
        parent::__construct();

        $this->attributeRepository = $attributeRepository;
        $this->attributeOptionRepository = $attributeOptionRepository;
        $this->attributeFamilyRepository = $attributeFamilyRepository;
        $this->categoryRepository = $categoryRepository;
        $this->productRepository = $productRepository;
        $this->productImageRepository = $productImageRepository;
        $this->productInventoryRepository = $productInventoryRepository;
        $this->productAttributeValueRepository = $productAttributeValueRepository;
    }

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

        // Load available locales from database
        $this->loadAvailableLocales();

        $this->info("Starting Shopify import from: {$this->baseUrl}");
        $this->info("Configuration: {$this->perPage} products per page, starting at page {$this->currentPage}");
        $this->info("Minimum pages to scrape: 3");

        $totalImported = 0;
        $totalFailed = 0;
        $pagesProcessed = 0;

        // Process pages (minimum 3 pages)
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

                    // If we haven't processed minimum 3 pages, continue trying
                    if ($pagesProcessed < 3) {
                        $this->warn("Retrying in 2 seconds...");
                        sleep(2);
                        continue;
                    }
                    break;
                }

                $data = $response->json();

                if (empty($data['products'])) {
                    if ($pagesProcessed < 3) {
                        $this->warn("No products found on page {$this->currentPage}, but continuing to meet minimum 3 pages requirement");
                        $pagesProcessed++;
                        $this->currentPage++;
                        continue;
                    }
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
                        $totalFailed++;
                        $this->error("✗ Failed to import {$productData['title']}: {$e->getMessage()}");
                        if ($this->option('verbose')) {
                            $this->error($e->getTraceAsString());
                        }
                    }
                }

                $pagesProcessed++;
                $this->currentPage++;

                // Break if we got fewer products than requested (last page) and we've processed at least 3 pages
                if ($productsCount < $this->perPage && $pagesProcessed >= 3) {
                    $this->info("Reached last page of products");
                    break;
                }

                // Small delay between pages to be respectful
                sleep(1);

            } catch (Exception $e) {
                $this->error("Error on page {$this->currentPage}: {$e->getMessage()}");
                if ($pagesProcessed >= 3) {
                    break;
                }
            }
        }

        $this->info("\n=== Import Summary ===");
        $this->info("Total products imported: {$totalImported}");
        $this->info("Total products failed: {$totalFailed}");
        $this->info("Pages processed: {$pagesProcessed}");
        $this->info("Brands created/updated: " . count($this->brandCache));
        $this->info("Categories created/updated: " . count($this->categoryCache));
        $this->info("Attributes created/updated: " . count($this->attributeCache));

        return 0;
    }

    /**
     * Load available locales from database
     */
    protected function loadAvailableLocales(): void
    {
        $locales = DB::table('locales')->pluck('code')->toArray();

        if (empty($locales)) {
            $this->warn("No locales found in database. Using default locale 'en' only.");
            $this->availableLocales = ['en'];
        } else {
            $this->availableLocales = $locales;
            $this->info("Loaded locales: " . implode(', ', $locales));
        }
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
            // Check for duplicate SKU
            $firstVariant = $productData['variants'][0] ?? [];
            $sku = $this->generateSku($firstVariant, $productData['title']);

            if (isset($this->skuCache[$sku]) || $this->productRepository->findOneByField('sku', $sku)) {
                $this->warn("Product with SKU {$sku} already exists. Skipping...");
                return;
            }

            $this->skuCache[$sku] = true;

            // 1. Get or create brand with image
            $brand = null;
            if (!empty($productData['vendor'])) {
                $brand = $this->getOrCreateBrand($productData['vendor'], $productData['image'] ?? null);
            }

            // 2. Get or create categories with images
            $categories = [];
            if (!empty($productData['product_type'])) {
                $category = $this->getOrCreateCategory($productData['product_type'], $productData['image'] ?? null);
                $categories[] = $category;
            }

            // 3. Get or create attribute family
            $attributeFamily = $this->getOrCreateAttributeFamily();

            // 4. Process product attributes/options
            $superAttributes = [];
            $attributeData = [];
            if (!empty($productData['options'])) {
                $result = $this->processProductOptions($productData['options']);
                $superAttributes = $result['super_attributes'];
                $attributeData = $result['attribute_data'];
            }

            // 5. Determine product type
            $hasVariants = !empty($productData['variants']) && count($productData['variants']) > 1;
            $productType = $hasVariants ? 'configurable' : 'simple';

            // 6. Create main product using repository
            $product = $this->createProduct($productData, $attributeFamily, $productType, $superAttributes);

            // 7. Assign brand
            if ($brand) {
                $this->assignBrandToProduct($product, $brand);
            }

            // 8. Assign categories
            if (!empty($categories)) {
                $product->categories()->sync(collect($categories)->pluck('id')->toArray());
                $this->info("  - Assigned " . count($categories) . " categories");
            }

            // 9. Process and assign main product images
            if (!empty($productData['images'])) {
                $this->processProductImages($product, $productData['images']);
            }

            // 10. Process variants
            if ($hasVariants) {
                $this->processVariants($product, $productData['variants'], $productData['images'] ?? [], $attributeData);
            } else {
                // For simple products, update with first variant data
                $this->updateSimpleProductData($product, $firstVariant);
            }

            // 11. Assign tags as additional categories if present
            if (!empty($productData['tags'])) {
                $tagCategories = $this->processTags($productData['tags']);
                if (!empty($tagCategories)) {
                    $allCategoryIds = array_merge(
                        collect($categories)->pluck('id')->toArray(),
                        collect($tagCategories)->pluck('id')->toArray()
                    );
                    $product->categories()->sync(array_unique($allCategoryIds));
                }
            }
        });
    }

    protected function generateSku(array $variant, string $productTitle): string
    {
        if (!empty($variant['sku'])) {
            return $variant['sku'];
        }

        return 'SHOP-' . Str::slug(substr($productTitle, 0, 20)) . '-' . Str::random(6);
    }

    protected function getOrCreateBrand(string $vendorName, ?array $imageData = null)
    {
        if (isset($this->brandCache[$vendorName])) {
            return $this->brandCache[$vendorName];
        }

        // Get the brand attribute (code: 'brand')
        $brandAttribute = DB::table('attributes')->where('code', 'brand')->first();

        if (!$brandAttribute) {
            $this->warn("  - Brand attribute not found in database. Skipping brand assignment.");
            return null;
        }

        // Check if brand option already exists
        $brandOption = DB::table('attribute_option_translations')
            ->where('label', $vendorName)
            ->whereIn('attribute_option_id', function ($query) use ($brandAttribute) {
                $query->select('id')
                    ->from('attribute_options')
                    ->where('attribute_id', $brandAttribute->id);
            })
            ->first();

        if ($brandOption) {
            $brand = DB::table('attribute_options')->find($brandOption->attribute_option_id);
        } else {
            // Create new brand option
            $brandOptionId = DB::table('attribute_options')->insertGetId([
                'admin_name' => $vendorName,
                'sort_order' => 0,
                'attribute_id' => $brandAttribute->id,
            ]);

            // Create translations for all available locales
            foreach ($this->availableLocales as $locale) {
                DB::table('attribute_option_translations')->insert([
                    'locale' => $locale,
                    'label' => $vendorName, // Same name for all locales (can be customized later)
                    'attribute_option_id' => $brandOptionId,
                ]);
            }

            $brand = DB::table('attribute_options')->find($brandOptionId);
            $this->info("  - Created brand option: {$vendorName} (locales: " . implode(', ', $this->availableLocales) . ")");

            // Download and assign brand image if available (swatch image)
            if ($imageData && !empty($imageData['src']) && !isset($this->brandImageCache[$vendorName])) {
                $this->downloadAndAssignBrandImage($brandOptionId, $imageData['src']);
                $this->brandImageCache[$vendorName] = true;
            }
        }

        $this->brandCache[$vendorName] = $brand;
        return $brand;
    }

    protected function getOrCreateCategory(string $categoryName, ?array $imageData = null)
    {
        if (isset($this->categoryCache[$categoryName])) {
            return $this->categoryCache[$categoryName];
        }

        // Try to find existing category by translation
        $categoryTranslation = DB::table('category_translations')
            ->where('name', $categoryName)
            ->where('locale', $this->defaultLocale)
            ->first();

        if ($categoryTranslation) {
            $category = $this->categoryRepository->find($categoryTranslation->category_id);
        } else {
            // Create new category using repository with multi-locale support
            $slug = Str::slug($categoryName);

            // Build category data with all locales
            $categoryData = [
                'position' => 1,
                'status' => 1,
                'display_mode' => 'products_and_description',
            ];

            // Add translations for all available locales
            foreach ($this->availableLocales as $locale) {
                $categoryData[$locale] = [
                    'name' => $categoryName,
                    'slug' => $slug,
                    'description' => "Category for {$categoryName} products",
                    'meta_title' => $categoryName,
                    'meta_description' => "Shop {$categoryName} products",
                    'meta_keywords' => $categoryName,
                ];
            }

            $category = $this->categoryRepository->create($categoryData);

            $this->info("  - Created category: {$categoryName} (locales: " . implode(', ', $this->availableLocales) . ")");

            // Download and assign category image if available
            if ($imageData && !empty($imageData['src']) && !isset($this->categoryImageCache[$categoryName])) {
                $this->downloadAndAssignCategoryImage($category->id, $imageData['src']);
                $this->categoryImageCache[$categoryName] = true;
            }
        }

        $this->categoryCache[$categoryName] = $category;
        return $category;
    }

    protected function downloadAndAssignBrandImage(int $brandOptionId, string $imageUrl): void
    {
        try {
            $imageContent = $this->downloadImage($imageUrl);
            if (!$imageContent) {
                return;
            }

            // Convert to WebP
            $manager = new ImageManager(['driver' => 'gd']);
            $image = $manager->make($imageContent);
            $webpImage = $image->encode('webp', 90);

            // Save to storage as swatch image
            $filename = 'attribute-option-' . $brandOptionId . '-' . time() . '.webp';
            $path = 'attribute_option/' . $filename;
            Storage::put($path, $webpImage);

            // Update attribute option with swatch image path
            DB::table('attribute_options')->where('id', $brandOptionId)->update([
                'swatch_value' => $path,
            ]);

            $this->info("    - Downloaded and assigned brand swatch image");
        } catch (Exception $e) {
            $this->warn("    - Failed to download brand image: {$e->getMessage()}");
        }
    }

    protected function downloadAndAssignCategoryImage(int $categoryId, string $imageUrl): void
    {
        try {
            $imageContent = $this->downloadImage($imageUrl);
            if (!$imageContent) {
                return;
            }

            // Convert to WebP
            $manager = new ImageManager(['driver' => 'gd']);
            $image = $manager->make($imageContent);
            $webpImage = $image->encode('webp', 90);

            // Save to storage
            $filename = 'category-' . $categoryId . '-' . time() . '.webp';
            $path = 'category/' . $filename;
            Storage::put($path, $webpImage);

            // Update category with logo path
            DB::table('categories')->where('id', $categoryId)->update([
                'logo_path' => $path,
                'updated_at' => now(),
            ]);

            $this->info("    - Downloaded and assigned category image");
        } catch (Exception $e) {
            $this->warn("    - Failed to download category image: {$e->getMessage()}");
        }
    }

    protected function downloadImage(string $url): ?string
    {
        try {
            $response = Http::timeout(30)->get($url);

            if ($response->successful()) {
                return $response->body();
            }

            return null;
        } catch (Exception $e) {
            $this->warn("    - Failed to download image from {$url}: {$e->getMessage()}");
            return null;
        }
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
        $superAttributes = [];
        $attributeData = [];

        foreach ($options as $option) {
            $attributeName = $option['name'];
            $attributeCode = Str::slug($attributeName, '_');

            // Get or create attribute
            $attribute = $this->getOrCreateAttribute($attributeCode, $attributeName, $option['values']);

            $superAttributes[] = $attribute->id;

            $attributeData[$attributeCode] = [
                'attribute' => $attribute,
                'values' => $option['values'],
                'options' => $this->getAttributeOptions($attribute->id),
            ];
        }

        return [
            'super_attributes' => $superAttributes,
            'attribute_data' => $attributeData,
        ];
    }

    protected function getAttributeOptions(int $attributeId): array
    {
        $cacheKey = 'attr_options_' . $attributeId;

        if (isset($this->attributeOptionCache[$cacheKey])) {
            return $this->attributeOptionCache[$cacheKey];
        }

        $options = DB::table('attribute_options')
            ->where('attribute_id', $attributeId)
            ->get()
            ->keyBy('admin_name')
            ->toArray();

        $this->attributeOptionCache[$cacheKey] = $options;
        return $options;
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

            // Create attribute translations for all locales
            foreach ($this->availableLocales as $locale) {
                DB::table('attribute_translations')->insert([
                    'locale' => $locale,
                    'name' => $name,
                    'attribute_id' => $attributeId,
                ]);
            }

            // Create attribute options
            foreach ($values as $index => $value) {
                $optionId = DB::table('attribute_options')->insertGetId([
                    'admin_name' => $value,
                    'sort_order' => $index,
                    'attribute_id' => $attributeId,
                ]);

                // Create option translations for all locales
                foreach ($this->availableLocales as $locale) {
                    DB::table('attribute_option_translations')->insert([
                        'locale' => $locale,
                        'label' => $value,
                        'attribute_option_id' => $optionId,
                    ]);
                }
            }

            $attribute = DB::table('attributes')->find($attributeId);
            $this->info("Created attribute: {$name} with " . count($values) . " options");
        }

        $this->attributeCache[$code] = $attribute;
        return $attribute;
    }

    protected function createProduct(array $productData, $attributeFamily, string $productType, array $superAttributes = [])
    {
        $firstVariant = $productData['variants'][0] ?? [];
        $sku = $this->generateSku($firstVariant, $productData['title']);

        // Prepare product data for repository with multi-locale support
        $data = [
            'type' => $productType,
            'attribute_family_id' => $attributeFamily->id,
            'sku' => $sku,
            'status' => 1,
            'visible_individually' => 1,
            'guest_checkout' => 1,
            'new' => 1,
            'featured' => 0,
            'price' => (float) ($firstVariant['price'] ?? 0),
            'cost' => null,
            'special_price' => isset($firstVariant['compare_at_price']) && $firstVariant['compare_at_price'] > $firstVariant['price']
                ? (float) $firstVariant['price']
                : null,
            'special_price_from' => null,
            'special_price_to' => null,
            'weight' => (float) ($firstVariant['weight'] ?? 0),
            'channel' => $this->defaultChannel,
            'locale' => $this->defaultLocale,
            'inventories' => [
                $this->defaultInventorySourceId => (int) ($firstVariant['inventory_quantity'] ?? 0),
            ],
        ];

        // Add translations for all available locales
        $urlKey = Str::slug($productData['title']) . '-' . Str::random(4);
        foreach ($this->availableLocales as $locale) {
            $data[$locale] = [
                'name' => $productData['title'],
                'url_key' => $urlKey,
                'short_description' => $this->stripHtml($productData['body_html'] ?? ''),
                'description' => $productData['body_html'] ?? '',
                'meta_title' => $productData['title'],
                'meta_keywords' => $productData['tags'] ?? '',
                'meta_description' => substr($this->stripHtml($productData['body_html'] ?? ''), 0, 160),
            ];
        }

        // Add super attributes for configurable products
        if ($productType === 'configurable' && !empty($superAttributes)) {
            $data['super_attributes'] = $superAttributes;
        }

        // Create product using repository
        $product = $this->productRepository->create($data);

        $this->info("  - Created {$productType} product: {$productData['title']} (SKU: {$sku})");

        return $product;
    }

    protected function updateSimpleProductData($product, array $variant): void
    {
        // Update inventory if needed
        if (isset($variant['inventory_quantity'])) {
            DB::table('product_inventories')
                ->where('product_id', $product->id)
                ->update([
                    'qty' => (int) $variant['inventory_quantity'],
                ]);
        }
    }

    protected function assignBrandToProduct($product, $brand): void
    {
        if (!$brand) {
            return;
        }

        // Get brand attribute
        $brandAttribute = DB::table('attributes')->where('code', 'brand')->first();

        if (!$brandAttribute) {
            return;
        }

        // Assign brand as product attribute value
        DB::table('product_attribute_values')->updateOrInsert(
            [
                'product_id' => $product->id,
                'attribute_id' => $brandAttribute->id,
                'channel' => $this->defaultChannel,
                'locale' => $this->defaultLocale,
            ],
            [
                'text_value' => $brand->id, // Store the attribute option ID
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->info("  - Assigned brand: {$brand->admin_name}");
    }

    protected function processProductImages($product, array $images): void
    {
        $imageCount = 0;

        foreach ($images as $index => $image) {
            try {
                $imagePath = $this->downloadProductImage($image['src'], $product->id);

                if ($imagePath) {
                    DB::table('product_images')->insert([
                        'product_id' => $product->id,
                        'type' => 'image',
                        'path' => $imagePath,
                        'position' => $index,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Set first image as main product image in product_flat
                    if ($index === 0) {
                        DB::table('product_flat')
                            ->where('product_id', $product->id)
                            ->update([
                                'product_image' => $imagePath,
                                'updated_at' => now(),
                            ]);
                    }

                    $imageCount++;
                }
            } catch (Exception $e) {
                $this->warn("    - Failed to download image: {$e->getMessage()}");
            }
        }

        if ($imageCount > 0) {
            $this->info("  - Downloaded {$imageCount} product images");
        }
    }

    protected function processVariants($product, array $variants, array $images, array $attributeData): void
    {
        $this->info("  - Processing " . count($variants) . " variants");
        $variantCount = 0;

        foreach ($variants as $index => $variant) {
            try {
                $variantSku = !empty($variant['sku']) ? $variant['sku'] : $product->sku . '-V' . ($index + 1);

                // Check if variant already exists
                if (isset($this->skuCache[$variantSku]) || $this->productRepository->findOneByField('sku', $variantSku)) {
                    $this->warn("    - Variant SKU {$variantSku} already exists. Skipping...");
                    continue;
                }

                $this->skuCache[$variantSku] = true;

                // Prepare variant data
                $variantData = [
                    'type' => 'simple',
                    'attribute_family_id' => $product->attribute_family_id,
                    'sku' => $variantSku,
                    'parent_id' => $product->id,
                    $this->defaultLocale => [
                        'name' => $variant['title'] ?? $product->name . ' - Variant ' . ($index + 1),
                        'url_key' => Str::slug($variant['title'] ?? $product->name) . '-' . Str::random(4),
                        'short_description' => $product->short_description ?? '',
                        'description' => $product->description ?? '',
                    ],
                    'status' => 1,
                    'visible_individually' => 0,
                    'price' => (float) ($variant['price'] ?? 0),
                    'cost' => null,
                    'special_price' => isset($variant['compare_at_price']) && $variant['compare_at_price'] > $variant['price']
                        ? (float) $variant['price']
                        : null,
                    'weight' => (float) ($variant['weight'] ?? 0),
                    'channel' => $this->defaultChannel,
                    'locale' => $this->defaultLocale,
                    'inventories' => [
                        $this->defaultInventorySourceId => (int) ($variant['inventory_quantity'] ?? 0),
                    ],
                ];

                // Create variant product
                $variantProduct = $this->productRepository->create($variantData);

                // Assign variant attribute values
                $this->assignVariantAttributes($variantProduct, $variant, $attributeData);

                // Assign variant image if available
                if (!empty($variant['image_id'])) {
                    $variantImage = collect($images)->firstWhere('id', $variant['image_id']);
                    if ($variantImage && !empty($variantImage['src'])) {
                        $imagePath = $this->downloadProductImage($variantImage['src'], $variantProduct->id);
                        if ($imagePath) {
                            DB::table('product_images')->insert([
                                'product_id' => $variantProduct->id,
                                'type' => 'image',
                                'path' => $imagePath,
                                'position' => 0,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);

                            DB::table('product_flat')
                                ->where('product_id', $variantProduct->id)
                                ->update([
                                    'product_image' => $imagePath,
                                    'updated_at' => now(),
                                ]);
                        }
                    }
                }

                $variantCount++;
            } catch (Exception $e) {
                $this->error("    - Failed to create variant: {$e->getMessage()}");
            }
        }

        $this->info("  - Created {$variantCount} variants successfully");
    }

    protected function assignVariantAttributes($variantProduct, array $variant, array $attributeData): void
    {
        // Assign variant option values (option1, option2, option3)
        for ($i = 1; $i <= 3; $i++) {
            $optionKey = "option{$i}";
            if (!empty($variant[$optionKey])) {
                $optionValue = $variant[$optionKey];

                // Find the corresponding attribute
                foreach ($attributeData as $attrCode => $attrInfo) {
                    if (isset($attrInfo['values']) && in_array($optionValue, $attrInfo['values'])) {
                        // Find the option ID
                        $option = collect($attrInfo['options'])->firstWhere('admin_name', $optionValue);

                        if ($option) {
                            // Save attribute value
                            DB::table('product_attribute_values')->insert([
                                'product_id' => $variantProduct->id,
                                'attribute_id' => $attrInfo['attribute']->id,
                                'locale' => $this->defaultLocale,
                                'channel' => $this->defaultChannel,
                                'text_value' => $option->id,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                        break;
                    }
                }
            }
        }
    }

    protected function processTags($tags): array
    {
        $tagArray = is_array($tags) ? $tags : array_map('trim', explode(',', $tags));
        $categories = [];

        foreach ($tagArray as $tagName) {
            $tagName = trim($tagName);
            if (empty($tagName)) continue;

            $category = $this->getOrCreateCategory($tagName);
            $categories[] = $category;
        }

        return $categories;
    }

    protected function downloadProductImage(string $url, int $productId): ?string
    {
        try {
            $imageContent = $this->downloadImage($url);
            if (!$imageContent) {
                return null;
            }

            // Convert to WebP
            $manager = new ImageManager(['driver' => 'gd']);
            $image = $manager->make($imageContent);
            $webpImage = $image->encode('webp', 90);

            // Save to storage
            $fileName = 'product-' . $productId . '-' . Str::random(10) . '.webp';
            $path = "product/{$productId}/{$fileName}";
            Storage::put($path, $webpImage);

            return $path;
        } catch (Exception $e) {
            $this->warn("    - Product image download failed: {$e->getMessage()}");
            return null;
        }
    }

    protected function stripHtml(?string $html): string
    {
        if (!$html) return '';
        return strip_tags(html_entity_decode($html));
    }
}
