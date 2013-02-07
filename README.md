Overview
========

This library integrates the Elastica project with the Search Framework library.

```php

// Index an RSS feed into Elasticsearch.

// @see https://github.com/cpliakas/feed-collection
use Search\Collection\Feed\FeedCollection;
use Search\Framework\SearchServiceEndpoint;
use Search\Service\Elasticsearch\ElasticsearchService;

require 'vendor/autoload.php';

$endpoint = new SearchServiceEndpoint('local', 'localhost', 'feeds', 9200);
$elasticsearch = new ElasticsearchService($endpoint);

// Associate the collection with the Solr server.
$drupal_planet = new FeedCollection();
$drupal_planet->setFeedUrl('http://drupal.org/planet/rss.xml');
$elasticsearch->attachCollection($drupal_planet);

// Create the index and put the mappings.
$elasticsearch->createIndex();

// Index the feeds into Solr.
$elasticsearch->index();
```