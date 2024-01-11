<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Event;

use Shopware\Core\Framework\Log\Package;

#[Package('services-settings')]
#[IsFlowEventAware]
interface SalesChannelAware
{
    public const SALES_CHANNEL_ID = 'salesChannelId';

    public function getSalesChannelId(): string;
}
