<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Migration\Step\SalesOrder;

use Migration\App\Step\StageInterface;
use Migration\Handler;
use Migration\MapReader\MapReaderSalesOrder;
use Migration\MapReaderInterface;
use Migration\Resource;
use Migration\Resource\Record;
use Migration\App\ProgressBar;
use Migration\Logger\Manager as LogManager;

/**
 * Class Data
 */
class Data implements StageInterface
{
    /**
     * @var Resource\Source
     */
    protected $source;

    /**
     * @var Resource\Destination
     */
    protected $destination;

    /**
     * @var Resource\RecordFactory
     */
    protected $recordFactory;

    /**
     * @var MapReaderSalesOrder
     */
    protected $mapReader;

    /**
     * @var \Migration\RecordTransformerFactory
     */
    protected $recordTransformerFactory;

    /**
     * ProgressBar instance
     *
     * @var ProgressBar
     */
    protected $progress;

    /**
     * @var Helper
     */
    protected $helper;

    /**
     * @param ProgressBar $progress
     * @param Resource\Source $source
     * @param Resource\Destination $destination
     * @param Resource\RecordFactory $recordFactory
     * @param \Migration\RecordTransformerFactory $recordTransformerFactory
     * @param MapReaderSalesOrder $mapReader
     * @param Helper $helper
     */
    public function __construct(
        ProgressBar $progress,
        Resource\Source $source,
        Resource\Destination $destination,
        Resource\RecordFactory $recordFactory,
        \Migration\RecordTransformerFactory $recordTransformerFactory,
        MapReaderSalesOrder $mapReader,
        Helper $helper
    ) {
        $this->source = $source;
        $this->destination = $destination;
        $this->recordFactory = $recordFactory;
        $this->recordTransformerFactory = $recordTransformerFactory;
        $this->mapReader = $mapReader;
        $this->progress = $progress;
        $this->helper = $helper;
    }

    /**
     * Entry point. Run migration of SalesOrder structure.
     * @return bool
     */
    public function perform()
    {
        if (LogManager::getLogLevel() != LogManager::LOG_LEVEL_DEBUG) {
            $this->progress->start(count($this->helper->getDocumentList()));
        }
        $sourceDocuments = array_keys($this->helper->getDocumentList());
        foreach ($sourceDocuments as $sourceDocName) {
            if (LogManager::getLogLevel() != LogManager::LOG_LEVEL_DEBUG) {
                $this->progress->advance();
            }
            $sourceDocument = $this->source->getDocument($sourceDocName);

            $destinationDocumentName = $this->mapReader->getDocumentMap(
                $sourceDocName,
                MapReaderInterface::TYPE_SOURCE
            );
            if (!$destinationDocumentName) {
                continue;
            }
            $destDocument = $this->destination->getDocument($destinationDocumentName);
            $this->destination->clearDocument($destinationDocumentName);

            $eavDocumentName = $this->helper->getDestEavDocument();
            $eavDocumentResource = $this->destination->getDocument($eavDocumentName);

            /** @var \Migration\RecordTransformer $recordTranformer */
            $recordTransformer = $this->recordTransformerFactory->create(
                [
                    'sourceDocument' => $sourceDocument,
                    'destDocument' => $destDocument,
                    'mapReader' => $this->mapReader
                ]
            );
            $recordTransformer->init();
            $pageNumber = 0;
            if (LogManager::getLogLevel() == LogManager::LOG_LEVEL_DEBUG) {
                $this->progress->start($this->source->getRecordsCount($sourceDocName));
            }
            while (!empty($bulk = $this->source->getRecords($sourceDocName, $pageNumber))) {
                $pageNumber++;
                $destinationCollection = $destDocument->getRecords();
                $destEavCollection = $eavDocumentResource->getRecords();
                foreach ($bulk as $recordData) {
                    if (LogManager::getLogLevel() == LogManager::LOG_LEVEL_DEBUG) {
                        $this->progress->advance();
                    }
                    /** @var Record $sourceRecord */
                    $sourceRecord = $this->recordFactory->create(
                        ['document' => $sourceDocument, 'data' => $recordData]
                    );
                    /** @var Record $destRecord */
                    $destRecord = $this->recordFactory->create(['document' => $destDocument]);
                    $recordTransformer->transform($sourceRecord, $destRecord);
                    $destinationCollection->addRecord($destRecord);

                    $this->migrateAdditionalOrderData($recordData, $sourceDocument, $destEavCollection);
                }
                $this->destination->saveRecords($destinationDocumentName, $destinationCollection);
                $this->destination->saveRecords($eavDocumentName, $destEavCollection);
                if (LogManager::getLogLevel() == LogManager::LOG_LEVEL_DEBUG) {
                    $this->progress->finish();
                }
            }
        }
        if (LogManager::getLogLevel() != LogManager::LOG_LEVEL_DEBUG) {
            $this->progress->finish();
        }
        return true;
    }

    /**
     * @param array $data
     * @param Resource\Document $sourceDocument
     * @param Record\Collection $destEavCollection
     * @return void
     */
    public function migrateAdditionalOrderData($data, $sourceDocument, $destEavCollection)
    {
        foreach ($this->helper->getEavAttributes() as $orderEavAttribute) {
            $eavAttributeData = $this->prepareEavEntityData($orderEavAttribute, $data);
            if ($eavAttributeData) {
                $attributeRecord = $this->recordFactory->create(
                    [
                        'document' => $sourceDocument,
                        'data' => $eavAttributeData
                    ]
                );
                $destEavCollection->addRecord($attributeRecord);
            }
        }
    }

    /**
     * @param string $eavAttribute
     * @param array $recordData
     * @return array|null
     */
    protected function prepareEavEntityData($eavAttribute, $recordData)
    {
        $recordEavData = null;
        $value = $this->getAttributeValue($recordData, $eavAttribute);
        if ($value != null) {
            $attributeData = $this->getAttributeData($eavAttribute);
            $recordEavData = [
                'attribute_id' => $attributeData['attribute_id'],
                'entity_type_id' => $attributeData['entity_type_id'],
                'store_id' => $recordData['store_id'],
                'entity_id' => $recordData['entity_id'],
                'value' => $value
            ];
        }
        return $recordEavData;
    }

    /**
     * @param string $eavAttributeCode
     * @return array|null
     */
    protected function getAttributeData($eavAttributeCode)
    {
        $attributeData = null;
        $pageNumber = 0;
        while (!empty($bulk = $this->destination->getRecords('eav_attribute', $pageNumber))) {
            $pageNumber++;
            foreach ($bulk as $eavData) {
                if ($eavData['attribute_code'] == $eavAttributeCode) {
                    $attributeData = $eavData;
                    break;
                }
            }
        }
        return $attributeData;
    }

    /**
     * @param array $recordData
     * @param string $attributeName
     * @return array|null
     */
    protected function getAttributeValue($recordData, $attributeName)
    {
        $attributeValue = null;
        if (isset($recordData[$attributeName])) {
            return $attributeValue = $recordData[$attributeName];
        }
        return $attributeValue;
    }

    /**
     * @return int
     */
    protected function getDestEavDocument()
    {
        return count($this->helper->getDocumentList());
    }

    /**
     * @inheritdoc
     */
    public function rollback()
    {
        throw new \Exception('Rollback is impossible');
    }
}