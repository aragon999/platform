<?php declare(strict_types=1);

namespace Shopware\Core\Content\Mail\Service;

use Monolog\Level;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\MailTemplate\Exception\SalesChannelNotFoundException;
use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeSentEvent;
use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeValidateEvent;
use Shopware\Core\Content\MailTemplate\Service\Event\MailErrorEvent;
use Shopware\Core\Content\MailTemplate\Service\Event\MailSentEvent;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Framework\Adapter\Twig\StringTemplateRenderer;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Validation\EntityExists;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\Framework\Validation\DataValidator;
use Shopware\Core\Maintenance\Staging\Event\SetupStagingEvent;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Validator\Constraints\NotBlank;

#[Package('services-settings')]
class MailService extends AbstractMailService
{
    /**
     * @internal
     *
     * @param EntityRepository<MediaCollection> $mediaRepository
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        private readonly DataValidator $dataValidator,
        private readonly StringTemplateRenderer $templateRenderer,
        private readonly AbstractMailFactory $mailFactory,
        private readonly AbstractMailSender $mailSender,
        private readonly EntityRepository $mediaRepository,
        private readonly SalesChannelDefinition $salesChannelDefinition,
        private readonly EntityRepository $salesChannelRepository,
        private readonly SystemConfigService $systemConfigService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getDecorated(): AbstractMailService
    {
        throw new DecorationPatternException(self::class);
    }

    public function send(array $data, Context $context, array $templateData = []): ?Email
    {
        $event = new MailBeforeValidateEvent($data, $context, $templateData);
        $this->eventDispatcher->dispatch($event);
        if ($event->isPropagationStopped()) {
            return null;
        }

        $data = $event->getData();
        $templateData = $event->getTemplateData();

        $this->dataValidator->validate($data, $this->getValidationDefinition($context));

        $salesChannel = $this->getSalesChannel($templateData, $context);
        $templateData['salesChannel'] = $salesChannel;
        $templateData['salesChannelId'] = $salesChannel->getId();

        $mail = $this->createMail($data, $templateData, $salesChannel, $context);
        if (trim($mail->getBody()->toString()) === '') {
            $this->mailError('Mail body is null', $context, $templateData);

            return null;
        }

        if (isset($data['attachments']) && is_array($data['attachments'])) {
            foreach ($data['attachments'] as $attachment) {
                if ($attachment instanceof DataPart) {
                    $mail->addPart($attachment);
                }
            }
        }

        $this->eventDispatcher->dispatch(
            new MailBeforeSentEvent($data, $mail, $context, $templateData['eventName'] ?? null)
        );

        if ($event->isPropagationStopped()) {
            return null;
        }

        $this->mailSender->send($mail);

        $this->eventDispatcher->dispatch(new MailSentEvent(
            $data['subject'],
            $data['recipients'],
            ['text/html' => $mail->getHtmlBody(), 'text/plain' => $mail->getTextBody()],
            $context,
            $templateData['eventName'] ?? null,
        ));

        return $mail;
    }

    private function getValidationDefinition(Context $context): DataValidationDefinition
    {
        $definition = new DataValidationDefinition('mail_service.send');

        $definition->add('recipients', new NotBlank());
        $definition->add('salesChannelId', new EntityExists(['entity' => $this->salesChannelDefinition->getEntityName(), 'context' => $context]));
        $definition->add('contentHtml', new NotBlank());
        $definition->add('contentPlain', new NotBlank());
        $definition->add('subject', new NotBlank());
        $definition->add('senderName', new NotBlank());

        return $definition;
    }

    private function createMail(array &$data, array $templateData, SalesChannelEntity $salesChannel, Context $context): Email
    {
        $testMode = $this->systemConfigService->getBool(SetupStagingEvent::CONFIG_FLAG) ?: !empty($data['testMode']);

        $senderEmail = $this->getSender($data, $salesChannel->getId());
        if ($senderEmail === '') {
            $this->mailError(
                sprintf('senderMail not configured for salesChannel: %s. Please check system_config \'core.basicInformation.email\'', $salesChannel->getId()),
                $context,
                $templateData,
            );
        }

        if ($testMode) {
            $this->templateRenderer->enableTestMode();
            if (\is_array($templateData['order'] ?? []) && empty($templateData['order']['deepLinkCode'])) {
                $templateData['order']['deepLinkCode'] = 'home';
            }
        }

        foreach (['subject', 'senderName'] as $renderDataIndex) {
            try {
                $data[$renderDataIndex] = $this->templateRenderer->render($data[$renderDataIndex], $templateData, $context, false);
            } catch (\Throwable $e) {
                $this->mailError(
                    'Could not render Mail-Subject with error message: ' . $e->getMessage(),
                    $context,
                    array_merge(['template' => $data[$renderDataIndex], 'exception' => (string) $e], $templateData),
                    $data[$renderDataIndex],
                    $e,
                );

                return null;
            }
        }

        $contents = [];
        foreach ($this->buildContents($data, $salesChannel) as $index => $template) {
            try {
                $contents[$index] = $this->templateRenderer->render($template, $templateData, $context, $index !== 'text/plain');
            } catch (\Throwable $e) {
                $this->mailError(
                    sprintf('Could not render Mail-Content (%s) with error message: %s', $index, $e->getMessage()),
                    $context,
                    array_merge(['template' => $template, 'exception' => (string) $e], $templateData),
                    $template,
                    $e,
                );

                return null;
            }
        }

        if ($testMode) {
            $this->templateRenderer->disableTestMode();
        }

        $mail = $this->mailFactory->create(
            $data['subject'],
            [$senderEmail => $data['senderName']],
            $data['recipients'],
            $contents,
            $this->getMediaUrls($data, $context),
            $data,
            $data['binAttachments'] ?? null
        );

        if ($testMode) {
            $mail->getHeaders()
                ->addTextHeader('X-Shopware-Event-Name', $templateData['eventName'] ?? '')
                ->addTextHeader('X-Shopware-Sales-Channel-Id', $salesChannel->getId())
                ->addTextHeader('X-Shopware-Language-Id', $context->getLanguageId())
            ;
        }

        return $mail;
    }

    private function mailError(string $errorMessage, Context $context, array $templateData, ?string $template = null, ?\Exception $e = null): void
    {
        $this->eventDispatcher->dispatch(
            new MailErrorEvent($context, Level::Error, $e, $errorMessage, $template, $templateData)
        );

        $this->logger->error($errorMessage, $templateData);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function getSender(array $data, string $salesChannelId): string
    {
        $senderEmail = $data['senderEmail'] ?? null;
        if ($senderEmail !== null && trim((string) $senderEmail) !== '') {
            return $senderEmail;
        }

        return trim(
            $this->systemConfigService->getString(
                'core.basicInformation.email',
                $salesChannelId
            )
        ) ?: trim(
            $this->systemConfigService->getString(
                'core.mailerSettings.senderAddress',
                $salesChannelId
            )
        );
    }

    /**
     * Attaches header and footer to given email bodies
     *
     * @param array{contentPlain: string, contentHtml: string}
     *
     * @return array{'text/plain': string, 'text/html': string} e.g. ['text/plain' => '{{foobar}}', 'text/html' => '<h1>{{foobar}}</h1>']
     */
    private function buildContents(array $data, SalesChannelEntity $salesChannel): array
    {
        $mailHeaderFooter = $salesChannel->getMailHeaderFooter();
        if ($mailHeaderFooter !== null) {
            $headerPlain = $mailHeaderFooter->getTranslation('headerPlain') ?? '';
            \assert(\is_string($headerPlain));
            $footerPlain = $mailHeaderFooter->getTranslation('footerPlain') ?? '';
            \assert(\is_string($footerPlain));
            $headerHtml = $mailHeaderFooter->getTranslation('headerHtml') ?? '';
            \assert(\is_string($headerHtml));
            $footerHtml = $mailHeaderFooter->getTranslation('footerHtml') ?? '';
            \assert(\is_string($footerHtml));

            return [
                'text/plain' => \sprintf('%s%s%s', $headerPlain, $data['contentPlain'], $footerPlain),
                'text/html' => \sprintf('%s%s%s', $headerHtml, $data['contentHtml'], $footerHtml),
            ];
        }

        return [
            'text/html' => $data['contentHtml'],
            'text/plain' => $data['contentPlain'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function getMediaUrls(array $data, Context $context): array
    {
        if (empty($data['mediaIds'])) {
            return [];
        }
        $criteria = new Criteria($data['mediaIds']);
        $criteria->setTitle('mail-service::resolve-media-ids');
        $media = new MediaCollection();
        $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($criteria, &$media): void {
            $media = $this->mediaRepository->search($criteria, $context)->getEntities();
        });

        $urls = [];
        foreach ($media as $mediaItem) {
            $urls[] = $mediaItem->getPath();
        }

        return $urls;
    }

    /**
     * @param array<string, mixed> $templateData
     */
    private function getSalesChannel(array $templateData, Context $context): ?SalesChannelEntity
    {
        $salesChannel = $templateData['salesChannel'] ?? null;
        if ($salesChannel instanceof $salesChannel) {
            return $salesChannel;
        }

        $salesChannelId = $templateData['salesChannelId'] ?? null;
        if ($salesChannelId !== null) {
            $criteria = new Criteria([$salesChannelId]);
            $criteria->setTitle('mail-service::resolve-sales-channel-domain');
            $criteria->addAssociation('mailHeaderFooter');
            $criteria->getAssociation('domains')
                ->addFilter(
                    new EqualsFilter('languageId', $context->getLanguageId())
                );

            $salesChannel = $this->salesChannelRepository->search(
                $criteria,
                $context
            )->getEntities()->first();

            if ($salesChannel === null) {
                throw new SalesChannelNotFoundException($salesChannelId);
            }
        }

        return $salesChannel;
    }
}
