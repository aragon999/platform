<?php declare(strict_types=1);

namespace Shopware\Core\Profiling\Subscriber;

use Shopware\Core\Checkout\Cart\AbstractCartPersister;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Routing\Event\SalesChannelContextResolvedEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @internal
 */
#[Package('framework')]
class CartDataCollectorSubscriber extends AbstractDataCollector implements EventSubscriberInterface, ResetInterface
{
    private ?string $cartToken = null;

    private ?SalesChannelContext $salesChannelContext = null;

    public function __construct(
        private readonly AbstractCartPersister $cartPersister,
        private readonly array $collectorServices = [],
        private readonly array $processorServices = [],
        private readonly ?ContainerInterface $container = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SalesChannelContextResolvedEvent::class => 'onContextResolved',
        ];
    }

    public function reset(): void
    {
        parent::reset();
        $this->cartToken = null;
        $this->salesChannelContext = null;
    }

    public function getCart(): ?Cart
    {
        $cart = $this->data['cart'] ?? null;
        if (!$cart instanceof Cart) {
            return null;
        }

        return $cart;
    }

    public function getCurrency(): string
    {
        // Should never be null if there is a cart, however if it would be the case, the symfony toolbar yields an error, which should be prevented
        return $this->data['currency'] ?? 'EUR';
    }

    public function getItemCount(): int
    {
        return $this->getCart()?->getLineItems()->count() ?? 0;
    }

    public function getCartTotal(): float
    {
        return $this->getCart()?->getPrice()?->getTotalPrice() ?? 0.0;
    }

    public function getCollectors(): array
    {
        return $this->data['collectors'] ?? [];
    }

    public function getProcessors(): array
    {
        return $this->data['processors'] ?? [];
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->data = [
            'cart' => $this->getCartData(),
            'currency' => $this->salesChannelContext?->getCurrency()->getIsoCode(),
            'collectors' => $this->getCollectorsWithPriority(),
            'processors' => $this->getProcessorsWithPriority(),
        ];
    }

    public static function getTemplate(): string
    {
        return '@Profiling/Collector/cart.html.twig';
    }

    public function onContextResolved(SalesChannelContextResolvedEvent $event): void
    {
        $this->cartToken = $event->getUsedToken();
        $this->salesChannelContext = $event->getSalesChannelContext();
    }

    private function getCartData(): ?Cart
    {
        if ($this->cartToken === null || $this->salesChannelContext === null) {
            return null;
        }

        try {
            return $this->cartPersister->load($this->cartToken, $this->salesChannelContext);
        } catch (\Exception) {
            return null;
        }
    }

    private function getCollectorsWithPriority(): array
    {
        $collectors = [];

        // Process the collector services information from the compiler pass
        foreach ($this->collectorServices as $serviceId => $info) {
            if ($this->container && $this->container->has($serviceId)) {
                $service = $this->container->get($serviceId);
                $collectors[] = [
                    'serviceId' => $serviceId,
                    'class' => get_class($service),
                    'priority' => $info['priority'],
                    'decorates' => $info['decorates'],
                    'decoratedBy' => $info['decoratedBy'],
                ];
            } else {
                // Fallback when the container is not available or service not found
                $collectors[] = [
                    'serviceId' => $serviceId,
                    'class' => $serviceId, // Use the service ID as a fallback
                    'priority' => $info['priority'],
                    'decorates' => $info['decorates'],
                    'decoratedBy' => $info['decoratedBy'],
                ];
            }
        }

        return $collectors;
    }

    private function getProcessorsWithPriority(): array
    {
        $processors = [];

        // Process the processor services information from the compiler pass
        foreach ($this->processorServices as $serviceId => $info) {
            if ($this->container && $this->container->has($serviceId)) {
                $service = $this->container->get($serviceId);
                $processors[] = [
                    'serviceId' => $serviceId,
                    'class' => get_class($service),
                    'priority' => $info['priority'],
                    'decorates' => $info['decorates'],
                    'decoratedBy' => $info['decoratedBy'],
                ];
            } else {
                // Fallback when the container is not available or service not found
                $processors[] = [
                    'serviceId' => $serviceId,
                    'class' => $serviceId, // Use the service ID as a fallback
                    'priority' => $info['priority'],
                    'decorates' => $info['decorates'],
                    'decoratedBy' => $info['decoratedBy'],
                ];
            }
        }

        return $processors;
    }
}
