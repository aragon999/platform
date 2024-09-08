<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Maintenance\System\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Maintenance\System\Command\SystemDisableFirstRunWizardCommand;
use Shopware\Core\Framework\Store\Services\FirstRunWizardService;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversClass(SystemDisableFirstRunWizardCommand::class)]
class SystemDisableFirstRunWizardCommandTest extends TestCase
{
    public function testExecute(): void
    {
        $firstRunWizardService = $this->createMock(FirstRunWizardService::class);
        $firstRunWizardService
            ->expects(static::once())
            ->method('finishFrw');

        $tester = new CommandTester(new SystemDisableFirstRunWizardCommand(
            $firstRunWizardService
        ));

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
    }
}
