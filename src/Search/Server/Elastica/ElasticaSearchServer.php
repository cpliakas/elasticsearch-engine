<?php

/**
 * Search Framework
 *
 * @license http://www.gnu.org/licenses/lgpl-3.0.txt
 */

namespace Search\Server\Elastica;


use Elastica_Client;
use Elastica_Document;
use Elastica_Search;
use Search\Framework\Event\SearchCollectionEvent;
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
     * @param SolariumIndexDocument $document
     */
    public function indexDocument(SearchIndexDocument $document)
    {
        $index_doc = array();

        if (null !== ($boost = $document->getBoost())) {
            $index_doc['_boost'] = $boost;
        }

        foreach ($document as $field_id => $normalized_value) {
            $name = $document->getFieldName($field_id);
            $index_doc[$name] = $normalized_value;
        }

        // @todo Figure out how to get unique field from schema.
        $id = uniqid();

        $native_doc = new Elastica_Document($id, $index_doc);
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

    /**
     * Pass all other method calls directly to the Solarium client.
     */
    public function __call($method, $args)
    {
        return call_user_func_array(array($this->_client, $method), $args);
    }
}
