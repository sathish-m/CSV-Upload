<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

$sourceFolder = $argv[1];
$destinationFolder = $argv[2]; 

copyFiles($sourceFolder, $destinationFolder);

// Function to Copy folders and files       
function copyFiles($src, $dst) {
    if (file_exists($dst))
        removeDir($dst);
    if (is_dir($src)) {
        mkdir($dst);
        $files = scandir($src);
        foreach ($files as $file)
            if ($file != "." && $file != "..")
                copyFiles("$src/$file", "$dst/$file");
    } else if (file_exists($src)){
        copy($src, $dst);
        removeDir($src);  
    }    
}

// Function to remove folders and files 
function removeDir($dir) {
    if (is_dir($dir)) {
        $files = scandir($dir);
        foreach ($files as $file)
            if ($file != "." && $file != "..")
                removeDir("$dir/$file");
        rmdir($dir);
    }
    else if (file_exists($dir))
        unlink($dir);
}

