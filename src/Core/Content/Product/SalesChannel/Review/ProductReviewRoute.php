<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product\SalesChannel\Review;

use Shopware\Core\Content\Product\Aggregate\ProductReview\ProductReviewCollection;
use Shopware\Core\Framework\Adapter\Cache\Event\AddCacheTagEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Cache\EntityCacheKeyGenerator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\FilterAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Route(defaults: ['_routeScope' => ['store-api']])]
#[Package('inventory')]
class ProductReviewRoute extends AbstractProductReviewRoute
{
    /**
     * @internal
     *
     * @param EntityRepository<ProductReviewCollection> $productReviewRepository
     */
    public function __construct(
        private readonly EntityRepository $productReviewRepository,
        private readonly EventDispatcherInterface $dispatcher
    ) {
    }

    public static function buildName(string $parentId): string
    {
        return EntityCacheKeyGenerator::buildProductTag($parentId);
    }

    public function getDecorated(): AbstractProductReviewRoute
    {
        throw new DecorationPatternException(self::class);
    }

    #[Route(path: '/store-api/product/{productId}/reviews', name: 'store-api.product-review.list', methods: ['POST'], defaults: ['_entity' => 'product_review'])]
    public function load(string $productId, Request $request, SalesChannelContext $context, Criteria $criteria): ProductReviewRouteResponse
    {
        $criteria->setTitle('product-review-route');
        $criteria->addFilter(
            new MultiFilter(MultiFilter::CONNECTION_AND, [
                $this->createActiveOrCustomerReviewFilter($context),
                new MultiFilter(MultiFilter::CONNECTION_OR, [
                    new EqualsFilter('product.id', $productId),
                    new EqualsFilter('product.parentId', $productId),
                ]),
            ])
        );

        $this->handlePointsAggregation($request, $criteria, $context);

        $result = $this->productReviewRepository->search($criteria, $context->getContext());

        return new ProductReviewRouteResponse($result);
    }

    private function handlePointsAggregation(Request $request, Criteria $criteria, SalesChannelContext $context): void
    {
        $points = $request->get('points', []);
        if (\is_array($points) && \count($points) > 0) {
            $pointFilter = [];
            foreach ($points as $point) {
                $pointFilter[] = new RangeFilter('points', [
                    'gte' => $point - 0.5,
                    'lt' => $point + 0.5,
                ]);
            }

            $criteria->addPostFilter(new MultiFilter(MultiFilter::CONNECTION_OR, $pointFilter));
        }

        $criteria->addAggregation(
            new FilterAggregation(
                'customer-login-filter',
                new TermsAggregation('ratingMatrix', 'points'),
                [$this->createActiveOrCustomerReviewFilter($context)]
            )
        );
    }

    private function createActiveOrCustomerReviewFilter(SalesChannelContext $context): Filter
    {
        $customer = $context->getCustomer();
        if ($customer === null) {
            return new EqualsFilter('status', true);
        }

        return new MultiFilter(MultiFilter::CONNECTION_OR, [
            new EqualsFilter('status', true),
            new EqualsFilter('customerId', $customer->getId()),
        ]);
    }
}
