Overview
========

This library integrates the Elastica project with the Search Framework library.
The following code is an example of how to index RSS feeds into Elasticsearch.

```php

use Search\Framework\Indexer;
use Search\Framework\SearchServiceEndpoint;

use Search\Collection\Feed\FeedCollection;     // @see https://github.com/cpliakas/feed-collection
use Search\Engine\Elasticsearch\Elasticsearch; // @see https://github.com/cpliakas/elasticsearch-engine

require 'vendor/autoload.php';

// Instantiate a collection that references the Drupal Planet feed. Collections
// are simply connectors to and models of the source data being indexed.
$drupal_planet = new FeedCollection('feed.drupal');
$drupal_planet->setFeedUrl('http://drupal.org/planet/rss.xml');

// Connect to an Elasticsearch server.
$elasticsearch = new Elasticsearch(new SearchEngineEndpoint('local', 'localhost', 'feeds', 9200));

// Instantiate an indexer, attach the collection, and index it.
$indexer = new Indexer($elasticsearch);
$indexer->attachCollection($drupal_planet);
$indexer->createIndex();
$indexer->index();

```