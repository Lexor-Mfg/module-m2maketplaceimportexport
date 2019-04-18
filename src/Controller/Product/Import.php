<?php

namespace Lexor\M2MaketplaceImportExport\Controller\Product;

use Magento\Framework\App\Action\Action;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\App\RequestInterface;
use Ramsey\Uuid\Uuid;

class Import extends Action
{
    protected $_request;
    protected $_customerSession;
    protected $_formKeyValidator;
    protected $_helper;
    protected $_helperMarketplace;

    public function __construct(
        Context $context,
        Session $customerSession,
        FormKeyValidator $formKeyValidator,
        PageFactory $resultPageFactory,
        \Lexor\M2MaketplaceImportExport\Helper\Data $helper,
        \Webkul\Marketplace\Helper\Data $helperMarketplace
    ) {
        $this->_request = $context->getRequest();
        $this->_customerSession = $customerSession;
        $this->_formKeyValidator = $formKeyValidator;
        $this->_resultPageFactory = $resultPageFactory;
        $this->_helper = $helper;
        $this->_helperMarketplace = $helperMarketplace;
        parent::__construct($context);
    }

    /**
     * Check customer authentication.
     *
     * @param RequestInterface $request
     *
     * @return \Magento\Framework\App\ResponseInterface
     */
    public function dispatch(RequestInterface $request)
    {
        $loginUrl = $this->_objectManager->get('Magento\Customer\Model\Url')->getLoginUrl();

        if (!$this->_customerSession->authenticate($loginUrl)) {
            $this->_actionFlag->set('', self::FLAG_NO_DISPATCH, true);
        }

        return parent::dispatch($request);
    }

    /**
     * Retrieve customer session object.
     *
     * @return \Magento\Customer\Model\Session
     */
    protected function _getSession()
    {
        return $this->_customerSession;
    }

    /**
     * Seller Product Create page.
     *
     * @return \Magento\Framework\Controller\Result\RedirectFactory
     */
    public function execute()
    {
        $isPartner = $this->_helperMarketplace->isSeller();
        if ($isPartner == 1) {
            try {
                // Check Max upload size
                $this->messageManager->addNotice(
                    $this->_objectManager
                        ->get(\Magento\ImportExport\Helper\Data::class)
                        ->getMaxUploadSizeMessage()
                );
                if (!$this->getRequest()->isPost()) {
                    /** @var \Magento\Framework\View\Result\Page $resultPage */
                    $resultPage = $this->_resultPageFactory->create();
                    if ($this->_helperMarketplace->getIsSeparatePanel()) {
                        $resultPage->addHandle('marketplace_layout2_product_import');
                    }
                    $resultPage
                        ->getConfig()
                        ->getTitle()
                        ->set(__('Import Products'));

                    return $resultPage;
                }
                // Set Max Time
                set_time_limit(0);
                if (!$this->_formKeyValidator->validate($this->getRequest())) {
                    return $this->resultRedirectFactory
                        ->create()
                        ->setPath('*/*/import', ['_secure' => $this->getRequest()->isSecure()]);
                }

                // Process POST request
                $uuid = Uuid::uuid4();

                // Upload Processing
                $uploadProcess = $this->upload($uuid);
                if (!$uploadProcess['success']) {
                    $this->messageManager->addError(__($uploadProcess['msg']));
                    return $this->resultRedirectFactory
                        ->create()
                        ->setPath('*/*/import', ['_secure' => $this->getRequest()->isSecure()]);
                }

                // Process data and import to DB
                $importProcess = $this->runImport($uploadProcess);
                if (!$importProcess['success']) {
                    $this->messageManager->addError(__($importProcess['msg']));
                    return $this->resultRedirectFactory
                        ->create()
                        ->setPath('*/*/import', ['_secure' => $this->getRequest()->isSecure()]);
                }

                $this->messageManager->addSuccess(__($importProcess['msg']));
                return $this->resultRedirectFactory->create()->setPath('*/*/import');
            } catch (\Exception $e) {
                $this->messageManager->addError($e->getMessage());
                return $this->resultRedirectFactory
                    ->create()
                    ->setPath('*/*/import', ['_secure' => $this->getRequest()->isSecure()]);
            }
        } else {
            return $this->resultRedirectFactory
                ->create()
                ->setPath('marketplace/account/becomeseller', [
                    '_secure' => $this->getRequest()->isSecure()
                ]);
        }
    }

    /**
     * Upload process
     */
    private function upload($uuid)
    {
        $result = ['success' => true, 'uuid' => $uuid];

        $validateData = $this->_helper->validateUploadedFiles();
        if ($validateData['error']) {
            $this->messageManager->addError(__($validateData['msg']));
            return $this->resultRedirectFactory->create()->setPath('*/*/import');
        }
        // Build profile
        $productType = $validateData['type'];
        $fileName = $validateData['csv'];
        $fileData = $validateData['csv_data'];
        $extension = $validateData['extension'];
        $attributeSet = $this->_request->getParam('attribute_set');
        $profile = [
            'name' => time() . ".csv",
            'customer_id' => $this->_helper->getCustomerId(),
            'product_type' => $productType,
            'attribute_set_id' => $attributeSet,
            'image_file' => 'images',
            'link_file' => 'links',
            'sample_file' => 'samples',
            'data_row' => serialize($fileData),
            'file_type' => $extension
        ];
        $result['profile'] = $profile;

        // Upload file csv
        $uploadCsv = $this->_helper->uploadCsv(
            $uuid,
            $profile,
            $validateData['extension'],
            $fileName
        );
        if ($uploadCsv['error']) {
            $result['success'] = false;
            $result['msg'] = $uploadCsv['msg'];
        } else {
            $result['uploadCsv'] = $uploadCsv;
        }

        // Upload zip
        $uploadZip = $this->_helper->uploadZip($uuid, $profile, $fileData);
        if ($uploadZip['error']) {
            $result['success'] = false;
            $result['msg'] = $uploadZip['msg'];
        } else {
            $result['uploadZip'] = $uploadZip;
        }

        return $result;
    }

    /**
     * Run Import Products
     */
    private function runImport($uploadData)
    {
        $return = ['success' => true];
        try {
            $uuid = $uploadData['uuid'];
            $profile = $uploadData['profile'];
            $sellerId = $this->_helper->getCustomerId();
            $productCount = $this->_helper->getTotalCount($profile);
            for ($i = 1; $i <= $productCount; $i++) {
                $wholeData = $this->_helper->calculateProductRowData($uuid, $profile, $i);
                $result = $this->_helper->saveProduct($sellerId, $wholeData);
            }
            $return['msg'] = 'Import products are successfully.';
            $this->_helper->flushData($uuid);
        } catch (\Exception $e) {
            $return['success'] = false;
            $return['msg'] = $e->getMessage();
        }

        return $return;
    }
}
