<?php
declare(strict_types=1);

namespace Pinterest\PinterestMagento2Extension\Helper;

use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableProductType;
use SimpleXMLElement;
use Pinterest\PinterestMagento2Extension\Constants\IntegrationErrorId;
use Pinterest\PinterestMagento2Extension\Helper\LocaleList;
use Pinterest\PinterestMagento2Extension\Helper\PinterestHelper;
use Pinterest\PinterestMagento2Extension\Helper\PluginErrorHelper;
use Pinterest\PinterestMagento2Extension\Helper\SavedFile;
use Pinterest\PinterestMagento2Extension\Logger\Logger;

class ProductExporter
{
    /**
     * @var ConfigurableProductType
     */
    protected $configurableProductType;

    /**
     * @var PluginErrorHelper
     */
    protected $pluginErrorHelper;

    /**
     * @var CollectionFactory
     */
    protected $collectionFactory;

    /**
     * @var ProductRepositoryInterface
     */
    protected $productLoader;

    /**
     * @var StockRegistryInterface
     */
    protected $stockRegistryInterface;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var CategoryRepositoryInterface
     */
    protected $categoryRepository;

    /**
     * @var SavedFile
     */
    private $savedFile;

    /**
     * @var array
     */
    private $productsData;

    /**
     * @var LocaleList
     */
    private $localelist;

    /**
     * @var int
     */
    private $lastProcessTime;

    /**
     * @var PinterestHelper
     */
    private $pinterestHelper;

    /**
     * @var Logger
     */
    private $appLogger;
    /**
     * @var string
     */
    private $absolute_path;
    /**
     * @param CategoryRepositoryInterface $categoryRepository
     * @param SavedFile $savedFile
     * @param PinterestHelper $pinterestHelper
     * @param Logger $appLogger
     * @param LocaleList $localelist
     * @param ProductRepositoryInterface $productRepository
     * @param CollectionFactory $collectionFactory
     * @param StockRegistryInterface $stockRegistryInterface
     * @param PluginErrorHelper $pluginErrorHelper
     * @param StoreManagerInterface $storeManager
     * @param ConfigurableProductType $configurableProductType
     */
    public function __construct(
        CategoryRepositoryInterface $categoryRepository,
        SavedFile $savedFile,
        PinterestHelper $pinterestHelper,
        Logger $appLogger,
        LocaleList $localelist,
        ProductRepositoryInterface $productRepository,
        CollectionFactory $collectionFactory,
        StockRegistryInterface $stockRegistryInterface,
        PluginErrorHelper $pluginErrorHelper,
        StoreManagerInterface $storeManager,
        ConfigurableProductType $configurableProductType
    ) {
        $this->categoryRepository = $categoryRepository;
        $this->savedFile = $savedFile;
        $this->pinterestHelper = $pinterestHelper;
        $this->appLogger = $appLogger;
        $this->localelist = $localelist;
        $this->productLoader = $productRepository;
        $this->collectionFactory = $collectionFactory;
        $this->stockRegistryInterface = $stockRegistryInterface;
        $this->pluginErrorHelper = $pluginErrorHelper;
        $this->storeManager = $storeManager;
        $this->configurableProductType = $configurableProductType;
        $this->lastProcessTime = 0;
        $this->productsData = null;
    }

    /**
     *
     * @return void
     * @throws NoSuchEntityException
     */
    public function processExport()
    {
        $time_start = microtime(true);
        $this->pinterestHelper->logInfo("processExport Started at microseconds =".$time_start);

        $data = [];
        $success = 0;
        $stores = $this->storeManager->getStores();
        $country_locales = $this->localelist->getListLocaleForAllStores();
        foreach ($stores as $store) {
            $storeId = $store->getId();
            $baseUrl = $this->pinterestHelper->getMediaBaseUrlByStoreId($storeId) ?? "";
            $country_locale = $country_locales[$storeId];
            $key = "{$country_locale}\n{$baseUrl}";
            $content = $this->productsData ?? $this->prepareData($storeId);
            $data[$key] = array_merge($data[$key] ?? [], $content);
            $this->appLogger->info("Store{$store->getId()} processed,locale={$country_locale}");
        }

        $this->pinterestHelper->logInfo("Processed all stores. Advanced = ".(microtime(true) - $time_start));
        
        if (!$this->savedFile->isEnabled()) {
            return true;
        }

        uasort($data, function ($v1, $v2) {
            return count($v2) - count($v1);
        });

        $this->pinterestHelper->logInfo("Sorted data. Advanced = ".(microtime(true) - $time_start));
        $this->pinterestHelper->logInfo("Deleting all catalogs before creating XML from: ".SavedFile::DIRECTORY_NAME_PATH);
        $this->savedFile->deleteCatalogs();
        $this->pinterestHelper->logInfo("Deleted data. Advanced = ".(microtime(true) - $time_start));

        foreach ($data as $key => $content) {
            $success += $this->saveXml($key, $content) ? 1 : 0;
        }
        $this->pinterestHelper->logInfo("Wrote data to file. Advanced = ".(microtime(true) - $time_start));
        return $success;
    }

    /**
     *
     * @return string
     */
    public function getOutputUrls()
    {
        $stores = $this->storeManager->getStores();
        $country_locales = $this->localelist->getListLocaleForAllStores();
        $urls = [];
        $keys = [];
        foreach ($stores as $store) {
            $storeId = $store->getId();
            $baseUrl = $this->pinterestHelper->getMediaBaseUrlByStoreId($storeId) ?? "";
            $locale = explode("\n", $country_locales[$storeId])[1];
            $key = $baseUrl.$locale;
            if (! in_array($key, $keys)) {
                $urls[] = $this->savedFile->getExportUrl($baseUrl, $locale);
                $keys[] = $key;
            }
        }
        return $urls;
    }

    /**
     * Return compiled array of simple and configurable product data for feed xml
     *
     * @param string $storeId
     *
     * @return array
     * @throws NoSuchEntityException
     */
    private function prepareData($storeId)
    {
        $simpleProducts = $this->prepareSimpleProductData($storeId);
        $variableProducts = $this->prepareConfigurableProductData($storeId, count($simpleProducts));
        return array_merge($simpleProducts, $variableProducts);
    }

    /**
     * Return simple product data for feed xml
     *
     * @param string $storeId
     *
     * @return array
     * @throws NoSuchEntityException
     */
    private function prepareSimpleProductData($storeId)
    {
        $content = [];
        $counter = 0;
        $collection = $this->collectionFactory->create();

        // use type_id to filter out variants items here. variants will be through a different function.
        $collection->setStoreId($storeId)
                   ->addAttributeToFilter('status', Status::STATUS_ENABLED)
                   ->addAttributeToFilter('type_id', ProductType::TYPE_SIMPLE)
                   ->addFieldToFilter([['attribute'=>'visibility',
                                                  'neq'=>Visibility::VISIBILITY_NOT_VISIBLE]])
                   ->addUrlRewrite()
                   ->addAttributeToSelect('*');
        $products = $collection->getItems();
        foreach ($products as $product) {
            $productValues = [
                "xmlns:g:id" => "v_".$this->getUniqueId($product),
                "item_group_id" => $this->getUniqueId($product),
                "title" => $this->getProductName($product),
                "description" => $this->getProductDescription($product),
                "link" => $this->getProductUrl($product),
                "xmlns:g:image_link" => $this->getProductImageUrl($storeId, $product),
                "xmlns:g:price" => $this->getProductPrice($product),
                "xmlns:g:product_type" => $this->getProductCategories($product),
                "xmlns:g:availability" => $this->getProductAvailability($product),
            ];
            if ($productValues["link"] == null) {
                continue;
            }
            if ($product->getSpecialPrice() != 0 && $product->getSpecialPrice() < $product->getPrice()) {
                $productValues["sale_price"] = $this->getProductSalePrice($product);
            }
            $additionalImages = $this->getAdditionalImages($product);
            if ($additionalImages) {
                $productValues["additional_image_link"] = $additionalImages;
            }

            $content["item" . $counter] = $productValues;
            $counter++;
        }
        return $content;
    }

    /**
     * Return configurable product data for feed xml
     *
     * @param string $storeId
     * @param int $counter
     *
     * @return array
     * @throws NoSuchEntityException
     */
    private function prepareConfigurableProductData($storeId, $counter)
    {
        $content = [];
        $configurableCollection = $this->collectionFactory->create();

        // Get configurable products
        $configurableCollection->setStoreId($storeId)
                            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
                            ->addAttributeToFilter('type_id', 'configurable')
                            ->addFieldToFilter([['attribute'=>'visibility',
                            'neq'=>Visibility::VISIBILITY_NOT_VISIBLE]])
                            ->addUrlRewrite()
                            ->addAttributeToSelect('*');
        $configurableProducts = $configurableCollection->getItems();
        
        foreach ($configurableProducts as $configurableProduct) {
            $productTypeInstance = $configurableProduct->getTypeInstance();
            $productAttributeOptions = $productTypeInstance->getConfigurableAttributesAsArray($configurableProduct);
            
            $variantNames = [];
            foreach ($productAttributeOptions as $option) {
                array_push($variantNames, $option['frontend_label']);
            }

            $itemGroupId = $this->getUniqueId($configurableProduct);
            $itemLink = $this->getProductUrl($configurableProduct);

            // Get variable products from configurable product
            $variableProductsForCurrentConfigurable =
                $this->configurableProductType->getChildrenIds($configurableProduct->getId());
            foreach ($variableProductsForCurrentConfigurable[0] as $key => $productId) {
                $product = $this->productLoader->getById($productId);

                // Get variant values from simple product
                $variantValues = [];
                foreach ($variantNames as $variantName) {
                    array_push($variantValues, $this->getConfigurableProductValue($product, $variantName));
                }

                $productValues = [
                    "xmlns:g:id" => $this->getUniqueId($product),
                    "item_group_id" => $itemGroupId,
                    "title" => $this->getProductName($product),
                    "description" => $this->getProductDescription($product),
                    "link" => $itemLink,
                    "xmlns:g:image_link" => $this->getProductImageUrl($storeId, $product),
                    "xmlns:g:price" => $this->getProductPrice($product),
                    "xmlns:g:product_type" => $this->getProductCategories($product),
                    "xmlns:g:availability" => $this->getProductAvailability($product),
                    "variant_names" => implode(",", $variantNames),
                    "variant_values" => implode(",", $variantValues),
                ];
                if ($this->getConfigurableProductValue($product, "color")) {
                    $productValues["color"] = $this->getConfigurableProductValue($product, "color");
                }
                if ($this->getConfigurableProductValue($product, "size")) {
                    $productValues["size"] = $this->getConfigurableProductValue($product, "size");
                }
                if ($productValues["link"] == null) {
                    continue;
                }
                if ($product->getSpecialPrice() != 0 && $product->getSpecialPrice() < $product->getPrice()) {
                    $productValues["sale_price"] = $this->getProductSalePrice($product);
                }
                $additionalImages = $this->getAdditionalImages($product);
                if ($additionalImages) {
                    $productValues["additional_image_link"] = $additionalImages;
                }

                $content["item" . $counter] = $productValues;
                $counter++;
            }
        }
        return $content;
    }

    /**
     * Return categories for a given product
     *
     * @param \Magento\Catalog\Model\Product $product
     *
     * @return string
     * @throws NoSuchEntityException
     */
    private function getProductCategories($product)
    {
        $categoryIds = $product->getCategoryIds();
        $categoryPath = "";
        $longest_chain = [];
        foreach ($categoryIds as $categoryId) {
            $category = $this->categoryRepository->get($categoryId);
            $chain = [];
            foreach ($category->getParentIds() as $parentId) {
                if ($parentId == Category::TREE_ROOT_ID) {
                    continue;
                }
                $chain []= $this->categoryRepository->get($parentId)->getName();
            }
            $chain []= $category->getName();
            if (count($chain) > count($longest_chain)) {
                $longest_chain = $chain;
            }
        }

        return implode(" > ", $longest_chain);
    }

    /**
     *
     * @return void
     */
    private function saveXml($key, $content)
    {
        if (is_null($key)) {
            return false;
        }
        $pair = explode("\n", $key);
        $country = $pair[0];
        $locale = $pair[1];
        $baseUrl = $pair[2];
        $this->pluginErrorHelper->clearError("errors/catalog_export/{$locale}");

        $this->absolute_path = $this->savedFile->getFileSystemPath($baseUrl, $locale, true);
        $this->appLogger->info("Country={$country},Locale={$locale},XML size=" . count($content));
        $this->appLogger->info("SaveTo=" . $this->absolute_path);
        $this->appLogger->info("URL=" . $this->savedFile->getExportUrl($baseUrl, $locale));
        $xml = new SimpleXMLElement(
            '<rss xmlns:g="http://base.google.com/ns/1.0" />'
        );
        $xml->addAttribute('version', '2.0');
        $xml->addChild('channel');
        $this->arrayToXml($content, $xml);
        $success = $xml->asXML($this->absolute_path);
        if (!$success) {
            $this->appLogger->error("export failed with " . $this->absolute_path);
            $this->pluginErrorHelper->logAndSaveError(
                "errors/catalog_export/{$locale}",
                [],
                "save to {$this->absolute_path} failed",
                IntegrationErrorId::ERROR_CATALOG_EXPORT_SAVE
            );
        }
        $this->lastProcessTime = time();
        return $success;
    }

    /**
     *
     * @param $data
     * @param $xml
     *
     * @return void
     */
    private function arrayToXml($data, &$xml)
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $subnode = $xml->channel->addChild("item");
                $this->arrayToXml($value, $subnode);
            } else {
                $xml->addChild("$key", htmlspecialchars("$value"));
            }
        }
    }

    /**
     * Return unique id for product
     *
     * @param \Magento\Catalog\Model\Product $product
     *
     * @return string
     */
    private function getUniqueId($product)
    {
        $productId = $product->getId();
        return $productId."_".$product->getSku();
    }

    /**
     * Return list of all additional images associated with a product
     *
     * @param \Magento\Catalog\Model\Product $product
     *
     * @return string
     */
    public function getAdditionalImages($product)
    {
        $allImages = [];
        $productId = $product->getId();
        $loadedProduct = $this->productLoader->getById($productId);
        $images = $loadedProduct->getMediaGalleryImages();
    
        foreach ($images as $image) {
            // Only add image to array if less than allowed number
            if (isset($image['url']) && $image['url'] !== '') {
                if (count($allImages) > 10) {
                    break;
                }
                array_push($allImages, $image->getUrl());
            }
        }

        // Remove first image link
        if (count($allImages) > 0) {
            array_shift($allImages);
        }
        return implode(",", $allImages);
    }

    /**
     *
     * @param \Magento\Catalog\Model\Product $product
     *
     * @return mixed
     */
    private function getProductName($product)
    {
        return $product->getName();
    }

    /**
     *
     * @param \Magento\Catalog\Model\Product $product
     *
     * @return mixed
     */
    private function getProductDescription($product)
    {
        return $product->getDescription();
    }

    /**
     *
     * @param \Magento\Catalog\Model\Product $product
     *
     * @return mixed
     */
    private function getProductUrl($product)
    {
        $websites = $product->getWebsiteIds();
        if (!$websites || count($websites) == 0) {
            return null;
        }
        return $product->getProductUrl();
    }

    /**
     *
     * @param string $storeId
     * @param \Magento\Catalog\Model\Product $product
     *
     * @return string
     * @throws NoSuchEntityException
     */
    private function getProductImageUrl($storeId, $product)
    {
        return $this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_MEDIA)
            . 'catalog/product' . $product->getImage();
    }

    /**
     *
     * @param \Magento\Catalog\Model\Product $product
     *
     * @return string
     * @throws NoSuchEntityException
     */
    private function getProductPrice($product)
    {
        return $this->storeManager->getStore()->getBaseCurrencyCode() . number_format(
            (float)$product->getPrice(),
            2,
            '.',
            ''
        );
    }

    /**
     *
     * @param \Magento\Catalog\Model\Product $product
     *
     * @return string
     * @throws NoSuchEntityException
     */
    private function getProductSalePrice($product)
    {
        return $this->storeManager->getStore()->getBaseCurrencyCode() . number_format(
            (float)$product->getSpecialPrice(),
            2,
            '.',
            ''
        );
    }

    /**
     *
     * @param \Magento\Catalog\Model\Product $product
     *
     * @return string
     */
    private function getProductAvailability($product)
    {
        if ($this->stockRegistryInterface->getStockItem($product->getId())->getIsInStock()) {
            return "in stock";
        } else {
            return "out of stock";
        }
    }

    /**
     * Get product values for configurable product
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param string $variantName
     *
     * @return string
     */
    private function getConfigurableProductValue($product, $variantName)
    {
        $product->getResource()->load($product, $product->getId(), [strtolower($variantName)]);

        $attribute = $product->getResource()->getAttribute(strtolower($variantName));
        if (!$attribute) {
            return null;
        }

        $frontend = $attribute->getFrontend();
        if (!$frontend) {
            return null;
        }

        return $frontend->getValue($product);
    }
}
