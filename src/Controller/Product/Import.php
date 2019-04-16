<?php

namespace Lexor\M2MaketplaceImportExport\Controller\Product;

use Magento\Framework\App\Action\Action;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
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
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    protected $_date;

    /**
     * @var \Magento\Framework\Filesystem\Directory\WriteInterface
     */
    protected $_mediaDirectory;

    /**
     * @var \Magento\Framework\Filesystem\Directory\WriteInterface
     */
    protected $_varDirectory;

    protected $_fileUploaderFactory;
    protected $_fileCsv;

    /**
     * @param Context                                     $context
     * @param Session                                     $customerSession
     * @param FormKeyValidator                            $formKeyValidator
     * @param \Magento\Framework\Stdlib\DateTime\DateTime $date
     * @param Filesystem                                  $filesystem
     * @param PageFactory                                 $resultPageFactory
     */
    public function __construct(
        Context $context,
        Session $customerSession,
        FormKeyValidator $formKeyValidator,
        \Magento\Framework\Stdlib\DateTime\DateTime $date,
        Filesystem $filesystem,
        PageFactory $resultPageFactory,
        \Magento\MediaStorage\Model\File\UploaderFactory $fileUploaderFactory,
        \Magento\Framework\File\Csv $fileCsv
    ) {
        $this->_customerSession = $customerSession;
        $this->_formKeyValidator = $formKeyValidator;
        $this->_date = $date;
        $this->_mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $this->_resultPageFactory = $resultPageFactory;
        $this->_fileUploaderFactory = $fileUploaderFactory;
        $this->_varDirectory = $filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $this->_fileCsv = $fileCsv;
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
        $helper = $this->_objectManager->create('Webkul\Marketplace\Helper\Data');
        $isPartner = $helper->isSeller();
        if ($isPartner == 1) {
            try {
                $allowedAttributesetIds = $helper->getAllowedAttributesetIds();
                $allowedProductType = $helper->getAllowedProductType();
                $allowedsets = [];
                $allowedtypes = [];
                if (trim($allowedAttributesetIds)) {
                    $allowedsets = explode(',', $allowedAttributesetIds);
                }
                if (trim($allowedProductType)) {
                    $allowedtypes = explode(',', $allowedProductType);
                }
                if (count($allowedsets) > 1 || count($allowedtypes) > 1) {
                    if (!$this->getRequest()->isPost()) {
                        /** @var \Magento\Framework\View\Result\Page $resultPage */
                        $resultPage = $this->_resultPageFactory->create();
                        if ($helper->getIsSeparatePanel()) {
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
                    $set = $this->getRequest()->getParam('set');
                    $type = $this->getRequest()->getParam('type');
                    /**
                     * Upload File
                     */
                    $target = $this->_varDirectory->getAbsolutePath('marketplaceimportexport/');
                    /** @var $uploader \Magento\MediaStorage\Model\File\Uploader */
                    $uploader = $this->_fileUploaderFactory->create(['fileId' => 'import_file']);
                    // $this->_getSession()->getCustomerId()
                    /** Allowed extension types */
                    $uploader->setAllowedExtensions(['csv']);
                    /** rename file name if already exists */
                    $uploader->setAllowRenameFiles(true);
                    /** upload file in folder "mycustomfolder" */
                    $result = $uploader->save(
                        $target,
                        $this->_getSession()->getCustomerId() . '_' . Uuid::uuid4() . '.csv'
                    );
                    $path = $result['path'];
                    $file = $path . $result['file'];
                    if (file_exists($file)) {
                        $data = $this->_fileCsv->getData($file);
                        // This skips the first line of your csv file, since it will probably be a heading. Set $i = 0 to not skip the first line.
                        for ($i = 1; $i < count($data); $i++) {
                            var_dump($data[$i]); // $data[$i] is an array with your csv columns as values.
                        }
                    }
                    exit();
                } elseif (count($allowedsets) == 0 || count($allowedtypes) == 0) {
                    $this->messageManager->addError(
                        'Please ask admin to configure product settings properly to add products.'
                    );

                    return $this->resultRedirectFactory
                        ->create()
                        ->setPath('marketplace/account/dashboard', [
                            '_secure' => $this->getRequest()->isSecure()
                        ]);
                } else {
                    $this->_getSession()->setAttributeSet($allowedsets[0]);

                    return $this->resultRedirectFactory->create()->setPath('*/*/add', [
                        'set' => $allowedsets[0],
                        'type' => $allowedtypes[0],
                        '_secure' => $this->getRequest()->isSecure()
                    ]);
                }
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
     * @return Import
     * @deprecated 100.1.0
     */
    private function getImport()
    {
        if (!$this->import) {
            $this->import = $this->_objectManager->get(\Magento\ImportExport\Model\Import::class);
        }
        return $this->import;
    }
}
