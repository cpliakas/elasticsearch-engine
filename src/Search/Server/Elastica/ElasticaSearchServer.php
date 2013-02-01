<?php

/**
 * Elastica search server for the Search Framework library.
 *
 * @license http://www.gnu.org/licenses/lgpl-3.0.txt
 */

namespace Search\Server\Elastica;


use Elastica_Client;
use Elastica_Document;
use Elastica_Search;
use Elastica_Type_Mapping;
use Search\Framework\Event\SearchCollectionEvent;
use Search\Framework\SearchCollectionAbstract;
use Search\Framework\SearchEvents;
use Search\Framework\SearchServerAbstract;
use Search\Framework\SearchIndexDocument;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Integrates the Solarium library with the Search Framework.
 */
class ElasticaSearchServer extends SearchServerAbstract implements EventSubscriberInterface
{
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
     * Instantiates a ElasticaSearchServer object or sets it.
     *
     * @param array|Elastica_Client $options
     *   The populated Elastica client object, or an array of configuration
     *   options used to instantiate a new client object.
     * @param EventDispatcher|null $dispatcher
     *   Optionally pass a dispatcher object that was instantiated elsewhere in
     *   the application. This is useful in cases where a global dispatcher is
     *   being used.
     *
     * @throws InvalidArgumentException
     */
    public function __construct($options, $dispatcher = null)
    {
        if (empty($options['index'])) {
            throw new \InvalidArgumentException('The "index" option is required.');
        }

        $this->_activeIndex = $options['index'];
        unset($options['index']);

        if ($options instanceof Elastica_Client) {
            $this->_client = $options;
        } else {
            $this->_client = new Elastica_Client($options);
        }

        if ($dispatcher instanceof EventDispatcher) {
            $this->setDispatcher($dispatcher);
        }

        $this->getDispatcher()->addSubscriber($this);
    }

    /**
     * Implements EventSubscriberInterface::getSubscribedEvents().
     */
    public static function getSubscribedEvents()
    {
        return array(
            SearchEvents::COLLECTION_PRE_INDEX => array('preIndexCollection'),
            SearchEvents::COLLECTION_POST_INDEX => array('postIndexCollection'),
        );
    }

    /**
     * Sets the Elastica_Client object.
     *
     * @param Elastica_Client $client
     *   The Elastica client.
     *
     * @return ElasticaSearchServer
     */
    public function setClient(Elastica_Client $client)
    {
        return $this->_client;
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
     * @return ElasticaSearchServer
     */
    public function setActiveIndex($index)
    {
        return $this->_activeIndex;
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
     * Overrides Search::Server::SearchServerAbstract::getDocument().
     *
     * Returns an Elastica specific search index document object.
     *
     * @return SolariumIndexDocument
     */
    public function newDocument()
    {
        return new ElasticaIndexDocument($this);
    }

    /**
     * Implements Search::Server::SearchServerAbstract::createIndex().
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

                // @todo Implement types.
                $properties[$field_name]['type'] = 'string';

                if (!$field->isIndexed()) {
                    $properties[$field_name]['index'] = 'no';
                }

                if (!$field->isStored()) {
                    $properties[$field_name]['store'] = true;
                }
            }

            $mapping->setProperties($properties);
            $mapping->send();
        }

    }

    /**
     * Listener for the SearchEvents::COLLECTION_PRE_INDEX event.
     *
     * @param SearchCollectionEvent $event
     */
    public function preIndexCollection(SearchCollectionEvent $event)
    {

    }

    /**
     * Implements Search::Server::SearchServerAbstract::indexDocument().
     *
     * @param SearchCollectionAbstract $collection
     * @param SolariumIndexDocument $document
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
        $native_doc->setType('item');
        $this->_documents[] = $native_doc;
    }

    /**
     * Listener for the SearchEvents::COLLECTION_POST_INDEX event.
     *
     * @param SearchCollectionEvent $event
     */
    public function postIndexCollection(SearchCollectionEvent $event)
    {
        $this->_client->addDocuments($this->_documents);
        $this->_client->getIndex($this->_activeIndex)->refresh();
    }

    /**
     * Implements Search::Server::SearchServerAbstract::search().
     *
     * @return Elastica_ResultSet
     */
    public function search($keywords, array $options = array())
    {
        $query = new Elastica_Search($this->_client);
        return $query->search($keywords);
    }

    /**
     * Implements Search::Server::SearchServerAbstract::delete().
     *
     * @return Elastica_Response Response object
     */
    public function delete()
    {
        $this->_client->getIndex($this->_activeIndex)->delete();
    }
}
