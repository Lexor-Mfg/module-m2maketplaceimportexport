<?php
namespace Lexor\M2MaketplaceImportExport\Controller\Product;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\RequestInterface;

class Options extends \Magento\Framework\App\Action\Action
{
    /**
     * @var \Magento\Customer\Model\Url
     */
    protected $_url;

    /**
     * @var \Magento\Customer\Model\Session
     */
    protected $_session;

    /**
     * @var \Lexor\M2MaketplaceImportExport\Helper\Data
     */
    protected $_helper;

    public function __construct(
        Context $context,
        \Magento\Customer\Model\Url $url,
        \Magento\Customer\Model\Session $session,
        \Lexor\M2MaketplaceImportExport\Helper\Data $helper,
        \Magento\Framework\Controller\Result\JsonFactory $resultJson
    ) {
        $this->_url = $url;
        $this->_session = $session;
        $this->_helper = $helper;
        $this->_resultJson = $resultJson;
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
        $loginUrl = $this->_url->getLoginUrl();
        if (!$this->_session->authenticate($loginUrl)) {
            $this->_actionFlag->set('', self::FLAG_NO_DISPATCH, true);
        }
        return parent::dispatch($request);
    }

    /**
     * @return \Magento\Framework\View\Result\Page
     */
    public function execute()
    {
        $attributeCode = $this->getRequest()->getParam("code");
        $result = $this->_helper->getAttributeOptions($attributeCode);
        return $this->_resultJson->create()->setData($result);
    }
}
