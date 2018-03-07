<?php
/**
 * Created by PhpStorm.
 * User: smadhavan
 * Date: 2/20/18
 * Time: 5:37 PM
 */

class PVSolrQuery
{
    private $solr_connections = array();
    private $db = null;
    private $errorHandler = null;
    private $entitySpecs = null;
    private $fieldSpecs = null;

    public function __construct(array $entitySpecs, array $fieldSpecs)
    {
        global $config;
        $this->entitySpecs = $entitySpecs;
        $this->fieldSpecs = $fieldSpecs;
        $currentDBSetting = $config->getSOLRSettings();
        $currentDBSetting["path"] = "solr/" . $entitySpecs[0]['solr_fetch_collection'];
        $this->solr_connections["main_entity_fetch"] = new SolrClient($currentDBSetting);

        foreach ($entitySpecs as $entitySpec) {
            if (!array_key_exists($entitySpec["entity_name"], $this->solr_connections)) {
                $currentDBSetting = $config->getSOLRSettings();

                $currentDBSetting["path"] = "solr/" . $entitySpec['solr_collection'];
                try {
//                file_put_contents('php://stderr', print_r($currentDBSetting, TRUE));
                    $this->solr_connections[$entitySpec["entity_name"]] = new SolrClient($currentDBSetting);
                } catch (SolrIllegalArgumentException $e) {

                    $this->errorHandler->sendError(500, "Failed to connect to database: $currentDBSetting[hostname] , $entitySpecs[solr_collection]", $e);
                    throw new $e;
                }
                //$this->solr_connections[$entitySpec["entity_name"]] = $currentDBSetting;
            }
        }

    }

    public function getSolrConnection($entity_name)
    {
        return $this->solr_connections[$entity_name];
    }

    public function loadQuery($whereClause, $queryDefId, $db, $table_usage, array $isSecondaryKeyUpdate, $level = 0)
    {
        $base = 1;
        if ($table_usage["base"][0] == 1) {
            $base = 2;
        }
        if (!(array_key_exists("AND", $whereClause)) && (!array_key_exists("OR", $whereClause))) {
            $this->loadEntityQuery(getEntitySpecs($this->entitySpecs, $whereClause["e"]), $whereClause["q"], $queryDefId, $db, $table_usage, $base, $whereClause["s"]);

        } else {
            foreach (array_keys($whereClause) as $whereJoin) {
                if ($table_usage["base"][0] == 1) {
                    $base = 2;
                }
                $isSecondaryKeyUpdate[$level] = false;
                $i = 0;
                $joins = array_keys($whereClause[$whereJoin]);
                foreach ($whereClause[$whereJoin] as $clause) {
                    if ((array_key_exists("s", $clause) && $clause["s"])) {
                        $isSecondaryKeyUpdate[$level] = true;
                    }
                }

                foreach ($whereClause[$whereJoin] as $clause) {

                    if (array_key_exists("e", $clause)) {
                        $secondarySoFar = array_sum(array_slice($isSecondaryKeyUpdate, 0, $level + 1));
                        $table_usage = $this->loadEntityQuery(getEntitySpecs($this->entitySpecs, $clause["e"]), $clause["q"], $queryDefId, $db, $table_usage, $base, $secondarySoFar);

                    } else {
                        $table_usage = $this->loadQuery($clause, $queryDefId, $db, $table_usage, $isSecondaryKeyUpdate, $level + 1);
                        if ((array_sum($table_usage['base']) > 0) && (array_sum($table_usage["supp"]) > 0) || ((array_sum($table_usage['base']) > 1))) {
                            $table_usage = $db->updateBase($whereJoin, $table_usage, $queryDefId, $isSecondaryKeyUpdate[$level]);
                            $isSecondaryKeyUpdate[$level] = false;
                            for ($k = $i; $k < count($joins); $k++) {
                                $lookAheadClause = $whereClause[$joins[$k]];
                                if ((array_key_exists("s", $lookAheadClause) && $lookAheadClause["s"])) {
                                    $isSecondaryKeyUpdate[$level] = true;
                                }
                            }
                        }
                    }
                    if ((array_sum($table_usage['base']) > 0) && (array_sum($table_usage["supp"]) > 0)) {
                        $table_usage = $db->updateBase($whereJoin, $table_usage, $queryDefId, $isSecondaryKeyUpdate[$level]);

                        $isSecondaryKeyUpdate[$level] = false;
                        for ($k = $i; $k < count($joins); $k++) {
                            $lookAheadClause = $whereClause[$joins[$k]];
                            if ((array_key_exists("s", $lookAheadClause) && $lookAheadClause["s"])) {
                                $isSecondaryKeyUpdate[$level] = true;
                            }
                        }

                    }
                    $base = 1;
                    $i += 1;
                }

            }

        }
        return $table_usage;
    }

    public function loadEntityQuery($entitySpec, $query_string, $queryDefId, $db, $table_usage, $base, $useSecondary = false)
    {

        if ($table_usage["base"][0] == 0) {
            $tableName = "QueryResultsBase";
            $baseKey = "base";
            $baseIndex = 0;
            //$table_usage["base"][0] = 1;
        } else if ($base == 2 && $table_usage["base"][1] == 0) {
            $tableName = "QueryResultsBaseLevel2";
            $baseKey = "base";
            $baseIndex = 1;
            //$table_usage["base"][1] = 1;
        } else if ($table_usage["supp"][0] == 0) {
            $tableName = "QueryResultsSupp";
            $baseKey = "supp";
            $baseIndex = 0;
            //$table_usage["supp"][0] = 1;
        }

        $connectionToUse = $this->solr_connections[$entitySpec["entity_name"]];
        $query = new SolrQuery();
        $query->setQuery($query_string);
        $db->connectToDb();
        $db->startTransaction();
        $query->setGroup(true);
        $keyField = $this->entitySpecs[0]["solr_key_id"];
        $fieldPresence = array("keyField" => $keyField);
        $query->addField($keyField);
        $query->addGroupField($keyField);
        if (array_key_exists("secondary_key_id", $entitySpec) & $useSecondary) {
            $secondaryKeyField = $entitySpec["secondary_key_id"];
            $query->addField($secondaryKeyField);
            $query->addGroupField($secondaryKeyField);
            $fieldPresence["secondaryKeyField"] = $secondaryKeyField;
        }

        $rows_fetched = 0;
        $total_fetched = 0;
        $keys = array();
        try {
            do {
                $query->setRows(10000);
                $query->setStart($total_fetched);

                //http://ec2-52-23-55-147.compute-1.amazonaws.com:8983/solr/location_patent_join/select?indent=on&q=patents.patent_num_cited_by_us_patents%20:%203&wt=json&group=true&group.main=true&group.field=location_key_id&group.field=inventor_id&fl=location_key_id,inventor_id&rows=10000


                try {
                    //$query->setTimeAllowed(300000);
                    $q = $connectionToUse->query($query);
                } catch (SolrClientException $e) {

                }
                $response = $q->getResponse();
                $rows_fetched = count($response["grouped"][$keyField]["groups"]);
                $table_usage[$baseKey][$baseIndex] = 1;
                if ($rows_fetched < 1) {
                    break;
                }


//                foreach ($response["response"]["docs"] as $doc) {
//                    if (!array_key_exists($doc->location_key_id, $keys)) {
//                        $keys[$doc->location_key_id] = 0;
//                    }else{
//                        null;
//                    }
//                    $keys[$doc->location_key_id] += 1;
//                }
                $db->loadEntityID($response["grouped"][$keyField]["groups"], $fieldPresence, $queryDefId, $tableName, $total_fetched);
                $total_fetched += $rows_fetched;


            } while (True);
            $db->commitTransaction();
        } catch (PDOException $e) {
            $db->rollbackTransaction();
        }

        return $table_usage;
    }

    public
    function fetchQuery($fieldList, $whereClause, $queryDefId, $db, $options)
    {
        $rows = 25;
        $start = 0;
        $matchSubEntityOnly = false;
        $subEntityCounts = false;
        if ($options) {

            if (array_key_exists("per_page", $options)) {
                $rows = (int)$options["per_page"];
            }

            if (array_key_exists("page", $options)) {
                $start = ($rows) * ($options["page"] - 1);
            }

            if (array_key_exists("matched_subentities_only", $options)) {
                $matchSubEntityOnly = $options["matched_subentities_only"];
            }

            if (array_key_exists("include_subentity_total_counts ", $options)) {
                $subEntityCounts = $options["include_subentity_total_counts"];
            }
        }

        $entityValuesToFetch = $db->retrieveEntityIdForSolr($queryDefId, $start, $rows);
        if (count($entityValuesToFetch) < 1) {
            return array("db_results" => array($this->entitySpecs[0]["entity_name"] => array()), "count_results" => array("total_" . $this->entitySpecs[0]["entity_name"] . "_count" => 0));
        }
        $entityValueString = "( " . (implode(" ", $entityValuesToFetch)) . " ) ";
        $entitiesToFetch = array_keys($fieldList);
        $returned_values = array();
        $main_group = $this->entitySpecs[0]["group_name"];
        $return_array = array();
        foreach ($entitiesToFetch as $entity) {
            $solr_response = $this->fetchEntityQuery($entity, $this->entitySpecs[0]["solr_key_id"] . ":" . $entityValueString, $start, $rows, $fieldList[$entity]);
            $current_array = array();
            foreach ($solr_response["docs"] as $solrDoc) {
                $keyName = $this->entitySpecs[0]["solr_key_id"];
                if (!array_key_exists($solrDoc->$keyName, $current_array)) {
                    $current_array[$solrDoc->$keyName] = array();
                }
                $current_array[$solrDoc->$keyName][] = $solrDoc;
            }
            if ($subEntityCounts || $entity == $this->entitySpecs[0]["entity_name"]) {
                $total_count = $this->getEntityCounts($entity, $queryDefId, $db);
                $return_array["total_" . $entity . "_count"] = $total_count;
            }

            $returned_values[$entity] = $current_array;
        }
        if (!array_key_exists($this->entitySpecs[0]["entity_name"], $returned_values)) {
            $solr_response = $this->fetchEntityQuery($this->entitySpecs[0]["entity_name"], $this->entitySpecs[0]["solr_key_id"] . ":" . $entityValueString, $start, $rows, array($this->fieldSpecs[$this->entitySpecs[0]["solr_key_id"]]));
            $current_array = array();
            foreach ($solr_response["docs"] as $solrDoc) {
                $keyName = $this->entitySpecs[0]["solr_key_id"];
                if (!array_key_exists($solrDoc->$keyName, $current_array)) {
                    $current_array[$solrDoc->$keyName] = array();
                }
                $current_array[$solrDoc->$keyName][] = $solrDoc;
            }
            if ($subEntityCounts || $this->entitySpecs[0]["entity_name"] == $this->entitySpecs[0]["entity_name"]) {
                $return_array["total_" . $this->entitySpecs[0]["entity_name"] . "_count"] = $solr_response["numFound"];
            }

            $returned_values[$this->entitySpecs[0]["entity_name"]] = $current_array;
        }
        return array("db_results" => $returned_values, "count_results" => $return_array);
    }

    public
    function fetchEntityQuery($entity_name, $queryString, $start, $rows, $fieldList)
    {

        $connectionToUse = $this->solr_connections[$entity_name];
        if ($entity_name == $this->entitySpecs[0]["entity_name"]) {
            $connectionToUse = $this->solr_connections["main_entity_fetch"];
        }
        $query = new SolrQuery();
        //$query->setTimeAllowed(300000);
        $query->setQuery($queryString);
        $query->setStart($start);
        $query->setRows($rows);
        foreach (array_keys($fieldList) as $field) {
            $query->addField($fieldList[$field]["solr_column_name"]);
        }

        $q = $connectionToUse->query($query);
        $response = $q->getResponse();
        return $response["response"];

    }

    public function getEntityCounts($entity, $queryDefId, $db)
    {
        $rows = 1000;
        $offset = 0;
        $entity_count = 0;

        if ($entity == $this->entitySpecs[0]["entity_name"]) {

            $entityValuesToFetch = $db->retrieveEntityIdForSolr($queryDefId, $offset, $rows, true);
            return count($entityValuesToFetch);
        }

        do {

            $entityValuesToFetch = $db->retrieveEntityIdForSolr($queryDefId, $offset, $rows);
            if (count($entityValuesToFetch) < 1) {
                break;
            }
            $entityValueString = "( " . (implode(" ", $entityValuesToFetch)) . " ) ";
            $entity_count += $this->countRowsForQuery($entity, $this->entitySpecs[0]["solr_key_id"] . ":" . $entityValueString);
            $offset += $rows;
        } while (true);
        return $entity_count;
    }

    public function countRowsForQuery($entity, $where)
    {
        $connectionToUse = $this->solr_connections[$entity];
        $query = new SolrQuery();
        $query->setStart(0);
        $query->setRows(0);
        $query->setQuery($where);
        $q = $connectionToUse->query($query);
        $response = $q->getResponse();
        return $response["response"]["numFound"];
    }

}