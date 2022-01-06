<?php
/**
 * The class to provide functions for new_shipment.phtml
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Block\Sales;

use Magento\Backend\Block\Template\Context;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Sales\Block\Adminhtml\Items\AbstractItems;
use MyParcelNL\Magento\Helper\Checkout;
use MyParcelNL\Magento\Model\Source\DefaultOptions;

class NewShipment extends AbstractItems
{
    /**
     * @var \Magento\Sales\Model\Order
     */
    private $order;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var \MyParcelNL\Magento\Model\Source\DefaultOptions
     */
    private $defaultOptions;

    public $form;

    /**
     * @param \Magento\Backend\Block\Template\Context                   $context
     * @param \Magento\CatalogInventory\Api\StockRegistryInterface      $stockRegistry
     * @param \Magento\CatalogInventory\Api\StockConfigurationInterface $stockConfiguration
     * @param \Magento\Framework\Registry                               $registry
     * @param \Magento\Framework\ObjectManagerInterface                 $objectManager
     */
    public function __construct(
        Context $context,
        StockRegistryInterface $stockRegistry,
        StockConfigurationInterface $stockConfiguration,
        Registry $registry,
        ObjectManagerInterface $objectManager
    ) {
        // Set order
        $this->order = $registry->registry('current_shipment')->getOrder();
        $this->objectManager = $objectManager;
        $this->form = new NewShipmentForm();

        $this->defaultOptions = new DefaultOptions(
            $this->order,
            $this->objectManager->get('\MyParcelNL\Magento\Helper\Data')
        );

        parent::__construct($context, $stockRegistry, $stockConfiguration, $registry);
    }

    /**
     * @param $option 'signature', 'only_recipient'
     *
     * @return bool
     */
    public function getDefaultOption($option)
    {
        return $this->defaultOptions->getDefault($option);
    }
    /**
     * @param string $option 'large_format'
     *
     * @return bool
     */
    public function getDefaultLargeFormat(string $option): bool
    {
        return $this->defaultOptions->getDefaultLargeFormat($option);
    }

    /**
     * Get default value of age check
     *
     * @param string $option
     *
     * @return bool
     */
    public function getDefaultOptionsWithoutPrice(string $option): bool
    {
        return $this->defaultOptions->getDefaultOptionsWithoutPrice($option);
    }

    /**
     * Get default value of insurance based on order grand total
     * @return int
     */
    public function getDefaultInsurance()
    {
        return $this->defaultOptions->getDefaultInsurance();
    }

    /**
     * Get default value of insurance based on order grand total
     * @return int
     */
    public function getDigitalStampWeight()
    {
        return $this->defaultOptions->getDigitalStampDefaultWeight();
    }

    /**
     * Get package type
     */
    public function getPackageType()
    {
        return $this->defaultOptions->getPackageType();
    }

    /**
     * @return string
     */
    public function getCountry()
    {
        return $this->order->getShippingAddress()->getCountryId();
    }

    /**
     * Get all chosen options
     *
     * @return array
     */
    public function getChosenOptions()
    {
        return json_decode($this->order->getData(Checkout::FIELD_DELIVERY_OPTIONS), true);
    }
}
