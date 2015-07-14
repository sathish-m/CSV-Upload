<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

$link = false;

$confArray = parse_ini_file("import.ini", TRUE);
$distributorConfig = array();
$itemMasterFields = "";
$itemMasterTableFields = "";
$itemDistributorFields = "";
$itemDistTableFields = "";
$tableName = "";
$currentDistributorId = 0;

$confDistributorList = array_keys($confArray);
$dbDistributorList = array();
$dbDistributorList = getDistributorList();

$distributorsToCreate = array_diff($confDistributorList, $dbDistributorList);

if (count($distributorsToCreate) > 0) {
    $response = createDistributors($distributorsToCreate);
}

foreach ($confArray as $key => $value) {
    global $distributorConfig, $currentDistributorId, $itemMasterFields, $tableName, 
           $itemMasterTableFields, $itemDistTableFields, $checkMrp, $isMappingHeaderAdded, $isMrpHeaderAdded;
    $currentDistributorId = $key;
    $distributorConfig = $value;
    $isMappingHeaderAdded = false;
    $isMrpHeaderAdded = false;
    $mappingExepFile = NULL;
    $mrpExcepFile = NULL;
    $sourceFolder = $value['sourceDir'];
    $exceptionDir = $distributorConfig['exceptionDir'];
    $mrpExceptionDir = $distributorConfig['priceExceptionDir'];
    $checkMrp = $distributorConfig['checkMrp'];
    $itemMasterFields = explode(",", $distributorConfig['itemMasterFields']);
    $itemDistributorFields = explode(",", $distributorConfig['itemDistFields']);
    $tableName = $distributorConfig['destinationTable'];
    $itemMasterTableFields = explode(",", $distributorConfig['tableFields']);
    $itemDistTableFields = explode(",", $distributorConfig['itemDistTableFields']);
    processFiles($sourceFolder);
}

function processFiles($src) {
    $files = scandir($src);
    if (count($files) > 0) {
        foreach ($files as $file) {
            if ($file != "." && $file != "..") {
                csvParsing($src, $file);
            }
        }
    }
}

function csvParsing($src, $file) {
    global $distributorConfig, $checkMrp;
    $result = '';
    $isInserted = false;
    $processedFolder = $distributorConfig['processedDir'];
    $defaults = array(
        'length' => 0,'delimiter' => ',','enclosure' => '"','escape' => '\\','headers' => true,'text' => false
    );
    $fields = array();
    $options = array();
    $options = array_merge($defaults, $options);
    if (($handle = fopen("$src/$file", "r")) !== FALSE) {
        if (empty($fields)) {
            // read the 1st row as headings
            $fields = fgetcsv($handle, $options['length'], $options['delimiter'], $options['enclosure']);
        }
        // return the messages
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $rowData = array();
            foreach ($data as $row) {
                array_push($rowData, addslashes($row));
            }
            $itemKeyValues = array_combine($fields, $rowData);
            if($checkMrp){
                if(isMrpEmpty($itemKeyValues)){
                    createMrpException($itemKeyValues);
                    continue;
                }
            }
            if($distributorConfig['isForceInsert']){
                $result = saveCSVData($itemKeyValues);                
            } else {
                $updateResult = mappingItemWithDistributor($itemKeyValues);
            }    
            if ($result || $updateResult) {
                $isInserted = true;
            } else {
                $isInserted = false;
            }
        }
        if ($isInserted) {
            copyFiles($file, $src, $processedFolder);
        }
        fclose($handle);
        return;
    }
}

function saveCSVData($itemKeyValues) {
    global $currentDistributorId, $tableName, $itemMasterTableFields, $itemDistTableFields;
    $filterItemData = filterItemMasterFields($itemKeyValues);
    $filterDistData = filterItemDistributorFields($itemKeyValues);
    $item_values = array_values($filterItemData);
    $itemDistValues = array_values($filterDistData);
    $query = "INSERT INTO " . $tableName . " (`" . implode("`, `", $itemMasterTableFields) . "`) values ('" . implode("', '", $item_values) . "')";
    $res = mysql_query($query, getMyConnection());
    if($res){
        $lastInsertItemId = mysql_insert_id();
        $nameRuleQuery = "INSERT INTO itemname_rules (`itemId`, `Name`) VALUES(".$lastInsertItemId.", '".$filterItemData['Name']."')";
        $nameRuleResult = mysql_query($nameRuleQuery, getMyConnection());
        if($nameRuleResult){
            $itemDistributorQuery = "INSERT INTO item_distributor (`ItemId`, `DistributorId`, `".implode("`, `", $itemDistTableFields)."`) VALUES(".$lastInsertItemId.", ".$currentDistributorId.", '".implode("', '", $itemDistValues)."')";
            $itemDistributorResult = mysql_query($itemDistributorQuery, getMyConnection());
        }
    }
    closeConnection();
    return $res;
}

function filterItemMasterFields($itemKeyValues){
    global $itemMasterFields;
    $processedData = array();
    
    $item_keys = array_keys($itemKeyValues);
    foreach ($itemMasterFields as $value) {
        if (in_array($value, $item_keys)) {
            $processedData[$value] = $itemKeyValues[$value];
        } else {
            $processedData[$value] = '';
        }
    }
    return $processedData;
}

function filterItemDistributorFields($itemKeyValues){
    global $itemDistributorFields;
    $processedData = array();
    $item_keys = array_keys($itemKeyValues);
    
    foreach ($itemDistributorFields as $value) {
        if (in_array($value, $item_keys)) {
            $processedData[$value] = $itemKeyValues[$value];
        } else {
            $processedData[$value] = '';
        }
    }
    return $processedData;
}

function isMrpEmpty($itemKeyValues){
    $filteredDistValues = filterItemDistributorFields($itemKeyValues);
    if(empty($filteredDistValues['Mrp'])){
        return true;
    } else {
        return false;
    }
}

function getMyConnection() {
    global $link;
    if ($link)
        return $link;
    $link = mysql_connect('localhost', 'root', 'root') or die('Could not connect to server.');
    mysql_select_db('jetpatray', $link) or die('Could not select database.');
    return $link;
}

function closeConnection() {
    global $link;
    if ($link != false)
        mysql_close($link);
    $link = false;
}

function copyFiles($file, $src, $dst) {
    if (file_exists("$src/$file")) {
        if(copy("$src/$file", "$dst/$file")){
            unlink( "$src/$file" );
        }
    }
}

function getDistributorList() {
    $distributorList = array();
    $query = "select Id from distributor_master order by Id";
    $result = mysql_query($query, getMyConnection());
    if (!$result) {
        die("Couldn't fetch result");
    }
    while ($row = mysql_fetch_assoc($result)) {
        $distributorList[] = $row['Id']; // Inside while loop
    }
    closeConnection();
    return $distributorList;
}

function createDistributors($distributorList) {
    $query = "INSERT INTO distributor_master (Id, ParentId) values (" . implode("), (", $distributorList) . ", 471)";
    $res = mysql_query($query, getMyConnection());
    closeConnection();
}

function mappingItemWithDistributor($itemKeyValues){
    global $currentDistributorId, $itemDistTableFields;
    $filterDistData = filterItemDistributorFields($itemKeyValues);
    $itemDistValues = array_values($filterDistData);
    $checkItemQuery = "SELECT ItemId FROM itemname_rules where `name` like '".$itemKeyValues['Name']."'";
    $result = mysql_query($checkItemQuery, getMyConnection());
    if (mysql_num_rows($result) > 0) {
        $nameRuleResult = mysql_fetch_assoc($result);
        if($nameRuleResult['ItemId']){
               $existingItemId = $nameRuleResult['ItemId'];
               $query = "INSERT INTO item_distributor (`ItemId`, `DistributorId`, `".implode("`, `", $itemDistTableFields)."`) "
                       . "VALUES(".$existingItemId.", ".$currentDistributorId.", '".implode("', '", $itemDistValues)."')"
                       . " ON DUPLICATE KEY UPDATE ItemId=".$existingItemId.", DistributorId= ".$currentDistributorId.", Mrp= ".$filterDistData['Mrp'].", SellingPrice=".$filterDistData['Selling Price'].", Offer='".$filterDistData['Offer']."'";
               $res = mysql_query($query, getMyConnection());
               return $res;
        }
    } else {
        createExceptionFile($itemKeyValues);
        return false;
    }
    closeConnection();
}

function createExceptionFile($itemKeyValues){
    global $exceptionDir, $isHeaderAdded, $itemMasterFields, $itemDistributorFields, $mappingExepFile;
    if(!$isHeaderAdded){
        $mappingExepFile = new SplFileObject($exceptionDir, 'w');
        $mappingExepFile->fputcsv(array_merge($itemMasterFields, $itemDistributorFields));    
        $isMappingHeaderAdded = true;
    }
    $filterItemData = filterItemMasterFields($itemKeyValues);
    $filterDistData = filterItemDistributorFields($itemKeyValues);
    $exceptionKeyValues = array_merge($filterItemData, $filterDistData);
    $mappingExepFile->fputcsv($exceptionKeyValues);
}

function createMrpException($itemKeyValues){
    global $mrpExceptionDir, $isHeaderAdded, $itemMasterFields, $itemDistributorFields, $mrpExcepFile;
    if(!$isHeaderAdded){
        $mrpExcepFile = new SplFileObject($mrpExceptionDir, 'w');
        $mrpExcepFile->fputcsv(array_merge($itemMasterFields, $itemDistributorFields));    
        $isMrpHeaderAdded = true;
    }
    $mrpExcepFile->fputcsv($itemKeyValues);
}
