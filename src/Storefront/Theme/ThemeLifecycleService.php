<?php declare(strict_types=1);

namespace Shopware\Storefront\Theme;

use function GuzzleHttp\Psr7\mimetype_from_filename;
use Shopware\Core\Content\Media\File\FileSaver;
use Shopware\Core\Content\Media\File\MediaFile;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Storefront\Theme\StorefrontPluginConfiguration\StorefrontPluginConfiguration;

class ThemeLifecycleService
{
    /**
     * @var StorefrontPluginRegistry
     */
    private $pluginRegistry;

    /**
     * @var EntityRepositoryInterface
     */
    private $themeRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $mediaRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $mediaFolderRepository;

    /**
     * @var FileSaver
     */
    private $fileSaver;

    public function __construct(
        StorefrontPluginRegistry $pluginRegistry,
        EntityRepositoryInterface $themeRepository,
        EntityRepositoryInterface $mediaRepository,
        EntityRepositoryInterface $mediaFolderRepository,
        FileSaver $fileSaver
    ) {
        $this->pluginRegistry = $pluginRegistry;
        $this->themeRepository = $themeRepository;
        $this->mediaRepository = $mediaRepository;
        $this->mediaFolderRepository = $mediaFolderRepository;
        $this->fileSaver = $fileSaver;
    }

    public function refreshThemes(Context $context, ?StorefrontPluginConfiguration $storefrontPluginConfig = null): void
    {
        if ($storefrontPluginConfig === null) {
            $configurationCollection = $this->pluginRegistry->getConfigurations()->getThemes();
        } else {
            $configurationCollection = clone $this->pluginRegistry->getConfigurations()->getThemes();
            $configurationCollection->add($storefrontPluginConfig);
        }

        /** @var ThemeCollection $themes */
        $themes = $this->themeRepository->search(new Criteria(), $context)->getEntities();

        $data = [];
        foreach ($configurationCollection as $themeConfig) {
            if ($themes->getByTechnicalName($themeConfig->getTechnicalName())) {
                continue;
            }

            $translations = [];

            $labelTranslations = $this->getLabelsFromConfig($themeConfig->getConfig());
            foreach ($labelTranslations as $locale => $translation) {
                $translations[$locale] = ['labels' => $translation];
            }

            $helpTextTranslations = $this->getHelpTextsFromConfig($themeConfig->getConfig());
            foreach ($helpTextTranslations as $locale => $translation) {
                $translations[$locale]['helpTexts'] = $translation;
            }

            $themeData = [
                'name' => $themeConfig->getName(),
                'technicalName' => $themeConfig->getTechnicalName(),
                'author' => $themeConfig->getAuthor(),
                'translations' => $translations,
            ];

            // handle media
            $themeFolderId = $this->getMediaDefaultFolderId('theme', $context);
            $media = [];

            if (!array_key_exists('fields', $themeConfig->getConfig())) {
                $data[] = $themeData;
                continue;
            }

            if ($themeConfig->getPreviewMedia()) {
                $mediaId = Uuid::randomHex();
                $path = $themeConfig->getPreviewMedia();

                $mediaItem = $this->createMedia($path, $mediaId, $themeFolderId);

                if ($mediaItem) {
                    $themeData['previewMediaId'] = $mediaId;
                    $media[$path] = $mediaItem;
                }
            }

            $config = $themeConfig->getConfig();

            foreach ($config['fields'] as $key => $field) {
                if ($field['type'] !== 'media') {
                    continue;
                }

                $path = $themeConfig->getBasePath() . DIRECTORY_SEPARATOR . $field['value'];

                if (!array_key_exists($path, $media)) {
                    $mediaId = Uuid::randomHex();
                    $mediaItem = $this->createMedia($path, $mediaId, $themeFolderId);

                    if (!$mediaItem) {
                        continue;
                    }

                    $media[$path] = $mediaItem;

                    $config['fields'][$key]['value'] = $mediaId;
                } else {
                    $config['fields'][$key]['value'] = $media[$path]['media']['id'];
                }
            }
            $themeData['baseConfig'] = $config;

            if (!empty($media)) {
                $this->mediaRepository->create(array_column($media, 'media'), $context);
                foreach ($media as $item) {
                    $this->fileSaver->persistFileToMedia($item['mediaFile'], $item['basename'], $item['media']['id'], $context);
                }
            }

            $data[] = $themeData;
        }

        if (count($data) === 0) {
            return;
        }

        $this->themeRepository->create($data, $context);
    }

    private function createMedia(string $path, string $mediaId, string $themeFolderId): ?array
    {
        if (!file_exists($path) || is_dir($path)) {
            return null;
        }

        $pathinfo = pathinfo($path);

        return [
            'basename' => $pathinfo['filename'],
            'media' => ['id' => $mediaId, 'mediaFolderId' => $themeFolderId],
            'mediaFile' => new MediaFile(
                $path,
                mimetype_from_filename($pathinfo['basename']),
                $pathinfo['extension'],
                filesize($path)
            ),
        ];
    }

    private function getMediaDefaultFolderId(string $folder, Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('media_folder.defaultFolder.entity', $folder));
        $criteria->addAssociation('defaultFolder');
        $criteria->setLimit(1);
        $defaultFolder = $this->mediaFolderRepository->search($criteria, $context);
        $defaultFolderId = null;
        if ($defaultFolder->count() === 1) {
            $defaultFolderId = $defaultFolder->first()->getId();
        }

        return $defaultFolderId;
    }

    private function getLabelsFromConfig(array $config): array
    {
        $translations = [];
        if (array_key_exists('blocks', $config)) {
            $translations = array_merge_recursive($translations, $this->extractLabels('blocks', $config['blocks']));
        }

        if (array_key_exists('sections', $config)) {
            $translations = array_merge_recursive($translations, $this->extractLabels('sections', $config['sections']));
        }

        if (array_key_exists('fields', $config)) {
            $translations = array_merge_recursive($translations, $this->extractLabels('fields', $config['fields']));
        }

        return $translations;
    }

    private function extractLabels(string $prefix, array $data): array
    {
        $labels = [];
        foreach ($data as $key => $item) {
            foreach ($item['label'] as $locale => $label) {
                $labels[$locale][$prefix . '.' . $key] = $label;
            }
        }

        return $labels;
    }

    private function getHelpTextsFromConfig(array $config): array
    {
        $translations = [];

        if (array_key_exists('fields', $config)) {
            $translations = array_merge_recursive($translations, $this->extractHelpTexts('fields', $config['fields']));
        }

        return $translations;
    }

    private function extractHelpTexts(string $prefix, array $data): array
    {
        $helpTexts = [];
        foreach ($data as $key => $item) {
            if (!isset($item['helpText'])) {
                continue;
            }

            foreach ($item['helpText'] as $locale => $label) {
                $helpTexts[$locale][$prefix . '.' . $key] = $label;
            }
        }

        return $helpTexts;
    }
}
