<?php

namespace Lexor\M2MaketplaceImportExport\Block\Product;

use Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\CollectionFactory;

class Import extends \Magento\Framework\View\Element\Template
{
    protected $_storeManager;
    protected $_formKey;
    protected $_entity;
    protected $marketplaceHelper;
    protected $_setCollection;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Eav\Model\Entity $entity,
        \Webkul\Marketplace\Helper\Data $marketplaceHelper,
        CollectionFactory $setCollection,
        array $data = []
    ) {
        $this->_storeManager = $context->getStoreManager();
        $this->_entity = $entity;
        $this->marketplaceHelper = $marketplaceHelper;
        $this->_setCollection = $setCollection;
        parent::__construct($context, $data);
    }

    /**
     * Prepare layout.
     *
     * @return this
     */
    public function _prepareLayout()
    {
        $pageMainTitle = $this->getLayout()->getBlock('page.main.title');
        if ($pageMainTitle) {
            $pageMainTitle->setPageTitle(__('Import Products'));
        }
        return parent::_prepareLayout();
    }

    /**
     * Get Attribute Set Collection.
     *
     * @return collection object
     */
    public function getAttributeSetCollection()
    {
        $allowedAttributeSets = $this->marketplaceHelper->getAllowedAttributesetIds();
        $allowedAttributeSetIds = explode(',', $allowedAttributeSets);
        $entityTypeId = $this->_entity->setType('catalog_product')->getTypeId();
        $attributeSetCollection = $this->_setCollection
            ->create()
            ->addFieldToFilter('attribute_set_id', ['in' => $allowedAttributeSetIds])
            ->setEntityTypeFilter($entityTypeId);
        return $attributeSetCollection;
    }
}
