<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Event;

use Shopware\Core\Framework\Event\EventData\MailRecipientStruct;
use Shopware\Core\Framework\Log\Package;

#[Package('services-settings')]
#[IsFlowEventAware]
interface MailAware extends SalesChannelAware
{
    public const MAIL_STRUCT = 'mailStruct';

    public function getMailStruct(): MailRecipientStruct;
}
