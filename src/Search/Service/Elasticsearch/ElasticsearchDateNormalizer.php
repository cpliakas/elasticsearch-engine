<?php

/**
 * Elasticservice search service for the Search Framework library.
 *
 * @license http://www.gnu.org/licenses/lgpl-3.0.txt
 */

namespace Search\Service\Elasticsearch;

use Search\Framework\SearchNormalizerInterface;

/**
 * Models a document containing the source data being indexed.
 *
 * This object adds Lucene specific properties, such as document boosting.
 */
class ElasticsearchDateNormalizer implements SearchNormalizerInterface
{
    /**
     * The format that dates are normalized to.
     *
     * @var string
     *
     * @see http://php.net/manual/en/function.date.php
     */
    protected $_dateFormat;

    /**
     * Constructs a ElasticsearchDateNormalizer object.
     *
     * @param type $date_format
     *   The data format that the value is transformed into.
     */
    public function __construct($date_format = 'Y-m-d\TH:i:s\Z')
    {
        $this->_dateFormat = $date_format;
    }

    /**
     * Implements SearchNormalizerInterface::normalize().
     *
     * Normalizes date formats to avoid errors thrown by Elasticsearch.
     */
    public function normalize($value)
    {
        if ($value) {
            if (is_int($value) || ctype_digit($value)) {
                $timestamp = $value;
            } elseif (!$timestamp = strtotime($value)) {
                return $value;
            }
            return date($this->_dateFormat, $timestamp);
        }
        return $value;
    }
}