<?php declare(strict_types=1);

namespace Shopware\Core\Profiling\DependencyInjection;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Profiling\Subscriber\CartDataCollectorSubscriber;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @internal
 */
#[Package('framework')]
class CartServiceCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(CartDataCollectorSubscriber::class)) {
            return;
        }

        $definition = $container->getDefinition(CartDataCollectorSubscriber::class);
        $definition->setArgument(1, $this->processTaggedServices($container, 'shopware.cart.collector'));
        $definition->setArgument(2, $this->processTaggedServices($container, 'shopware.cart.processor'));
    }

    private function processTaggedServices(ContainerBuilder $container, string $tag): array
    {
        $services = [];
        foreach ($container->findTaggedServiceIds($tag) as $serviceId => $tags) {
            foreach ($tags as $tag) {
                $priority = $tag['priority'] ?? 0;
                $services[$serviceId] = [
                    'serviceId' => $serviceId,
                    'priority' => $priority,
                    'decorates' => null,
                    'decoratedBy' => [],
                ];
                break;
            }
        }

        $this->extractDecorationInfo($container, $services);

        // Sort collectors by priority (higher number = higher priority)
        uasort($services, static function ($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });

        return $services;
    }

    private function extractDecorationInfo(ContainerBuilder $container, array &$services): void
    {
        foreach ($services as $serviceId => $info) {
            if (!$container->hasDefinition($serviceId)) {
                continue;
            }

            $decoratedService = $container->getDefinition($serviceId)->getDecoratedService();
            if ($decoratedService !== null) {
                // Format: [decorated service ID, decoration inner name, decoration priority]
                $decoratedServiceId = $decoratedService[0];
                $decorationPriority = $decoratedService[2] ?? 0;

                $services[$serviceId]['decorates'] = [
                    'serviceId' => $decoratedServiceId,
                    'priority' => $decorationPriority,
                ];

                if (isset($services[$decoratedServiceId])) {
                    $services[$decoratedServiceId]['decoratedBy'][] = [
                        'serviceId' => $serviceId,
                        'priority' => $decorationPriority,
                    ];
                }
            }
        }
    }
}
