<?php

namespace MyParcelNL\Magento\Model\Quote;

use Magento\Checkout\Model\Cart;
use Magento\Checkout\Model\Session;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;
use MyParcelNL\Magento\Model\Source\PriceDeliveryOptionsView;
use MyParcelNL\Magento\Service\Config\ConfigService;
use MyParcelNL\Magento\Service\Costs\DeliveryCostsService;
use MyParcelNL\Sdk\src\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

class Checkout
{
    private const PLATFORM             = 'myparcel';
    private const PACKAGE_TYPE_MAILBOX = 'mailbox';

    /**
     * @var \MyParcelNL\Magento\Helper\Checkout
     */
    private $configService;

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
     * @var mixed
     */
    private $country;
    private DeliveryCostsService $deliveryCostsService;

    /**
     * Checkout constructor.
     *
     * @param Session $session
     * @param Cart $cart
     * @param ConfigService $configService
     * @param PackageRepository $package
     * @param StoreManagerInterface $currency
     */
    public function __construct(
        Session $session,
        Cart $cart,
        ConfigService $configService,
        DeliveryCostsService $deliveryCostsService,
        PackageRepository $package,
        StoreManagerInterface $currency
    ) {
        $this->configService   = $configService;
        $this->deliveryCostsService= $deliveryCostsService;
        $this->quoteId  = $session->getQuoteId();
        $this->cart     = $cart->getQuote();
        $this->package  = $package;
        $this->currency = $currency;
    }

    /**
     * Get settings for MyParcel delivery options
     *
     * @param array $forAddress associative array holding the latest address from the client
     *
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getDeliveryOptions(array $forAddress = []): array
    {
        $this->hideDeliveryOptionsForProduct();

        if (isset($forAddress['countryId'])) {
            $this->country = $forAddress['countryId'];
        }

        $packageType = $this->getPackageType();

        $data = [
            /* the 'method' string here is actually the carrier_code of the method */
            'methods'    => explode(',', $this->configService->getGeneralConfig('shipping_methods/methods') ?? ''),
            'config'     => array_merge(
                $this->getGeneralData(),
                $this->getDeliveryData($packageType),
                ['packageType' => $packageType]
            ),
            'strings'    => $this->getDeliveryOptionsStrings(),
            'forAddress' => $forAddress,
        ];

        return [
            'root' => [
                'version' => $this->configService->getVersion(),
                'data'    => $data,
            ],
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
            'carriers'                   => $this->getActiveCarriers(),
            'currency'                   => $this->currency->getStore()->getCurrentCurrency()->getCode(),
            'allowShowDeliveryDate'      => $this->configService->getBoolConfig(ConfigService::XML_PATH_GENERAL, 'date_settings/allow_show_delivery_date'),
            'deliveryDaysWindow'         => $this->configService->getIntegerConfig(ConfigService::XML_PATH_GENERAL, 'date_settings/deliverydays_window'),
            'dropOffDelay'               => $this->getDropOffDelay(ConfigService::XML_PATH_GENERAL, 'date_settings/dropoff_delay'),
            'pickupLocationsDefaultView' => $this->configService->getConfigValue(ConfigService::XML_PATH_GENERAL . 'shipping_methods/pickup_locations_view'),
            'showPriceSurcharge'         => $this->configService->getConfigValue(ConfigService::XML_PATH_GENERAL . 'shipping_methods/delivery_options_prices') === PriceDeliveryOptionsView::SURCHARGE,
            'basePrice'                  => $this->deliveryCostsService->getBasePrice(),
        ];
    }

    /**
     * Get general data
     *
     * @return string
     */
    private function getPackageType(): string
    {
        $packageType    = AbstractConsignment::PACKAGE_TYPE_PACKAGE_NAME;
        $activeCarriers = $this->getActiveCarriers();

        foreach ($activeCarriers as $carrier) {
            $tentativePackageType = $this->checkPackageType($carrier);

            switch ($tentativePackageType) {
                case AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP_NAME:
                    return AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP_NAME;
                case AbstractConsignment::PACKAGE_TYPE_MAILBOX_NAME:
                    $packageType = AbstractConsignment::PACKAGE_TYPE_MAILBOX_NAME;
                    break;
                case AbstractConsignment::PACKAGE_TYPE_PACKAGE_SMALL_NAME:
                    return AbstractConsignment::PACKAGE_TYPE_PACKAGE_SMALL_NAME;
            }
        }

        return $packageType;
    }

    /**
     * Get delivery data
     *
     * @param string|null $packageType
     * @return array
     */
    private function getDeliveryData(?string $packageType = null): array
    {
        $myParcelConfig = [];
        $activeCarriers = $this->getActiveCarriers();
        $carrierPaths   = ConfigService::CARRIERS_XML_PATH_MAP;
        $showTotalPrice = $this->configService->getConfigValue(ConfigService::XML_PATH_GENERAL . 'shipping_methods/delivery_options_prices') === PriceDeliveryOptionsView::TOTAL;

        foreach ($activeCarriers as $carrier) {
            $carrierPath = $carrierPaths[$carrier];

            try {
                $consignment = ConsignmentFactory::createByCarrierName($carrier);
                $consignment->setPackageType(AbstractConsignment::PACKAGE_TYPE_PACKAGE);
            } catch (\Throwable $ex) {
                $this->configService->log(sprintf('getDeliveryData: Could not create default consignment for %s', $carrier));
                continue;
            }

            $canHaveDigitalStamp  = $consignment->canHavePackageType(AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP_NAME);
            $canHaveMailbox       = $consignment->canHavePackageType(AbstractConsignment::PACKAGE_TYPE_MAILBOX_NAME);
            $canHavePackageSmall  = $consignment->canHavePackageType(AbstractConsignment::PACKAGE_TYPE_PACKAGE_SMALL_NAME);
            $canHaveSameDay       = $consignment->canHaveExtraOption(AbstractConsignment::SHIPMENT_OPTION_SAME_DAY_DELIVERY);
            $canHaveMonday        = $consignment->canHaveExtraOption(AbstractConsignment::EXTRA_OPTION_DELIVERY_MONDAY);
            $canHaveMorning       = $consignment->canHaveDeliveryType(AbstractConsignment::DELIVERY_TYPE_MORNING_NAME);
            $canHaveEvening       = $consignment->canHaveDeliveryType(AbstractConsignment::DELIVERY_TYPE_EVENING_NAME);
            $canHaveSignature     = $consignment->canHaveShipmentOption(AbstractConsignment::SHIPMENT_OPTION_SIGNATURE);
            $canHaveOnlyRecipient = $consignment->canHaveShipmentOption(AbstractConsignment::SHIPMENT_OPTION_ONLY_RECIPIENT);
            $canHaveAgeCheck      = $consignment->canHaveShipmentOption(AbstractConsignment::SHIPMENT_OPTION_AGE_CHECK);
            $canHavePickup        = $consignment->canHaveDeliveryType(AbstractConsignment::DELIVERY_TYPE_PICKUP_NAME);

            $mailboxFee = 0;
            if ($canHaveMailbox) {
                $cc = $this->country ?? $this->cart->getShippingAddress()->getCountryId() ?? AbstractConsignment::CC_NL;
                if (AbstractConsignment::CC_NL === $cc) {
                    $mailboxFee = $this->deliveryCostsService->getMethodPrice($carrierPath, 'mailbox/fee', false);
                } else {
                    $mailboxFee = $this->deliveryCostsService->getMethodPrice($carrierPath, 'mailbox/international_fee', false);
                }
            }

            $basePrice        = $this->deliveryCostsService->getBasePrice($consignment);
            $mondayFee        = $canHaveMonday ? $this->deliveryCostsService->getMethodPrice($carrierPath, 'delivery/monday_fee') : 0;
            $morningFee       = $canHaveMorning ? $this->deliveryCostsService->getMethodPrice($carrierPath, 'morning/fee') : 0;
            $eveningFee       = $canHaveEvening ? $this->deliveryCostsService->getMethodPrice($carrierPath, 'evening/fee') : 0;
            $sameDayFee       = $canHaveSameDay ? (int) $this->deliveryCostsService->getMethodPrice($carrierPath, 'delivery/same_day_delivery_fee') : 0;
            $signatureFee     = $canHaveSignature ? $this->deliveryCostsService->getMethodPrice($carrierPath, 'delivery/signature_fee', false) : 0;
            $onlyRecipientFee = $canHaveOnlyRecipient ? $this->deliveryCostsService->getMethodPrice($carrierPath, 'delivery/only_recipient_fee', false) : 0;
            $isAgeCheckActive = $canHaveAgeCheck && $this->isAgeCheckActive($carrierPath);

            $allowPickup           = $this->configService->getBoolConfig($carrierPath, 'pickup/active');
            $allowStandardDelivery = $this->configService->getBoolConfig($carrierPath, 'delivery/active');
            $allowMorningDelivery  = ! $isAgeCheckActive && $canHaveMorning && $this->configService->getBoolConfig($carrierPath, 'morning/active');
            $allowEveningDelivery  = ! $isAgeCheckActive && $canHaveEvening && $this->configService->getBoolConfig($carrierPath, 'evening/active');
            $allowDeliveryOptions  = ! $this->package->deliveryOptionsDisabled
                && ($allowPickup || $allowStandardDelivery || $allowMorningDelivery || $allowEveningDelivery);

            if ($allowDeliveryOptions && $packageType === AbstractConsignment::PACKAGE_TYPE_MAILBOX_NAME) {
                $this->package->setMailboxSettings($carrierPath);
                $allowDeliveryOptions = $this->configService->getBoolConfig($carrierPath, 'mailbox/active')
                    && $this->package->getMaxMailboxWeight() >= $this->package->getWeight();
            }

            $myParcelConfig['carrierSettings'][$carrier] = [
                'allowDeliveryOptions'  => $allowDeliveryOptions,
                'allowStandardDelivery' => $allowStandardDelivery,
                'allowSignature'        => $canHaveSignature && $this->configService->getBoolConfig($carrierPath, 'delivery/signature_active'),
                'allowOnlyRecipient'    => $canHaveOnlyRecipient && $this->configService->getBoolConfig($carrierPath, 'delivery/only_recipient_active'),
                'allowMorningDelivery'  => $allowMorningDelivery,
                'allowEveningDelivery'  => $allowEveningDelivery,
                'allowPickupLocations'  => $canHavePickup && $this->isPickupAllowed($carrierPath),
                'allowMondayDelivery'   => $canHaveMonday && $this->configService->getBoolConfig($carrierPath, 'delivery/monday_active'),
                'allowSameDayDelivery'  => $canHaveSameDay && $this->configService->getBoolConfig($carrierPath, 'delivery/same_day_delivery_active'),

                'dropOffDays'           => $this->getDropOffDays($carrierPath),

                'priceSignature'                       => $signatureFee,
                'priceOnlyRecipient'                   => $onlyRecipientFee,
                'priceStandardDelivery'                => $showTotalPrice ? $basePrice : 0,
                'priceMondayDelivery'                  => $mondayFee,
                'priceMorningDelivery'                 => $morningFee,
                'priceEveningDelivery'                 => $eveningFee,
                'priceSameDayDelivery'                 => $sameDayFee,
                'priceSameDayDeliveryAndOnlyRecipient' => $sameDayFee + $onlyRecipientFee,

                'priceMorningSignature'          => ($morningFee + $signatureFee),
                'priceEveningSignature'          => ($eveningFee + $signatureFee),
                'priceSignatureAndOnlyRecipient' => ($basePrice + $signatureFee + $onlyRecipientFee),

                'pricePickup'                  => $canHavePickup ? $this->deliveryCostsService->getMethodPrice($carrierPath, 'pickup/fee') : 0,
                'pricePackageTypeMailbox'      => $mailboxFee,
                'pricePackageTypeDigitalStamp' => $canHaveDigitalStamp ? $this->deliveryCostsService->getMethodPrice($carrierPath, 'digital_stamp/fee', false) : 0,
                'pricePackageTypePackageSmall' => $canHavePackageSmall ? $this->deliveryCostsService->getMethodPrice($carrierPath, 'package_small/fee', false) : 0,
            ];
        }

        return $myParcelConfig;
    }

    /**
     * Get the array of enabled carriers by checking if they have either delivery or pickup enabled.
     *
     * @return array
     */
    public function getActiveCarriers(): array
    {
        $carriers = [];
        foreach (ConfigService::CARRIERS_XML_PATH_MAP as $carrier => $path) {
            if ($this->configService->getBoolConfig($path, 'delivery/active') ||
                $this->configService->getBoolConfig($path, 'pickup/active')
            ) {
                $carriers[] = $carrier;
            }
        }

        return $carriers;
    }

    private function getDropOffDays(string $carrierPath): array {
        $dropOffDays = [];
        for ($weekday = 0; $weekday < 7; $weekday++) {
            $cutoffTimeSameDay = $this->configService->getTimeConfig($carrierPath, "drop_off_days/cutoff_time_same_day_$weekday");
            $sameDayTimeEntry = $cutoffTimeSameDay ? ['cutoffTimeSameDay' => $cutoffTimeSameDay] : [];
            if ($this->configService->getBoolConfig($carrierPath, "drop_off_days/day_{$weekday}_active")) {
                $dropOffDays[] = (object) array_merge([
                    'weekday' => $weekday,
                    'cutoffTime' => $this->configService->getTimeConfig($carrierPath, "drop_off_days/cutoff_time_$weekday"),
                ], $sameDayTimeEntry);
            }
        }

        return $dropOffDays;
    }

    /**
     * Get delivery options strings
     *
     * @return array
     */
    private function getDeliveryOptionsStrings(): array
    {
        return [
            'deliveryTitle'             => $this->configService->getGeneralConfig('delivery_titles/delivery_title') ?: __('delivery_title'),
            'deliveryStandardTitle'     => $this->configService->getGeneralConfig('delivery_titles/standard_delivery_title') ?: __('standard_delivery'),
            'deliveryMorningTitle'      => $this->configService->getGeneralConfig('delivery_titles/morning_title') ?: __('morning_title'),
            'deliveryEveningTitle'      => $this->configService->getGeneralConfig('delivery_titles/evening_title') ?: __('evening_title'),
            'deliveryPickupTitle'       => $this->configService->getGeneralConfig('delivery_titles/pickup_title') ?: __('pickup_title'),
            'pickupTitle'               => $this->configService->getGeneralConfig('delivery_titles/pickup_title') ?: __('pickup_title'),
            'deliverySameDayTitle'      => $this->configService->getGeneralConfig('delivery_titles/same_day_title') ?: __('same_day_title'),
            'hideSenderTitle'           => $this->configService->getGeneralConfig('delivery_titles/hide_sender_title') ?: __('hide_sender_title'),
            'list'                      => $this->configService->getGeneralConfig('delivery_titles/pickup_list_button_title') ?: __('list_title'),
            'map'                       => $this->configService->getGeneralConfig('delivery_titles/pickup_map_button_title') ?: __('map_title'),
            'packageTypeMailbox'        => $this->configService->getGeneralConfig('delivery_titles/mailbox_title') ?: __('mailbox_title'),
            'packageTypeDigitalStamp'   => $this->configService->getGeneralConfig('delivery_titles/digital_stamp_title') ?: __('digital_stamp_title'),
            'packageTypePackageSmall'   => $this->configService->getGeneralConfig('delivery_titles/package_small_title') ?: __('packet_title'),
            'signatureTitle'            => $this->configService->getGeneralConfig('delivery_titles/signature_title') ?: __('signature_title'),
            'onlyRecipientTitle'        => $this->configService->getGeneralConfig('delivery_titles/only_recipient_title') ?: __('only_recipient_title'),
            'saturdayDeliveryTitle'     => $this->configService->getGeneralConfig('delivery_titles/saturday_title') ?: __('saturday_delivery_title'),

            'wrongPostalCodeCity' => __('Postcode/city combination unknown'),
            'addressNotFound'     => __('Address details are not entered'),
            'closed'              => __('Closed'),
            'discount'            => __('Discount'),
            'ecoFriendly'         => __('Most sustainable'),
            'free'                => __('Free'),
            'from'                => __('From'),
            'retry'               => __('Again'),
            'parcelLocker'        => __('Parcel locker'),
            'pickUpFrom'          => __('Pick up from'),
            'openingHours'        => __('Opening hours'),
            'showMoreHours'       => __('Show more opening hours'),
            'showMoreLocations'   => __('Show more locations'),

            'error3212'         => __('{field} is required.'),
            'error3501'         => __('Address not found.'),
            'error3505'         => __('Postal code is invalid for the current country.'),

            'cityText'       => __('City'),
            'city'           => __('City'),
            'cc'             => __('Country'),
            'houseNumber'    => __('House number'),
            'numberText'     => __('House number'),
            'postalCode'     => __('Postal code'),
            'street'         => __('Street'),
        ];
    }

    /**
     * @param string      $carrier
     * @param string|null $country
     *
     * @return string
     */
    public function checkPackageType(string $carrier, ?string $country = null): string
    {
        try {
            $consignment = ConsignmentFactory::createByCarrierName($carrier);
        } catch (\Throwable $e) {
            $this->configService->log(sprintf('checkPackageType: Could not create default consignment for %s', $carrier));

            return AbstractConsignment::DEFAULT_PACKAGE_TYPE_NAME;
        }

        $carrierPath         = ConfigService::CARRIERS_XML_PATH_MAP[$carrier];
        $products            = $this->cart->getAllItems();
        $country             = $country ?? $this->country ?? $this->cart->getShippingAddress()->getCountryId();
        $canHaveDigitalStamp = $consignment->canHavePackageType(AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP_NAME);
        $canHaveMailbox      = $consignment->canHavePackageType(AbstractConsignment::PACKAGE_TYPE_MAILBOX_NAME);
        $canHavePackageSmall = $consignment->canHavePackageType(AbstractConsignment::PACKAGE_TYPE_PACKAGE_SMALL_NAME);

        $this->package->setMailboxSettings($carrierPath);
        $this->package->setDigitalStampSettings($carrierPath);
        $this->package->setPackageSmallSettings($carrierPath);

        if ($canHaveMailbox) {
            if (AbstractConsignment::CC_NL === $country) {
                $this->package->setMailboxActive($this->configService->getBoolConfig($carrierPath, 'mailbox/active'));
            } else {
                $this->package->setMailboxActive($this->configService->getBoolConfig($carrierPath, 'mailbox/international_active'));
            }
        } else {
            $this->package->setMailboxActive(false);
        }

        $this->package->setCurrentCountry($country);
        $this->package->setDigitalStampActive($canHaveDigitalStamp && $this->configService->getBoolConfig($carrierPath, 'digital_stamp/active'));
        $this->package->setPackageSmallActive($canHavePackageSmall && $this->configService->getBoolConfig($carrierPath, 'package_small/active'));

        return $this->package->selectPackageType($products, $carrierPath);
    }

    /**
     * @param string $carrierPath
     *
     * @return bool
     */
    public function isAgeCheckActive(string $carrierPath): bool
    {
        $products = $this->cart->getAllItems();

        return $this->package->getAgeCheck($products, $carrierPath);
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
            $productDelay = $this->configService->getIntegerConfig($carrierPath, $key);
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

    /**
     * @param  string $carrier
     *
     * @return bool
     */
    private function isPickupAllowed(string $carrier): bool
    {
        $isMailboxPackage     = self::PACKAGE_TYPE_MAILBOX === $this->getPackageType();
        $pickupEnabled        = $this->configService->getBoolConfig($carrier, 'pickup/active');
        $showPickupForMailbox = $this->configService->getBoolConfig($carrier, 'mailbox/pickup_mailbox');
        $showPickup           = ! $isMailboxPackage || $showPickupForMailbox;

        return ! $this->package->deliveryOptionsDisabled && $pickupEnabled && $showPickup;
    }
}
