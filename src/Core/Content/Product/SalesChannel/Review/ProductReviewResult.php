<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product\SalesChannel\Review;

use Shopware\Core\Content\Product\Aggregate\ProductReview\ProductReviewCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Log\Package;

/**
 * @extends EntitySearchResult<ProductReviewCollection>
 *
 * @deprecated tag:v6.6.0 use Shopware\Core\Content\Product\SalesChannel\Review\ProductReviewLoaderResult instead
 */
#[Package('inventory')]
class ProductReviewResult extends ProductReviewLoaderResult
{
}
