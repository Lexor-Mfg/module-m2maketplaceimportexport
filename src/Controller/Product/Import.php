<?php

namespace Lexor\M2MaketplaceImportExport\Controller\Product;

use Magento\Framework\App\Action\Action;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\App\RequestInterface;
use Ramsey\Uuid\Uuid;

/**
 * Webkul Marketplace Product Create Controller Class.
 */
class Import extends Action
{
    /**
     * @var \Magento\Customer\Model\Session
     */
    protected $_customerSession;

    /**
     * @var \Magento\Framework\Data\Form\FormKey\Validator
     */
    protected $_formKeyValidator;

    /**
     * @var \Lexor\M2MaketplaceImportExport\Helper\Data
     */
    protected $_helper;

    /**
     * @var \Webkul\Marketplace\Helper\Data
     */
    protected $_helperMarketplace;

    public function __construct(
        Context $context,
        Session $customerSession,
        FormKeyValidator $formKeyValidator,
        PageFactory $resultPageFactory,
        \Lexor\M2MaketplaceImportExport\Helper\Data $helper,
        \Webkul\Marketplace\Helper\Data $helperMarketplace
    ) {
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
                    $this->_objectManager->get(\Magento\ImportExport\Helper\Data::class)->getMaxUploadSizeMessage()
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
                if (!$this->_formKeyValidator->validate($this->getRequest())) {
                    return $this->resultRedirectFactory
                        ->create()
                        ->setPath('*/*/import', ['_secure' => $this->getRequest()->isSecure()]);
                }
                
                // Process POST request
                return $this->upload();
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
    private function upload()
    {
        $validateData = $this->_helper->validateUploadedFiles();
        if ($validateData['error']) {
            $this->messageManager->addError(__($validateData['msg']));
            return $this->resultRedirectFactory->create()->setPath('*/*/import');
        }
        $productType = $validateData['type'];
        $fileName = $validateData['csv'];
        $fileData = $validateData['csv_data'];
        $result = $this->_helper->saveProfileData(
            $productType,
            $fileName,
            $fileData,
            $validateData['extension']
        );
        $uploadCsv = $this->_helper->uploadCsv($result, $validateData['extension'], $fileName);
        if ($uploadCsv['error']) {
            $this->messageManager->addError(__($uploadCsv['msg']));
            return $this->resultRedirectFactory->create()->setPath('*/*/import');
        }
        $uploadZip = $this->_helper->uploadZip($result, $fileData);
        if ($uploadZip['error']) {
            $this->messageManager->addError(__($uploadZip['msg']));
            return $this->resultRedirectFactory->create()->setPath('*/*/import');
        }

        $message = __('Your zip file was uploaded and unpacked.');
        $this->messageManager->addSuccess($message);
        return $this->resultRedirectFactory->create()->setPath('*/*/import');
    }
}
