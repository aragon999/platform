<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Promotion\Cart;

use Shopware\Core\Checkout\Promotion\PromotionEntity;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Struct\Struct;

#[Package('buyers-experience')]
class CartPromotionsDataDefinition extends Struct
{
    /**
     * @var array<string, array<PromotionEntity>>
     */
    private array $promotions = [];

    /**
     * Adds a list of promotions to the existing list of automatic promotions.
     *
     * @deprecated tag:v6.7.0 - Will be removed use addPromotions instead
     *
     * @param array<PromotionEntity> $promotions
     */
    public function addAutomaticPromotions(array $promotions): void
    {
        Feature::triggerDeprecationOrThrow(
            'v6.7.0.0',
            Feature::deprecatedMethodMessage(__CLASS__, __METHOD__, 'v6.7.0.0')
        );

        $this->addPromotions('', $promotions);
    }

    /**
     * Gets all added automatic promotions.
     *
     * @deprecated tag:v6.7.0 - Will be removed without replacement as the method is not used
     *
     * @return array<PromotionEntity>
     */
    public function getAutomaticPromotions(): array
    {
        Feature::triggerDeprecationOrThrow(
            'v6.7.0.0',
            Feature::deprecatedMethodMessage(__CLASS__, __METHOD__, 'v6.7.0.0')
        );

        return $this->promotions[''];
    }

    /**
     * Gets all added code promotions
     *
     * @deprecated tag:v6.7.0 - Will be removed without replacement as the method is not used
     *
     * @return array<string, array<PromotionEntity>>
     */
    public function getCodePromotions(): array
    {
        Feature::triggerDeprecationOrThrow(
            'v6.7.0.0',
            Feature::deprecatedMethodMessage(__CLASS__, __METHOD__, 'v6.7.0.0')
        );

        $codePromotions = $this->promotions;
        unset($codePromotions['']);

        return $codePromotions;
    }

    /**
     * Adds the provided list of promotions to the existing list of promotions for this code.
     *
     * @deprecated tag:v6.7.0 - Will be removed use addPromotions instead
     *
     * @param array<PromotionEntity> $promotions a list of promotion entities for this code
     */
    public function addCodePromotions(string $code, array $promotions): void
    {
        Feature::triggerDeprecationOrThrow(
            'v6.7.0.0',
            Feature::deprecatedMethodMessage(__CLASS__, __METHOD__, 'v6.7.0.0')
        );

        $this->addPromotions($code, $promotions);
    }

    public function addPromotion(string $code, PromotionEntity $promotion): void
    {
        if (!isset($this->promotions[$code])) {
            $this->promotions[$code] = [$promotion];

            return;
        }

        $this->promotions[$code][] = $promotion;
    }

    /**
     * @param array<PromotionEntity> $promotions
     */
    public function addPromotions(string $code, array $promotions): void
    {
        if (!\array_key_exists($code, $this->promotions)) {
            $this->promotions[$code] = $promotions;

            return;
        }

        $this->promotions[$code] = array_merge($this->promotions[$code], $promotions);
    }

    /**
     * @return \Generator<string, PromotionEntity>
     */
    public function iteratePromotions(): \Generator
    {
        foreach ($this->promotions as $code => $promotions) {
            foreach ($promotions as $promotion) {
                yield $code => $promotion;
            }
        }
    }

    /**
     * @param array<string> $codes
     */
    public function removeNonExistingCodes(array $codes)
    {
        // TODO: Does it do the same as before
        $nonExistingCodes = array_diff_key($this->promotions, array_flip($codes));
        // foreach ($nonExistingCodes as $code) {
        //     $this->removeCode((string) $code);
        // }
    }

    /**
     * TODO: Rename method?
     *
     * @param array<string> $codes
     */
    public function getPromotionsToFetch(array $codes)
    {
        return array_diff_key(array_flip($codes), $this->promotions);
    }

    /**
     * Gets a list of all added automatic and code promotions.
     *
     * @deprecated tag:v6.7.0 - Will be removed use addPromotions instead
     *
     * @return list<PromotionCodeTuple>
     */
    public function getPromotionCodeTuples(): array
    {
        Feature::triggerDeprecationOrThrow(
            'v6.7.0.0',
            Feature::deprecatedMethodMessage(__CLASS__, __METHOD__, 'v6.7.0.0')
        );

        $list = [];
        foreach ($this->iteratePromotions() as $code => $promotion) {
            $list[] = new PromotionCodeTuple($code, $promotion);
        }

        return $list;
    }

    /**
     * Gets if there is at least an empty list of promotions available for the provided code.
     */
    public function hasCode(string $code): bool
    {
        return \array_key_exists($code, $this->promotions);
    }

    /**
     * Removes the assigned promotions for the provided code, if existing.
     */
    public function removeCode(string $code): void
    {
        unset($this->promotions[$code]);
    }

    /**
     * Gets a flat list of all added codes.
     *
     * @return list<string>
     */
    public function getAllCodes(): array
    {
        Feature::triggerDeprecationOrThrow(
            'v6.7.0.0',
            Feature::deprecatedMethodMessage(__CLASS__, __METHOD__, 'v6.7.0.0')
        );

        $codes = array_keys($this->promotions);
        unset($codes['']);

        return $codes;
    }

    public function getApiAlias(): string
    {
        return 'cart_promotions_data_definition';
    }
}
