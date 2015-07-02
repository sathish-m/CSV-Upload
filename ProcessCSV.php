<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

$confArray = parse_ini_file("import.ini");

$link = false;

$sourceFolder = $confArray['sourceDir'];


processFiles($sourceFolder);

function processFiles($src) {
    $files = scandir($src);
    if (count($files) > 0) {
        foreach ($files as $file) {
            if ($file != "." && $file != "..") {
                csvParsing($src,$file);
            }
        }
    }
}

function csvParsing($src, $file) {
    $isInserted = false;
    $processedFolder = "/home/sathish/FileUpload/Processed"; 
    $defaults = array(
        'length' => 0,
        'delimiter' => ',',
        'enclosure' => '"',
        'escape' => '\\',
        'headers' => true,
        'text' => false,    
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
            $keyValues = array_combine($fields, $rowData);
            $result = saveCSVData($keyValues);
            echo 'RESPONSE--'.$result;
            if($result){
                $isInserted = true;
            } else {
                $isInserted = false;
            }
        }
        if($isInserted){
            copyFiles($file, $src, $processedFolder);
        }
        fclose($handle);
        return;
    }
}

function saveCSVData($keyValues) {
    global $confArray;
    $processedData = array();
    $srcFields = explode(",", $confArray['sourceFields']);
    $tableName = $confArray['destinationTable'];
    $tableFields = explode(",", $confArray['tableFields']);
    $array_keys = array_keys($keyValues);
    foreach ($srcFields as $value) {
        if (in_array($value, $array_keys)) {
            $processedData[$value] = $keyValues[$value];
        } else {
            $processedData[$value] = '';
        }
    }
    $array_values = array_values($processedData);
    $query = "INSERT INTO " . $tableName . " (`" . implode("`, `", $tableFields) . "`) values ('" . implode("', '", $array_values) . "')";
    print_r($query);
    $res = mysql_query($query, getMyConnection());
    closeConnection();
    return $res;
}

function getMyConnection() {
    global $link;
    if ($link)
        return $link;
    $link = mysql_connect('localhost', 'root', 'root') or die('Could not connect to server.');
    mysql_select_db('jetPat', $link) or die('Could not select database.');
    return $link;
}

function closeConnection() {
    global $link;
    if ($link != false)
        mysql_close($link);
    $link = false;
}

function copyFiles($file, $src, $dst) {
    if (file_exists("$src/$file")){
        copy("$src/$file", "$dst/$file");
    }    
}

