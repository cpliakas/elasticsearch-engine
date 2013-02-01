<?php

/**
 * Elastica search server for the Search Framework library.
 *
 * @license http://www.gnu.org/licenses/lgpl-3.0.txt
 */

namespace Search\Server\Elastica;

use Search\Framework\SearchIndexDocument;

/**
 * Models a document containing the source data being indexed.
 *
 * This object adds Lucene specific properties, such as document boosting.
 */
class ElasticaIndexDocument extends SearchIndexDocument
{
    /**
     * The document level boost set for this document.
     */
    protected $_boost;

    /**
     * Sets the document level boost.
     *
     * @param float $boost
     *   The boost factor applied to the field.
     *
     * @return ElasticaIndexDocument
     */
    public function setBoost($boost)
    {
        $this->_boost = $boost;
        return $this;
    }

    /**
     * Returns the document level boost.
     *
     * @return float
     */
    public function getBoost()
    {
        return $this->_boost;
    }
}
