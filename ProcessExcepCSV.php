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

foreach ($confArray as $key => $value) {
    global $distributorConfig, $currentDistributorId, $itemMasterFields, $tableName,
    $itemMasterTableFields, $itemDistTableFields, $checkMrp, $isMappingHeaderAdded, $isMrpHeaderAdded;
    $currentDistributorId = $key;
    $distributorConfig = $value;
    $isMappingHeaderAdded = false;
    $isMrpHeaderAdded = false;
    $mappingExepFile = NULL;
    $mrpExcepFile = NULL;
    $sourceFolder = $distributorConfig['exceptionDir'];
    $exceptionDir = $distributorConfig['exceptionDir'];
    $mrpExceptionDir = $distributorConfig['priceExceptionDir'];
    $checkMrp = $distributorConfig['checkMrp'];
    $itemMasterRawFields = explode(",", $distributorConfig['itemMasterFields']);
    $itemMasterFields = array_map('strtolower', $itemMasterRawFields);
    $itemDistributorRawFields = explode(",", $distributorConfig['itemDistFields']);
    $itemDistributorFields = array_map('strtolower', $itemDistributorRawFields);
    
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
        'length' => 0, 'delimiter' => ',', 'enclosure' => '"', 'escape' => '\\', 'headers' => true, 'text' => false
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
            if ($checkMrp) {
                if (isMrpEmpty($itemKeyValues)) {
                    createMrpException($itemKeyValues);
                    continue;
                }
            }
            if (!empty($itemKeyValues['isNew']) && $itemKeyValues['isNew'] == 1) {
                $result = saveCSVData($itemKeyValues);
            } else if (!empty($itemKeyValues['itemId']) && is_numeric($itemKeyValues['itemId'])) {
                $itemId = $itemKeyValues['itemId'];
                $updateResult = mappingItemWithDistributor($itemId, $itemKeyValues);
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
    if ($res) {
        $lastInsertItemId = mysql_insert_id();
        $nameRuleQuery = "INSERT INTO itemname_rules (`itemId`, `Name`) VALUES(" . $lastInsertItemId . ", '" . $filterItemData['name'] . "')";
        $nameRuleResult = mysql_query($nameRuleQuery, getMyConnection());
        if ($nameRuleResult) {
            $itemDistributorQuery = "INSERT INTO item_distributor (`ItemId`, `DistributorId`, `" . implode("`, `", $itemDistTableFields) . "`) VALUES(" . $lastInsertItemId . ", " . $currentDistributorId . ", '" . implode("', '", $itemDistValues) . "')";
            $itemDistributorResult = mysql_query($itemDistributorQuery, getMyConnection());
        }
    }
    closeConnection();
    return $res;
}

function filterItemMasterFields($itemKeyValues) {
    global $itemMasterFields;
    $processedData = array();
    $itemKeyValues = array_change_key_case($itemKeyValues, CASE_LOWER);
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

function filterItemDistributorFields($itemKeyValues) {
    global $itemDistributorFields;
    $processedData = array();
    $itemKeyValues = array_change_key_case($itemKeyValues, CASE_LOWER);
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

function isMrpEmpty($itemKeyValues) {
    $filteredDistValues = filterItemDistributorFields($itemKeyValues);
    if (empty($filteredDistValues['mrp'])) {
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
        if (copy("$src/$file", "$dst/$file")) {
            unlink("$src/$file");
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

function isItemExist($itemId) {
    $query = "select itemMaster.Id from item_master itemMaster where itemMaster.Id = $itemId";
    $result = mysql_query($query, getMyConnection());
    if (mysql_num_rows($result) > 0) {
        return true;
    } else {
        return false;
    }
    closeConnection();
}

function mappingItemWithDistributor($itemId, $itemKeyValues) {
    global $currentDistributorId, $itemDistTableFields;
    $filterDistData = filterItemDistributorFields($itemKeyValues);
    $itemDistValues = array_values($filterDistData);
    $filterItemData = filterItemMasterFields($itemKeyValues);

    $nameRuleQuery = "INSERT INTO itemname_rules (`itemId`, `Name`) VALUES(" . $itemId . ", '" . $filterItemData['name'] . "')";
    $nameRuleResult = mysql_query($nameRuleQuery, getMyConnection());
    if ($nameRuleResult) {
        $query = "INSERT INTO item_distributor (`ItemId`, `DistributorId`, `" . implode("`, `", $itemDistTableFields) . "`) "
                . "VALUES(" . $itemId . ", " . $currentDistributorId . ", '" . implode("', '", $itemDistValues) . "')"
                . " ON DUPLICATE KEY UPDATE ItemId=" . $itemId . ", DistributorId= " . $currentDistributorId . ", Mrp= " . $filterDistData['mrp'] . ", SellingPrice=" . $filterDistData['selling price'] . ", Offer='" . $filterDistData['offer'] . "'";
        $res = mysql_query($query, getMyConnection());
        return $res;
    }
    closeConnection();
}

function createMrpException($itemKeyValues) {
    global $mrpExceptionDir, $isHeaderAdded, $itemMasterFields, $itemDistributorFields, $mrpExcepFile;
    if (!$isHeaderAdded) {
        $mrpExcepFile = new SplFileObject($mrpExceptionDir, 'w');
        $mrpExcepFile->fputcsv(array_merge($itemMasterFields, $itemDistributorFields));
        $isMrpHeaderAdded = true;
    }
    $mrpExcepFile->fputcsv($itemKeyValues);
}
