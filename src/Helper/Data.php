<?php
namespace Lexor\M2MaketplaceImportExport\Helper;

use Magento\Framework\UrlInterface;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollection;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\CollectionFactory as AttributeSetCollection;
use Magento\Framework\Filesystem\Driver\File;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Magento\Framework\App\Filesystem\DirectoryList;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
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
        $url = $this->mediaDirectory . 'lexor/marketplaceimportexport/samples/';
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

        $url = $this->mediaDirectory . 'lexor/marketplaceimportexport/samples/';
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
    public function uploadCsv($result, $extension, $csvFile)
    {
        $sellerId = $result['customer_id'];
        try {
            $csvUploadPath = $this->getBasePath($sellerId);
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
            $this->flushData($sellerId);
            $msg = 'There is some problem in uploading csv file.' . $e->getMessage();
            $result = ['error' => true, 'msg' => $msg];
        }
        return $result;
    }

    /**
     * Get Base Path
     *
     * @param int $sellerId
     *
     * @return string
     */
    public function getBasePath($sellerId)
    {
        $mediaPath = $this->getMediaPath();
        $basePath = $mediaPath . 'lexor/marketplaceimportexport/' . $sellerId . "/";
        return $basePath;
    }

    /**
     * Flush Unwanted Data
     *
     * @param int $sellerId
     */
    public function flushData($sellerId)
    {
        $path = $this->getBasePath($sellerId);
        $this->flushFilesCache($path, true);
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
     * Save Profile Data
     *
     * @param string $productType
     * @param string $csvFile
     * @param array $uploadedFileRowData
     * @param string $extension
     *
     * @return array
     */
    public function saveProfileData($productType, $fileName, $fileData, $extension)
    {
        $result = [];
        $time = time();
        $name = $time . ".csv";

        $customerId = $this->getCustomerId();
        $attributeSet = $this->_request->getParam('attribute_set');
        $result = [
            'name' => $name,
            'customer_id' => $customerId,
            'product_type' => $productType,
            'attribute_set_id' => $attributeSet,
            'image_file' => 'images',
            'link_file' => 'links',
            'sample_file' => 'samples',
            'data_row' => serialize($fileData),
            'file_type' => $extension
        ];
        return $result;
    }

    /**
     * Upload Images Zip File
     *
     * @param array $result
     * @param array $fileData
     *
     * @return array
     */
    public function uploadZip($result, $fileData)
    {
        $sellerId = $result['customer_id'];
        try {
            $basePath = $this->getBasePath($sellerId);
            $imageUploadPath = $basePath . 'zip/';
            $imageUploader = $this->_fileUploader->create(['fileId' => 'images_zip_file']);
            $validateData = $imageUploader->validateFile();
            $imageUploader->setAllowedExtensions(['zip']);
            $imageUploader->setAllowRenameFiles(true);
            $imageUploader->setFilesDispersion(false);
            $imageUploader->save($imageUploadPath);
            $fileName = $imageUploader->getUploadedFileName();
            $source = $imageUploadPath . $fileName;
            $filePath = $this->getMediaPath() . 'tmp/catalog/product/' . $sellerId . '/';
            $destination = $filePath . 'tempfiles/';
            $this->_zip->unzipImages($source, $destination);
            $this->arrangeFiles($destination);
            $this->flushFilesCache($destination);
            $this->copyFilesToDestinationFolder($sellerId, $fileData, $filePath, 'images');
            $result = ['error' => false];
        } catch (\Exception $e) {
            $this->flushData($sellerId);
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
     * @param int $profileId
     * @param array $fileData
     * @param string $filePath
     * @param string $fileType
     *
     * @return array
     */
    public function copyFilesToDestinationFolder($profileId, $fileData, $filePath, $fileType)
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
}
