<?php

namespace App\Repositories;

use Illuminate\Support\Arr;
use App\Product;
use App\ProductHistory;
use App\MultiBuyHistory;
use App\StyleDictionary;
use App\Style;
use Cache;
use Exception;
use Yish\Generators\Foundation\Repository\Repository;

use function Functional\each;
use function Functional\map;

class ProductRepository extends Repository
{
    const CACHE_KEY_LIMITED_OFFER = 'product:limited_offer';
    const CACHE_KEY_MULTI_BUY = 'product:multi_buy';
    const CACHE_KEY_SALE = 'product:sale';
    const CACHE_KEY_STOCKOUT = 'product:stockout';
    const CACHE_KEY_NEW = 'product:new';
    const SELECT_COLUMNS_FOR_PRODUCT_LIST = ['id', 'name', 'category_id', 'main_image_url', 'price', 'min_price', 'max_price', 'limit_sales_end_msg', 'multi_buy', 'new', 'sale'];

    protected $product;
    protected $styleDictionary;
    protected $style;

    public function __construct(
        Product $product,
        ProductHistory $productHistory,
        StyleDictionary $styleDictionary,
        Style $style
    ) {
        $this->product = $product;
        $this->productHistory = $productHistory;
        $this->styleDictionary = $styleDictionary;
        $this->style = $style;
    }

    public function saveProductsFromUniqlo($products)
    {
        each($products, function ($product) {
            try {
                $model = Product::firstOrNew(['id' => $product->id]);

                $model->id = $product->id;
                $model->name = $product->name;
                $model->category_id = $product->parentCategoryId;
                $model->categories = json_encode($product->categories);
                $model->ancestors = json_encode($product->ancestors);
                $model->main_image_url = $this->getProductMainImageUrl($product);
                $model->comment = $product->catchCopy;
                $model->price = $product->representativeSKU->salePrice;
                $model->flags = json_encode($product->flags);
                $model->representative_sku_flags = json_encode($product->representativeSKU->flags);
                $model->limit_sales_end_msg = $product->representativeSKU->limitSalesEndMsg;
                $model->new = $product->new;
                $model->sale = $this->getSaleStatus($product);
                $model->sub_images = json_encode($product->subImages);
                $model->colors = json_encode($product->colors);
                $model->review_count = $product->reviewCount;

                $model->save();

                $history = new ProductHistory;
                $history->price = $model->price;
                $model->histories()->save($history);
            } catch (Exception $e) {
                echo 'Caught exception: ', $e->getMessage(), "\n";
            }
        });
    }

    public function getProductMainImageUrl($product)
    {
        $color = $product->representativeSKU->color;
        $id = $product->id;

        return "https://im.uniqlo.com/images/tw/uq/pc/goods/{$id}/item/{$color}_{$id}.jpg";
    }

    public function getSaleStatus($product)
    {
        $flags = $product->representativeSKU->flags;

        foreach ($flags as $flag) {
            if ($flag->name == 'SALE') {
                return true;
            }
        }

        return false;
    }

    public function saveStyleDictionaryFromUniqlo($detail, $imgDir)
    {
        try {
            $model = StyleDictionary::firstOrNew(['id' => $detail->id]);

            $model->id = $detail->id;
            $model->fnm = $detail->fnm;
            $model->image_url = "https://www.uniqlo.com/{$imgDir}{$model->fnm}-xl.jpg";
            $model->detail_url = "https://www.uniqlo.com/tw/stylingbook/detail.html#/detail/{$model->id}";

            $model->save();
        } catch (Exception $e) {
            echo 'Caught exception: ', $e->getMessage(), "\n";
        }

        // TODO: Replace Functional\each and Arr::pluck
        each($detail->list, function ($person) use ($model) {
            try {
                $products = Arr::pluck($person->products, 'pub');
                $model->products()->syncWithoutDetaching($products);
            } catch (Exception $e) {
                echo 'Caught exception: ', $e->getMessage(), "\n";
            }
        });
    }

    public function saveStyleFromUniqloStyleBook($result)
    {
        try {
            $model = $this->style->firstOrNew(['id' => $result->photo->styleId]);
            $firstItem = $result->coordinates[0]->items[0];

            $model->id = $result->photo->styleId;
            $model->dpt_id = $result->photo->dptId;
            $model->image_path = $firstItem->image_path;
            $model->image_url = "https://im.uniqlo.com/style/{$model->image_path}-l.jpg";
            $model->detail_url = "http://www.uniqlo.com/tw/stylingbook/sp/style/{$model->id}";

            $model->save();
        } catch (Exception $e) {
            echo 'Caught exception: ', $e->getMessage(), "\n";
        }

        $items = collect($result->coordinates[0]->items);
        try {
            $products = Arr::pluck($items, 'sku_code');
            $model->products()->syncWithoutDetaching($products);
        } catch (Exception $e) {
            echo 'Caught exception: ', $e->getMessage(), "\n";
        }
    }

    public function getStyleDictionaries(Product $product)
    {
        return $product->styleDictionaries;
    }

    public function getStyles(Product $product)
    {
        return $product->styles;
    }

    /**
     * Set stockout status to the products.
     */
    public function setStockoutProductTags()
    {
        $this->product->whereDoesntHave('histories', function ($query) {
            $query->whereDate('created_at', today()->toDateString());
        })->update(['stockout' => true]);

        $this->product->whereHas('histories', function ($query) {
            $query->whereDate('created_at', today()->toDateString());
        })->update(['stockout' => false]);
    }

    /**
     * Get the stockout products.
     *
     * @return Collection|Product[]
     */
    public function getStockoutProducts()
    {
        if (!Cache::has(self::CACHE_KEY_STOCKOUT)) {
            $this->setStockoutProductsCache();
        }

        return Cache::get(self::CACHE_KEY_STOCKOUT);
    }

    /**
     * Put the stockout products to the cache.
     */
    public function setStockoutProductsCache()
    {
        // TODO: Pagination
        // TODO: Move to ProductHistory repository
        $productIds = ProductHistory::selectRaw('product_id, max(created_at)')
            ->groupBy('product_id')
            ->having('max(created_at)', '<', today())
            ->having('max(created_at)', '>=', today()->subWeek())
            ->orderBy('max(created_at)', 'desc')
            ->pluck('product_id');

        $products = $this->product
            ->select(self::SELECT_COLUMNS_FOR_PRODUCT_LIST)
            ->whereIn('id', $productIds)->get()->keyBy('id');
        $sortedProducts = $productIds->map(function ($id) use ($products) {
            return $products->get($id);
        });

        Cache::forever(self::CACHE_KEY_STOCKOUT, $sortedProducts);
    }

    public function getProductsByIds($productIds)
    {
        return $products = $this->product->whereIn('id', $productIds)->get();
    }

    /**
     * Get limited offer products.
     *
     * @return Collection|Product[] Limited offer products
     */
    public function getLimitedOfferProducts()
    {
        if (!Cache::has(self::CACHE_KEY_LIMITED_OFFER)) {
            $this->setLimitedOfferProductsCache();
        }

        return Cache::get(self::CACHE_KEY_LIMITED_OFFER);
    }

    /**
     * Put limited offer products to the cache.
     */
    public function setLimitedOfferProductsCache()
    {
        $products = $this->product
            ->select(self::SELECT_COLUMNS_FOR_PRODUCT_LIST)
            ->where('limit_sales_end_msg', '!=', '')
            ->where('stockout', false)
            ->orderBy('limit_sales_end_msg')
            ->orderByRaw('price/max_price')
            ->get();

        Cache::forever(self::CACHE_KEY_LIMITED_OFFER, $products);
    }

    /**
     * Get MULTI_BUY products.
     *
     * @return Collection|Product[] MULTI_BUY products
     */
    public function getMultiBuyProducts()
    {
        if (!Cache::has(self::CACHE_KEY_MULTI_BUY)) {
            $this->setMultiBuyProductsCache();
        }

        return Cache::get(self::CACHE_KEY_MULTI_BUY);
    }

    /**
     * Put the MULTI_BUY products to the cache.
     */
    public function setMultiBuyProductsCache()
    {
        $products = $this->product
            ->select(self::SELECT_COLUMNS_FOR_PRODUCT_LIST)
            ->whereNotNull('multi_buy')
            ->where('stockout', false)
            ->orderBy('multi_buy')
            ->orderBy('price')
            ->get();

        Cache::forever(self::CACHE_KEY_MULTI_BUY, $products);
    }

    /**
     * Get sale products.
     *
     * @return Collection|Product[] Sale products
     */
    public function getSaleProducts()
    {
        if (!Cache::has(self::CACHE_KEY_SALE)) {
            $this->setSaleProductsCache();
        }

        return Cache::get(self::CACHE_KEY_SALE);
    }

    /**
     * Put sale products to the cache.
     */
    public function setSaleProductsCache()
    {
        $products = $this->product
            ->select(self::SELECT_COLUMNS_FOR_PRODUCT_LIST)
            ->where('sale', true)
            ->where('stockout', false)
            ->orderByRaw('price/max_price')
            ->get();

        Cache::forever(self::CACHE_KEY_SALE, $products);
    }

    /**
     * Get new products.
     *
     * @return Collection|Product[] New products
     */
    public function getNewProducts()
    {
        if (!Cache::has(self::CACHE_KEY_NEW)) {
            $this->setNewProductsCache();
        }

        return Cache::get(self::CACHE_KEY_NEW);
    }

    /**
     * Put new products to the cache.
     */
    public function setNewProductsCache()
    {
        $products = $this->product
            ->select(self::SELECT_COLUMNS_FOR_PRODUCT_LIST)
            ->where('new', true)
            ->where('stockout', false)
            ->orderByRaw('price/max_price')
            ->orderBy('created_at', 'desc')
            ->get();

        Cache::forever(self::CACHE_KEY_NEW, $products);
    }

    /**
     * Get the min price and the max price from the products table.
     *
     * @param array $prices
     * @return void
     */
    public function setMinPricesAndMaxPrices($prices)
    {
        $prices->each(function ($price) {
            $product = $this->product->find($price->product_id);

            if (!empty($product)) {
                $product->min_price = $price->min_price;
                $product->max_price = $price->max_price;

                $product->save();
            }
        });
    }

    /**
     * Get all of the MULTI_BUY Products.
     *
     * @return array
     */
    public function getMultiBuyProductIds()
    {
        $ids = $this->product->select('id')
            ->where('stockout', false)
            ->where('representative_sku_flags', 'like', '%MULTI_BUY%')
            ->pluck('id');

        return $ids;
    }

    /**
     * Save the MULTI_BUY promo.
     *
     * @param string $id
     * @param string $promo
     * @return void
     */
    public function saveMultiBuyPromo($id, $promo)
    {
        $multiBuy = new MultiBuyHistory;
        $multiBuy->product_id = $id;
        $multiBuy->multi_buy = $promo;
        $multiBuy->save();
    }

    /**
     * Set MULTI_BUY to the products.
     *
     * @return void
     */
    public function setMultiBuyProducts()
    {
        $this->product->whereNotNull('multi_buy')
            ->whereDoesntHave('multiBuys', function ($query) {
                $query->whereDate('created_at', today()->toDateString());
            })->update(['multi_buy' => null]);

        $multiBuyHistories = \DB::table('multi_buy_histories')
            ->select('product_id', \DB::raw('multi_buy as promo'))
            ->whereIn('id', function ($query) {
                $query->selectRaw('MAX(id)')
                    ->from('multi_buy_histories')
                    ->whereDate('created_at', today()->toDateString())
                    ->groupBy('product_id');
            });

        $this->product->joinSub($multiBuyHistories, 'multi_buy_histories', function ($join) {
            $join->on('products.id', '=', 'multi_buy_histories.product_id');
        })->update(['multi_buy' => \DB::raw('promo')]);
    }

    public function getRelatedProducts($product)
    {
        $relatedId = substr($product->id, 0, 6);

        return $this->product
            ->select(self::SELECT_COLUMNS_FOR_PRODUCT_LIST)
            ->where('stockout', false)
            ->where(function ($query) use ($relatedId, $product) {
                $query->where('id', 'like', "{$relatedId}%")
                    ->orWhere('name', $product->name);
            })
            ->where('id', '<>', $product->id)
            ->orderBy('price')
            ->orderBy('id', 'desc')
            ->get();
    }
}
