<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Services\Normalizer;

use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\src\Model\Consignment\PostNLConsignment;

class ConsignmentNormalizer
{
    /**
     * @var array|null
     */
    private $data;

    public function __construct(?array $data)
    {
        $this->data = $data;
    }

    public function normalize(): array
    {
        $data                 = $this->data;
        $data['carrier']      = $data['carrier'] ?? PostNLConsignment::CARRIER_NAME;
        $data['deliveryType'] = $data['deliveryType'] ?? AbstractConsignment::DELIVERY_TYPE_STANDARD_NAME;
        $data['package_type'] = $data['package_type'] ?? AbstractConsignment::PACKAGE_TYPE_PACKAGE;

        return $data;
    }
}
