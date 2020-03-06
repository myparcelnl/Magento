<?php

namespace MyParcelNL\Magento\Ui\Component\Listing\Column;

use Magento\Sales\Model\Order;
use Magento\Ui\Component\Listing\Columns\Column;
use MyparcelNL\Sdk\src\Helper\TrackTraceUrl;

class TrackNumber extends Column
{
    /**
     * Script tag to unbind the click event from the td wrapping the barcode link.
     */
    const SCRIPT_UNBIND_CLICK = "<script type='text/javascript'>jQuery('.myparcel-barcode-link').closest('td').unbind('click');</script>";

    /**
     * Set column MyParcel barcode to order grid
     *
     * @param array $dataSource
     *
     * @return array
     */
    public function prepareDataSource(array $dataSource): array
    {
        parent::prepareDataSource($dataSource);

        if (! isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        /**
         * @var Order                  $order
         * @var Order\Shipment\Track[] $tracks
         */
        foreach ($dataSource['data']['items'] as & $item) {
            $addressParts = explode(",", $item['shipping_address']);

            if (count($addressParts) === 4) {
                [$company, $street, $city, $postalCode] = $addressParts;
            } else {
                [$street, $city, $postalCode] = $addressParts;
            }

            // Stop if either the barcode/status or postal code is missing.
            if (! $item['track_number'] || ! $postalCode) {
                continue;
            }

            $trackNumber = $item['track_number'];
            $data = $this->getData('name');

            // Only render the T&T as a link and add the script to remove the click handler if it's actually a barcode.
            if (strpos($trackNumber, '3S') === 0) {
                $trackTrace  = (new TrackTraceUrl())->create($trackNumber, $postalCode);

                $item[$data] = "<a class=\"myparcel-barcode-link\" target=\"_blank\" href=\"$trackTrace\">$trackNumber</a>";
                $item[$data] .= self::SCRIPT_UNBIND_CLICK;
            }
        }

        return $dataSource;
    }
}
