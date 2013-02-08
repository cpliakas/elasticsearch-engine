<?php

/**
 * Elasticsearch search service for the Search Framework library.
 *
 * @license http://www.gnu.org/licenses/lgpl-3.0.txt
 */

namespace Search\Service\Elasticsearch;

use Elastica_Client;
use Elastica_Document;
use Elastica_Search;
use Elastica_Type_Mapping;
use Search\Framework\Event\SearchFieldEvent;
use Search\Framework\Event\SearchServiceEvent;
use Search\Framework\SearchCollectionAbstract;
use Search\Framework\SearchEvents;
use Search\Framework\SearchIndexDocument;
use Search\Framework\SearchSchemaField;
use Search\Framework\SearchServiceAbstract;

/**
 * Provides an Elasticsearch service by using the Elastica library.
 */
class ElasticsearchService extends SearchServiceAbstract
{

    protected static $_id = 'elasticsearch';

    /**
     * The Elastica client interacting with the server.
     *
     * @var Elastica_Client
     */
    protected $_client;

    /**
     * The native document objects ready to be indexed indexing.
     *
     * @var array
     */
    protected $_documents;

    /**
     * The active index being written to / deleted from.
     *
     * @var string
     */
    protected $_activeIndex;

    /**
     * Implements SearchServiceAbstract::init().
     *
     * Sets the Solarium client, registers listeners.
     */
    public function init(array $endpoints, array $options)
    {
        // @see http://ruflin.github.com/Elastica/#section-connect
        $client_options = array('servers' => array());
        foreach ($endpoints as $endpoint) {
            $client_options['servers'][] = array(
                'host' => $endpoint->getHost(),
                'port' => $endpoint->getPort(),
            );
            $this->_activeIndex = $endpoint->getIndex();
        }

        if (count($client_options['servers']) < 2) {
            $this->_client = new Elastica_Client($client_options['servers'][0]);
        } else {
            $this->_client = new Elastica_Client($client_options);
        }
    }

    /**
     * Overrides SearchServiceAbstract::getSubscribedEvents().
     */
    public static function getSubscribedEvents()
    {
        return array(
            SearchEvents::SERVICE_POST_INDEX => array('postIndex'),
            SearchEvents::FIELD_NORMALIZE => array('normalizeField'),
        );
    }

    /**
     * Sets the Elastica_Client object.
     *
     * @param Elastica_Client $client
     *   The Elastica client.
     *
     * @return ElasticsearchService
     */
    public function setClient(Elastica_Client $client)
    {
        $this->_client = $client;
        return $this;
    }

    /**
     * Returns the Elastica_Client object.
     *
     * @return Elastica_Client
     */
    public function getClient()
    {
        return $this->_client;
    }

    /**
     * Sets the active index.
     *
     * @param string $index
     *   The active index.
     *
     * @return ElasticsearchService
     */
    public function setActiveIndex($index)
    {
        $this->_activeIndex = $index;
        return $this;
    }

    /**
     * Gets the active index.
     *
     * @return string
     */
    public function getActiveIndex()
    {
        return $this->_activeIndex;
    }

    /**
     * Overrides SearchServiceAbstract::getDocument().
     *
     * @return ElasticsearchIndexDocument
     */
    public function newDocument()
    {
        return new ElasticsearchIndexDocument($this);
    }

    /**
     * Implements SearchServiceAbstract::createIndex().
     */
    public function createIndex($name, array $options = array())
    {
        // Create the index.
        $options += array(
            'number_of_shards' => 4,
            'number_of_replicas' => 1,
        );
        $index = $this->_client->getIndex($name);
        $index->create($options, true);

        // Put the mappings for each collection.
        foreach ($this->_collections as $collection) {
            $schema = $collection->getSchema();

            $mapping = new Elastica_Type_Mapping();
            $type = $index->getType($collection->getType());
            $mapping->setType($type);

            $properties = array();
            foreach ($schema as $field_id => $field) {
                $field_name = $field->getName();

                // Converts the Search Framework types to Elasticsearch types.
                // @todo Break this down into something more pluggable.
                // @see http://www.elasticsearch.org/guide/reference/mapping/core-types.html
                switch ($field->getType()) {

                    case SearchSchemaField::TYPE_STRING;
                        $properties[$field_name]['type'] = 'string';
                        if ($field->isIndexed()) {
                            $index_property = ($field->isAnalyzed()) ? 'analyzed' : 'not_analyzed';
                            $properties[$field_name]['index'] = $index_property;
                        } else {
                            $properties[$field_name]['index'] = 'no';
                        }
                        break;

                    case SearchSchemaField::TYPE_INTEGER;
                        // Use size as type, expects integer, byte, short, long.
                        $size = $field->getSize();
                        $type_property = ($size) ? $size : 'integer';
                        $properties[$field_name]['type'] = $type_property;
                        break;

                    case SearchSchemaField::TYPE_DECIMAL;
                        // Use size as type, expects float, double
                        $size = $field->getSize();
                        $type_property = ($size) ? $size : 'float';
                        $properties[$field_name]['type'] = $type_property;
                        break;

                    case SearchSchemaField::TYPE_DATE;
                        // Use size as type, expects float, double
                        $properties[$field_name]['type'] = 'date';
                        break;

                    case SearchSchemaField::TYPE_BOOLEAN;
                        // Use size as type, expects float, double
                        $properties[$field_name]['type'] = 'boolean';
                        break;

                    case SearchSchemaField::TYPE_BINARY;
                        $properties[$field_name]['type'] = 'binary';
                        break;

                    default:
                        $properties[$field_name]['type'] = 'string';
                        $properties[$field_name]['index'] = 'not_analyzed';
                        break;
                }

                $properties[$field_name]['store'] = $field->isStored();
            }

            $mapping->setProperties($properties);
            $mapping->send();
        }
    }

    /**
     * Listener for the SearchEvents::FIELD_NORMALIZE event.
     */
    public function normalizeField(SearchFieldEvent $event)
    {
        // Refer to https://github.com/cpliakas/search-framework/issues/26.
        // This is nasty, but it works. Hopefully it won't be required soon.
        $schema = $this->getSchema();
        $field = $event->getField();
        switch ($schema->getField($field->getId())->getType()) {
            case SearchSchemaField::TYPE_DATE:
                $timestamp = strtotime($field);
                $event->setValue(date('Y-m-d\TH:i:s\Z', $timestamp));
                break;
        }
    }

    /**
     * Implements SearchServiceAbstract::indexDocument().
     *
     * @param SearchCollectionAbstract $collection
     * @param ElasticsearchIndexDocument $document
     */
    public function indexDocument(SearchCollectionAbstract $collection, SearchIndexDocument $document)
    {
        $index_doc = array();

        if (null !== ($boost = $document->getBoost())) {
            $index_doc['_boost'] = $boost;
        }

        foreach ($document as $field_id => $normalized_value) {
            $name = $document->getFieldName($field_id);
            $index_doc[$name] = $normalized_value;
        }

        $native_doc = new Elastica_Document(null, $index_doc);
        $native_doc->setIndex($this->_activeIndex);
        $native_doc->setType($collection->getType());
        $this->_documents[] = $native_doc;
    }

    /**
     * Listener for the SearchEvents::SERVICE_POST_INDEX event.
     *
     * @param SearchCollectionEvent $event
     */
    public function postIndex(SearchServiceEvent $event)
    {
        $this->_client->addDocuments($this->_documents);
        $this->_client->getIndex($this->_activeIndex)->refresh();
    }

    /**
     * Implements SearchServiceAbstract::search().
     *
     * @return Elastica_ResultSet
     */
    public function search($keywords, array $options = array())
    {
        $query = new Elastica_Search($this->_client);
        return $query->search($keywords);
    }

    /**
     * Implements SearchServiceAbstract::delete().
     *
     * @return Elastica_Response Response object
     */
    public function delete()
    {
        $this->_client->getIndex($this->_activeIndex)->delete();
    }
}
