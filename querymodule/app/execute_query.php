<?php
require_once dirname(__FILE__) . '/QueryParser.php';
require_once dirname(__FILE__) . '/query_database.php';
require_once dirname(__FILE__) . '/convertDBResultsToNestedStructure.php';
require_once dirname(__FILE__) . '/parse_fields.php';

function executeQuery(array $queryParam=null, array $fieldsParam=null)
{
    $pq = new QueryParser();
    $whereClause = $pq->parse($queryParam);
    if (!$fieldsParam) $fieldsParam = $pq->getFieldsUsed();
    $selectFieldSpecs = parseFieldList($fieldsParam);
    $dbQuery = new DatabaseQuery();
    $dbResults = $dbQuery->queryDatabase($whereClause, $selectFieldSpecs);
    $results = convertDBResultsToNestedStructure($dbResults);
    return $results;
}