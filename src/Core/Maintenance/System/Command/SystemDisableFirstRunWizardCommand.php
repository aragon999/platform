<?php declare(strict_types=1);

namespace Shopware\Core\Maintenance\System\Command;

use Shopware\Core\Framework\Adapter\Console\ShopwareStyle;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Log\Package;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Shopware\Core\Framework\Store\Services\FirstRunWizardService;

/**
 * @internal should be used over the CLI only
 */
#[AsCommand(
    name: 'system:disable-first-run-wizard',
    description: 'Disables the first run wizard',
)]
#[Package('core')]
class SystemDisableFirstRunWizardCommand extends Command
{
    public function __construct(
        private readonly FirstRunWizardService $firstRunWizardService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->firstRunWizardService->finishFrw(false, Context::createDefaultContext());

        $io = new ShopwareStyle($input, $output);
        $io->success('First run wizard skipped.');

        return self::SUCCESS;
    }
}
