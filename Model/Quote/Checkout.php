<?php

namespace MyParcelNL\Magento\Model\Quote;

use Magento\Checkout\Model\Cart;
use Magento\Checkout\Model\Session;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Helper\Data;
use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;

class Checkout
{
    const SELECT_CARRIER_ARRAY = 0;
    const SELECT_CARRIER_PATH  = 1;
    const PLATFORM             = 'myparcel';

    /**
     * @var array
     */
    private $data = [];

    /**
     * @var \MyParcelNL\Magento\Helper\Checkout
     */
    private $helper;

    /**
     * @var \Magento\Quote\Model\Quote
     */
    private $quoteId;

    /**
     * @var PackageRepository
     */
    private $package;

    /**
     * @var \Magento\Eav\Model\Entity\Collection\AbstractCollection[]
     */
    private $cart;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $currency;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $carrier;

    /**
     * Checkout constructor.
     *
     * @param \Magento\Checkout\Model\Session            $session
     * @param \Magento\Checkout\Model\Cart               $cart
     * @param \MyParcelNL\Magento\Helper\Checkout        $helper
     * @param PackageRepository                          $package
     * @param \Magento\Store\Model\StoreManagerInterface $currency
     *
     */
    public function __construct(
        Session $session,
        Cart $cart,
        \MyParcelNL\Magento\Helper\Checkout $helper,
        PackageRepository $package,
        StoreManagerInterface $currency
    ) {
        $this->helper   = $helper;
        $this->quoteId  = $session->getQuoteId();
        $this->cart     = $cart->getQuote();
        $this->package  = $package;
        $this->currency = $currency;

        $this->package->setMailboxSettings();
        $this->package->setDigitalStampSettings();
    }

    /**
     * Get settings for MyParcel delivery options
     *
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getDeliveryOptions(): array
    {
        $this->helper->setBasePriceFromQuote($this->quoteId);
        $this->hideDeliveryOptionsForProduct();

        $this->data = [
            'methods' => [$this->helper->getParentMethodNameFromQuote($this->quoteId)],
            'config'  => array_merge(
                $this->getGeneralData(),
                $this->getPackageType(),
                $this->getDeliveryData()
            ),
            'strings' => $this->getDeliveryOptionsStrings(),
        ];

        return [
            'root' => [
                'version' => (string) $this->helper->getVersion(),
                'data'    => (array) $this->data
            ]
        ];
    }

    /**
     * Get general data
     *
     * @return array)
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function getGeneralData()
    {
        return [
            'allowRetry'                 => true,
            'platform'                   => self::PLATFORM,
            'carriers'                   => array_column($this->get_carriers(), self::SELECT_CARRIER_ARRAY),
            'currency'                   => $this->currency->getStore()->getCurrentCurrency()->getCode(),
            'pickupLocationsDefaultView' => $this->helper->getArrayConfig(Data::XML_PATH_GENERAL, 'shipping_methods/pickup_locations_view')
        ];
    }

    /**
     * Get general data
     *
     * @return array
     */
    private function getPackageType()
    {
        $carriersPath = $this->get_carriers();
        $packageType  = [];

        foreach ($carriersPath as $carrier) {
            $packageType = [
                'packageType'                  => $this->checkPackageType($carrier),
                'cutoffTime'                   => $this->helper->getTimeConfig($carrier[self::SELECT_CARRIER_PATH], 'general/cutoff_time'),
                'saturdayCutoffTime'           => $this->helper->getTimeConfig($carrier[self::SELECT_CARRIER_PATH], 'general/saturday_cutoff_time'),
                'deliveryDaysWindow'           => $this->helper->getIntegerConfig($carrier[self::SELECT_CARRIER_PATH], 'general/deliverydays_window'),
                'allowMondayDelivery'          => $this->helper->getIntegerConfig($carrier[self::SELECT_CARRIER_PATH], 'general/monday_delivery_active'),
                'dropOffDays'                  => $this->helper->getArrayConfig($carrier[self::SELECT_CARRIER_PATH], 'general/dropoff_days'),
                'dropOffDelay'                 => $this->getDropOffDelay($carrier[self::SELECT_CARRIER_PATH], 'general/dropoff_delay'),
            ];
        }

        return $packageType;
    }

    /**
     * Get delivery data
     *
     * @return array
     */
    private function getDeliveryData(): array
    {
        $carriersPath   = $this->get_carriers();
        $myParcelConfig = [];

        foreach ($carriersPath as $carrier) {
            $basePrice        = $this->helper->getBasePrice();
            $morningFee       = $this->helper->getMethodPrice($carrier[self::SELECT_CARRIER_PATH], 'morning/fee');
            $eveningFee       = $this->helper->getMethodPrice($carrier[self::SELECT_CARRIER_PATH], 'evening/fee');
            $signatureFee     = $this->helper->getMethodPrice($carrier[self::SELECT_CARRIER_PATH], 'delivery/signature_fee', false);
            $onlyRecipientFee = $this->helper->getMethodPrice($carrier[self::SELECT_CARRIER_PATH], 'delivery/only_recipient_fee', false);

            $myParcelConfig["carrierSettings"][$carrier[self::SELECT_CARRIER_ARRAY]] = [
                'allowDeliveryOptions' => $this->package->deliveryOptionsDisabled ? false : $this->helper->getBoolConfig($carrier[self::SELECT_CARRIER_PATH], 'delivery/active'),
                'allowSignature'       => $this->helper->getBoolConfig($carrier[self::SELECT_CARRIER_PATH], 'delivery/signature_active'),
                'allowOnlyRecipient'   => $this->helper->getBoolConfig($carrier[self::SELECT_CARRIER_PATH], 'delivery/only_recipient_active'),
                'allowMorningDelivery' => $this->helper->getBoolConfig($carrier[self::SELECT_CARRIER_PATH], 'morning/active'),
                'allowEveningDelivery' => $this->helper->getBoolConfig($carrier[self::SELECT_CARRIER_PATH], 'evening/active'),
                'allowPickupLocations' => $this->package->deliveryOptionsDisabled ? false : $this->helper->getBoolConfig($carrier[self::SELECT_CARRIER_PATH], 'pickup/active'),

                'priceSignature'        => $signatureFee,
                'priceOnlyRecipient'    => $onlyRecipientFee,
                'priceStandardDelivery' => $basePrice,
                'priceMorningDelivery'  => $morningFee,
                'priceEveningDelivery'  => $eveningFee,

                'priceMorningSignature'          => ($morningFee + $signatureFee),
                'priceEveningSignature'          => ($eveningFee + $signatureFee),
                'priceSignatureAndOnlyRecipient' => ($basePrice + $signatureFee + $onlyRecipientFee),

                'pricePickup'                  => $this->helper->getMethodPrice($carrier[self::SELECT_CARRIER_PATH], 'pickup/fee'),
                'pricePackageTypeMailbox'      => $this->helper->getMethodPrice($carrier[self::SELECT_CARRIER_PATH], 'mailbox/fee', false),
                'pricePackageTypeDigitalStamp' => $this->helper->getMethodPrice($carrier[self::SELECT_CARRIER_PATH], 'digital_stamp/fee', false),

            ];
        }

        return $myParcelConfig;
    }

    /**
     * Get the a list of the shipping methods.
     *
     * @return string
     */
    private function getDeliveryMethods(): string
    {
        return $this->helper->getArrayConfig(Data::XML_PATH_GENERAL, 'shipping_methods/methods');
    }

    /**
     * Get the array of enabled carriers by checking if they have either delivery or pickup enabled.
     *
     * @return array
     */
    public function get_carriers(): array
    {
        $carriersSettings = [
            ['postnl', Data::XML_PATH_POSTNL_SETTINGS]
        ];

        foreach ($carriersSettings as $carrier) {
            if ($this->helper->getBoolConfig("{$carrier[self::SELECT_CARRIER_PATH]}", 'delivery/active') ||
                $this->helper->getBoolConfig("{$carrier[self::SELECT_CARRIER_PATH]}", 'pickup/active')
            ) {
                $carriers[] = $carrier;
            }
        }

        return $carriers;
    }

    /**
     * Get delivery options strings
     *
     * @return array
     */
    private function getDeliveryOptionsStrings()
    {
        return [
            'deliveryTitle'             => $this->helper->getGeneralConfig('delivery_titles/delivery_title'),
            'deliveryStandardTitle'     => $this->helper->getGeneralConfig('delivery_titles/standard_delivery_title'),
            'deliveryMorningTitle'      => $this->helper->getGeneralConfig('delivery_titles/morning_title'),
            'deliveryEveningTitle'      => $this->helper->getGeneralConfig('delivery_titles/evening_title'),
            'packageTypeMailbox'        => $this->helper->getGeneralConfig('delivery_titles/mailbox_title'),
            'packageTypeDigitalStamp'   => $this->helper->getGeneralConfig('delivery_titles/digital_stamp_title'),
            'pickupTitle'               => $this->helper->getGeneralConfig('delivery_titles/pickup_title'),
            'pickupLocationsListButton' => $this->helper->getGeneralConfig('delivery_titles/pickup_list_button_title'),
            'pickupLocationsMapButton'  => $this->helper->getGeneralConfig('delivery_titles/pickup_map_button_title'),
            'signatureTitle'            => $this->helper->getGeneralConfig('delivery_titles/signature_title'),
            'onlyRecipientTitle'        => $this->helper->getGeneralConfig('delivery_titles/only_recipient_title'),
            'saturdayDeliveryTitle'     => $this->helper->getGeneralConfig('delivery_titles/saturday_title'),

            'wrongPostalCodeCity' => __('Postcode/city combination unknown'),
            'addressNotFound'     => __('Address details are not entered'),
            'closed'              => __('Closed'),
            'retry'               => __('Again'),
            'pickUpFrom'          => __('Pick up from'),
            'openingHours'        => __('Opening hours'),

            'cityText'       => __('City'),
            'postalCodeText' => __('Postcode'),
            'numberText'     => __('House number'),
            'city'           => __('City'),
            'postcode'       => __('Postcode'),
            'houseNumber'    => __('House number'),
        ];
    }

    /**
     * @param array       $carrier
     * @param string|null $country
     *
     * @return string
     */
    public function checkPackageType(array $carrier, string $country = null): string
    {
        $products = $this->cart->getAllItems();
        $country  = $country ?? $this->cart->getShippingAddress()->getCountryId();

        $this->package->setCurrentCountry($country);
        $this->package->setDigitalStampActive($this->helper->getBoolConfig($carrier[self::SELECT_CARRIER_PATH], 'digital_stamp/active'));
        $this->package->setMailboxActive($this->helper->getBoolConfig($carrier[self::SELECT_CARRIER_PATH], 'mailbox/active'));
        $this->package->setWeightFromQuoteProducts($products);

        return $this->package->selectPackageType($products);
    }

    /**
     * @param string $carrierPath
     * @param string $key
     *
     * @return int
     */
    public function getDropOffDelay(string $carrierPath, string $key): int
    {
        $products     = $this->cart->getAllItems();
        $productDelay = $this->package->getProductDropOffDelay($products);

        if (! $productDelay) {
            $productDelay = $this->helper->getIntegerConfig($carrierPath, $key);
        }

        return (int) $productDelay;
    }

    /**
     * @return $this
     */
    public function hideDeliveryOptionsForProduct()
    {
        $products = $this->cart->getAllItems();
        $this->package->productWithoutDeliveryOptions($products);

        return $this;
    }
}
