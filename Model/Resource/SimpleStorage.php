<?php

namespace BigBridge\ProductImport\Model\Resource;

use BigBridge\ProductImport\Model\Db\Magento2DbConnection;
use BigBridge\ProductImport\Model\Data\SimpleProduct;
use BigBridge\ProductImport\Model\ImportConfig;
use BigBridge\ProductImport\Model\Resource\Reference\UrlKeyGenerator;
use Exception;
use PDOException;

/**
 * @author Patrick van Bergen
 */
class SimpleStorage
{
    /** @var  Magento2DbConnection */
    protected $db;

    /** @var  MetaData */
    protected $metaData;

    /** @var Validator */
    protected $validator;

    /** @var  ReferenceResolver */
    protected $referenceResolver;

    /** @var UrlKeyGenerator */
    protected $urlKeyGenerator;

    /** @var UrlRewriteStorage */
    protected $urlRewriteStorage;

    public function __construct(
        Magento2DbConnection $db,
        MetaData $metaData,
        Validator $validator,
        ReferenceResolver $referenceResolver,
        UrlKeyGenerator $urlKeyGenerator,
        UrlRewriteStorage $urlRewriteStorage)
    {
        $this->db = $db;
        $this->metaData = $metaData;
        $this->validator = $validator;
        $this->referenceResolver = $referenceResolver;
        $this->urlKeyGenerator = $urlKeyGenerator;
        $this->urlRewriteStorage = $urlRewriteStorage;
    }

    /**
     * @param SimpleProduct[] $simpleProducts
     * @param ImportConfig $config
     */
    public function storeSimpleProducts(array $simpleProducts, ImportConfig $config)
    {
        // collect skus
        $skus = array_column($simpleProducts, 'sku');

        // collect inserts and updates
        $sku2id = $this->getExistingSkus($skus);

        $insertProducts = $updateProducts = [];

        // separate new products from existing products and assign id
        foreach ($simpleProducts as $product) {
            if (array_key_exists($product->sku, $sku2id)) {
                $product->id = $sku2id[$product->sku];
                $updateProducts[] = $product;
            } else {
                $insertProducts[] = $product;
            }
        }

        // create url keys based on name and id
        // changes $product->ok and $product->errors
        $this->urlKeyGenerator->createUrlKeysForNewProducts($insertProducts, $config->urlKeyScheme, $config->duplicateUrlKeyStrategy);

        $this->urlKeyGenerator->createUrlKeysForExistingProducts($updateProducts, $config->urlKeyScheme, $config->duplicateUrlKeyStrategy);

        $validProducts = [];

        foreach ($simpleProducts as $product) {

            // replace Reference(s) with ids, changes $product->ok and $product->errors
            $this->referenceResolver->resolveIds($product, $config);

            // checks all attributes, changes $product->ok and $product->errors
            $this->validator->validate($product);

            if (!$product->ok) {
                continue;
            }

            // collect valid products
            $validProducts[] = $product;
        }

        // in a "dry run" no actual imports to the database are done
        if (!$config->dryRun) {

            $this->saveProducts($validProducts);
        }

        // call user defined functions to let them process the results
        foreach ($config->resultCallbacks as $callback) {
            foreach ($simpleProducts as $product) {
                call_user_func($callback, $product);
            }
        }
    }

    protected function saveProducts(array $validProducts)
    {
        $validUpdateProducts = $validInsertProducts = [];
        $productsByAttribute = [];

        foreach ($validProducts as $product) {

            // collect valid new and existing products
            if ($product->id !== null) {
                $validUpdateProducts[] = $product;
            } else {
                $validInsertProducts[] = $product;
            }

            // collect products by attribute
            foreach ($product as $key => $value) {
                if ($value !== null) {
                    $productsByAttribute[$key][] = $product;
                }
            }
        }

        $this->db->execute("START TRANSACTION");

            $existingValues = $this->getExistingProductValues($validUpdateProducts);

//        try {
            $this->insertMainTable($validInsertProducts);
            $this->updateMainTable($validUpdateProducts);

            foreach ($this->metaData->productEavAttributeInfo as $eavAttribute => $info) {
                if (array_key_exists($eavAttribute, $productsByAttribute)) {
                    $this->insertEavAttribute($productsByAttribute[$eavAttribute], $eavAttribute);
                }
            }

            if (array_key_exists('category_ids', $productsByAttribute)) {
                $this->insertCategoryIds($productsByAttribute['category_ids']);
            }

            // url_rewrite (must be done after url_key and category_id)
            $this->urlRewriteStorage->insertRewrites($validInsertProducts);
            $this->urlRewriteStorage->updateRewrites($validUpdateProducts, $existingValues);

            $this->db->execute("COMMIT");

//        } catch (PDOException $e) {
//
//            try {
//                $this->db->execute("ROLLBACK");
//            } catch (Exception $f) {
//            }
//
//            foreach ($validProducts as $product) {
//                $product->errors[] = $e->getMessage();
//                $product->ok = false;
//            }
//
//        } catch (Exception $e) {
//
//            try {
//                $this->db->execute("ROLLBACK");
//            } catch (Exception $f) {
//            }
//
//            foreach ($validProducts as $product) {
//                $message = $e->getMessage();
//                $product->errors[] = $message;
//                $product->ok = false;
//            }
//
//        }
    }

    protected function getExistingProductValues(array $products)
    {
        if (empty($products)) {
            return [];
        }

        $productIds = array_column($products, 'id');

        $attributeId = $this->metaData->productEavAttributeInfo['url_key']->attributeId;

        $existingData = $this->db->fetchAllAssoc("
            SELECT URL_KEY.`entity_id` as product_id, URL_KEY.`value` AS url_key, GROUP_CONCAT(PG.`category_id` SEPARATOR ',') as category_ids, URL_KEY.`store_id`
            FROM `{$this->metaData->productEntityTable}_varchar` URL_KEY
            LEFT JOIN `{$this->metaData->urlRewriteProductCategoryTable}` PG ON PG.`product_id` = URL_KEY.`entity_id`
            WHERE 
                URL_KEY.`attribute_id` = $attributeId AND
                URL_KEY.`entity_id` IN (" . implode(', ', $productIds) . ")
            GROUP BY URL_KEY.`entity_id`, URL_KEY.`store_id` 
        ");

        $data = [];
        foreach ($existingData as $existingDatum) {
            $productId = $existingDatum['product_id'];
            $storeId = $existingDatum['store_id'];
            $categoryIds = is_null($existingDatum['category_ids']) ? [] : explode(',', $existingDatum['category_ids']);
            $urlKey = $existingDatum['url_key'];
            $data[$storeId][$productId] = ['url_key' => $urlKey, 'category_ids' => $categoryIds];
        }

        return $data;
    }

    /**
     * Returns an sku => id map for all existing skus.
     *
     * @param array $skus
     * @return array
     */
    protected function getExistingSkus(array $skus)
    {
        if (count($skus) == 0) {
            return [];
        }

        $serialized = $this->db->quoteSet($skus);
        return $this->db->fetchMap("SELECT `sku`, `entity_id` FROM {$this->metaData->productEntityTable} WHERE `sku` in ({$serialized})");
    }

    /**
     * @param SimpleProduct[] $products
     */
    protected function insertMainTable(array $products)
    {
#todo has_options, required_options

        $values = [];
        $skus = [];
        foreach ($products as $product) {

            // index with sku to prevent creation of multiple products with the same sku
            // (this happens when products with different store views are inserted at once)
            if (array_key_exists($product->sku, $skus)) {
                continue;
            }
            $skus[$product->sku] = $product->sku;

            $sku = $this->db->quote($product->sku);
            $values[] = "({$product->attribute_set_id}, 'simple', {$sku}, 0, 0)";
        }

        if (count($values) > 0) {

            $sql = "INSERT INTO `{$this->metaData->productEntityTable}` (`attribute_set_id`, `type_id`, `sku`, `has_options`, `required_options`) VALUES " .
                implode(',', $values);

            $this->db->execute($sql);

            // store the new ids with the products
            $serialized = $this->db->quoteSet($skus);
            $sql = "SELECT `sku`, `entity_id` FROM `{$this->metaData->productEntityTable}` WHERE `sku` IN ({$serialized})";
            $sku2id = $this->db->fetchMap($sql);

            foreach ($products as $product) {
                $product->id = $sku2id[$product->sku];
            }
        }
    }

    /**
     * @param SimpleProduct[] $products
     */
    protected function updateMainTable(array $products)
    {

#todo has_options, required_options

        $values = [];
        $skus = [];
        foreach ($products as $product) {
            $skus[] = $product->sku;
            $sku = $this->db->quote($product->sku);
            $values[] = "({$product->id},{$product->attribute_set_id}, 'simple', {$sku}, 0, 0)";
        }

        if (count($values) > 0) {

            $sql = "INSERT INTO `{$this->metaData->productEntityTable}`" .
                " (`entity_id`, `attribute_set_id`, `type_id`, `sku`, `has_options`, `required_options`) " .
                " VALUES " . implode(', ', $values) .
                " ON DUPLICATE KEY UPDATE `attribute_set_id`=VALUES(`attribute_set_id`), `has_options`=VALUES(`has_options`), `required_options`=VALUES(`required_options`)";

            $this->db->execute($sql);
        }
    }

    /**
     * @param SimpleProduct[] $products
     * @param string $eavAttribute
     */
    protected function insertEavAttribute(array $products, string $eavAttribute)
    {
        $attributeInfo = $this->metaData->productEavAttributeInfo[$eavAttribute];
        $tableName = $attributeInfo->tableName;
        $attributeId = $attributeInfo->attributeId;

        $values = [];
        foreach ($products as $product) {

            $entityId = $product->id;
            $value = $this->db->quote($product->$eavAttribute);
            $values[] = "({$entityId},{$attributeId},{$product->store_view_id},{$value})";
        }

        $sql = "INSERT INTO `{$tableName}` (`entity_id`, `attribute_id`, `store_id`, `value`)" .
            " VALUES " . implode(', ', $values) .
            " ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

        $this->db->execute($sql);
    }

    protected function insertCategoryIds(array $products)
    {
        $values = [];

        foreach ($products as $product) {
            foreach ($product->category_ids as $categoryId) {
                $values[] = "({$categoryId}, {$product->id})";
            }
        }

        if (count($values) > 0) {

            // IGNORE serves two purposes:
            // 1. do not fail if the product-category link already existed
            // 2. do not fail if the category does not exist

            $sql = "
                INSERT IGNORE INTO `{$this->metaData->categoryProductTable}` (`category_id`, `product_id`) 
                VALUES " . implode(', ', $values);

            $this->db->execute($sql);
        }
    }
}