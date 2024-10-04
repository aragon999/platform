<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Promotion\Gateway\Template;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;

/**
 * @final
 *
 * @deprecated tag 6.7.0.0 - Will be removed without replacement, use PromotionGateway with the filters
 */
#[Package('buyers-experience')]
class PermittedGlobalCodePromotions extends MultiFilter
{
    /**
     * Gets a criteria for all permitted promotions of the provided
     * sales channel context, that do require a global code.
     *
     * @param list<string> $codes
     */
    public function __construct(
        array $codes,
        string $salesChannelId
    ) {
        Feature::triggerDeprecationOrThrow('v6.7.0.0', 'Will be removed without replacement, use PromotionGateway with the according filters');

        if (Feature::isActive('v6.7.0.0')) {
            parent::__construct(
                MultiFilter::CONNECTION_AND,
                [
                    new EqualsFilter('useCodes', true),
                    new EqualsFilter('useIndividualCodes', false),
                    new EqualsAnyFilter('code', $codes),
                ]
            );

            return;
        }

        parent::__construct(
            MultiFilter::CONNECTION_AND,
            [new EqualsFilter('active', true),
                new EqualsFilter('promotion.salesChannels.salesChannelId', $salesChannelId),
                new ActiveDateRange(),
                new EqualsFilter('useCodes', true),
                new EqualsFilter('useIndividualCodes', false),
                new EqualsAnyFilter('code', $codes),
            ]
        );
    }
}
