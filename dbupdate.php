<?php
// Module dbupdate.php

// This file is part of huygens remote manager.

// Copyright: Montpellier RIO Imaging (CNRS) 

// contributors : 
// 	     Pierre Travo	(concept)	     
// 	     Volker Baecker	(concept, implementation)

// email:
// 	pierre.travo@crbm.cnrs.fr
// 	volker.baecker@crbm.cnrs.fr

// Web:     www.mri.cnrs.fr

// huygens remote manager is a software that has been developed at 
// Montpellier Rio Imaging (mri) in 2004 by Pierre Travo and Volker 
// Baecker. It allows running image restoration jobs that are processed 
// by 'Huygens professional' from SVI. Users can create and manage parameter 
// settings, apply them to multiple images and start image processing 
// jobs from a web interface. A queue manager component is responsible for 
// the creation and the distribution of the jobs and for informing the user 
// when jobs finished.

// This software is governed by the CeCILL license under French law and
// abiding by the rules of distribution of free software. You can use, 
// modify and/ or redistribute the software under the terms of the CeCILL
// license as circulated by CEA, CNRS and INRIA at the following URL
// "http://www.cecill.info". 

// As a counterpart to the access to the source code and  rights to copy,
// modify and redistribute granted by the license, users are provided only
// with a limited warranty and the software's author, the holder of the
// economic rights, and the successive licensors  have only limited
// liability. 

// In this respect, the user's attention is drawn to the risks associated
// with loading, using, modifying and/or developing or reproducing the
// software by the user in light of its specific status of free software,
// that may mean that it is complicated to manipulate, and that also
// therefore means that it is reserved for developers and experienced
// professionals having in-depth IT knowledge. Users are therefore encouraged
// to load and test the software's suitability as regards their requirements
// in conditions enabling the security of their systems and/or data to be
// ensured and, more generally, to use and operate it in the same conditions
// as regards security. 

// The fact that you are presently reading this means that you have had
// knowledge of the CeCILL license and that you accept its terms.

include "inc/hrm_config.inc";
include "inc/reservation_config.inc";
include $adodb;


// Temporary part
$db_name_test = "hrm-test";


// Last database version
$last_version = 2;

// Current data
$current_date = date('l jS \of F Y h:i:s A');


// Error file
$error_file = "run/dbupdate_error.log";
$efh = fopen($error_file, 'a') or die(); // If the file does not exist, it is created
write_to_error($current_date . "\n");


// Log file
$log_file = "run/dbupdate.log";
$fh = fopen($log_file, 'a');
if(!$fh) {
    $message = "Can't open the dbupdate log file.\n";
    write_to_error($message);
    die();
}
write_to_log($current_date . "\n");

 
// Connect to the database   
$connection = ADONewConnection($db_type);
$result = $connection->Connect($db_host, $db_user, $db_password, $db_name_test); 
if(!$result)
    exit("Database connection failed.\n");   // OK cosi , poi ridirezione??


// Read the database current version
$query = "SELECT * FROM global_variables";  // Check if the table global_variables exists
$result = $connection->Execute($query);
if(!$result) {  // If the table does not exist, create it
    $query = "CREATE TABLE `global_variables` (`variable` VARCHAR( 30 ) NOT NULL,
                                                `value` VARCHAR( 30 ) NOT NULL DEFAULT 0)";
    $test = $connection->Execute($query);
    if(!$test) {
       $message = error_message("global_variables");
       write_to_error($message);
       die();
    }
    $message = "The table global_variables has been created\n";
    write_to_log($message);
}
$query = "SELECT value FROM global_variables WHERE variable = 'dbversion'"; // Check if the record dbversion does exist
$result = $connection->Execute($query);
$rows = $result->GetRows();
if(count($rows) == 0) { // If the record dbversion does not exist, create it and set the value to 0
    $query = "INSERT INTO global_variables (variable, value) VALUES ('dbversion','0')";
    $result = $connection->Execute($query);
    if(!$result) {
        $message = error_message("global_variables");
        write_to_error($message);
        die();
    }
    $current_version = 0;
    $message = "The db version has been set to 0\n";
    write_to_log($message);
}
else {
    $current_version = $rows[0][0];
}


// Check existing database
// -----------------------

// Check 'boundary_values'
// -----------------------
$table = "boundary_values";
$table_structure = "`parameter` VARCHAR( 255 ) NOT NULL DEFAULT '0',
                    `min` VARCHAR( 30 ) NULL DEFAULT NULL ,
                    `max` VARCHAR( 30 ) NULL DEFAULT NULL ,
                    `min_included` ENUM( 't', 'f' ) NULL DEFAULT 't',
                    `max_included` ENUM( 't', 'f' ) NULL DEFAULT 't',
                    `standard` VARCHAR( 30 ) NULL DEFAULT NULL";
$table_content = array("parameter"=>array("'PinholeSize'","'RemoveBackgroundPercent'","'BackgroundOffsetPercent'","'ExcitationWavelength'",
                                          "'EmissionWavelength'","'CMount'","'TubeFactor'","'CCDCaptorSizeX'",
                                          "'CCDCaptorSizeY'","'ZStepSize'","'TimeInterval'","'SignalNoiseRatio'",
                                          "'NumberOfIterations'","'QualityChangeStoppingCriterion'"),
                       "min"=>array("'0'","'0'","'0'","'0'","'0'","'0.4'","'1'","'1'","'1'","'50'","'0.001'","'0'","'1'","'0'"),
                       "max"=>array("'NULL'","'100'","''","'NULL'","'NULL'","'1'","'2'","'25000'","'25000'","'600000'","'NULL'","'100'","'100'","'NULL'"),
                       "min_included"=>array("'f'","'f'","'t'","'f'","'f'","'t'","'t'","'t'","'t'","'t'","'f'","'f'","'t'","'t'"),
                       "max_included"=>array("'t'","'t'","'f'","'t'","'t'","'t'","'t'","'t'","'t'","'t'","'t'","'t'","'t'","'t'"),
                       "standard"=>array("'NULL'","'NULL'","'NULL'","'NULL'","'NULL'","'1'","'1'","'NULL'","'NULL'","'NULL'","'NULL'","'NULL'","'NULL'","'NULL'"));
$n_key = 0;
check_table_existence($table_structure);
check_records($table_content,$n_key);


// Check 'file_extension'
$table = "file_extension";
$table_structure = "`file_format` VARCHAR( 30 ) NOT NULL DEFAULT '0',
                    `extension` VARCHAR( 4 ) NOT NULL ,
                    `file_format_key` VARCHAR( 30 ) NOT NULL DEFAULT '0'";
$table_content = array("file_format"=>array("'dv'","'ics'","'ics2'","'ims'","'lif'","'lsm'","'lsm-single'","'ome-xml'",
                                              "'pic'","'stk'","'tiff'","'tiff-leica'","'tiff-series'","'tiff-single'",
                                              "'tiff'","'tiff-leica'","'tiff-series'","'tiff-single'"),
                       "extension"=>array("'dv'","'ics'","'ics2'","'ims'","'lif'","'lsm'","'lsm'","'ome'",
                                              "'pic'","'stk'","'tif'","'tif'","'tif'","'tif'",
                                              "'tiff'","'tiff'","'tiff'","'tiff'"),
                       "file_format_key"=>array("'dv'","'ics'","'ics2'","'ims'","'lif'","'lsm'","'lsm-single'","'ome-xml'",
                                              "'pic'","'stk'","'tiff'","'tiff-leica'","'tiff-series'","'tiff-single'",
                                              "'tiff2'","'tiff-leica2'","'tiff-series2'","'tiff-single2'"));
$n_key = 2;
if($current_version == 1) { // it is necessary to create a column that works as a key
    $query = "SELECT * FROM ". $table;  // check if the table exist   
    $result = $connection->Execute($query);
    if($result) {     
        $query = "DROP TABLE ". $table; // delete the table
        $test = $connection->Execute($query);
        if(!$test) {
            $message = error_message($table);
            write_to_error($message);
            die();
        }
        $message = "The table file_extension need a ribuild, therefore it has been deleted.\n";
        write_to_log($message);
    }
}
check_table_existence($table_structure);
check_records($table_content,$n_key);


// Check 'file_format'
// -------------------
$table = "file_format";
$table_structure = "`name` VARCHAR( 30 ) NOT NULL DEFAULT '0',
                    `isFixedGeometry` ENUM( 't', 'f' ) NOT NULL DEFAULT 't' ,
                    `isSingleChannel` ENUM( 't', 'f' ) NOT NULL DEFAULT 't' ,
                    `isVariableChannel` ENUM( 't', 'f' ) NOT NULL DEFAULT 't'";
$table_content = array("name"=>array("'dv'","'ics'","'ics2'","'ims'","'lif'","'lsm'","'lsm-single'","'ome-xml'",
                                    "'pic'","'stk'","'tiff'","'tiff-leica'","'tiff-series'","'tiff-single'"),
                       "isFixedGeometry"=>array("'f'","'f'","'f'","'f'","'f'","'f'","'t'","'f'","'f'","'f'","'f'","'f'","'f'","'t'"),
                       "isSingleChannel"=>array("'f'","'f'","'f'","'f'","'f'","'f'","'f'","'f'","'f'","'f'","'f'","'f'","'f'","'f'"),
                       "isVariableChannel"=>array("'t'","'t'","'t'","'t'","'t'","'t'","'t'","'t'","'t'","'t'","'t'","'t'","'t'","'t'"));
$n_key = 0;
check_table_existence($table_structure);
check_records($table_content,$n_key);


// Check 'geometry'
// ----------------
$table = "geometry";
$table_structure = "`name` VARCHAR( 30 ) NOT NULL DEFAULT '0',
                    `isThreeDimensional` ENUM( 't', 'f' ) NULL DEFAULT 'NULL' ,
                    `isTimeSeries` ENUM( 't', 'f' ) NULL DEFAULT 'NULL'";
$table_content = array("name"=>array("'XYZ'","'XYZ - time'","'XY - time'"),
                       "isThreeDimensional"=>array("'t'","'t'","'f'"),
                       "isTimeSeries"=>array("'f'","'t'","'t'"));
$n_key = 0;
check_table_existence($table_structure);
check_records($table_content,$n_key);


// Check 'global_variables'
// ------------------------
$table = "global_variables";
$table_structure = "`variable` VARCHAR( 30 ) NOT NULL,
                    `value` VARCHAR NOT NULL DEFAULT '0'";
$table_content = array("variable"=>array("'dbversion'"),
                       "value"=>array("'" . $current_version . "'"));
$n_key = 0;
check_table_existence($table_structure);
check_records($table_content,$n_key);


// Check 'possible_values'
// -----------------------
$table = "possible_values";
$table_structure = "`parameter` VARCHAR( 30 ) NOT NULL DEFAULT '0',
                    `value` VARCHAR( 255 ) NULL DEFAULT 'NULL',
                    `translation` VARCHAR( 50 ) NULL DEFAULT 'NULL',
                    `isDefault` ENUM( 't', 'f' ) NULL DEFAULT 'f',
                    `parameter_key` VARCHAR( 30 ) NOT NULL DEFAULT '0'";
$table_content = array("parameter"=>array("'IsMultiChannel'","'IsMultiChannel'",
                                          "'ImageFileFormat'","'ImageFileFormat'","'ImageFileFormat'","'ImageFileFormat'","'ImageFileFormat'","'ImageFileFormat'","'ImageFileFormat'","'ImageFileFormat'",
                                          "'NumberOfChannels'","'NumberOfChannels'","'NumberOfChannels'","'NumberOfChannels'",
                                          "'ImageGeometry'","'ImageGeometry'","'ImageGeometry'",
                                          "'MicroscopeType'","'MicroscopeType'","'MicroscopeType'","'MicroscopeType'",
                                          "'ObjectiveMagnification'","'ObjectiveMagnification'","'ObjectiveMagnification'","'ObjectiveMagnification'",
                                          "'ObjectiveType'","'ObjectiveType'","'ObjectiveType'",
                                          "'SampleMedium'","'SampleMedium'"
                                          ),
                       "value"=>array("'True'","'False'",
                                      "'dv'","'stk'","'tiff-series'","'tiff-single'","'ims'","'lsm'","'lsm-single'","'pic'",
                                      "1","2","3","4",
                                      "'XYZ'","'XY - time'","'XYZ - time'",
                                      "'widefield'","'multipoint confocal (spinning disk)'","'single point confocal'","'two photon '",
                                      "'10'","'20'","'25'","'40'",
                                      "'oil'","'water'","'air'",
                                      "'water / buffer'","'liquid vectashield / 90-10 (v:v) glycerol - PBS ph 7.4'"
                                      ),
                       "translation"=>array("''","''",
                                            "'Delta Vision (*.dv)'","'Metamorph (*.stk)'","'Numbered TIFF series (*.tif, *.tiff)'","'TIFF (*.tif, *.tiff) single XY plane'","'Imaris Classic (*.ims)'","'Zeiss (*.lsm)'","'Zeiss (*.lsm) single XY plane'","'Biorad (*.pic)'",
                                            "''","''","''","''",
                                            "''","''","''",
                                            "'widefield'","'nipkow'","'confocal'","'widefield'",
                                            "''","''","''","''",
                                            "'1.515'","'1.3381'","'1.0'",
                                            "'1.339 '","'1.47'"
                                            ),
                       "isDafault"=>array("'f'","'f'",
                                          "'f'","'f'","'f'","'f'","'f'","'f'","'f'","'f'",
                                          "'f'","'f'","'f'","'f'",
                                          "'f'","'f'","'f'",
                                          "'f'","'f'","'f'","'f'",
                                          "'f'","'f'","'f'","'f'",
                                          "'f'","'f'","'f'"
                                          ),
                       "parameter_key"=>array("'IsMultiChannel1'","'IsMultiChannel2'",
                                              "'ImageFileFormat1'","'ImageFileFormat2'","'ImageFileFormat3'","'ImageFileFormat4'","'ImageFileFormat5'","'ImageFileFormat6'","'ImageFileFormat7'","'ImageFileFormat8'",
                                              "'NumberOfChannels1'","'NumberOfChannels2'","'NumberOfChannels3'","'NumberOfChannels4'",
                                              "'ImageGeometry1'","'ImageGeometry2'","'ImageGeometry3'",
                                              "'MicroscopeType1'","'MicroscopeType2'","'MicroscopeType3'","'MicroscopeType4'",
                                              "'ObjectiveMagnification1'","'ObjectiveMagnification2'","'ObjectiveMagnification3'","'ObjectiveMagnification4'",
                                              "'ObjectiveType1'","'ObjectiveType2'","'ObjectiveType3'",
                                              "'SampleMedium1'","'SampleMedium2'"
                                              ));
$n_key = 4;
//if($current_version == 1) { // it is necessary to create a column that works as a key
//    $query = "SELECT * FROM ". $table;  // check if the table exist   
//    $result = $connection->Execute($query);
//    if($result) {     
//        $query = "DROP TABLE ". $table; // delete the table
//        $test = $connection->Execute($query);
//        if(!$test) {
//            $message = error_message($table);
//            write_to_error($message);
//            die();
//        }
//        $message = "The table possible_values need a ribuild, therefore it has been deleted.\n";
//        write_to_log($message);
//    }
//}
//check_table_existence($table_structure);
//check_records($table_content,$n_key);








fclose($fh);

//
echo "current version = " . $current_version . "\n";
echo "last version = " . $last_version . "\n";
//












// Methods
// -------

// Return an error message
function error_message($table) {
    $string = "An error occured in the update of the table " . $table . ".\n";
    return($string);
}


// Write a message in the log file
function write_to_log($message) {
    global $fh;
    fwrite($fh, $message); 
    return;
}


// Write a message in the error file
function write_to_error($message) {
    global $efh;
    fwrite($efh, $message); 
    return;
}


// Check if the table $table exists; if not, create the table
function check_table_existence($var) {
    global $table, $connection;

    $query = "SELECT * FROM ". $table;   
    $result = $connection->Execute($query);
    
    if(!$result) {  // the table does not exist
        $query = "CREATE TABLE `" . $table . "` (" . $var .")";
        $test = $connection->Execute($query);
        if(!$test) {
            $message = error_message($table);
            write_to_error($message);
            die();
        }
echo "the table " . $table . " has been created\n";
        $message = "The table '" . $table . "' has been created in the database.\n";
        $control = 'false';
    }
    else {
        $message = "The table '" . $table . "' exists.\n";
    }
    write_to_log($message);
    return;
}


// Check if each record exists; if not, create the record; if yes, update the record
function check_records($var,$n_key) {
    global $connection, $table;
    
    $keys = array_keys($var);
    $n_columns = count($keys);
    $n_rows = count($var[$keys[0]]);
    
echo "\nn_columns = " . $n_columns . "\n";
echo "n_rows = " . $n_rows . "\n";
for($i = 0; $i < $n_columns; $i++)
    echo $keys[$i] . "\n\n";
    
    for($i = 0; $i < $n_rows; $i++) {    //loop through all the table fields (rows)
    
echo "\ncheck record " . $i . "\n";

        $query =  "SELECT * FROM " . $table . " WHERE " . $keys[$n_key] . " = " . $var[$keys[$n_key]][$i];  // verify if the field exist
        $result = $connection->Execute($query);
        $rows = $result->GetRows();
        
echo "rows number = " . count($rows) . "\n";
        
        if(count($rows) == 0) { // the field does not exist -> create the databade entry
            $query = "INSERT INTO " . $table . " (" . $keys[0];
            for($j = 1; $j < $n_columns; $j++) {
                $query .= ", " . $keys[$j]; 
            }
            $query .= ") VALUES (" . $var[$keys[0]][$i];
            for($j = 1; $j < $n_columns; $j++) {
                $query .= ", " . $var[$keys[$j]][$i];
            }
            $query .= ")";
            $message = "\tThe record ". $var[$keys[$n_key]][$i] ." has been inserted into the table " . $table . ".\n";
echo "The record ". $var[$keys[$n_key]][$i] ." has been inserted into the table " . $table . ".\n";
        }
        
        else {
            $query = "UPDATE " . $table . " SET " . $keys[0] . " = " . $var[$keys[0]][$i];
            for($j = 1; $j < $n_columns; $j++) {
                $query .= ", " . $keys[$j] . " = " . $var[$keys[$j]][$i]; 
            }
            $query .= " WHERE " . $keys[$n_key] . " = " . $var[$keys[$n_key]][$i];
            
            $message = "\tThe record ". $var[$keys[$n_key]][$i] ." of the table " . $table . " has been checked.\n";
echo "The record ". $var[$keys[$n_key]][$i] ." has been updated in the table " . $table . ".\n";
        }
        
        $test = $connection->Execute($query);
        if(!$test) {
           $message = error_message($table);
           write_to_error($message);
           die(); 
        }
        write_to_log($message);
    }
    return;
}


















?>