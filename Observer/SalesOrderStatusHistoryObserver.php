<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Observer;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Status\History;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\src\Collection\Fulfilment\OrderNotesCollection;
use MyParcelNL\Sdk\src\Model\Fulfilment\OrderNote;

class SalesOrderStatusHistoryObserver implements ObserverInterface
{
    private Config        $configService;
    private ObjectManager $objectManager;

    public function __construct() {
        $this->objectManager = ObjectManager::getInstance();
        $this->configService = $this->objectManager->get(Config::class);
    }

    /**
     * @param  \Magento\Framework\Event\Observer $observer
     *
     * @return $this
     */
    public function execute(Observer $observer): self
    {
        /** @var \Magento\Sales\Model\Order\Status\History $history */
        $history = $observer->getData()['status_history'] ?? null;

        if (! is_a($history, History::class)
            || ! $history->getComment()
            || ! $history->getOrder()
        ) {
            return $this;
        }

        /** @var Order $magentoOrder */
        $magentoOrder = $this->objectManager->create(Order::class)
            ->loadByIncrementId($history->getOrder()->getIncrementId());

        $uuid = $magentoOrder->getData('myparcel_uuid');

        if (! $uuid) {
            return $this;
        }

        (new OrderNotesCollection())->setApiKey($this->configService->getApiKey())
            ->push(
                new OrderNote([
                        'orderUuid' => $uuid,
                        'note'      => $history->getComment(),
                        'author'    => 'webshop',
                    ]
                )
            )
            ->save();

        return $this;
    }
}
