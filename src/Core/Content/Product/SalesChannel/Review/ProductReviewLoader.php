<?php declare(strict_types=1);

namespace Shopware\Core\Content\Product\SalesChannel\Review;

use Shopware\Core\Content\Product\Aggregate\ProductReview\ProductReviewCollection;
use Shopware\Core\Content\Product\Aggregate\ProductReview\ProductReviewEntity;
use Shopware\Core\Content\Product\SalesChannel\Review\Event\ProductReviewsLoadedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\FilterAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

#[Package('inventory')]
class ProductReviewLoader extends AbstractProductReviewLoader
{
    private const LIMIT = 10;
    private const DEFAULT_PAGE = 1;
    private const FILTER_LANGUAGE = 'filter-language';
    private const DEFAULT_SORTING = 'createdAt';
    private const ALLOWED_SORTINGS = ['createdAt', 'points'];

    /**
     * @internal
     */
    public function __construct(
        private readonly AbstractProductReviewRoute $productReviewRoute,
        private readonly SystemConfigService $systemConfigService,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {
    }

    public function getDecorated(): AbstractProductReviewLoader
    {
        throw new DecorationPatternException(self::class);
    }

    /**
     * Load reviews for one product. The request must contain the productId or the
     * parentId otherwise a ProductException is thrown
     *
     * @throws StorefrontException
     * @throws ProductException
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     */
    public function load(
        Request $request,
        SalesChannelContext $context,
        string $productId,
        ?string $productParentId = null,
    ): ProductReviewResult {
        $salesChannelId = $context->getSalesChannelId();
        if (!$this->systemConfigService->getBool('core.listing.showReview', $salesChannelId)) {
            throw StorefrontException::reviewNotActive();
        }

        $productId = $request->get('parentId') ?: $request->get('productId');
        if (!$productId) {
            throw ProductException::missingProductId();
        }

        $criteria = $this->createCriteria($request, $context);
        $reviews = $this->productReviewRoute
            ->load($productId, $request, $context, $criteria)
            ->getResult()
        ;

        $reviewResult = ProductReviewResult::createFrom($reviews);
        $reviewResult->setProductId($request->get('productId'));
        $reviewResult->setParentId($request->get('parentId'));

        $aggregation = $reviews->getAggregations()->get('ratingMatrix');
        if ($aggregation instanceof TermsResult) {
            $matrix = $aggregation->getBuckets();
        }

        $reviewResult->setMatrix(new RatingMatrix($matrix ?? []));
        $reviewResult->setCustomerReview($this->getCustomerReview($productId, $context));
        $reviewResult->setTotalReviews(
            $reviewResult->getMatrix()->getTotalReviewCount()
        );

        $this->eventDispatcher->dispatch(new ProductReviewsLoadedEvent($reviewResult, $context, $request));

        return $reviewResult;
    }

    private function createCriteria(Request $request, SalesChannelContext $context): Criteria
    {
        $limit = (int) $request->get('limit', self::LIMIT);
        $page = (int) $request->get('p', self::DEFAULT_PAGE);
        $offset = $limit * ($page - 1);

        $sort = (string) $request->get('sort', self::DEFAULT_SORTING);
        if (!\in_array($sort, self::ALLOWED_SORTINGS, true)) {
            $sort = self::DEFAULT_SORTING;
        }

        $criteria = new Criteria();
        $criteria
            ->setLimit($limit)
            ->setOffset($offset)
            ->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT)
            ->addSorting(new FieldSorting($sort, 'DESC'))
        ;

        if ($request->get('language') === self::FILTER_LANGUAGE) {
            $criteria->addPostFilter(
                new EqualsFilter('languageId', $context->getContext()->getLanguageId())
            );
        }

        return $criteria;
    }

    /**
     * get review by productId and customer
     * a customer should only create one review per product, so if there are more than one
     * review we only take one
     *
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     */
    private function getCustomerReview(string $productId, SalesChannelContext $context): ?ProductReviewEntity
    {
        $customer = $context->getCustomer();
        if ($customer === null) {
            return null;
        }

        $criteria = new Criteria();
        $criteria
            ->setLimit(1)
            ->setOffset(0)
            ->addFilter(new EqualsFilter('customerId', $customer->getId()))
        ;

        return $this->productReviewRoute
            ->load($productId, new Request(), $context, $criteria)
            ->getResult()
            ->first()
        ;
    }
}
