<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use MyParcelNL\Magento\Pdk\Hooks\Contract\MagentoHooksInterface;
use MyParcelNL\Magento\Pdk\Hooks\MessageManagerHook;
use MyParcelNL\Pdk\Facade\Pdk;
use RuntimeException;

final class MagentoHookService
{
    /**
     * @throws \Exception
     * @return void
     */
    public function applyAll(): void
    {
        foreach ($this->getHooks() as $class => $data) {
            /** @var \MyParcelNL\Magento\Pdk\Hooks\Contract\MagentoHooksInterface $hook */
            $instance = Pdk::get($class);

            if (! $instance instanceof MagentoHooksInterface) {
                throw new RuntimeException("Service {$class} does not implement MagentoHooksInterface");
            }

            $instance->apply($data ?? null);
        }
    }

    /**
     * @return class-string<\MyParcelNL\Magento\Pdk\Hooks\Contract\MagentoHooksInterface>[]
     */
    private function getHooks(): array
    {
        return [
            MessageManagerHook::class => [],
        ];
    }
}
