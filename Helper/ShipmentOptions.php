<?php

namespace MyParcelNL\Magento\Helper;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

class ShipmentOptions
{
    private const INSURANCE         = 'insurance';
    private const ONLY_RECIPIENT    = 'only_recipient';
    private const SAME_DAY_DELIVERY = 'same_day_delivery';
    private const SIGNATURE         = 'signature';
    private const COLLECT           = AbstractConsignment::SHIPMENT_OPTION_COLLECT;
    private const RECEIPT_CODE      = AbstractConsignment::SHIPMENT_OPTION_RECEIPT_CODE;
    private const RETURN            = 'return';
    private const AGE_CHECK         = 'age_check';
    private const LARGE_FORMAT      = 'large_format';
    private const HIDE_SENDER       = 'hide_sender';
    private const LABEL_DESCRIPTION = 'label_description';
    private const ORDER_NUMBER      = '%order_nr%';
    private const DELIVERY_DATE     = '%delivery_date%';
    private const PRODUCT_ID        = '%product_id%';
    private const PRODUCT_NAME      = '%product_name%';
    private const PRODUCT_QTY       = '%product_qty%';

    /**
     * @var string
     */
    private $carrier;

    /**
     * @var \MyParcelNL\Magento\Model\Source\DefaultOptions
     */
    private $defaultOptions;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var array
     */
    private $options;

    /**
     * @var \MyParcelNL\Magento\Helper\Data
     */
    private $helper;

    /**
     * @var \Magento\Sales\Model\Order
     */
    private $order;

    /**
     * @var string|null
     */
    private $cc;

    /**
     * @param \MyParcelNL\Magento\Model\Source\DefaultOptions $defaultOptions
     * @param \MyParcelNL\Magento\Helper\Data                 $helper
     * @param \Magento\Sales\Model\Order                      $order
     * @param \Magento\Framework\ObjectManagerInterface       $objectManager
     * @param string                                          $carrier
     * @param array                                           $options
     */
    public function __construct(
        DefaultOptions         $defaultOptions,
        Data                   $helper,
        Order                  $order,
        ObjectManagerInterface $objectManager,
        string                 $carrier,
        array                  $options = []
    ) {
        $this->defaultOptions = $defaultOptions;
        $this->helper         = $helper;
        $this->order          = $order;
        $this->objectManager  = $objectManager;
        $this->carrier        = $carrier;
        $this->options        = $options;
        $this->cc             = $order->getShippingAddress() ? $order->getShippingAddress()->getCountryId() : null;
    }

    /**
     * @return int
     */
    public function getInsurance(): int
    {
        return $this->options['insurance'] ?? $this->defaultOptions->getDefaultInsurance($this->carrier);
    }

    /**
     * @return bool
     */
    public function hasSignature(): bool
    {
        if (AbstractConsignment::CC_BE === $this->cc && $this->hasOnlyRecipient()) {
            return false;
        }

        $signatureFromOptions = self::getValueOfOptionWhenSet(self::SIGNATURE, $this->options);

        return $signatureFromOptions ?? $this->optionIsEnabled(self::SIGNATURE);
    }

    public function hasReceiptCode(): bool
    {
        $deliveryOptions = $this->order->getData(Checkout::FIELD_DELIVERY_OPTIONS) ?? [];
        $deliveryType    = $deliveryOptions['deliveryType'] ?? AbstractConsignment::DEFAULT_DELIVERY_TYPE;

        if (AbstractConsignment::CC_NL !== $this->cc
            || CarrierPostNL::NAME !== $this->carrier
            || AbstractConsignment::DELIVERY_TYPE_STANDARD !== $deliveryType
        ) {
            return false;
        }

        return self::getValueOfOptionWhenSet(self::RECEIPT_CODE, $this->options) ?? $this->optionIsEnabled(self::RECEIPT_CODE);
    }

    public function hasCollect(): bool
    {
        $collectFromOptions = self::getValueOfOptionWhenSet(self::COLLECT, $this->options);

        return $collectFromOptions ?? $this->optionIsEnabled(self::COLLECT);
    }

    /**
     * @return bool
     */
    public function hasOnlyRecipient(): bool
    {
        $onlyRecipientFromOptions = self::getValueOfOptionWhenSet(self::ONLY_RECIPIENT, $this->options);

        return $onlyRecipientFromOptions ?? $this->optionIsEnabled(self::ONLY_RECIPIENT);
    }

    /**
     * @return bool
     */
    public function hasSameDayDelivery(): bool
    {
        $sameDayFromOptions = self::getValueOfOptionWhenSet(self::SAME_DAY_DELIVERY, $this->options);

        return $sameDayFromOptions ?? $this->optionIsEnabled(self::SAME_DAY_DELIVERY);
    }

    /**
     * @return bool
     */
    public function hasReturn(): bool
    {
        $returnFromOptions = self::getValueOfOptionWhenSet(self::RETURN, $this->options);

        return $returnFromOptions ?? $this->optionIsEnabled(self::RETURN);
    }

    /**
     * @return bool
     */
    public function hasAgeCheck(): bool
    {
        if (AbstractConsignment::CC_NL !== $this->cc) {
            return false;
        }

        $ageCheckFromOptions  = self::getValueOfOptionWhenSet(self::AGE_CHECK, $this->options);
        $ageCheckOfProduct    = self::getAgeCheckFromProduct($this->order->getItems());
        $ageCheckFromSettings = $this->defaultOptions->hasDefaultOption($this->carrier, self::AGE_CHECK);

        return $ageCheckFromOptions ?? $ageCheckOfProduct ?? $ageCheckFromSettings;
    }

    public function hasHideSender(): bool
    {
        $hideSenderFromOptions = self::getValueOfOptionWhenSet(self::HIDE_SENDER, $this->options);

        return $hideSenderFromOptions ?? $this->optionIsEnabled(self::HIDE_SENDER);
    }

    /**
     * @param $products
     *
     * @return null|bool
     */
    public static function getAgeCheckFromProduct($products): ?bool
    {
        $hasAgeCheck = false;

        foreach ($products as $product) {
            $productAgeCheck = self::getAttributeValue(
                'catalog_product_entity_varchar',
                $product['product_id'],
                'age_check'
            );

            if (! isset($productAgeCheck) || '' === $productAgeCheck) {
                $hasAgeCheck = null;
            } elseif ('1' === $productAgeCheck) {
                return true;
            }
        }

        return $hasAgeCheck;
    }

    /**
     * @param string $tableName
     * @param string $entityId
     * @param string $column
     *
     * @return null|string
     */
    public static function getAttributeValue(string $tableName, string $entityId, string $column): ?string
    {
        $objectManager = ObjectManager::getInstance();
        $resource      = $objectManager->get(ResourceConnection::class);
        $connection    = $resource->getConnection();
        $attributeId   = self::getAttributeId(
            $connection,
            $resource->getTableName('eav_attribute'),
            $column
        );

        return self::getValueFromAttribute(
            $connection,
            $resource->getTableName($tableName),
            $attributeId,
            $entityId
        );
    }

    /**
     * @param        $connection
     * @param string $tableName
     * @param string $databaseColumn
     *
     * @return mixed
     */
    public static function getAttributeId($connection, string $tableName, string $databaseColumn): string
    {
        $sql = $connection
            ->select('entity_type_id')
            ->from($tableName)
            ->where('attribute_code = ?', 'myparcel_' . $databaseColumn);

        return $connection->fetchOne($sql);
    }

    /**
     * @param object $connection
     * @param string $tableName
     * @param string $attributeId
     * @param string $entityId
     *
     * @return string|null
     */
    public static function getValueFromAttribute(
        $connection,
        string $tableName,
        string $attributeId,
        string $entityId
    ): ?string
    {
        $sql = $connection
            ->select()
            ->from($tableName, ['value'])
            ->where('attribute_id = ?', $attributeId)
            ->where('entity_id = ?', $entityId);

        return $connection->fetchOne($sql);
    }

    /**
     * @param string $key
     * @param array  $options
     *
     * @return bool|null boolean value of the option named $key, or null when not set in $options
     */
    public static function getValueOfOptionWhenSet(string $key, array $options): ?bool
    {
        if (! isset($options[$key])) {
            return null;
        }

        return (bool) $options[$key];
    }

    /**
     * @return bool
     */
    public function hasLargeFormat(): bool
    {
        if (! in_array($this->cc, AbstractConsignment::EURO_COUNTRIES)) {
            return false;
        }

        $largeFormatFromOptions  = self::getValueOfOptionWhenSet(self::LARGE_FORMAT, $this->options);
        $largeFormatFromSettings = $this->defaultOptions->hasOptionSet(self::LARGE_FORMAT, $this->carrier);

        return $largeFormatFromOptions ?? $largeFormatFromSettings;
    }

    /**
     * @return string
     */
    public function getLabelDescription(): string
    {
        $checkoutData     = $this->order->getData('myparcel_delivery_options');
        $labelDescription = $this->helper->getGeneralConfig(
            'print/label_description',
            $this->order->getStoreId()
        );

        if (! $labelDescription) {
            return '';
        }

        $productInfo      = $this->getItemsCollectionByShipmentId($this->order->getId());
        $deliveryDate     = $checkoutData ? date('d-m-Y', strtotime($this->helper->convertDeliveryDate($checkoutData))) : null;
        $labelDescription = str_replace(
            [
                self::ORDER_NUMBER,
                self::DELIVERY_DATE,
                self::PRODUCT_ID,
                self::PRODUCT_NAME,
                self::PRODUCT_QTY,
            ],
            [
                $this->order->getIncrementId(),
                $this->helper->convertDeliveryDate($checkoutData) ? $deliveryDate : '',
                $this->getProductInfo($productInfo, 'product_id'),
                $this->getProductInfo($productInfo, 'name'),
                $productInfo ? round($this->getProductInfo($productInfo, 'qty')) : null,
            ],
            $labelDescription
        );

        return (string) $labelDescription;
    }

    /**
     * @param array  $productInfo
     * @param string $field
     *
     * @return string|null
     */
    private function getProductInfo(array $productInfo, string $field): ?string
    {
        if ($productInfo) {
            return $productInfo[0][$field];
        }

        return null;
    }

    /**
     * @param $shipmentId
     *
     * @return array
     */
    public function getItemsCollectionByShipmentId($shipmentId): array
    {
        /** @var ResourceConnection $connection */
        $connection = $this->objectManager->create(ResourceConnection::class);
        $conn       = $connection->getConnection();
        $select     = $conn->select()
                           ->from(
                               ['main_table' => $connection->getTableName('sales_shipment_item')]
                           )
                           ->where('main_table.parent_id=?', $shipmentId);
        return $conn->fetchAll($select);
    }

    /**
     * Get default value if option === null
     *
     * @param      $optionKey
     *
     * @return bool
     * @internal param $option
     */
    private function optionIsEnabled($optionKey): bool
    {
        if (! isset($this->options[$optionKey])) {
            return $this->defaultOptions->hasOptionSet($optionKey, $this->carrier);
        }

        return (bool) $this->options[$optionKey];
    }

    /**
     * @return array
     */
    public function getShipmentOptions(): array
    {
        return [
            self::INSURANCE         => $this->getInsurance(),
            self::RETURN            => $this->hasReturn(),
            self::ONLY_RECIPIENT    => $this->hasOnlyRecipient(),
            self::SIGNATURE         => $this->hasSignature(),
            self::COLLECT           => $this->hasCollect(),
            self::RECEIPT_CODE      => $this->hasReceiptCode(),
            self::AGE_CHECK         => $this->hasAgeCheck(),
            self::LARGE_FORMAT      => $this->hasLargeFormat(),
            self::LABEL_DESCRIPTION => $this->getLabelDescription(),
            self::SAME_DAY_DELIVERY => $this->hasSameDayDelivery(),
            self::HIDE_SENDER       => $this->hasHideSender(),
        ];
    }
}

