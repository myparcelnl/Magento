<?php

namespace MyParcelNL\Magento\Model\Checkout;

use Exception;
use Magento\Checkout\Model\Session;
use MyParcelNL\Magento\Api\ShippingMethodsInterface;
use MyParcelNL\Sdk\src\Factory\DeliveryOptionsAdapterFactory;

/**
 * @since 3.0.0
 */
class ShippingMethods implements ShippingMethodsInterface
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    private Session $session;

    /**
     * ShippingMethods constructor.
     *
     * @param \Magento\Checkout\Model\Session $session
     */
    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    /**
     * @param mixed $deliveryOptions indexed array holding 1 deliveryOptions object
     *
     * @return array[]
     * @throws Exception
     */
    public function getFromDeliveryOptions($deliveryOptions): array
    {
        if (! $deliveryOptions[0]) {
            return [];
        }

        try {
            //$shipping = new DeliveryOptionsToShippingMethods($deliveryOptions[0]);

            $response = [
                'root' => [
                    'element_id' => 'myparcel-miep',//TODO get the user inputted title here //$shipping->getShippingMethod(),
                ],
            ];
        } catch (Exception $e) {
            $response = [
                'code'    => '422',
                'message' => $e->getMessage(),
            ];
        }

        $response[] = $this->persistDeliveryOptions($deliveryOptions[0]);

        return $response;
    }

    /**
     * @param array $deliveryOptions
     *
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function persistDeliveryOptions(array $deliveryOptions): array
    {
        $quote = $this->session->getQuote();
        $adapted = DeliveryOptionsAdapterFactory::create($deliveryOptions);
        $quote->addData(['myparcel_delivery_options' => json_encode($adapted->toArray())]);
        $quote->save();

        return [
            'delivery_options' => $deliveryOptions,
            'message' => "Delivery options persisted in quote {$quote->getId()}",
        ];
    }
}
