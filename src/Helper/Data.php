<?php
namespace Lexor\M2MaketplaceImportExport\Helper;

use Magento\Framework\UrlInterface;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollection;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\CollectionFactory as AttributeSetCollection;
use Magento\Framework\Filesystem\Driver\File;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollection;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableProTypeModel;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    private $pathMediaModule = 'lexor/marketplaceimportexport/';
    protected $_request;
    protected $_storeManager;
    protected $_customerSession;
    protected $_filesystem;
    protected $marketplaceHelper;
    protected $_attributeCollection;
    protected $_attributeSetCollection;
    protected $_entity;
    protected $_resource;
    protected $_config;
    protected $_file;
    protected $_fileDriver;
    protected $_fileUploader;
    protected $_csvReader;
    protected $_zip;
    protected $mediaDirectory;
    protected $_saveProduct;
    protected $_categoryCollection;
    protected $_product;
    protected $_formKey;
    protected $_configurableProTypeModel;
    protected $_jsonHelper;

    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Framework\Filesystem $filesystem,
        \Webkul\Marketplace\Helper\Data $marketplaceHelper,
        AttributeCollection $attributeCollectionFactory,
        AttributeSetCollection $attributeSetCollectionFactory,
        \Magento\Eav\Model\Entity $entity,
        \Magento\Framework\App\ResourceConnection $resource,
        \Magento\Eav\Model\Config $config,
        \Magento\Framework\Filesystem\Driver\File $fileDriver,
        \Magento\MediaStorage\Model\File\UploaderFactory $fileUploader,
        \Magento\Framework\File\Csv $csvReader,
        \Lexor\M2MaketplaceImportExport\Model\Zip $zip,
        \Webkul\Marketplace\Controller\Product\SaveProduct $saveProduct,
        CategoryCollection $categoryCollectionFactory,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Framework\Data\Form\FormKey $formKey,
        ConfigurableProTypeModel $configurableProTypeModel,
        \Magento\Framework\Json\Helper\Data $jsonHelper,
        File $file
    ) {
        $this->_request = $context->getRequest();
        $this->_storeManager = $storeManager;
        $this->_customerSession = $customerSession;
        $this->_filesystem = $filesystem;
        $this->marketplaceHelper = $marketplaceHelper;
        $this->_attributeCollection = $attributeCollectionFactory;
        $this->_attributeSetCollection = $attributeSetCollectionFactory;
        $this->_entity = $entity;
        $this->_resource = $resource;
        $this->_config = $config;
        $this->_file = $file;
        $this->_fileDriver = $fileDriver;
        $this->_fileUploader = $fileUploader;
        $this->_csvReader = $csvReader;
        $this->_zip = $zip;
        $this->_saveProduct = $saveProduct;
        $this->_categoryCollection = $categoryCollectionFactory;
        $this->_product = $productFactory;
        $this->_formKey = $formKey;
        $this->_configurableProTypeModel = $configurableProTypeModel;
        $this->_jsonHelper = $jsonHelper;
        $this->mediaDirectory = $this->_storeManager
            ->getStore()
            ->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
        parent::__construct($context);
    }

    /**
     * Get Sample Csv File Urls.
     *
     * @return array
     */
    public function getSampleCsv()
    {
        $result = [];
        $url = $this->mediaDirectory . $this->pathMediaModule . 'samples/';
        $result[] = $url . 'simple.csv';
        $result[] = $url . 'config.csv';
        return $result;
    }

    /**
     * Get Sample XLS File Urls.
     *
     * @return array
     */
    public function getSampleXls()
    {
        $result = [];

        $url = $this->mediaDirectory . $this->pathMediaModule . 'samples/';
        $result[] = $url . 'simple.xls';
        $result[] = $url . 'config.xls';
        return $result;
    }

    /**
     * Check Whether Product Type is Allowed or Not
     *
     * @param string $attribute
     *
     * @return bool
     */
    public function isProductTypeAllowed($type)
    {
        $allowedProductTypes = explode(',', $this->marketplaceHelper->getAllowedProductType());
        if (in_array($type, $allowedProductTypes)) {
            return true;
        }
        return false;
    }

    /**
     * Get Current Customer Id
     *
     * @return int
     */
    public function getCustomerId()
    {
        $customerId = 0;
        if ($this->_customerSession->isLoggedIn()) {
            $customerId = (int) $this->marketplaceHelper->getCustomerId();
        }
        return $customerId;
    }

    /**
     * Get Super Attribute Codes
     *
     * @return array
     */
    public function getSuperAttributes()
    {
        $attributes = [];
        $collection = $this->_attributeCollection
            ->create()
            ->addFieldToFilter('frontend_input', 'select')
            ->addFieldToFilter("is_global", 1)
            ->addFieldToFilter("is_user_defined", 1);
        foreach ($collection as $item) {
            $code = $item->getAttributeCode();
            if ($code != "wk_marketplace_preorder") {
                $attributes[$code] = $code;
            }
        }
        return $attributes;
    }

    /**
     * Get Attribute Info With Attribute Set Id
     *
     * @return collection
     */
    public function getAttributeInfo($group = true)
    {
        $attributeSets = $this->getAttributeSets();
        $attributeSetIds = array_keys($attributeSets);
        $tableName = $this->_resource->getTableName('eav_entity_attribute');
        $attributeIds = [];
        if ($group) {
            $collection = $this->_attributeCollection
                ->create()
                ->addFieldToFilter('main_table.attribute_id', ['in' => $attributeIds]);

            $collection->join(
                ['entity_attribute' => $tableName],
                'entity_attribute.attribute_id = main_table.attribute_id',
                '*'
            );
            $collection->addFieldToFilter('entity_attribute.attribute_set_id', [
                'in' => $attributeSetIds
            ]);

            $collection
                ->getSelect()
                ->reset('columns')
                ->columns('main_table.attribute_code')
                ->columns('entity_attribute.attribute_set_id')
                ->columns('entity_attribute.attribute_id')
                ->group('entity_attribute.attribute_id');
            return $collection;
        } else {
            $allCollections = [];
            foreach ($attributeSetIds as $attributeSetId) {
                $collection = $this->_attributeCollection
                    ->create()
                    ->addFieldToFilter('main_table.attribute_id', ['in' => $attributeIds]);

                $collection->join(
                    ['entity_attribute' => $tableName],
                    'entity_attribute.attribute_id = main_table.attribute_id',
                    '*'
                );
                $collection->addFieldToFilter('entity_attribute.attribute_set_id', [
                    'eq' => $attributeSetId
                ]);

                $collection
                    ->getSelect()
                    ->reset('columns')
                    ->columns('main_table.attribute_code')
                    ->columns('entity_attribute.attribute_set_id')
                    ->columns('entity_attribute.attribute_id');

                $allCollections[] = $collection;
            }
            return $allCollections;
        }
    }

    /**
     * Get Attribute Sets
     *
     * @return array
     */
    public function getAttributeSets()
    {
        $result = [];
        $allowedAttributeSets = $this->marketplaceHelper->getAllowedAttributesetIds();
        $allowedAttributeSetIds = explode(',', $allowedAttributeSets);
        $entityTypeId = $this->_entity->setType('catalog_product')->getTypeId();
        $attributeSetCollection = $this->_attributeSetCollection
            ->create()
            ->addFieldToFilter('attribute_set_id', ['in' => $allowedAttributeSetIds])
            ->setEntityTypeFilter($entityTypeId);
        foreach ($attributeSetCollection as $set) {
            $result[$set->getAttributeSetId()] = $set->getAttributeSetName();
        }
        return $result;
    }

    /**
     * Get Attribute Options
     *
     * @param string $attributeCode
     *
     * @return array
     */
    public function getAttributeOptions($attributeCode)
    {
        $result = [];
        $model = $this->_config;
        $attribute = $model->getAttribute('catalog_product', $attributeCode);
        $options = $attribute->getSource()->getAllOptions(false);
        foreach ($options as $option) {
            $result[$option['value']] = $option['label'];
        }
        return $result;
    }

    /**
     * Upload Csv File
     *
     * @param array $result
     * @param string $extension
     * @param string $csvFile
     *
     * @return array
     */
    public function uploadCsv($uuid, $result, $extension, $csvFile)
    {
        try {
            $csvUploadPath = $this->getBasePath($uuid);
            if ($extension == 'xls') {
                $data = $this->_file->createDirectory($csvUploadPath);
                $sourcePath =
                    $this->_filesystem
                        ->getDirectoryWrite(DirectoryList::MEDIA)
                        ->getAbsolutePath('/xlscoverted') .
                    $csvFile .
                    '.csv';
                $this->_file->copy($sourcePath, $csvUploadPath . '/' . $result['name']);
                $this->_file->deleteFile($sourcePath);
            } else {
                $csvUploader = $this->_fileUploader->create(['fileId' => 'csv_file']);
                $extension = $csvUploader->getFileExtension();
                $csvUploader->setAllowedExtensions(['csv', 'xls']);
                $csvUploader->setAllowRenameFiles(true);
                $csvUploader->setFilesDispersion(false);
                $csvUploader->save($csvUploadPath, $result['name']);
            }
            $result = ['error' => false];
        } catch (\Exception $e) {
            $this->flushData($uuid);
            $msg = 'There is some problem in uploading csv file.' . $e->getMessage();
            $result = ['error' => true, 'msg' => $msg];
        }
        return $result;
    }

    /**
     * Get Base Path
     *
     * @param int $uuid
     *
     * @return string
     */
    public function getBasePath($uuid)
    {
        $mediaPath = $this->getMediaPath();
        $basePath = $mediaPath . $this->pathMediaModule . 'dataSellerUpload/' . $uuid . "/";
        return $basePath;
    }

    /**
     * Flush Unwanted Data
     *
     * @param int $uuid
     */
    public function flushData($uuid)
    {
        $path = $this->getBasePath($uuid);
        $this->flushFilesCache($path, true);
        $cacheImages = $this->getMediaPath() . 'tmp/catalog/product/' . $uuid . '/';
        $this->flushFilesCache($cacheImages, true);
    }

    /**
     * Delte Extra Images and Folder
     *
     * @param string $path
     * @param bool $removeParent [optional]
     */
    public function flushFilesCache($path, $removeParent = false)
    {
        $entries = $this->_fileDriver->readDirectory($path);
        foreach ($entries as $entry) {
            if ($this->_fileDriver->isDirectory($entry)) {
                $this->removeDir($entry);
            }
        }
        if ($removeParent) {
            $this->removeDir($path);
        }
    }

    /**
     * Remove Folder and Its Content
     *
     * @param string $dir
     */
    public function removeDir($dir)
    {
        if ($this->_fileDriver->isDirectory($dir)) {
            $entries = $this->_fileDriver->readDirectory($dir);
            foreach ($entries as $entry) {
                if ($this->_fileDriver->isFile($entry)) {
                    $this->_fileDriver->deleteFile($entry);
                } else {
                    $this->removeDir($entry);
                }
            }
            $this->_fileDriver->deleteDirectory($dir);
        }
    }

    /**
     * Validate Uploaded Files
     *
     * @return array
     */
    public function validateUploadedFiles()
    {
        $validateCsv = $this->validateCsv();
        if ($validateCsv['error']) {
            return $validateCsv;
        }
        $csvFile = $validateCsv['csv'];
        $validateZip = $this->validateZip();
        if ($validateZip['error']) {
            return $validateZip;
        }

        $csvFilePath = $validateCsv['path'];
        if ($validateCsv['extension'] == 'csv') {
            $uploadedFileRowData = $this->readCsv($csvFilePath);
        } else {
            $objPhpSpreadsheetReader = IOFactory::load($csvFilePath);

            $loadedSheetNames = $objPhpSpreadsheetReader->getSheetNames();

            $objWriter = IOFactory::createWriter($objPhpSpreadsheetReader, 'Csv');

            $csvXLSFilePath =
                $this->_filesystem
                    ->getDirectoryWrite(DirectoryList::MEDIA)
                    ->getAbsolutePath('/xlscoverted') .
                $csvFile .
                '.csv';
            foreach ($loadedSheetNames as $sheetIndex => $loadedSheetName) {
                $objWriter->setSheetIndex($sheetIndex);
                $objWriter->save($csvXLSFilePath);
            }
            $uploadedFileRowData = $this->readCsv($csvXLSFilePath);
        }
        $validateCsvData = $this->validateCsvData($uploadedFileRowData);
        if ($validateCsvData['error']) {
            return $validateCsvData;
        }
        $productType = $validateCsvData['type'];

        $result = [
            'error' => false,
            'type' => $productType,
            'csv' => $csvFile,
            'csv_data' => $uploadedFileRowData,
            'extension' => $validateCsv['extension']
        ];
        return $result;
    }

    /**
     * Validate uploaded Csv File
     *
     * @return array
     */
    public function validateCsv()
    {
        try {
            $csvUploader = $this->_fileUploader->create(['fileId' => 'csv_file']);
            $csvUploader->setAllowedExtensions(['csv', 'xls']);
            $validateData = $csvUploader->validateFile();
            $extension = $csvUploader->getFileExtension();
            $csvFilePath = $validateData['tmp_name'];
            $csvFile = $validateData['name'];
            $csvFile = $this->getValidName($csvFile);
            $result = [
                'error' => false,
                'path' => $csvFilePath,
                'csv' => $csvFile,
                'extension' => $extension
            ];
        } catch (\Exception $e) {
            $msg = 'There is some problem in uploading file.';
            $result = ['error' => true, 'msg' => $msg];
        }
        return $result;
    }

    /**
     * Remove Special Characters From String
     *
     * @param string $string
     *
     * @return string
     */
    public function getValidName($string)
    {
        $string = str_replace(' ', '-', $string);
        $string = preg_replace('/[^A-Za-z0-9\-]/', '', $string);
        return preg_replace('/-+/', '-', $string);
    }

    /**
     * Validate uploaded Images Zip File
     *
     * @return array
     */
    public function validateZip()
    {
        try {
            $imageUploader = $this->_fileUploader->create(['fileId' => 'images_zip_file']);
            $imageUploader->setAllowedExtensions(['zip']);
            $validateData = $imageUploader->validateFile();
            $zipFilePath = $validateData['tmp_name'];
            $allowedImages = ['png', 'jpg', 'jpeg', 'gif'];
            $zip = zip_open($zipFilePath);
            if ($zip) {
                while ($zipEntry = zip_read($zip)) {
                    $fileName = zip_entry_name($zipEntry);
                    if (strpos($fileName, '.') !== false) {
                        $ext = strtolower(substr($fileName, strrpos($fileName, '.') + 1));
                        if (!in_array($ext, $allowedImages)) {
                            $msg = 'There are some files in zip which are not image.';
                            $result = ['error' => true, 'msg' => $msg];
                            return $result;
                        }
                    }
                }
                zip_close($zip);
            }
            $result = ['error' => false];
        } catch (\Exception $e) {
            $msg = 'There is some problem in uploading image zip file.';
            $result = ['error' => true, 'msg' => $msg];
        }
        return $result;
    }

    /**
     * Read Csv File
     *
     * @param string $csvFilePath
     *
     * @return array
     */
    public function readCsv($csvFilePath)
    {
        try {
            $uploadedFileRowData = $this->_csvReader->getData($csvFilePath);
        } catch (\Exception $e) {
            $uploadedFileRowData = [];
        }
        return $uploadedFileRowData;
    }

    /**
     * Validate Csv Data
     *
     * @param array $uploadedFileRowData
     *
     * @return array
     */
    public function validateCsvData($uploadedFileRowData)
    {
        $productType = $this->getProductType($uploadedFileRowData);
        $result = ['error' => false, 'type' => $productType];
        if ($productType == '') {
            $msg = 'Something went wrong.';
            $result = ['error' => true, 'msg' => $msg];
        }
        return $result;
    }

    /**
     * Get Csv Product Type
     *
     * @param array $uploadedFileRowData
     *
     * @return string
     */
    public function getProductType($uploadedFileRowData)
    {
        if (count($uploadedFileRowData) > 0) {
            if (in_array('_super_attribute_code', $uploadedFileRowData[0])) {
                return 'configurable';
            }
            return 'simple';
        }
        return '';
    }

    /**
     * Upload Images Zip File
     *
     * @param array $result
     * @param array $fileData
     *
     * @return array
     */
    public function uploadZip($uuid, $result, $fileData)
    {
        try {
            $basePath = $this->getBasePath($uuid);
            $imageUploadPath = $basePath . 'zip/';
            $imageUploader = $this->_fileUploader->create(['fileId' => 'images_zip_file']);
            $validateData = $imageUploader->validateFile();
            $imageUploader->setAllowedExtensions(['zip']);
            $imageUploader->setAllowRenameFiles(true);
            $imageUploader->setFilesDispersion(false);
            $imageUploader->save($imageUploadPath);
            $fileName = $imageUploader->getUploadedFileName();
            $source = $imageUploadPath . $fileName;
            $filePath = $this->getMediaPath() . 'tmp/catalog/product/' . $uuid . '/';
            $destination = $filePath . 'tempfiles/';
            $this->_zip->unzipImages($source, $destination);
            $this->arrangeFiles($destination);
            $this->flushFilesCache($destination);
            $this->copyFilesToDestinationFolder($uuid, $fileData, $filePath, 'images');
            $result = ['error' => false];
        } catch (\Exception $e) {
            $this->flushData($uuid);
            $msg = 'There is some problem in uploading image zip file.';
            $result = ['error' => true, 'msg' => $msg];
        }
        return $result;
    }

    /**
     * Get Media Path
     *
     * @return string
     */
    public function getMediaPath()
    {
        return $this->_filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath();
    }

    /**
     * Rearrange Images of Product to upload
     *
     * @param string $path
     * @param string $originalPath [Optional]
     * @param array  $result [Optional]
     */
    public function arrangeFiles($path, $originalPath = '', $result = [])
    {
        if ($originalPath == '') {
            $originalPath = $path;
        }
        $entries = $this->_fileDriver->readDirectory($path);
        foreach ($entries as $file) {
            if ($this->_fileDriver->isDirectory($file)) {
                $result = $this->arrangeFiles($file, $originalPath, $result);
            } else {
                $tmp = explode("/", $file);
                $fileName = end($tmp);
                $sourcePath = $path . '/' . $fileName;
                $destinationPath = $originalPath . '/' . $fileName;
                if (!$this->_fileDriver->isExists($destinationPath)) {
                    $result[$sourcePath] = $destinationPath;
                    $this->_fileDriver->copy($sourcePath, $destinationPath);
                }
            }
        }
    }

    /**
     * Upload Sample Files
     *
     * @param int $uuid
     * @param array $fileData
     * @param string $filePath
     * @param string $fileType
     *
     * @return array
     */
    public function copyFilesToDestinationFolder($uuid, $fileData, $filePath, $fileType)
    {
        $totalRows = count($fileData);
        $skuIndex = '';
        $fileIndex = '';
        foreach ($fileData[0] as $key => $value) {
            if ($value == 'sku') {
                $skuIndex = $key;
            }
            if ($value == $fileType) {
                $fileIndex = $key;
            }
        }
        $fileTempPath = $filePath . 'tempfiles/';
        for ($i = 1; $i < $totalRows; $i++) {
            if (!empty($fileData[$i][$skuIndex]) && !empty($fileData[$i][$fileIndex])) {
                $sku = $fileData[$i][$skuIndex];
                $destinationPath = $filePath . $sku;
                $isDestinationExist = 0;
                $files = explode(',', $fileData[$i][$fileIndex]);
                foreach ($files as $file) {
                    if (!empty(trim($file))) {
                        $sourcefilePath = $fileTempPath . $file;
                        if ($this->_fileDriver->isExists($sourcefilePath)) {
                            if ($isDestinationExist == 0) {
                                /* Create per product file folder if not exist */
                                if (!$this->_fileDriver->isExists($destinationPath)) {
                                    $this->_file->createDirectory($destinationPath);
                                    $isDestinationExist = 1;
                                }
                            }
                            $this->_file->copy($sourcefilePath, $destinationPath . '/' . $file);
                        }
                    }
                }
            }
        }
        $this->_file->deleteDirectory($fileTempPath);
    }

    /**
     * calculate Product Row Data
     *
     * @return array
     */
    public function calculateProductRowData($uuid, $profile, $row)
    {
        $profileType = $profile['product_type'];
        $uploadedFileRowData = unserialize($profile['data_row']);
        $mainRow = $row;
        $isConfigurableAllowed = $this->isProductTypeAllowed('configurable');
        if ($profileType == 'configurable' && $isConfigurableAllowed) {
            $rowIndexArr = $this->getConfigurableFormatCsv($uploadedFileRowData, 1);
            if (!empty($rowIndexArr[$row])) {
                $row = $rowIndexArr[$row];
            }
            $childRowIndexArr = $this->getConfigurableFormatCsv($uploadedFileRowData, 0);
            if (!empty($childRowIndexArr[$mainRow])) {
                $childRowArr = $childRowIndexArr[$mainRow];
            } else {
                $childRowArr = [];
            }
        }
        if (!array_key_exists($row, $uploadedFileRowData)) {
            $wholeData['error'] = 1;
            $wholeData['msg'] = __('Product data for row %1 does not exist', $mainRow);
        }
        // Prepare product row data
        $i = 0;
        $j = 0;
        $data = [];
        if (!empty($uploadedFileRowData[$row])) {
            $data = $uploadedFileRowData[$row];
        }
        $customData = [];
        $customData['product'] = [];
        foreach ($uploadedFileRowData[0] as $value) {
            if (!empty($data[$i])) {
                $customData['product'][$value] = $data[$i];
            } else {
                $customData['product'][$value] = '';
            }
            $i++;
        }
        $data = $customData;
        $validate = $this->validateFields($data, $profileType, $mainRow);
        if ($validate['error']) {
            $wholeData['error'] = $validate['error'];
            $wholeData['msg'] = $validate['msg'];
        }
        $data = $validate['data'];
        /*Calculate product weight*/
        $weight = $data['product']['weight'];
        /*Get Category ids by category name (set by comma seperated)*/
        $categoryIds = $this->getCategoryIds($data['product']['category']);
        /*Get $taxClassId by tax*/
        $taxClassId = $this->getAttributeOptionIdbyOptionText(
            "tax_class_id",
            trim($data['product']['tax_class_id'])
        );
        $isInStock = 1;
        if (!empty($data['product']['stock']) && !(int) $data['product']['stock']) {
            $isInStock = 0;
        } elseif (empty($data['product']['stock'])) {
            $data['product']['stock'] = '';
        }
        $attributeSetId = $profile['attribute_set_id'];
        $wholeData['form_key'] = $this->_formKey->getFormKey();
        $wholeData['type'] = $profileType;
        $wholeData['set'] = $attributeSetId;
        if (!empty($data['id'])) {
            $wholeData['id'] = $data['id'];
            $wholeData['product_id'] = $data['product_id'];
            $wholeData['product']['website_ids'] = $data['product']['website_ids'];
            $wholeData['product']['url_key'] = $data['product']['url_key'];
        }
        $wholeData['product']['category_ids'] = $categoryIds;
        $wholeData['product']['name'] = $data['product']['name'];
        $wholeData['product']['short_description'] = $data['product']['short_description'];
        $wholeData['product']['description'] = $data['product']['description'];
        $wholeData['product']['sku'] = $data['product']['sku'];
        $wholeData['product']['price'] = $data['product']['price'];
        $wholeData['product']['visibility'] = 4;
        $wholeData['product']['tax_class_id'] = $taxClassId;
        $wholeData['product']['product_has_weight'] = 1;
        $wholeData['product']['weight'] = $weight;
        $wholeData['product']['stock_data']['manage_stock'] = 1;
        $wholeData['product']['stock_data']['use_config_manage_stock'] = 1;
        $wholeData['product']['quantity_and_stock_status']['qty'] = $data['product']['stock'];
        $wholeData['product']['quantity_and_stock_status']['is_in_stock'] = $isInStock;
        $wholeData['product']['meta_title'] = $data['product']['meta_title'];
        $wholeData['product']['meta_keyword'] = $data['product']['meta_keyword'];
        $wholeData['product']['meta_description'] = $data['product']['meta_description'];
        /*START :: Set Special Price Info*/
        $wholeData = $this->processSpecialPriceData($wholeData, $data);
        /*Set Image Info*/
        $wholeData = $this->processImageData($wholeData, $data, $uuid);
        /*Set Configurable Data*/
        $isConfigurableAllowed = $this->isProductTypeAllowed('configurable');
        if ($profileType == 'configurable' && $isConfigurableAllowed) {
            $wholeData = $this->processConfigurableData(
                $wholeData,
                $data,
                $mainRow,
                $childRowArr,
                $uploadedFileRowData,
                $uuid
            );
        }
        $wholeData = $this->utf8Converter($wholeData);
        return $wholeData;
    }

    /**
     * Convert array to utf-8.
     *
     * @return array
     */
    public function utf8Converter($data = [])
    {
        array_walk_recursive($data, function (&$item, $key) {
            if (!mb_detect_encoding($item, 'utf-8', true)) {
                $item = utf8_encode($item);
            }
        });
        return $data;
    }

    /**
     * Get Category Ids From Name
     *
     * @param string $categories
     *
     * @return array
     */
    public function getCategoryIds($categories)
    {
        $categoryIds = [];
        $categoryList = $this->getCategotyList();
        if (strpos($categories, ',') !== false) {
            $categories = explode(',', $categories);
        } else {
            $categories = [$categories];
        }
        $categories = array_unique($categories);
        foreach ($categories as $category) {
            $parentId = 2;
            if (strpos($category, '>>') !== false) {
                $category = explode('>>', $category);
                foreach ($category as $ch) {
                    if ($ch != "Default Category") {
                        $parentId = $this->getChildId($parentId, $ch);
                    }
                }
                foreach ($categoryList as $key => $cat) {
                    if ($key == $parentId) {
                        $categoryIds[] = $key;
                    }
                }
            } else {
                $category = trim($category);
                if (in_array($category, $categoryList)) {
                    foreach ($categoryList as $key => $cat) {
                        if ($cat == $category) {
                            $categoryIds[] = $key;
                        }
                    }
                }
            }
        }
        return $categoryIds;
    }

    /**
     * Get All Categories
     *
     * @return array
     */
    public function getCategotyList()
    {
        $categoryList = [];
        $collection = $this->_categoryCollection->create()->addAttributeToSelect('name');
        foreach ($collection as $category) {
            $categoryList[$category->getEntityId()] = trim($category->getName());
        }
        return $categoryList;
    }

    /**
     * Get Child Category Id By Parent Category Id and Child Category Name
     *
     * @param int $parentId
     * @param string $childName
     * @return int
     */
    public function getChildId($parentId = false, $childName)
    {
        if ($parentId) {
            $collection = $this->_categoryCollection
                ->create()
                ->addFieldToFilter('parent_id', $parentId)
                ->addFieldToFilter('name', $childName);
            foreach ($collection as $category) {
                return $category->getEntityId();
            }
        }
        return $parentId;
    }

    /**
     * Get Csv Data in Format to Upload Configurable Product
     *
     * @param array $uploadedFileRowData
     * @param int $isParent
     *
     * @return array
     */
    public function getConfigurableFormatCsv($uploadedFileRowData, $isParent = 0)
    {
        $configData = [];
        $skipData = [];
        $parent = 0;
        $count = 0;
        $length = count($uploadedFileRowData);
        for ($i = 1; $i < $length; ++$i) {
            if ($uploadedFileRowData[$i][0] == 'configurable') {
                $parent = $i;
                ++$count;
                if ($isParent == 1) {
                    $configData[$count] = $i;
                }
            }
            if ($parent > 0) {
                if ($uploadedFileRowData[$i][0] == 'simple') {
                    if ($isParent != 1) {
                        $configData[$count][] = $i;
                    }
                }
            }
        }
        return $configData;
    }

    /**
     * Validate Product Data
     *
     * @param array $data
     * @param string $productType
     * @param int $row
     *
     * @return array
     */
    public function validateFields($data, $productType, $row)
    {
        $data = $this->prepareProductDataIfNotSet($data, $productType);
        if (empty($data['product'])) {
            $result['error'] = 1;
            $result['data'] = $data;
            $result['msg'] = __('Skipped row %1. product data can not be empty.', $row);
            return $result;
        } else {
            $name = $data['product']['name'];
            $sku = $data['product']['sku'];
            $description = $data['product']['description'];
            $weight = $data['product']['weight'];
            if (strlen($name) <= 0) {
                $result['error'] = 1;
                $result['data'] = $data;
                $result['msg'] = __('Skipped row %1. product name can not be empty.', $row);
                return $result;
            }
            if (strlen($description) <= 0) {
                $result['error'] = 1;
                $result['data'] = $data;
                $result['msg'] = __('Skipped row %1. product description can not be empty.', $row);
                return $result;
            }
            if (
                $productType != 'virtual' &&
                $productType != 'downloadable' &&
                strlen($weight) <= 0
            ) {
                $result['error'] = 1;
                $result['data'] = $data;
                $result['msg'] = __('Skipped row %1. product weight can not be empty.', $row);
                return $result;
            }
            if (strlen($sku) <= 0) {
                $result['error'] = 1;
                $result['data'] = $data;
                $result['msg'] = __('Skipped row %1. product sku can not be empty.', $row);
                return $result;
            }
            $productId = $this->_product->create()->getIdBySku($sku);
            if ($productId) {
                $product = $this->_product->create()->load($productId);
                $data['id'] = $productId;
                $data['product_id'] = $productId;
                $data['product']['website_ids'][] = $product->getStore()->getWebsiteId();
                $data['product']['url_key'] = $product->getUrlKey();
            }
        }
        return ['error' => 0, 'data' => $data];
    }

    /**
     * Prepare Product Data If NotSet
     *
     * @param array $data
     * @param string $productType
     *
     * @return array
     */
    public function prepareProductDataIfNotSet($data, $productType)
    {
        if (empty($data['product']['name'])) {
            $data['product']['name'] = '';
        }
        if (empty($data['product']['sku'])) {
            $data['product']['sku'] = '';
        }
        if (empty($data['product']['description'])) {
            $data['product']['description'] = '';
        }
        if (empty($data['product']['weight'])) {
            $data['product']['weight'] = 0;
        }
        if (empty($data['product']['category'])) {
            $data['product']['category'] = '';
        }
        if (empty($data['product']['tax_class_id'])) {
            $data['product']['tax_class_id'] = '';
        }
        if (empty($data['product']['stock'])) {
            $data['product']['stock'] = '';
        }
        if (empty($data['product']['short_description'])) {
            $data['product']['short_description'] = '';
        }
        if (empty($data['product']['price'])) {
            $data['product']['price'] = '';
        }
        if (empty($data['product']['meta_title'])) {
            $data['product']['meta_title'] = '';
        }
        if (empty($data['product']['meta_keyword'])) {
            $data['product']['meta_keyword'] = '';
        }
        if (empty($data['product']['meta_description'])) {
            $data['product']['meta_description'] = '';
        }
        if (empty($data['product']['special_price'])) {
            $data['product']['special_price'] = '';
        }
        if (empty($data['product']['special_from_date'])) {
            $data['product']['special_from_date'] = '';
        }
        if (empty($data['product']['special_to_date'])) {
            $data['product']['special_to_date'] = '';
        }
        if (empty($data['product']['images'])) {
            $data['product']['images'] = '';
        }
        if (empty($data['product']['custom_option'])) {
            $data['product']['custom_option'] = '';
        }
        $isConfigurableAllowed = $this->isProductTypeAllowed('configurable');
        if ($productType == 'configurable' && $isConfigurableAllowed) {
            if (empty($data['product']['_super_attribute_code'])) {
                $data['product']['_super_attribute_code'] = '';
            }
            if (empty($data['product']['_super_attribute_option'])) {
                $data['product']['_super_attribute_option'] = '';
            }
        }
        return $data;
    }

    /**
     * getAttributeOptionIdbyOptionText This returns
     * @param String $attributeCode Conatines Attribute code
     * @param String $optionText Conatines Attribute text
     * @var Object $productModel Catalog product model object
     * @var Object $attribute Eav Attribute model object
     * @var Int $optionId Containes Attribute option id corrosponding to option text
     * @var String $attributeValidationClass Attribute Validation class
     */
    public function getAttributeOptionIdbyOptionText($attributeCode, $optionText)
    {
        if ($optionText == "") {
            return $optionText;
        }
        $model = $this->_config;
        $attribute = $model->getAttribute('catalog_product', $attributeCode);
        if ($attribute) {
            $optionId = $attribute->getSource()->getOptionId(trim($optionText));
            return $optionId;
        } else {
            return "";
        }
    }

    /**
     * Process Special Price Data
     *
     * @param array $wholeData
     * @param array $data
     * @param int $flag [optional]
     *
     * @return array
     */
    public function processSpecialPriceData($wholeData, $data, $flag = 0)
    {
        if ($flag == 1) {
            /*Configurable Case*/
            $price = (float) $data['product']['price'];
            $specialPrice = (float) $data['product']['special_price'];
            $specialFromDate = trim($data['product']['special_from_date']);
            $specialToDate = trim($data['product']['special_to_date']);
        } else {
            $price = (float) $data['product']['price'];
            $specialPrice = (float) $data['product']['special_price'];
            $specialFromDate = trim($data['product']['special_from_date']);
            $specialToDate = trim($data['product']['special_to_date']);
        }

        $specialFromDate = $this->getDate($specialFromDate);
        $specialToDate = $this->getDate($specialToDate);
        if ($specialFromDate != "" && $specialToDate != "") {
            $diff = strtotime($specialToDate) - strtotime($specialFromDate);
        } else {
            $diff = 1;
        }
        if ($diff > 0 && $specialPrice != "" && $specialPrice < $price) {
            $wholeData['product']['special_price'] = $specialPrice;
            $wholeData['product']['special_from_date'] = $specialFromDate;
            $wholeData['product']['special_to_date'] = $specialToDate;
        }

        return $wholeData;
    }

    /**
     * Get Valid Date
     *
     * @param string $date
     *
     * @return string
     */
    public function getDate($date)
    {
        $year = date("Y", strtotime($date));
        if ($year <= 1970) {
            return "";
        }
        return date("Y-m-d", strtotime($date));
    }

    /**
     * Process Image Data
     *
     * @param array $wholeData
     * @param array $data
     * @param int $uuid
     *
     * @return array $wholeData
     */
    public function processImageData($wholeData, $data, $uuid)
    {
        if (!empty($data['product']['images'])) {
            $sku = $data['product']['sku'];
            $images = $this->getArrayFromString($data['product']['images']);
            $customOptionData = [];
            $i = 0;
            foreach ($images as $key => $value) {
                $imageName = '/' . $uuid . '/' . $sku . '/' . $value;
                $imagePath = $this->getMediaPath() . 'tmp/catalog/product' . $imageName;
                if (!empty(trim($value)) && $this->_fileDriver->isExists($imagePath)) {
                    $i++;
                    $wholeData['product']['media_gallery']['images'][$key]['position'] = $i;
                    $wholeData['product']['media_gallery']['images'][$key]['media_type'] = '';
                    $wholeData['product']['media_gallery']['images'][$key]['video_provider'] = '';
                    $wholeData['product']['media_gallery']['images'][$key]['file'] =
                        $imageName . '.tmp';
                    $wholeData['product']['media_gallery']['images'][$key]['value_id'] = '';
                    $wholeData['product']['media_gallery']['images'][$key]['label'] = '';
                    $wholeData['product']['media_gallery']['images'][$key]['disabled'] = '';
                    $wholeData['product']['media_gallery']['images'][$key]['removed'] = '';
                    $wholeData['product']['media_gallery']['images'][$key]['video_url'] = '';
                    $wholeData['product']['media_gallery']['images'][$key]['video_title'] = '';
                    $wholeData['product']['media_gallery']['images'][$key]['video_description'] =
                        '';
                    $wholeData['product']['media_gallery']['images'][$key]['video_metadata'] = '';
                    $wholeData['product']['media_gallery']['images'][$key]['role'] = '';
                    if ($i == 1) {
                        $wholeData['product']['image'] = $imageName . '.tmp';
                        $wholeData['product']['small_image'] = $imageName . '.tmp';
                        $wholeData['product']['thumbnail'] = $imageName . '.tmp';
                    }
                }
            }
        }
        return $wholeData;
    }

    /**
     * Get Array From String
     *
     * @param string $string
     * @param string $delimiter [optional]
     *
     * @return array
     */
    public function getArrayFromString($string, $delimiter = ",")
    {
        if (strpos($string, $delimiter) !== false) {
            $data = explode($delimiter, $string);
        } else {
            $data = [$string];
        }
        return $data;
    }

    /**
     * Process Configurable Data
     *
     * @param array $wholeData
     * @param array $data
     * @param int $uuid
     *
     * @return array $wholeData
     */
    public function processConfigurableData(
        $wholeData,
        $data,
        $row,
        $childRowArr,
        $uploadedFileRowData,
        $uuid
    ) {
        $attributeCodes = $data['product']['_super_attribute_code'];
        $error = 0;
        $attributeData = $this->processAttributeData($attributeCodes);
        $attributes = $attributeData['attributes'];
        $flag = $attributeData['flag'];
        if ($flag == 1) {
            $msg = __('Skipped row %1. Some of super attributes are not valid.', $row);
            $validate['msg'] = $msg;
            $validate['error'] = 1;
            if ($validate['error']) {
                $wholeData['error'] = $validate['error'];
                $wholeData['msg'] = $validate['msg'];
            }
        }
        foreach ($attributes as $attribute) {
            $attributeId = $attribute['attribute_id'];
            $wholeData['attributes'][] = $attributeId;
        }
        $attributeOptions = [];
        foreach ($childRowArr as $key => $childRow) {
            // Prepare Associated product row data
            $i = 0;
            $j = 0;
            $childRowData = $uploadedFileRowData[$childRow];
            $customData = [];
            foreach ($uploadedFileRowData[0] as $value) {
                $key = $i++;
                if (empty($childRowData[$key])) {
                    $customData['product'][$value] = '';
                } else {
                    $customData['product'][$value] = $childRowData[$key];
                }
                if ($value == 'description' && empty($customData['product'][$value])) {
                    $customData['product'][$value] = $wholeData['product']['description'];
                }
            }
            if (!empty($customData['product']['stock'])) {
                $customData['product']['stock'] = $customData['product']['stock'];
            } else {
                $customData['product']['stock'] = $data['product']['stock'];
            }
            $childRowData = $customData;
            $childRowData = $this->prepareAssociatedProductIfNotSet($childRowData, $data);
            $superAttributeOptions = $this->getArrayFromString(
                $childRowData['product']['_super_attribute_option']
            );
            $arributeCodeIndex = 0;
            foreach ($attributes as $attribute) {
                if (!empty($superAttributeOptions[$arributeCodeIndex])) {
                    $attributeId = $attribute['attribute_id'];
                    $attributeOptions[$attributeId][] = $superAttributeOptions[$arributeCodeIndex];
                    $arributeCodeIndex++;
                }
            }
            $wholeData['product']['configurable_attributes_data'] = [];
            $pos = 0;
            $allAttributeOptionsIdsArr = [];
            foreach ($attributes as $attribute) {
                $attributeId = $attribute['attribute_id'];
                $code = $attribute['attribute_code'];
                $wholeData['product']['configurable_attributes_data'][$attributeId][
                    'attribute_id'
                ] = $attributeId;
                $wholeData['product']['configurable_attributes_data'][$attributeId]['code'] = $code;
                $wholeData['product']['configurable_attributes_data'][$attributeId]['label'] =
                    $attribute['frontend_label'];
                $wholeData['product']['configurable_attributes_data'][$attributeId][
                    'position'
                ] = $pos;
                $wholeData['product']['configurable_attributes_data'][$attributeId]['values'] = [];
                if (empty($attributeOptions[$attributeId])) {
                    $attributeOptions[$attributeId] = [];
                }
                foreach ($attributeOptions[$attributeId] as $key => $option) {
                    $attributeOptionsId = '';
                    $attributeOptionsByCode = $this->getAttributeOptions($code);
                    if (!in_array($option, $attributeOptionsByCode)) {
                        $result = [
                            'msg' => __(
                                'Skipped row %1. Super attribute value is not valid.',
                                $row
                            ),
                            'error' => 1
                        ];
                        $wholeData['error'] = $result['error'];
                        $wholeData['msg'] = $result['msg'];
                    } else {
                        $attributeOptionsId = array_search($option, $attributeOptionsByCode);
                        $allAttributeOptionsIdsArr[$option]['id'] = $attributeOptionsId;
                        $allAttributeOptionsIdsArr[$option]['code'] = $code;
                    }
                    $wholeData['product']['configurable_attributes_data'][$attributeId]['values'][
                        $attributeOptionsId
                    ]['include'] = 1;
                    $wholeData['product']['configurable_attributes_data'][$attributeId]['values'][
                        $attributeOptionsId
                    ]['value_index'] = $attributeOptionsId;
                }
                $pos++;
            }

            // prepare variation matrix
            $variationMatrixArr = [];
            $variationMatrixConfAttribute = [];
            foreach ($superAttributeOptions as $key => $value) {
                if (!empty($allAttributeOptionsIdsArr[$value])) {
                    $optionAttrCode = $allAttributeOptionsIdsArr[$value]['code'];
                    $optionId = $allAttributeOptionsIdsArr[$value]['id'];
                    array_push($variationMatrixArr, $optionId);
                    $variationMatrixConfAttribute[$optionAttrCode] = $optionId;
                }
            }
            $associatedProductIds = [];
            if (!empty($wholeData['product_id'])) {
                $associatedProductIds = $this->getAllAssociatedProductsIds(
                    $wholeData['product_id']
                );
            }

            $variationMatrixIndex = implode('-', $variationMatrixArr);
            $configurableAttribute = $this->_jsonHelper->jsonEncode($variationMatrixConfAttribute);
            $associatedProId = $this->_product
                ->create()
                ->getIdBySku($childRowData['product']['sku']);
            $assoImageData = $this->processImageData($childRowData, $childRowData, $uuid);
            if ($associatedProId && in_array($associatedProId, $associatedProductIds)) {
                $variationMatrixIndex = $associatedProId;
                $wholeData['configurations'][$variationMatrixIndex]['image'] = '';
                $wholeData['associated_product_ids'][] = $associatedProId;
                if (!empty($assoImageData['product']['image'])) {
                    $wholeData['configurations'][$variationMatrixIndex]['image'] =
                        $assoImageData['product']['image'];
                    $wholeData['configurations'][$variationMatrixIndex]['small_image'] =
                        $assoImageData['product']['small_image'];
                    $wholeData['configurations'][$variationMatrixIndex]['thumbnail'] =
                        $assoImageData['product']['thumbnail'];
                    $wholeData['configurations'][$variationMatrixIndex]['media_gallery'] =
                        $assoImageData['product']['media_gallery'];
                }

                $wholeData['configurations'][$variationMatrixIndex]['name'] =
                    $childRowData['product']['name'];
                $wholeData['configurations'][$variationMatrixIndex][
                    'configurable_attribute'
                ] = $configurableAttribute;
                $wholeData['configurations'][$variationMatrixIndex]['status'] = 1;
                if (empty($childRowData['product']['sku'])) {
                    $childRowData['product']['sku'] =
                        $wholeData['product']['sku'] . '-' . implode('-', $superAttributeOptions);
                }
                $wholeData['configurations'][$variationMatrixIndex]['sku'] =
                    $childRowData['product']['sku'];
                $wholeData['configurations'][$variationMatrixIndex]['price'] =
                    $childRowData['product']['price'];
                $wholeData['configurations'][$variationMatrixIndex]['quantity_and_stock_status'][
                    'qty'
                ] = $childRowData['product']['stock'];
                $wholeData['configurations'][$variationMatrixIndex]['quantity_and_stock_status'][
                    'qty'
                ] = $childRowData['product']['stock'];
                $wholeData['configurations'][$variationMatrixIndex]['weight'] =
                    $childRowData['product']['weight'];
            } else {
                $wholeData['variations-matrix'][$variationMatrixIndex]['image'] = '';
                if (!empty($wholeData['product_id'])) {
                    $wholeData['associated_product_ids'][] = '';
                }
                if (!empty($assoImageData['product']['image'])) {
                    $wholeData['variations-matrix'][$variationMatrixIndex]['image'] =
                        $assoImageData['product']['image'];
                    $wholeData['variations-matrix'][$variationMatrixIndex]['small_image'] =
                        $assoImageData['product']['small_image'];
                    $wholeData['variations-matrix'][$variationMatrixIndex]['thumbnail'] =
                        $assoImageData['product']['thumbnail'];
                    $wholeData['variations-matrix'][$variationMatrixIndex]['media_gallery'] =
                        $assoImageData['product']['media_gallery'];
                }

                $wholeData['variations-matrix'][$variationMatrixIndex]['name'] =
                    $childRowData['product']['name'];
                $wholeData['variations-matrix'][$variationMatrixIndex][
                    'configurable_attribute'
                ] = $configurableAttribute;
                $wholeData['variations-matrix'][$variationMatrixIndex]['status'] = 1;
                if (empty($childRowData['product']['sku'])) {
                    $childRowData['product']['sku'] =
                        $wholeData['product']['sku'] . '-' . implode('-', $superAttributeOptions);
                }
                $wholeData['variations-matrix'][$variationMatrixIndex]['sku'] =
                    $childRowData['product']['sku'];
                $wholeData['variations-matrix'][$variationMatrixIndex]['price'] =
                    $childRowData['product']['price'];
                $wholeData['variations-matrix'][$variationMatrixIndex]['quantity_and_stock_status'][
                    'qty'
                ] = $childRowData['product']['stock'];
                $wholeData['variations-matrix'][$variationMatrixIndex]['weight'] =
                    $childRowData['product']['weight'];
            }
        }
        $wholeData['affect_configurable_product_attributes'] = 1;
        return $wholeData;
    }

    /**
     * get all associated product ids
     *
     * @param int
     *
     * @return array
     */
    public function getAllAssociatedProductsIds($id)
    {
        $childProductsIds = $this->_configurableProTypeModel->getChildrenIds($id);
        return $childProductsIds[0];
    }

    /**
     * Prepare Product Data If NotSet
     *
     * @param array $childRow
     * @param array $data
     *
     * @return array
     */
    public function prepareAssociatedProductIfNotSet($childRow, $data)
    {
        if (empty($childRow['product']['name'])) {
            $childRow['product']['name'] = $data['product']['name'];
        }
        if (empty($childRow['product']['weight'])) {
            $childRow['product']['weight'] = $data['product']['weight'];
        }
        if (empty($childRow['product']['stock'])) {
            $childRow['product']['stock'] = $data['product']['stock'];
        }
        if (empty($childRow['product']['price'])) {
            $childRow['product']['price'] = $data['product']['price'];
        }
        return $childRow;
    }

    /**
     * Process Attribute Data
     *
     * @param array|string $attributeCodes
     *
     * @return array
     */
    public function processAttributeData($attributeCodes)
    {
        $result = ['flag' => 0];
        $attributes = [];
        if (strpos($attributeCodes, ',') !== false) {
            $attributeCodes = explode(',', $attributeCodes);
            foreach ($attributeCodes as $attributeCode) {
                $attributeCode = trim($attributeCode);
                if (!$this->isValidAttribute($attributeCode)) {
                    $result['flag'] = 1;
                    break;
                }
                $attributesResultData = $this->getAttributeByCode($attributeCode);
                if (!empty($attributesResultData)) {
                    $attributes[] = $attributesResultData;
                }
            }
        } else {
            $attributeCodes = trim($attributeCodes);
            if (!$this->isValidAttribute($attributeCodes)) {
                $result['flag'] = 1;
            }
            $attributesResultData = $this->getAttributeByCode($attributeCodes);
            if (!empty($attributesResultData)) {
                $attributes[] = $attributesResultData;
            }
        }
        $result['attributes'] = $attributes;
        return $result;
    }

    /**
     * Check Attribute Code is VAlid or Not for Configurable Product
     *
     * @param string $attributeCode
     *
     * @return bool
     */
    public function isValidAttribute($attributeCode)
    {
        $collection = $this->_attributeCollection
            ->create()
            ->addFieldToFilter('attribute_code', $attributeCode)
            ->addFieldToFilter('frontend_input', 'select');
        foreach ($collection as $attribute) {
            if ($attribute->getId()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get Attribute by Attribute Code
     *
     * @param string $attributeCode
     *
     * @return int
     */
    public function getAttributeByCode($attributeCode)
    {
        $attributeData = [];
        $collection = $this->_attributeCollection
            ->create()
            ->addFieldToFilter('attribute_code', $attributeCode)
            ->addFieldToFilter('frontend_input', 'select');
        foreach ($collection as $attribute) {
            $attributeData = $attribute->getData();
        }
        return $attributeData;
    }

    /**
     * Get Total Product to Upload
     *
     * @param array $profile
     *
     * @return int
     */
    public function getTotalCount($profile)
    {
        $type = $profile['product_type'];
        $uploadedFileRowData = unserialize($profile['data_row']);
        $isConfigurableAllowed = $this->isProductTypeAllowed('configurable');
        if ($type == 'configurable' && $isConfigurableAllowed) {
            $count = count($this->getConfigurableFormatCsv($uploadedFileRowData, 1));
        } else {
            $count = count($uploadedFileRowData);
            if ($count >= 1) {
                --$count;
            }
        }
        return $count;
    }

    /**
     * Save Product
     */
    public function saveProduct($sellerId, $wholeData)
    {
        return $this->_saveProduct->saveProductData($sellerId, $wholeData);
    }
}
