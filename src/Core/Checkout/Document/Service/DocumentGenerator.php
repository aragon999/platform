<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Document\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Document\DocumentCollection;
use Shopware\Core\Checkout\Document\DocumentEntity;
use Shopware\Core\Checkout\Document\DocumentException;
use Shopware\Core\Checkout\Document\DocumentGenerationResult;
use Shopware\Core\Checkout\Document\DocumentIdStruct;
use Shopware\Core\Checkout\Document\Exception\DocumentGenerationException;
use Shopware\Core\Checkout\Document\Exception\DocumentNumberAlreadyExistsException;
use Shopware\Core\Checkout\Document\Exception\InvalidDocumentRendererException;
use Shopware\Core\Checkout\Document\Renderer\DocumentRendererConfig;
use Shopware\Core\Checkout\Document\Renderer\DocumentRendererRegistry;
use Shopware\Core\Checkout\Document\Renderer\InvoiceRenderer;
use Shopware\Core\Checkout\Document\Renderer\RenderedDocument;
use Shopware\Core\Checkout\Document\Struct\DocumentGenerateOperation;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Util\Random;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

/**
 * @final
 */
#[Package('checkout')]
class DocumentGenerator
{
    /**
     * @internal
     *
     * @param EntityRepository<DocumentCollection> $documentRepository
     */
    public function __construct(
        private readonly DocumentRendererRegistry $rendererRegistry,
        private readonly PdfRenderer $pdfRenderer,
        private readonly MediaService $mediaService,
        private readonly EntityRepository $documentRepository,
        private readonly Connection $connection
    ) {
    }

    public function readDocument(string $documentId, Context $context, string $deepLinkCode = ''): ?RenderedDocument
    {
        $documentMedia = $this->getOrCreateDocumentMedia($document, $context);
        if ($documentMedia === null) {
            return null;
        }

        $documentContent = $context->scope(
            Context::SYSTEM_SCOPE,
            fn (Context $context): string => $this->mediaService->loadFile($documentMedia->getId(), $context)
        );

        return new RenderedDocument(
            name: sprintf('%s.%s', $documentMedia->getFileName(), $documentMedia->getFileExtension()),
            contentType: $documentMedia->getMimeType(),
            content: $documentContent,
        );
    }

    public function preview(string $documentType, DocumentGenerateOperation $operation, string $deepLinkCode, Context $context): RenderedDocument
    {
        $config = new DocumentRendererConfig();
        $config->deepLinkCode = $deepLinkCode;

        if (!empty($operation->getConfig()['custom']['invoiceNumber'])) {
            $invoiceNumber = (string) $operation->getConfig()['custom']['invoiceNumber'];
            $operation->setReferencedDocumentId($this->getReferenceId($operation->getOrderId(), $invoiceNumber));
        }

        $rendered = $this->rendererRegistry->render($documentType, [$operation->getOrderId() => $operation], $context, $config);

        $document = $rendered->getOrderSuccess($operation->getOrderId());
        if ($document === null) {
            throw DocumentException::generationError($rendered->getOrderError($operation->getOrderId())?->getMessage());
        }

        $document->setContent($this->pdfRenderer->render($document));

        return $document;
    }

    /**
     * @param array<string, DocumentGenerateOperation> $operations
     */
    public function generate(string $documentType, array $operations, Context $context): DocumentGenerationResult
    {
        $documentTypeId = $this->getDocumentTypeByName($documentType);
        if ($documentTypeId === null) {
            throw new InvalidDocumentRendererException($documentType);
        }

        $rendered = $this->rendererRegistry->render($documentType, $operations, $context, new DocumentRendererConfig());

        $result = new DocumentGenerationResult();

        foreach ($rendered->getErrors() as $orderId => $error) {
            $result->addError($orderId, $error);
        }

        $records = [];

        $success = $rendered->getSuccess();

        foreach ($operations as $orderId => $operation) {
            try {
                $document = $success[$orderId] ?? null;
                if ($document === null) {
                    continue;
                }

                if ($this->checkDocumentNumberAlreadyExits($documentType, $document->getNumber(), $operation->getDocumentId())) {
                    $result->addError($orderId, new DocumentNumberAlreadyExistsException($document->getNumber()));

                    continue;
                }

                $deepLinkCode = Random::getAlphanumericString(32);
                $id = $operation->getDocumentId() ?? Uuid::randomHex();
                $mediaId = $this->resolveMediaId($operation, $context, $document);

                $records[] = [
                    'id' => $id,
                    'documentTypeId' => $documentTypeId,
                    'fileType' => $operation->getFileType(),
                    'orderId' => $orderId,
                    'orderVersionId' => $operation->getOrderVersionId(),
                    'static' => $operation->isStatic(),
                    'documentMediaFileId' => $mediaId,
                    'config' => $document->getConfig(),
                    'deepLinkCode' => $deepLinkCode,
                    'referencedDocumentId' => $operation->getReferencedDocumentId(),
                ];

                $result->addSuccess(new DocumentIdStruct($id, $deepLinkCode, $mediaId));
            } catch (\Throwable $exception) {
                $result->addError($orderId, $exception);
            }
        }

        if (count($records) > 0) {
            $this->documentRepository->upsert($records, $context);
        }

        return $result;
    }

    public function upload(string $documentId, Context $context, Request $uploadedFileRequest): DocumentIdStruct
    {
        $document = $this->documentRepository->search(new Criteria([$documentId]), $context)->getEntities()->first();
        if ($document === null) {
            throw DocumentException::documentNotFound($documentId);
        }

        if ($document->getDocumentMediaFileId() !== null) {
            throw new DocumentGenerationException('Document already exists');
        }

        if ($document->isStatic() === false) {
            throw new DocumentGenerationException('This document is dynamically generated and cannot be overwritten');
        }

        $fileName = (string) $uploadedFileRequest->query->get('fileName');
        if ($fileName === '') {
            throw new DocumentGenerationException('Parameter "fileName" is missing');
        }

        $mediaFile = $this->mediaService->fetchFile($uploadedFileRequest);
        $mediaId = $context->scope(Context::SYSTEM_SCOPE, fn (Context $context): string => $this->mediaService->saveMediaFile($mediaFile, $fileName, $context, 'document'));

        $this->documentRepository->update([[
            'id' => $documentId,
            'documentMediaFileId' => $mediaId,
        ]], $context);

        return new DocumentIdStruct($documentId, $document->getDeepLinkCode(), $mediaId);
    }

    private function getDocumentTypeByName(string $documentType): ?string
    {
        $id = $this->connection->fetchOne(
            'SELECT LOWER(HEX(id)) as id FROM document_type WHERE technical_name = :technicalName',
            ['technicalName' => $documentType]
        );

        return $id ?: null;
    }

    private function checkDocumentNumberAlreadyExits(
        string $documentTypeName,
        string $documentNumber,
        ?string $documentId = null
    ): bool {
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select(['COUNT(id)'])
            ->from('document')
            ->where($qb->expr()->and(
                'document_type_id IN (
                    SELECT id
                    FROM document_type
                    WHERE technical_name = :documentTypeName
                )',
                $qb->expr()->eq('document_number', ':documentNumber'),
            ))
            ->setMaxResults(1)
            ->setParameters([
                'documentTypeName' => $documentTypeName,
                'documentNumber' => $documentNumber,
            ])
        ;

        if ($documentId === null) {
            $qb->andWhere($qb->expr()->isNotNull('id'));
        } else {
            $qb->andWhere($qb->expr()->eq('id', ':documentId'))->setParameter('documentId', $documentId);
        }

        return (bool) $qb->executeQuery()->fetchOne();
    }

    private function getOrCreateDocumentMedia(string $documentId, string $deepLinkCode, Context $context): ?MediaEntity
    {
        $document = $this->getDocument($documentId, $deepLinkCode, $context);
        if ($document === null) {
            throw DocumentException::documentNotFound($documentId);
        }

        if ($document->isStatic()) {
            return null;
        }

        $documentMediaFile = $document->getDocumentMediaFile();
        if ($documentMediaFile !== null) {
            return $documentMediaFile;
        }

        // If a deep link code is provided, we do not want to generate a new document (with a new deep link code)
        if ($deepLinkCode !== '') {
            throw DocumentException::documentNotFound($documentId);
        }

        $operation = new DocumentGenerateOperation(
            orderId: $document->getOrderId(),
            config: $document->getConfig(),
            referencedDocumentId: $document->getReferencedDocumentId(),
            documentId: $document->getId(),
        );

        $technicalName = $document->getDocumentType()?->getTechnicalName();
        assert($technicalName !== null);

        $documentStruct = $this
            ->generate($technicalName, [$document->getOrderId() => $operation], $context)
            ->getSuccess()
            ->first()
        ;

        // TODO: Handle error
        if ($documentStruct === null) {
            return $document;
        }

        // Fetch the document again because new mediaFile is generated
        $document = $this->getDocument($document->getId(), '', $context);
        assert($document !== null);

        return $document;
    }

    private function getDocument(string $documentId, string $deepLinkCode, Context $context): ?DocumentEntity
    {
        $criteria = new Criteria([$documentId]);

        if ($deepLinkCode !== '') {
            $criteria->addFilter(new EqualsFilter('deepLinkCode', $deepLinkCode));
        }

        $criteria->addAssociations([
            'documentMediaFile',
            'documentType',
        ]);

        return $this->documentRepository->search($criteria, $context)->getEntities()->first();
    }

    private function resolveMediaId(DocumentGenerateOperation $operation, Context $context, RenderedDocument $document): ?string
    {
        if ($operation->isStatic()) {
            return null;
        }

        return $context->scope(Context::SYSTEM_SCOPE, fn (Context $context): string => $this->mediaService->saveFile(
            $this->pdfRenderer->render($document),
            $document->getFileExtension(),
            $this->pdfRenderer->getContentType(),
            $document->getName(),
            $context,
            'document'
        ));
    }

    private function getReferenceId(string $orderId, string $invoiceNumber): string
    {
        return (string) $this->connection->fetchOne('
            SELECT LOWER(HEX(document.id))
            FROM document INNER JOIN document_type
                ON document.document_type_id = document_type.id
            WHERE document_type.technical_name = :technicalName
            AND document.document_number = :invoiceNumber
            AND document.order_id = :orderId
        ', [
            'technicalName' => InvoiceRenderer::TYPE,
            'invoiceNumber' => $invoiceNumber,
            'orderId' => Uuid::fromHexToBytes($orderId),
        ]);
    }
}
