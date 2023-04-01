<?php
declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Controller;

use DateTime;
use Exception;
use InvalidArgumentException;
use Neos\Flow\Annotations\InjectConfiguration;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Flow\Persistence\Exception\UnknownObjectException;
use Neos\Flow\Security\Context;
use Neos\Flow\Utility\Algorithms;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Psr\Http\Client\ClientExceptionInterface;
use Sitegeist\LostInTranslation\Domain\Model\GlossaryEntry;
use Sitegeist\LostInTranslation\Domain\Repository\GlossaryEntryRepository;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLTranslationService;

class GlossaryController extends AbstractModuleController
{
    /**
     * @var FusionView
     */
    protected $view;
    /**
     * @var string
     */
    protected $defaultViewObjectName = FusionView::class;
    /**
     * @var array
     */
    protected $supportedMediaTypes = ['application/json', 'text/html'];

    /**
     * @var array
     */
    protected $viewFormatToObjectNameMap = [
        'html' => FusionView::class,
        'json' => JsonView::class,
    ];

    #[InjectConfiguration(path: "DeepLApi.glossary.backendModule", package: "Sitegeist.LostInTranslation")]
    protected array $configuration;

    public function __construct(
        private readonly Context $securityContext,
        private readonly GlossaryEntryRepository $glossaryEntryRepository,
        private readonly DeepLTranslationService $deepLApi,
    ) {}

    /**
     * @throws Exception
     * @throws ClientExceptionInterface
     */
    public function indexAction(): void
    {
        $glossaryJson = json_encode($this->getEntryAggregates());
        $this->view->assignMultiple([
            'glossaryJson' => $glossaryJson,
            'languages' => $this->extractLanguagesFromConfiguredLanguagePairs(),
            'glossaryStatus' => $this->getGlossaryStatus(),
            'csrfToken' => $this->securityContext->getCsrfProtectionToken(),
        ]);
    }

    protected function getEntryAggregates(): array
    {
        $aggregates = [];
        $entriesDb = $this->glossaryEntryRepository->findAll();
        /** @var GlossaryEntry $entryDb */
        foreach ($entriesDb as $entryDb) {
            $identifier = $entryDb->aggregateIdentifier;
            if (!array_key_exists($identifier, $aggregates)) {
                $aggregates[$identifier] = [];
            }
            $aggregates[$identifier][$entryDb->glossaryLanguage] = $entryDb->text;
        }

        $sortByLanguage = $this->getSortByLanguage();
        uasort($aggregates, fn(array $a, array $b) => strcmp($a[$sortByLanguage], $b[$sortByLanguage]));

        return $aggregates;
    }

    /**
     * @throws IllegalObjectTypeException
     * @throws Exception
     * @throws ClientExceptionInterface
     */
    public function createAction(): void
    {
        [
            'aggregateIdentifier' => $aggregateIdentifier,
            'texts' => $texts,
        ] = $this->request->getArguments();

        if ($aggregateIdentifier !== null) {
            // ToDo exceptions to messages?
            throw new InvalidArgumentException('Create action must not have an aggregateIdentifier set.');
        }
        $aggregateIdentifier = Algorithms::generateUUID();

        $languages = $this->extractLanguagesFromConfiguredLanguagePairs();
        foreach ($languages as $language) {

            if (!array_key_exists($language, $texts)) {
                throw new InvalidArgumentException("There is no text for language $language.");
            }

            $entry = new GlossaryEntry(
                $aggregateIdentifier,
                new DateTime(),
                $language,
                $texts[$language]
            );
            $this->glossaryEntryRepository->add($entry);
        }
        $this->persistenceManager->persistAll();

        $this->view->assign('value', [
            'success' => true,
            'entries' => $this->getEntryAggregates(),
            // ToDo do we need this?
            'messages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
        ]);

    }

    /**
     * @throws IllegalObjectTypeException
     * @noinspection PhpUnused
     */
    public function deleteAction(): void
    {
        [
            'aggregateIdentifier' => $aggregateIdentifier,
        ] = $this->request->getArguments();

        $entries = $this->glossaryEntryRepository->findByAggregateIdentifier($aggregateIdentifier);
        foreach ($entries as $entry) {
            $this->glossaryEntryRepository->remove($entry);
        }
        $this->persistenceManager->persistAll();

        $this->view->assign('value', [
            'success' => true,
            'entries' => $this->getEntryAggregates(),
            // ToDo do we need this?
            'messages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
        ]);
    }

    /**
     * @throws UnknownObjectException
     * @throws IllegalObjectTypeException
     */
    public function updateAction(): void
    {
        [
            'aggregateIdentifier' => $aggregateIdentifier,
            'texts' => $texts,
        ] = $this->request->getArguments();
        $now = new DateTime();

        // update entries for languages that exist within the database already
        $entries = $this->glossaryEntryRepository->findByAggregateIdentifier($aggregateIdentifier);
        foreach ($entries as $entry) {
            $language = $entry->glossaryLanguage;
            $doUpdateCurrentLanguage = ($texts[$language] !== $entry->text);
            if ($doUpdateCurrentLanguage) {
                $entry->text = $texts[$language];
                $entry->lastModificationDateTime = $now;
                $this->persistenceManager->update($entry);
            }
            unset($texts[$language]);
        }

        // add entries for languages that do not yet exist within the database
        foreach ($texts as $language => $text) {
            $entry = new GlossaryEntry(
                $aggregateIdentifier,
                $now,
                $language,
                $text
            );
            $this->glossaryEntryRepository->add($entry);
        }

        $this->persistenceManager->persistAll();

        $this->view->assign('value', [
            'success' => true,
            'entries' => $this->getEntryAggregates(),
            // ToDo do we need this?
            'messages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
        ]);
    }

    /**
     * @throws ClientExceptionInterface
     */
    protected function extractLanguagesFromConfiguredLanguagePairs(): array
    {
        $languages = [];

        // we iterate over all sources first to let them precede all target languages
        $this->addLanguageFromLanguagePairs($languages, 'source');
        $this->addLanguageFromLanguagePairs($languages, 'target');

        return $languages;
    }

    /**
     * @throws ClientExceptionInterface
     */
    protected function addLanguageFromLanguagePairs(array &$languages, string $type): void
    {
        [$languagePairs] = $this->deepLApi->getLanguagePairs();
        foreach ($languagePairs as $languagePair) {
            $language = $languagePair[$type] ?? null;
            if (!empty($language) && !in_array($language, $languages, true)) {
                $languages[] = $language;
            }
        }
    }

    protected function getSortByLanguage(): string
    {
        // ToDo validate ?
        return $this->configuration['sortByLanguage'];
    }

    /**
     * @return array<string, DateTime>
     */
    protected function getDatabaseLanguagesLastModifiedAt(): array
    {
        $return = [];
        $dateTimes = $this->glossaryEntryRepository->getLanguagesLastModifiedAt();
        foreach ($dateTimes as $language => $dateTime) {
            $return[$language] = $dateTime;
        }
        return $return;
    }

    /**
     * @return array<string, string>
     * @throws Exception
     * @throws ClientExceptionInterface
     */
    protected function getGlossaryStatus(): array
    {
        $return = [];
        $languagesLastModifiedAt = $this->getDatabaseLanguagesLastModifiedAt();

        $glossaries = $this->deepLApi->getGlossaries();
        foreach ($glossaries as $glossary) {

            $sourceLang = strtoupper($glossary['source_lang']);
            $targetLang = strtoupper($glossary['target_lang']);
            $glossaryKey = $this->deepLApi->getInternalGlossaryKey($sourceLang, $targetLang);
            $glossaryDateTime = new DateTime('@' . strtotime($glossary['creation_time']));
            $sourceLangLastModifiedAt = $languagesLastModifiedAt[$sourceLang];
            $targetLangLastModifiedAt = $languagesLastModifiedAt[$targetLang];

            $glossaryIsOutdated = (
                $glossaryDateTime < $sourceLangLastModifiedAt
                || $glossaryDateTime < $targetLangLastModifiedAt
            );

            $return[$glossaryKey] = [
                'sourceLang' => $sourceLang,
                'targetLang' => $targetLang,
                'creationDate' => $glossaryDateTime->format('d.m.Y H:i:s'),
                'isOutdated' => $glossaryIsOutdated,
                'canBeUsed' => $glossary['ready'],
            ];

        }

        ksort($return);

        return $return;
    }

}