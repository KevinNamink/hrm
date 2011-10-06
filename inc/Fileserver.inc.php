<?php
// This file is part of the Huygens Remote Manager
// Copyright and license notice: see license.txt

require_once("hrm_config.inc.php");
require_once("Database.inc.php");
require_once("Util.inc.php");

/*!
  \class Fileserver
  \brief Takes care of all file handling to and from the image area and provides
        commodity functions for creating and displaying previews
*/
class Fileserver {

  /*!
    \var    $username
    \brief  Name of the user and of his home directory
  */
  private $username;

  /*!
    \var    $files
    \brief  Array of image file names in the user's source directory
  */
  private $files;

  /*!
    \var    $destFiles
    \brief  Array of image file names in the user's destination directory
  */
  private $destFiles;

  /*!
    \var    $imageExtensions
    \brief  Array of image file extensions
  */
  private $imageExtensions;

  /*!
    \var    $selectedFiles
    \brief  Files currently selected for processing
  */
  private $selectedFiles;

  /*!
    \var    $expandSubImages (boolean)
    \brief  Toggles whether sub-images should be expanded
  */
  private $expandSubImages = true;

  /*!
   \var     $validImageExtensions (array)
   \brief   Array of valid image extensions
  */
  private $validImageExtensions = array();

  /*!
   \var     $validImageExtensionsExtras (array)
   \brief   Array of additional valid image extensions
   \todo    This information should also be in the database
  */
  private $validImageExtensionsExtras =  array("ids", "ids.gz");

  /*!
   \var     $multiImageExtensions (array)
   \brief   Array of extensions for multi-image file formats
  */
  private $multiImageExtensions =  array();

  /*!
   \var     $validArchiveExtensions (array)
   \brief   Array of extensions for user-defined archive formats
  */
  private $validArchiveExtensions =  array();

  /*!
    \brief  Constructor: creates a new Fileserver
  */
  function __construct($name) {
    global $image_folder;
    global $image_source;
    global $decompressBin;
    $this->username = $name;
    $this->files = NULL;
    $this->selectedFiles = NULL;
    $this->destFiles = NULL;
    $this->imageExtensions = NULL;

    // Set the valid image extensions
    $db = new DatabaseConnection();
    $this->validImageExtensions = $db->allFileExtensions();

    // Set the multi-image extensions
    $this->multiImageExtensions = $db->allMultiFileExtensions();

    // Only valid archive types are those for which decompression commands are
    // specified.
    $this->validArchiveExtensions = array_keys($decompressBin);
  }

  /*!
    \brief  Checks whether the file area is reachable
    \return true if the file area is reachable
  */
  public function isReachable() {
    $result = file_exists($this->sourceFolder());
    $result = $result && file_exists($this->destinationFolder());
    return $result;
  }

  /*!
    \brief  Returns the name of the user
    \return name of the user
  */
  public function username() {
    return $this->username;
  }

  /*!
    \brief  Returns the absolute path to the user's source image folder. The
            folder may be on the local network
    \return absolute path to the source image folder
  */
  public function sourceFolder() {
    global $image_folder;
    global $image_source;
    $folder = $image_folder . "/" . $this->username . "/" . $image_source;
    return $folder;
  }

  /*!
    \brief  Returns the absolute path to the user's destination image folder.
            The folder may be on the local network
    \return absolute path to the destination image folder
  */
  public function destinationFolder() {
    global $image_folder;
    global $image_destination;
    $folder = $image_folder . "/" . $this->username . "/" . $image_destination;
    return $folder;
  }

  /*!
    \brief  Returns the destination image folder from a JobDescription object
    \param  $desc JobDescription object
    \return destination image folder with user-generated subfolder
  */
  public function destinationFolderFor($desc) {
    $folder = $this->destinationFolder() . "/" . $desc->relativeSourcePath();
    return $folder;
  }

  /*!
    \brief  Searches the source folder recursively and returns all found files
    \param  $extension  Extension to be considered to scan the folder. Omit to
                        get all files
    \return sorted array of file names
  */
  public function files( $extension = null ) {
    if ( is_null( $extension ) ) {
        if ($this->files == NULL) $this->getFiles();
        return $this->files;
    } else {
        if (!file_exists($this->sourceFolder())) return False;
        $files = $this->listFilesFrom($this->sourceFolder(), "", $extension);
        sort($files);
        return $files;
    }
  }

  /*!
    \brief  Searches the destination folder recursively and returns all found files
    \param  $extension  Extension to be considered to scan the folder. Omit to
                        get all files
    \return sorted array of file names
  */
  public function destFiles( $extension = null ) {
    if ( is_null( $extension ) ) {
        if ($this->destFiles == NULL) $this->getDestFiles();
        return $this->destFiles;
    } else {
        if (!file_exists($this->destinationFolder())) return False;
        $files = $this->listFilesFrom($this->destinationFolder(), "", $extension);
        sort($files);
        return $files;
    }
  }

  /*!
    \brief  A wrapper function to list files of a certain type
    \param  $format  Extension to be considered to scan the folder.
    \return array of file names
  */
  public function filesOfType( $format ) {

        if ($format == "ics") {
            $files = $_SESSION['fileserver']->files("ics");
        }
        else if ($format == "tiff" || $format == "tiff-single") {
            $files = $_SESSION['fileserver']->tiffFiles();
        }
        else if ($format == "tiff-series") {
            $files = $_SESSION['fileserver']->tiffSeriesFiles();
        }
        else if ($format == "tiff-leica") {
            $files = $_SESSION['fileserver']->tiffLeicaFiles();
        }
        else if ($format == "stk") {
            //if ($geometry->value() == "XY - time" || $geometry->value() ==
            //"XYZ - time") {
            if ($_SESSION['setting']->isTimeSeries()) {
                $files = $_SESSION['fileserver']->stkSeriesFiles();
            }
            else {
                $files = $_SESSION['fileserver']->stkFiles();
            }
            //}
            //else {
            //  $files = $_SESSION['fileserver']->files("stk");
            //}
        }
        else {
            $files = $_SESSION['fileserver']->files();
        }

        return $files;

  }

  /*!
    \brief  Scans the image source folder recursively and returns all files
    \return sorted array of file names
  */
  public function allFiles() {
    if (!file_exists($this->sourceFolder())) return False;
    $files = $this->listFilesFrom($this->sourceFolder(), "", "");
    sort($files);
    return $files;
  }

  /*!
    \brief  Convenience method to get TIFF files
    \return array of TIFF file names
  */
  public function tiffFiles() {
    $this->getFiles();
    $this->trimTiff();
    return $this->files;
  }

  /*!
    \brief  Convenience method to get numbered TIFF series
    \return array of numbered TIFF file names
  */
  public function tiffSeriesFiles() {
    $this->getFiles();
    // TODO refactor
    $this->trimTiffSeries();
    $this->condenseTimeSeries();
    return $this->files;
  }

  /*!
    \brief  Convenience method to get TIFF series with Leica style numbering
    \return array of Leica TIFF file names
  */
  public function tiffLeicaFiles() {
    $this->getFiles();
    // TODO refactor
    $this->trimTiffLeica();
    $this->condenseTiffLeica();
    return $this->files;
  }

  /*!
    \brief  Convenience method to get all STK files (also unprocessed time series)
    \return array of STK files with unprocessed time series
  */
  public function stkFiles() {
    $this->getFiles();
    // TODO refactor
    $this->trimStkSeries();
    return $this->files;
  }

  /*!
    \brief  Convenience method to get STK time series files (*_t#.stk)
    \return array of STK time series files
  */
  public function stkSeriesFiles() {
    $this->getFiles();
    // TODO refactor
    $this->trimStk();
    $this->condenseStkSeries();
    return $this->files;
  }

  /*!
    \brief  Resets the list of source files. Next time the list is accessed
            it will be recreated automatically
  */
  public function updateAvailableFiles() {
    $this->files = NULL;
  }

  /*!
    \brief  Resets the list of destination files. Next time the list is accessed
            it will be recreated automatically
  */
  public function updateAvailableDestFiles() {
    $this->destFiles = NULL;
  }

  /*!
    \brief  Sets a flag to indicate whether multi-experiment image files should
            be expanded in file lists or not
    \param  $bool True if multi-image files should be expanded; false otherwise
  */
  public function expandSubImages($bool) {
      $this->expandSubImages = $bool;
  }

  /*!
    \brief  Returns the list of selected files that will be added to a job
    \return Array of file names
  */
  public function selectedFiles() {
    if ($this->selectedFiles == NULL) $this->selectedFiles = array();
    return $this->selectedFiles;
  }

  /*!
    \brief  Add files to current selection if they are not already contained
    \param  $files  Array of file names to be added
  */
  public function addFilesToSelection($files) {
      foreach ($files as $key => $file) {
          $files[$key] = stripslashes($file);
      }
    $selected = $this->selectedFiles();
    $new = array_diff($files, $selected);
    $this->selectedFiles = array_merge($new, $this->selectedFiles);
    sort($this->selectedFiles);
  }

  /*!
    \brief  Remove files from current selection (if they are in)
    \param  $files  Array of file names to be removed
  */
  public function removeFilesFromSelection($files) {
      foreach ($files as $key => $file) {
          $files[$key] = stripslashes($file);
      }
    $this->selectedFiles = array_diff($this->selectedFiles, $files);
  }

  /*!
   \brief      Builds a regular expression to be able to look for files.
   \brief      based on ther job id.
   \return     The regular expression.
   \TODO       A new design and implementation of the file server is necessary.
  */
  public function getFilePattern($fileName) {

      // New naming convention: hrmJobid_hrm.extension
      if (!strstr($fileName, '/')) {
          $pattern = "/^([a-z0-9]{13,13})_hrm\.(.*)$/";
          preg_match($pattern,$fileName,$matches);

          if (isset($matches) && !empty($matches)) {
              return "*" . $matches[1] . "_hrm.*";
          }
      } else {
          $pattern = "/(.*)\/([a-z0-9]{13,13})_hrm\.(.*)$/";
          preg_match($pattern,$fileName,$matches);

          if (isset($matches) && !empty($matches)) {
              return $matches[1] . "/*" . $matches[2] . "_hrm.*";
          }
      }

      // Old naming convention: fileName_hrmJobid_hrm.extension
      if (!strstr($fileName, '/')) {
          $pattern = "/(.*)_(.*)_hrm\.(.*)$/";
          preg_match($pattern,$fileName,$matches);

          if (isset($matches) && !empty($matches)) {
              return "*" . $matches[2] . "_hrm.*";
          }
      } else {
          $pattern = "/(.*)\/(.*)_(.*)_hrm\.(.*)$/";
          preg_match($pattern,$fileName,$matches);

          if (isset($matches) && !empty($matches)) {
              return $matches[1] . "/*" . $matches[3] . "_hrm.*";
          }
      }
  }

  /*!
    \brief Packs a series of files to download.
    \param $files  Array  List of files to be added.
  */
  public function downloadResults($files) {
      global $compressBin, $compressExt, $dlMimeType, $packExcludePath;

      // Make sure that the script doesn't timeout before zipping and
      // reading the file to serve is completed.
      set_time_limit(0);

      $date = date("Y-m-d_His");
      $zipfile = "/tmp/download_".session_id().$date.$compressExt;
      $command = str_replace("%DEST%",
              $this->destinationFolder(), $compressBin);
      $command .= " ".$zipfile;

      foreach ($files as $file) {
          $filePattern = $this->getFilePattern($file);
          $path = str_replace(" ","\ ",$filePattern);
          $preview_path = dirname($path). "/hrm_previews/". basename($filePattern);
          if (!$packExcludePath) {
              $path = $this->destinationFolder()."/".$path;
              $preview_path = $this->destinationFolder()."/".$preview_path;
          }
          $command .= " ".$path." ".$preview_path;
      }

      $answer = exec($command , $output, $result);

      $size = filesize($zipfile);
      $type = $dlMimeType;
      $dlname = "hrm_results_$date$compressExt";

      if ($size) {
          header ("Accept-Ranges: bytes");
          header ("Connection: close");
          header ("Content-Disposition-type: attachment");
          header ("Content-Disposition: attachment; filename=\"$dlname\"");
          header ("Content-Length: $size");
          header ("Content-Type: $type; name=\"$dlname\"");
          ob_clean();
          flush();
          readfile_chunked($zipfile);
          unlink($zipfile);
          return "<p>OK</p>";
      } else {
          $error_msg = "No output from command $command.";
      }
      return "Problems with the packaging of the files:" . " $error_msg";
  }

  /*!
    \brief  Deletes a list of files and all dependent sub-files (e.g. thumbnails
            and so) from a user directory
    \param $files  Array of image file names
    \param $dir   Folder to consider, one of 'src' or 'dest'
  */
  public function deleteFiles($files, $dir = "dest" ) {

      if ( $dir == "src" ) {
          $pdir =  $this->sourceFolder();
      } else {
          $pdir =  $this->destinationFolder();
      }

      foreach ($files as $file) {
          // Delete all files name like this one, with all different extensions.
          $dirname = dirname($pdir."/".$file);
          $basename = basename($pdir."/".$file);

          if ( $dir == "src") {
              $path = preg_replace("/(.*)\.(.{3,4})/","\\1.*",
                                   $dirname."/".$basename);
              $path_preview = dirname($path) . "/hrm_previews/" . $basename;
          } else {
              $filePattern = $this->getFilePattern($basename);
              $path = $dirname . "/" . $filePattern;
              $path_preview = $dirname . "/hrm_previews/" . $filePattern;
          }

          $allFiles = glob($path);
          foreach ($allFiles as $f) {
              unlink($f);
          }

          // Clean also the subdirectory hrm_previews
          $allFiles = glob($path_preview);
          foreach ($allFiles as $f) {
              unlink($f);
          }
      }

      if ( $dir == "src" ) {
          $this->updateAvailableFiles();
      } else {
          $this->updateAvailableDestFiles();
      }


  }

  /*!
    \brief  Extracts files from compressed archives
    \param  $file       Archive name
    \param  $type       Archive type (zip, tar, tgz...)
    \param  $dest       Destination path
    \param  $okMsg      A string to accumulate OK messages
    \param  $errMsg     A string to accumulate error messages
    \param  $subdir     An optional subdirectory under $dest to expand the
                        files to
    \param  $imagesOnly Boolean Apply a filter to delete non-image files after
                        extraction
  */
  public function decompressArchive( $file, $type, $dest, &$okMsg, &$errMsg,
          $subdir = "", $imagesOnly = true ) {

      global $decompressBin;

      if ( $imagesOnly && $subdir == "" ) {
          $errMsg .= "Can't decompress: filtering valid images requires ".
              "expanding the archive to a subdirectory.";
          return;
      }

      if ($subdir != "" ) {
          $dest = $dest."/".$subdir;
          @mkdir($dest, 0777);
      }

      $command = str_replace("%DEST%", "\"" . $dest . "\"", $decompressBin[$type]).
          " \"$file\"";

      $answer = exec($command , $output, $result);

      # $okMsg .= "$type $command: $result";
      # foreach ($output as $line) {
          # $okMsg .= "\n<br>$line";
      # }

      if ($imagesOnly) {
          $deleted = "";
          $valid = "";
          $this->cleanNonImages($dest, "", $valid, $deleted);
          if ($deleted != "") {
              $errMsg .= "<br>\nThe following files, not being valid images,".
                  " were discarded: <kbd>$deleted</kbd>";
          }
          if ($valid != "") {
              $okMsg .= "<br>\nThe following images were extracted: ".
                  "<kbd>$valid</kbd><br>\n";
          }
      }

      return;


  }

  /*!
    \brief  Processes the $_FILES array posted when uploading files,  moving
            valid one to the specified directory. Compressed files are
            decompressed
    \param  $files  Array of files to be uploaded.
    \param  $dir    Destination path, one of 'src' or 'dest'
    \return message with details.
    \see See PHP documentation: POST method uploads
  */
  public function uploadFiles($files, $dir) {

      if ( $dir == "src" ) {
          $uploaddir =  $this->sourceFolder();
      } else {
          $uploaddir =  $this->destinationFolder();
      }

      $max = getMaxFileSize() / 1024 / 1024;
      $maxFile = "$max MB";

      $ok = "";
      $err = "";
      $okCnt = 0;

      # print_r($files); exit;

      // This needs some file type validation: only images should be allowed.

      // decompression still pending.

      try {

      foreach ($files['name'] as $i => $name) {

          if ( $name == "" ) {
              // This is also error UPLOAD_ERR_NO_FILE;
              continue;
          }
          $basename = basename($name);
          $basename = str_replace(" ","_",$basename);
          $uploadfile = $uploaddir . "/" . $basename;
          $info = pathinfo($uploadfile);
          $file_name =  basename($uploadfile,'.'.$info['extension']);

	  // If the php.ini upload variables are overriden in the HRM
	  // config files, PHP does not rise this error.
 	  if (($files['size'][$i] / 1024 / 1024) > $max) {
 	     $files['error'][$i] = UPLOAD_ERR_INI_SIZE;
  	  }

          if ($files['error'][$i]) {
              $err .= "Invalid file <kbd>".$basename."</kbd>: <b>";
              switch ($files['error'][$i]) {
                  case UPLOAD_ERR_INI_SIZE:
                     $err .= "larger than $maxFile.";
                     break;
                  case UPLOAD_ERR_PARTIAL:
                     $err .= "file loaded only partially.";
                     break;
                  case UPLOAD_ERR_NO_TMP_DIR:
                     $err .= "missing a temporary folder.";
                     break;
                  case UPLOAD_ERR_CANT_WRITE:
                     $err .= "can't write to disk.";
                     break;
                  case UPLOAD_ERR_EXTENSION:
                     $err .= "upload stopped by extension.";
                     break;

              }
              $err .="</b><br>\n";
              continue;
          }

          $type = $this->getCompressedArchiveType($name);

          if ( $type != "" ) {
              # If this is a compressed archive, extract its files.
              $subdir = $file_name;
              $zsuffix = 0;
              $zmaxSuffix = 100;

              $testExpand = $uploaddir . "/" . $subdir;

              while (file_exists($testExpand)) {
                  $zsuffix ++;
                  $testExpand = $uploaddir . "/" . $file_name. "_$zsuffix" ;
                  if ($zsuffix > $zmaxSuffix) {
                      $err .= "Directory <kbd>".$filename.
                          "</kbd> exists, <b>can't store more ".
                          " than $zmaxSuffix versions.</b><br>\n";
                      break;
                  }
              }
              if ($zsuffix > $zmaxSuffix) {
                  continue;
              }

              $okCnt++;
              $ok .= "<br>Processed <kbd>".$basename."</kbd>.<br>\n";


              if ($zsuffix > 0) {
                  $subdir = $file_name."_".$zsuffix;
                  $ok .= "Extracting files to <kbd>$subdir</kbd>.<br>\n";
              }
              $this->decompressArchive($files['tmp_name'][$i], $type,
                      $uploaddir, $ok, $err, $subdir, true);
              continue;

          }

          if (!$this->isValidImage($name, true)) {
              $err .= "Skipped <kbd>".$basename."</kbd>: ";
              $err .= "<b>unknown image type</b><br>\n";
              continue;

          }

          $suffix = 0;
          $maxSuffix = 20;

          while (file_exists($uploadfile)) {
              $suffix ++;
              $uploadfile = $uploaddir . "/" . $file_name
                  . "_$suffix." . $info['extension'];
              if ($suffix > $maxSuffix) {
                  $err .= "File <kbd>".$basename.
                      "</kbd> exists, <b>can't store more than $maxSuffix versions.</b>";
                  break;
              }
          }
          if ($suffix > $maxSuffix) {
              continue;
          }

          if (move_uploaded_file($files['tmp_name'][$i], $uploadfile)) {
              // echo "File is valid, and was successfully uploaded.\n";
              if ($suffix == 0) {
                  $ok .= "<kbd>".$basename."</kbd> uploaded <br>\n";
              } else {
                  $ok .= "<kbd>".$basename.
                      "</kbd> already exists, uploaded and <b>renamed</b> ".
                      "to <kbd>$file_name"
                      . "_$suffix." . $info['extension']. "</kbd><br>\n";
              }
              $okCnt++;
          } else {
              $err .= "Invalid file ".$basename."<br>\n";
          }
      }
      } catch (Exception $e) {
          $err .= "Error uploading files: ".$e->getMessage();
      }

      $msg = "<h3>Upload report</h3>\n";

      if ($okCnt == 0) {
          $msg .= "<p>No files uploaded!<p>$err";
      } else {
          $plural = "";
          if ($okCnt > 1) {
              $plural = "s";
          }
          $msg .= "<p class=\"report\">$okCnt file$plural uploaded.</p><p class=\"report\">$ok</p><p class=\"report\">$err</p>";
      }

      if ( $dir == "src" ) {
          $this->updateAvailableFiles();
      } else {
          $this->updateAvailableDestFiles();
      }

      return $msg;

  }

  /*!
    \brief  Returns a list of file extensions for supported images
    \return array of file extensions
    \todo   This must be obtained from the database!
  */
  public function imageExtensions() {
    if ($this->imageExtensions == NULL) $this->setDefaultImageExtensions();
    return $this->imageExtensions;
  }

  /*!
    \brief  Sets the list of image extensions. Files with these extensions
            under the user's source folder will be shown under available
            images. Whenever the image extensions are changed, the files and
            the selected files will be reset. Only exception is when the list
            of image extensions is replaced by itself
    \param  $extensions  Array of file extensions (strings)
  */
  public function setImageExtensions($extensions) {
    if (implode('', $extensions) !=  implode('', $this->imageExtensions())) {
      $this->selectedFiles = NULL;
      $this->files = NULL;
    }
    $this->imageExtensions = $extensions;
  }

  /*!
    \brief Sets the image extensions from the list of valid image extensions
    \todo Check the meaning of having this method!
  */
  public function setDefaultImageExtensions() {
    // new file formats support
    $this->imageExtensions = $this->validImageExtensions;
  }

  /*!
    \brief  Checks whether the filename extension matches the currently
            selected file format
    \param  $filename  Filename to be checked
    \return true if the file extension matches the file format, false otherwise
  */
  public function isImage($filename) {
    $ext = substr(strrchr($filename, "."),1);
    $ext = strtolower($ext);
    $result = False;
    if (in_array($ext, $this->imageExtensions())) {
      $result = True;
    }
    return $result;
  }

  /*!
    \brief  Checks whether the file name is of a valid type
    \param  $filename  The filename to be checked
    \param  $alsoExtras: if true, consider also extensions as ids or ids.gz
    \return true if the filename is of a valid type, false otherwise
  */
  public function isValidImage($filename, $alsoExtras = false) {
      $filename = strtolower($filename);
      $ext = substr(strrchr($filename, "."),1);
      if ( $ext == "gz" ) {
          // Use two suffixes as extension
          $filename  = basename($filename, ".gz");
          $ext = substr(strrchr($filename, "."),1) . ".gz";
      }
      $result = False;
      if (in_array($ext, $this->validImageExtensions)) {
          $result = True;
      }
      if ($alsoExtras && (in_array($ext, $this->validImageExtensionsExtras)) ) {
          $result = True;
      }
      return $result;
  }

  /*!
    \brief  Returns the archive type of a filename, if it is a valid known
            compressed archive.
    \param  $filename  The filename to be checked
    \return the archive type if the filename is valid archive, "" otherwise
  */
  public function getCompressedArchiveType($filename ) {

      if (stristr($filename, ".tar.gz")) {
          // This double extension is a special case.
          return "tar.gz";
      }
      $ext = substr(strrchr($filename, "."),1);
      $ext = strtolower($ext);
      $result = "";
      if (in_array($ext, $this->validArchiveExtensions)) {
          $result = $ext;
      }
      return $result;
  }

  /*!
    \brief  Returns all archive types as string
    \return string containing all archive types
  */
  public function getValidArchiveTypesAsString() {
      $ret = ""; $sep = "";
      foreach ($this->validArchiveExtensions as $ext) {
          $ret .= $sep.".$ext";
          $sep = " ";
      }
      return $ret;
  }

  /*!
    \brief  When the selected file type is one that can contain subimages
            (like LIF), the already built list of $this->files is extended to
            show all the available subimages. This is done by querying HuCore.
    \param  $files  Array of file names that have to be inspected for sub-files
    \return updated array of file names with sub-files
  */
  public function getSubImages($files) {

      $i = 0;
      $imgList = "";
      foreach ($files as $path) {
          $imgList .= " -img_$i \"$path\"";
          $i ++;
      }

      $opt = "-count $i $imgList -dir \"". $this->sourceFolder() ."\"";

      $answer = huCoreTools( "reportSubImages", $opt);

      if (! $answer ) return;
      # printDebug ($answer);

      $lines = count($answer);

      $tree = array();
      $new_files = array();
      $cur = NULL;

      for ($i = 0; $i < $lines; $i++ ) {
          $key = $answer[$i];

          switch ($key) {
              case "BEGIN IMG":
                  $i ++;
                  $cur = $answer[$i];
                  break;
              case "ERROR":
                  $i ++;
                  echo($answer[$i]);
              case "END IMG":
                  $cur = NULL;
                  break;
              case "PATH":
                  if ($cur) {
                      $i ++;
                      $tree[$cur]['path'] = $answer[$i];
                  }
                  break;
              case "COUNT":
                  if ($cur) {
                      $i ++;
                      $tree[$cur]['count'] = $answer[$i];
                  }
                  break;
              case "TYPE":
                  if ($cur) {
                      $i ++;
                      $tree[$cur]['type'] = $answer[$i];
                  }
                  break;
              case "SUBIMG":
                  if ($cur) {
                      $i ++;
                      $tree[$cur]['subimg'][] = $answer[$i];
                      $new_files[] = $cur ." (". $answer[$i] .")";
                  }
                  break;

          }
      }

      return $new_files;

      # printDebug ($tree);

  }

  /*!
    \brief  Some files like ICS can report their metadata without having to
            open the whole image, which is good e.g. to see the compatibility
            of the selected PSF with the current Parameter Setting.
            This is done by querying HuCore.
    \param  $type File type, default is "ics"
    \param  $file This can be a file name for the file to be inspected, or
            "all" to inspect all the files of type $type that are in the source
            folder
    \return N-dimensional array of metadata per file
  */
  public function getMetaData( $type = "ics", $file = "all" ) {

      $i = 0;
      $imgList = "";

      if ( $file == "all" ) {
          $files = $this->files($type);
      } else {
          $files[] = $file;
      }

      foreach ($files as $path) {
          $imgList .= " -img_$i \"$path\"";
          $i ++;
      }

      $opt = "-count $i $imgList -dir \"". $this->sourceFolder() ."\"";


      $answer = huCoreTools( "getMetaData", $opt);

      if (! $answer ) return;
      # printDebug ($answer);

      $lines = count($answer);

      $tree = array();
      $new_files = array();
      $cur = NULL;
      $param = NULL;

      for ($i = 0; $i < $lines; $i++ ) {
          $key = $answer[$i];

          switch ($key) {
              case "BEGIN IMG":
                  $i ++;
                  $cur = $answer[$i];
                  break;
              case "ERROR":
                  $i ++;
                  echo($answer[$i]);
              case "END IMG":
                  $cur = NULL;
                  $param = NULL;
                  $len = 1;
                  break;
              case "PATH":
                  if ($cur) {
                      $i ++;
                      $tree[$cur]['path'] = $answer[$i];
                  }
                  break;
              case "LENGTH":
                  if ($cur) {
                      $i ++;
                      $len = $answer[$i];
                  }
                  break;
              case "DATA":
                  if ($cur) {
                      $i ++;
                      $param= $answer[$i];
                      $tree[$cur]['parameters'][] = $param;
                  }
                  break;
              case "VALUE":
                  if ($cur && $param) {
                      $i ++;
                      // This is always an array even if $len == 1, because in
                      // other images this could be a multichannel parameter.
                      $tree[$cur][$param][] = $answer[$i];
                  }
                  break;

          }
      }


      # printDebug ($tree);
      return $tree;

  }

  /*!
    \brief  Generates a html line for a form listing images in the server
    \param  $file Image file name
    \param  $index  <b>(Unused)</b> Index in the file name array
    \param  $dir    <b>(Unused)</b> Destination directory, one of 'src', or 'dst'
    \param  $type   <b>(Unused)</b> Type of the image, e.g. 'preview'
    \param  $ref    <b>(Unused)</b> Default is 0
    \param  $data   <b>(Unused)</b> Default = 1
    \return the html code for the form
    \todo   Check why this function taks 6 parameters and uses only one.
  */
  public function getImageOptionLine ($file, $index, $dir, $type, $ref = 0, $data = 1) {
      $path = explode("/", $file);
      if (count($path) > 2)
          $filename = $path[0] . "/.../" . $path[count($path) - 1];
      else
          $filename = $file;

      return
      "                        <option value=\"$file\">$filename</option>\n";
  }

  /*!
    \brief  Generates a javascript command to show an image preview.
    \param  $file Image file name
    \param  $index  <b>(Unused)</b> Index in the file name array
    \param  $dir    <b>(Unused)</b> Destination directory, one of 'src', or 'dst'
    \param  $type   <b>(Unused)</b> Type of the image, e.g. 'preview'
    \param  $ref    <b>(Unused)</b> Default is 0
    \param  $data   <b>(Unused)</b> Default = 1
    \return the javascript code.
    \todo   Check why this function taks 6 parameters and uses only one.
  */
  public function getImageAction ($file, $index, $dir, $type, $ref = 0, $data = 1) {
      global $useThumbnails;
      global $genThumbnails;

      $path = explode("/", $file);
      if (count($path) > 2)
          $filename = $path[0] . "/.../" . $path[count($path) - 1];
      else
          $filename = $file;


      $mode = $this->imgPreviewMode($file, $dir, $type) ;
      if ( $ref ) {
          $referer = "?ref=". $_SESSION['referer'];
      } else {
          $referer = "";
      }

      // The first comparison mode is the 400x400 pixels preview.
      $compare = $this->imgCompareMode($file, $dir, "400") ;
      if ($compare > 0) { $compare = 400; }

      return
          "imgPrev('".rawurlencode($file)."', $mode, ".
          "$genThumbnails, $compare, $index, '$dir', ".
          "'$referer', $data)";
  }

  /*!
    \brief  Generates a link to retreive an image thumbnail, which is a jpg
            file saved near the file itself.
    \param  $image  Image file name
    \param  $dir    Destination directory, one of 'src' or 'dst'
    \param  $type   Type of thumbnail, one of 'preview_xy', 'preview_xz',
                    'preview_yz'; default is 'preview_xy'
    \param  $escape if true, the code is escaped to be used in Javascript code
    \return an <img src="..."> link to securely get the thumbnail.
  */
  public function imgPreview ($image, $dir, $type = "preview_xy", $escape = true ) {
      global $genThumbnails;

      if ( $dir == "src" ) {
          $pdir =  $this->sourceFolder();
      } else {
          $pdir =  $this->destinationFolder();
      }

      $dirname = dirname($pdir."/".$image);
      $base = basename($pdir."/".$image);

      // The thumbnail is saved in a subdirectory along with the image, and it
      // has a suffix indicating the thumbnail type plus the jpg extension.
      $path = $dirname."/hrm_previews/".$base.".".$type.".jpg";
      $thumb = rawurlencode(stripslashes($image).".".$type.".jpg");
      if (file_exists(stripslashes($path))) {
          $ret =  "<img src=\"file_management.php?getThumbnail=$thumb&amp;".
              "dir=$dir\" alt=\"preview\" />";
      } else {
           $imgsrc = "<img src=\"images/no_preview.jpg\" alt=\"No preview\" />";
           // $ret = "<p><center>No preview available.</center></p>";
           $ret .= "$imgsrc<br />No preview available";
      }

      if ($escape) {
          return escapeJavaScript($ret);
      } else {
          return $ret;
      }
  }

  /*!
    \brief  Shows stacks and time series saved as jpeg strips in a css container.
            Inspired by the paperbird code by Rom�n Cort�s:
    \param  $file   Image file name
    \param  $type   Type of the strip, one of 'stack.compare', 'tSeries.compare',
                    'preview_yz'; default is 'stack.compare'
    \param  $dir    Destination directory, one of 'src' or 'dest', default is 'dest'
    \param  $frame  True to draw a frame, false otherwise
    \param  $margin Thickness of the margin around th strip, default is 25
    \return html code to be output to the page
    \see    http://www.romancortes.com/blog/css-paper-bird/
  */
  public function viewStrip( $file, $type = "stack.compare", $dir = "dest", $frame = false, $margin = 25 ) {
      global $allowHttpTransfer;

      $fileinfo = pathinfo($file);
      $files = $this->findStrip($file, $type, $dir);

      if (count($files) != 1 ) {
          echo "<img src=\"images/no_preview.jpg\">";
          return;
      }
      preg_match ("/strip_(.+)x(.+)x(.+)_fr/", $files[0], $match);

      $thumb = $fileinfo['dirname']."/" .strstr($files[0],$fileinfo['filename']);

      $sx = $match[1];
      $sy = $match[2];
      $width = $sx + $margin * 2;
      $height = $sy + $margin * 2;
      $jump = $height * 400;
      $fCnt = $match[3];

      if ( $frame ) {
          // Use this function in two steps: first to create the iframe with
          // the correct width and height, using a link that calls this
          // function again to generate the embedded slicer page.
          echo ' <iframe src="?viewStrip='.$file.'&amp;type='.$type.
          '&amp;dir='.$dir.'" width="'. ($width + 25).
          '" height="'. ($height).'"> ';
          echo '</iframe>';
          return;
      }

      $borderColor = "#666";
      $textColor = "#bbb";

      $img = "file_management.php?getThumbnail=$thumb&amp;dir=$dir";

      $file = stripslashes($file);

      # $legend = $type. " ". $file;
      $legend = $type;
      if (strlen($legend) > 75) {
              $legend = substr($legend, 0, 70)."...";
      }

      echo '

<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
        <title>' . $file . " ". $type. '</title>
        <style type="text/css">
                body
                {
                       font-family: "verdana", "bitsream vera sans", sans-serif;
                       margin: 0;
                       padding: 0;
                       overflow: hidden;
                }

                #viewer
                {
                        width: '. ($width + $margin) .'px;
                        height: '. $height .'px;
                        background: '.$borderColor.';
                        overflow: auto;
                }

                #viewer div
                {
                        float: left;
                        width: '. $width .'px;
                        height: '.$jump.'px;
                        background-image: url('.$img.');
                        background-attachment: fixed;
                }

                .left
                {
                        position:absolute;
                        left: 0px;
                        top: 0px;
                        width: '.$margin.'px;
                        height: '.$height.'px;
                        background: '.$borderColor.';
                        z-index: 9998;
                }
                .right
                {
                        position:absolute;
                        left: '.( $sx + $margin).'px;
                        top: 0px;
                        width: '.$margin.'px;
                        height: '.$height.'px;
                        background: '.$borderColor.';
                        z-index: 9998;
                }

                .top
                {
                        position:absolute;
                        top: 0px;
                        left: 0px;
                        width: '.$width.'px;
                        height: '.$margin.'px;
                        background: '.$borderColor.';
                        z-index: 9999;
                }

                .bottom
                {
                        position:absolute;
                        left: 0px;
                        top: '.($height - $margin).'px;
                        color: '.$textColor.';
                        overflow: hidden;
                        font-size: 11px;
                        padding-left: '.$margin.'px;
                        width: '.($width - $margin).'px;
                        height: '.($margin ).'px;
                        background: '.$borderColor.';
                        z-index: 9999;
                }


';

      for ($n = 0; $n < $fCnt; $n++) {
          $pos = $sy * ($fCnt - $n  ) + $margin;
          echo "#f$n {background-position: ".$margin."px ".$pos."px;}";
      }


echo '  </style>
    </head>
    <body>
    <div id="viewer"> ';
      for ($n = 0; $n < $fCnt; $n++) {
          echo "<div id=\"f$n\">";
          echo "</div>";
          }

      echo "</div>";
      echo "<div class=\"top\">&nbsp;</div><div
          class=\"bottom\">$legend</div><div
          class=\"left\">&nbsp;</div><div class=\"right\">&nbsp;</div>";

echo '</body></html>';


  }

  /*!
    \brief  Creates the preview page for the file browser
    \param  $file Image file name
    \param  $op   Operation, one of 'close' or 'home'. Default is "close"
    \param  $mode Display mode. One of "MIP", "parameters", "log", "SFP", "stack", "tSeries",
                  "history", "remarks". Default is "MIP"
    \param  $size Size of the thumbnail. Default is 400
    \return HTML code (whole page)
  */
  public function previewPage ($file , $op = "close", $mode = "MIP", $size = 400) {
      global $allowHttpTransfer;

      $file = stripslashes($file);
      echo '<?xml version=\"1.0\" encoding=\"UTF-8\"?'.'>';

      echo ' <!DOCTYPE html
          PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
          "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
          <html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">

          <head>
          <title>Huygens Remote Manager</title>
          <link rel="SHORTCUT ICON" href="images/hrm.ico"/>
          <script type="text/javascript" src="scripts/common.js"></script>
          <style type="text/css">
          @import "stylesheets/default.css";
      </style>
          </head>
          <body>
          <script type="text/javascript" src="./scripts/wz_tooltip/wz_tooltip.js"></script>
          ';

      echo '
      <div id="prevBasket"> <!--basket-->
      <div id="title">
      <h1>HRM image preview</h1>
      <div id="logo"></div>
      </div>';

      $pdest =  $this->destinationFolder();
      $filePattern = $this->getFilePattern($file);

      if (!glob($pdest . "/" . $filePattern)) {
          echo "<br />";
          echo "Sorry, file $file does not exist any more on the server.<br />";
          echo "<br />";
          echo "<a href=\"home.php\"><img src=\"images/home.png\" alt=\"Home\" /></a>";
          echo "</body></html>";
          exit;
      }

      $dir = dirname($pdest."/".$file);
      $base = basename($pdest."/".$file);
      $prevBase = $dir."/hrm_previews/".$base;

      $fileBase = $dir."/".$base;
      $path_info = pathinfo($fileBase);
      $fileBase = $dir."/".$path_info['filename'];

      // (Sorted) available views
      //
      // If the file(s) corresponding to a view is missing, no entry will be
      // added to the navigation menu

      // MIP comparison
      $path = array();
      $test = $prevBase.".".$size."_xy.jpg";
      if (file_exists($test)) {
          $path['MIP'] = $test;
      }

      // This is the text file with the parameter summary (useful to check the
      // runtime parameters that were used if the template was incomplete)
      $filePattern = $this->getFilePattern(basename($file));
      $filePattern = str_replace(".*",".parameters.*",$filePattern);

      $paramFile = glob($dir . "/" . $filePattern);
      if (isset($paramFile) && !empty($paramFile)) {
          $path['parameters'] = $paramFile[0];
      }

      // Log file (this is the same as the email that is sent to the user)
      $filePattern = $this->getFilePattern(basename($file));
      $filePattern = str_replace(".*",".log.*",$filePattern);

      $logFile = glob($dir . "/" . $filePattern);
      if (isset($logFile) && !empty($logFile)) {
          $path['log'] = $logFile[0];
      }

      // SFP comparison
      $test = $prevBase.".sfp.jpg";
      if (file_exists($test)) {
          $path['SFP'] = $test;
      }

      // Slicer comparison
      $test = $this->findStrip($file, "stack.compare", $pdest);
      if (count($test) == 1) {
          $path['stack'] = $test[0];
      }
      $test =  $this->findStrip($file, "tSeries.compare", $pdest);
      if (count($test) == 1) {
          $path['tSeries'] = $test[0];
      }

      // Download movie
      $test =  $prevBase.".stack.avi";
      if (file_exists($test)) {
          $path['stackMovie'] = $test;
          $movie['stackMovie'] = $file.".stack.avi";
          $msize['stackMovie'] = round(filesize($test) / 1024.0);
      }
      $test =  $pdest."/hrm_previews/".$file.".tSeries.avi";
      if (file_exists($test)) {
          $path['tSeriesMovie'] = $test;
          $movie['tSeriesMovie'] = $file.".tSeries.avi";
          $msize['tSeriesMovie'] = round(filesize($test) / 1024.0);
      }
      $test =  $pdest."/hrm_previews/".$file.".tSeries.sfp.avi";
      if (file_exists($test)) {
          $path['tSeriesSfpMovie'] = $test;
          $movie['tSeriesSfpMovie'] = $file.".tSeries.sfp.avi";
          $msize['tSeriesSfpMovie'] = round(filesize($test) / 1024.0);
      }

      // Remarks file
      $test = $pdest."/".$file.".remarks.txt";
      if (file_exists($test)) {
          $path['remarks'] = $test;
      }

      // Old history filename didn't contain image type extension.
      $test = $fileBase.".history.txt";
      if (file_exists($test)) {
          $path['history'] = $test;
      }

      // New filename for history includes the file destination extension.
      $test = $pdest."/".$file.".history.txt";
      if (file_exists($test)) {
          $path['history'] = $test;
      }

      // Define some arrays
      $desc = array(  'MIP'             => "MIP",
                      'parameters'      => "parameters",
                      'log'             => "log",
                      'SFP'             => "SFP",
                      'stack'           => "slicer",
                      'tSeries'         => "series",
                      'stackMovie'      => "stack movie",
                      'tSeriesMovie'    => "series movie",
                      'tSeriesSfpMovie' => "series SFP movie",
                      'history'         => "history",
                      'remarks'         => "remarks" );

      $tip = array(   'MIP'             => "Compare Maximum Intensity Projections",
                      'parameters'      => "List the image parameters used (useful to check the runtime parameters that were used if the template was incomplete)",
                      'log'             => "See the image restoration log file",
                      'SFP'             => "Compare Simulated Fluorescence renderings",
                      'stack'           => "Browse along the Z-planes (this could take several seconds)",
                      'tSeries'         => "Browse along the time series (this could take several seconds)",
                      'stackMovie'      => "Download Z-stack movie",
                      'tSeriesMovie'    => "Download time series MIP movie",
                      'tSeriesSfpMovie' => "Download time series SFP movie" ,
                      'history'         => "See the image restoration history, the executed Huygens - Tcl commands.",
                      'remarks'         => "See the image restoration warnings." );

      // Refine some $tips
      if ( isset( $msize['stackMovie'] ) ) {
        $tip['stackMovie'] = "Download Z-stack movie<br>(".$msize['stackMovie']." kB)";
      }
      if ( isset( $msize['tSeriesMovie'] ) ) {
        $tip['tSeriesMovie'] = "Download time series MIP movie<br>(".$msize['tSeriesMovie']." kB)";
      }
      if ( isset( $msize['tSeriesSfpMovie'] ) ) {
        $tip['tSeriesSfpMovie'] = "Download time series SFP movie<br>(".$msize['tSeriesSfpMovie']." kB)";
      }

      $link = "file_management.php?compareResult=".rawurlencode($file).
          "&amp;op=$op&amp;mode=";
      $mlink = "file_management.php?getMovie=";

      echo "<div id=\"prevMenu\">\n";

      foreach ($path as $key => $val) {
          $class = 'menuEntry';
          $doLink = true;
          if ( $key == $mode ) {
              $doLink = false;
              $class = "menuEntryActive";
          }
          if ( isset($movie[$key]) ) {
              $bLink = $mlink.rawurlencode($movie[$key]);
          } else {
              $bLink = $link.$key;
          }
          echo "\n<div class=\"$class\"";
          if ($doLink) {
              echo " onclick=\"document.location.href='".$bLink."'\"";
          }
              echo " onmouseover=\"Tip('".$tip[$key]."')\" onmouseout=\"UnTip()\"";

          echo ">";
          if ($doLink) {
              echo "<a href=\"".$bLink."\">";
          }
          echo $desc[$key];
          if ($doLink) {
              echo "</a>";
          }
          echo "</div>";

      }

      if ( $allowHttpTransfer ) {
          if (!file_exists($file)) {
              $dirDest =  $this->destinationFolder();
              $dirName = dirname($dirDest . "/" . $file);
              $fileName = basename($dirDest . "/" . $file);
              $allFiles = glob($dirName . "/*" . $fileName . "*");
              $dirAndFile = str_replace($dirDest . "/","",$allFiles[0],$count);

              if ($dirAndFile && $count) {
                  $downloadFile = $dirAndFile;
              }
          } else {
              $downloadFile = $file;
          }

          echo "\n<div class=\"menuEntry\"
          onmouseover=\"Tip('Pack and download the restored image with all accessory files')\"
          onmouseout=\"UnTip()\"
          onclick=\"changeDiv('report','Packaging files, please wait'); setTimeout(smoothChangeDiv,5000,'report','',5000); document.location.href='file_management.php?download=".rawurlencode($downloadFile)."'\" ><a href='#'>download files</a></div>\n";
      }

      echo "\n<div class=\"menuEntry\" onclick=\"javascript:openWindow(".
          "'http://support.svi.nl/wiki/style=hrm&amp;".
          "help=HuygensRemoteManagerHelpCompareResult')\" ".
             "onmouseover=\"Tip('Open a pop up with help about this window.')\" onmouseout=\"UnTip()\">".
          "<a href=\"#\"><img src=\"images/help.png\" alt=\"help\" /> Help".
          "</a></div>";

      echo "\n<div class=\"menuEntry\" ";

      switch ($op) {
          case "close":
             echo "onclick=\"window.close()\"".
             "onmouseover=\"Tip('Close this window and go back to the File Manager.')\" onmouseout=\"UnTip()\">".
             "<a href=\"#\">".
             "<img src=\"images/cancel_help.png\" alt=\"cancel\" /> Back".
             "</a>\n";
             break;
          case "home":
             echo " onclick=\"document.location.href='home.php'\" ".
             "onmouseover=\"Tip('Go to your HRM home page.')\" ".
             " onmouseout=\"UnTip()\" ".
             "'select_parameter_settings.php'\">".
             "<a href=\"#\">".
             "<img src=\"images/home.png\" alt=\"home\" /> Home".
             "</a>\n";
             break;
      }
      echo "</div>\n";
      echo "</div>\n";
      echo "<div id=\"previewContents\">\n";

      if ($mode == "stack" || $mode == "tSeries" ) {
          $this->viewStrip( $file, "$mode.compare", "dest", true );
          echo "<div id=\"previewImg\">\n";
          echo "<center>Original - Restored<br>(drag scrollbar for browsing)</center>";
      } else if ( $mode == "log" || $mode == "history" || $mode == "remarks" || $mode == "parameters" ) {
          echo "<div id=\"logFile\">\n";
          print "<pre>";
          readfile ($path[$mode]);
          print "</pre>";
      } else {

      echo "<div id=\"previewImg\">\n";
      echo "\n<table>\n<tr>\n";

      echo "<td>Original</td><td>Restored</td>\n</tr>\n<tr>";

      $othumb_0 = rawurlencode($file.".original.".$size."_xy.jpg");
      $rthumb_0 = rawurlencode($file.".".$size."_xy.jpg");

      $osfp = rawurlencode($file.".original.sfp.jpg");
      $rsfp = rawurlencode($file.".sfp.jpg");

      // YZ slices not shown by now, but here they are:
      $othumb_2 = rawurlencode($file.".original.".$size."_xy.jpg");
      $rthumb_2 = rawurlencode($file.".".$size."_xy.jpg");

      if ( $mode == "MIP" ) {

          echo "\n<td><img src=\"file_management.php?getThumbnail=$othumb_0".
              "&amp;dir=dest\" alt=\"Original preview XY\" /></td>";

          echo "\n<td><img src=\"file_management.php?getThumbnail=$rthumb_0".
              "&amp;dir=dest\" alt=\"Restored preview XY\" /></td>";
      } else {
          echo "\n<td><img src=\"file_management.php?getThumbnail=$osfp".
              "&amp;dir=dest\" alt=\"Original SFP preview\" /></td>";

          echo "\n<td><img src=\"file_management.php?getThumbnail=$rsfp".
              "&amp;dir=dest\" alt=\"Restored SFP preview\" /></td>";

      }

      echo "\n</tr>";

      $othumb_1 = rawurlencode($file.".original.".$size."_xz.jpg");
      $rthumb_1 = $file.".".$size."_xz.jpg";
      $path = $pdest."/hrm_previews/".$rthumb_1;
      $path = stripslashes($path);
      $rthumb_1 = rawurlencode($rthumb_1);
      if ($mode == "MIP" && file_exists($path)) {
          // is a 3D image, so it has a lateral view.
          echo "\n<tr>";
          echo "\n<td><img src=\"file_management.php?getThumbnail=$othumb_1".
              "&amp;dir=dest\" alt=\"Original preview XZ\" /></td>";
          echo "\n<td><img src=\"file_management.php?getThumbnail=$rthumb_1".
              "&amp;dir=dest\" alt=\"Restored preview XZ\" /></td>";

          echo "\n</tr>";
      }

      echo "\n</table>\n\n";

      }
      echo "</div>\n";
      echo "<div id=\"report\"></div>";
      echo "</div>\n";
      include("footer.inc.php");

  }

  /*!
    \brief Shows original/result previews side by side.
    \param  $file Image file name
    \param  $size Size of the thumbnail. Default is 400
    \param  $op   Operation, one of 'close' or 'home'. Default is "close"
    \param  $mode Display mode. One of "MIP, "SFP", "stack", "tSeries", "log",
                  "history", "remarks". Default is "MIP"
    \return HTML code (whole page)
  */
  public function compareResult( $file, $size = "400", $op = "close", $mode="MIP" ) {
      global $allowHttpTransfer;

      $file = stripslashes($file);

      $excludeTitle = true;
      include("header.inc.php");

      if ( $mode == "MIP" ) {
          $altMode = "SFP";
      } else {
          $altMod = "MIP";
      }

      echo "</div>";

      echo "\n\n<h3>Image comparison ($mode)</h3>\n";

      $pdest =  $this->destinationFolder();
      $filePattern = $this->getFilePattern($file);

      if (!glob($pdest . "/" . $filePattern)) {
          echo "<br />";
          echo "Sorry, file $file does not exist any more on the server.<br />";
          echo "<br />";
          echo "<a href=\"home.php\"><img src=\"images/home.png\" alt=\"Home\" /></a>";
          echo "</body></html>";
          exit;
      }

      echo "\n<table>\n<tr>\n";

      echo "<td>Original</td><td>Restored</td>\n</tr>\n<tr>";

      $othumb_0 = rawurlencode($file.".original.".$size."_xy.jpg");
      $rthumb_0 = rawurlencode($file.".".$size."_xy.jpg");

      $osfp = rawurlencode($file.".original.sfp.jpg");
      $rsfp = rawurlencode($file.".sfp.jpg");

      if ( $mode == "MIP" ) {
          $altPath = $pdest."/hrm_previews/".$file.".sfp.jpg";
      } else {
          $altPath = $pdest."/hrm_previews/".$file.".".$size."_xy.jpg";
      }

      $altPath = stripslashes($altPath);
      // YZ slices not shown by now, but here they are:
      $othumb_2 = rawurlencode($file.".original.".$size."_xy.jpg");
      $rthumb_2 = rawurlencode($file.".".$size."_xy.jpg");

      if ( $mode == "MIP" ) {

          echo "\n<td><img src=\"file_management.php?getThumbnail=$othumb_0".
              "&amp;dir=dest\" alt=\"Original preview XY\" /></td>";

          echo "\n<td><img src=\"file_management.php?getThumbnail=$rthumb_0".
              "&amp;dir=dest\" alt=\"Restored preview XY\" /></td>";
      } else {
          echo "\n<td><img src=\"file_management.php?getThumbnail=$osfp".
              "&amp;dir=dest\" alt=\"Original SFP preview\" /></td>";

          echo "\n<td><img src=\"file_management.php?getThumbnail=$rsfp".
              "&amp;dir=dest\" alt=\"Restored SFP preview\" /></td>";

      }

      echo "\n</tr>";


      $othumb_1 = rawurlencode($file.".original.".$size."_xz.jpg");
      $rthumb_1 = $file.".".$size."_xz.jpg";
      $path = $pdest."/hrm_previews/".$rthumb_1;
      $path = stripslashes($path);
      $rthumb_1 = rawurlencode($rthumb_1);
      if ($mode == "MIP" && file_exists($path)) {
          // is a 3D image, so it has a lateral view.
          echo "\n<tr>";
          echo "\n<td><img src=\"file_management.php?getThumbnail=$othumb_1".
              "&amp;dir=dest\" alt=\"Original preview XZ\" /></td>";
          echo "\n<td><img src=\"file_management.php?getThumbnail=$rthumb_1".
              "&amp;dir=dest\" alt=\"Restored preview XZ\" /></td>";

          echo "\n</tr>";
      }

      echo "\n</table>\n\n";

      echo "\n<div id=\"message\"><br /><small>$file</small></div>\n";
      echo "\n<div id=\"info\">";

      if (file_exists($altPath)) {
          echo "\n<br /><small><a href=\"file_management.php?compareResult=".rawurlencode($file)."&amp;mode=$altMode&amp;op=$op\" >Compare images in $altMode view</a></small>\n";
      }



      $mpath =  $pdest."/hrm_previews/".$file.".stack.avi";
      $mpath = stripslashes($mpath);
      if ($mode == "MIP" && file_exists($mpath)) {
          $mSize = round(filesize($mpath) / 1024.0);
          echo "\n<br /><small><a href=\"file_management.php?getMovie=".rawurlencode($file.".stack.avi")."\" >Download stack preview video ($mSize kB) </a></small>\n";
      }
      if ( $mode == "MIP" ) {
          $tspath =  $pdest."/hrm_previews/".$file.".tSeries.avi";
          $vname = $file.".tSeries.avi";
      } else {
          $tspath =  $pdest."/hrm_previews/".$file.".tSeries.sfp.avi";
          $vname = $file.".tSeries.sfp.avi";
      }
      $tspath = stripslashes($tspath);
      if (file_exists($tspath)) {
          $tsSize = round(filesize($tspath) / 1024.0);
          echo "\n<br /><small><a href=\"file_management.php?getMovie=".rawurlencode($vname)."\" >Download time-series $mode preview video ($tsSize kB) </a></small>\n";
      }


      if ( $allowHttpTransfer ) {
          echo "\n<br /><small><a href=\"file_management.php?download=".rawurlencode($file)."\" onclick=\"changeDiv('info','Packaging files, please wait')\" >Download restored files</a></small>\n";
      }

      echo "</div>\n";
      echo "<div>\n";
      echo "\n<br /><br /><a href=\"javascript:openWindow(".
          "'http://support.svi.nl/wiki/style=hrm&amp;".
          "help=HuygensRemoteManagerHelpCompareResult')\">".
          "<img src=\"images/help.png\" alt=\"help\" />".
          "</a>";

      switch ($op) {
          case "close":
             echo " <a href=\"#\" onclick=\"window.close()\" ".
             "onmouseover=\"Tip('Close this window and go back to the File Manager.')\" onmouseout=\"UnTip()\">".
             "<img src=\"images/cancel_help.png\" alt=\"cancel\" />".
             "</a>\n";
             break;
          case "home":
             echo " <a href=\"#\" onclick=\"document.location.href=".
             "'home.php'\">".
             "<img src=\"images/home_large.png\" alt=\"home\" />".
             "</a>\n";
             break;
      }
      echo "</div>\n";
      // echo "<script type=\"text/javascript\"> window.close(); <script>\n";
      echo "</body></html>";
      ob_flush();
      flush();
  }

  /*!
    \brief  Calls hucore to open an image and generate a jpeg preview.
    \param  $file   Image file name
    \param  $src    Directory, one of 'src' or 'dest'
    \param  $dest   Directory, one of 'src' or 'dest'
    \param  $index  Index
    \param  $sizes  Either an integer, or "preview". Default = "preview"
    \param  $data   Either some data (unclear) of 0. Default =  0
    \return  HTML page (complete)
  */
  public function genPreview( $file, $src, $dest, $index, $sizes = "preview", $data = 0 ) {

      $excludeTitle = true;
      include("header.inc.php");

      echo "</div><div id=\"info\">".
      "<img src=\"images/spin.gif\" alt=\"busy...\" /><br />".
      "Generating preview for $file, please wait...<br /><br />\n\n<pre>";
      ob_flush();
      flush();

      if ( $src == "src" ) {
          $psrc =  $this->sourceFolder();
      } else {
          $psrc =  $this->destinationFolder();
      }
      if ( $dest == "src" ) {
          $pdest =  $this->sourceFolder();
      } else {
          $pdest =  $this->destinationFolder();
      }

      $psrc = dirname($psrc."/".$file);
      $basename = basename($pdest."/".$file);
      $pdest = dirname($pdest."/".$file)."/hrm_previews";

      // echo "$file, $src, $dest, $sizes<br />";
      // flush;

      $extra = "";
      $series = "auto";

      if ( $data ) {
          $nchan = $_SESSION['setting']->NumberOfChannels();
          $lmbV = "\"";
          $lambda = $_SESSION['setting']->parameter("EmissionWavelength");
          $l = $lambda->value();
          for ( $i = 0; $i < $nchan; $i++ ) {
              $lmbV .= " ".$l[$i];
          }
          $lmbV .= "\"";

          $xy = $_SESSION['setting']->parameter("CCDCaptorSizeX");
          $z = $_SESSION['setting']->parameter("ZStepSize");
          $xy_s = $xy->value() / 1000.0;
          $z_s = $z->value() / 1000.0;
          $extra = " -emission $lmbV -sampling \"$xy_s $xy_s $z_s\"";

          // Enable the -series off option depending on the file type.
          if (stristr($file, ".stk")) {
              $geom = $_SESSION['setting']->parameter("ImageGeometry");
              $geometry = $geom->value();
              if ( !stristr($geometry, "time") ) {
                  $series = "off";
              }
          }
          $formatParam = $_SESSION['setting']->parameter('ImageFileFormat');
          $format = $formatParam->value();
          if ($format == "tiff" || $format == "tiff-single") {
              // Olympus FluoView, or single XY plane: always
              $series = "off";
          }

      }

      $opt = "-filename \"$basename\" -src \"$psrc\" -dest \"$pdest\" ".
             "-scheme auto -sizes \{$sizes\} -series $series $extra";

      $answer = huCoreTools( "generateImagePreview", $opt);

      # if (! $answer ) return;
      # printDebug ($answer);

      $lines = count($answer);
      $html = "";

      $tree = array();
      $new_files = array();
      $cur = NULL;

      $ok = true;
      for ($i = 0; $i < $lines; $i++ ) {
          $key = $answer[$i];

          switch ($key) {
              case "ERROR":
                  $i ++;
                  $html .= $answer[$i]."<br />";
                  $ok = false;
                  break;
              case "REPORT":
                  $i ++;
                  echo $answer[$i]."\n";
                  ob_flush();
                  flush();
              default :
                  # $html .= $answer[$i]."<br />";
                  break;


          }
      }

      echo "Processing finished.\n";
      echo "</pre></div>";
      ob_flush();
      flush();

      $path = stripslashes($pdest."/".$basename.".preview_xy.jpg");
      if ($ok && ! file_exists($path)) {
          $ok = false;
          $html .= "$path does not exist.<br />";
      }

      if ($answer !== NULL)
          echo "<script type=\"text/javascript\"> changeDiv('info','');".
               "</script>";
      echo $html;

      if ($ok) {
          $nMode = $this->imgPreviewMode($file, $dest, "preview");
          $img = $this->imgPreview($file, $dest, "preview_xy", false) ;
          if ($nMode == 3) {
              $img .= "<br />".
                      $this->imgPreview($file, $dest, "preview_xz", false) ;
          }
          # $img .= "<p><center><kbd>$file</kbd></center></p>";
          echo $img;
      }

      echo "\n\n<script type=\"text/javascript\"> ";
      if ($ok) {
          echo "\nsetPrevGen($index, $nMode);";
          echo "\nchangeOpenerDiv('info','". escapeJavaScript($img). "'); ";
      } else {
          echo "\nchangeOpenerDiv('info','Preview generation failed.<br /><br /><kbd>".escapeJavaScript($html)."</kbd>'); ";
      }
      // Close the popup after a short delay, otherwise the image may not load
      // in the parent window, with some browsers.
      if ($answer !== NULL) echo "\nsetTimeout(\"window.close()\",200);";
      echo "\n</script>\n\n";
      echo "<br /><br /><a href=\"#\" onclick=\"window.close()\">Close</a>\n";
      # echo "<script type=\"text/javascript\"> window.close(); <script>\n";
      echo "</body></html>";
      ob_flush();
      flush();
  }

  /*!
    \brief  Serves a certain file from the dest directory. Intended to serve
            jpg thumbails in combination with imgThumbnail.
    \param  $file Image file name
    \param  $dir  Directory, either 'src' or 'dest'
    \return the binary file.
  */
  public function getThumbnail($file, $dir) {
      // rawurldecode
      if ( $dir == "src" ) {
          $pdir =  $this->sourceFolder();
      } else {
          $pdir =  $this->destinationFolder();
      }
      $dir = dirname($pdir."/".$file);
      $base = basename($pdir."/".$file);
      $path = $dir."/hrm_previews/".$base;
      $path = stripslashes($path);
      if (!file_exists($path)) {
          $path = "images/no_preview.jpg";
      }
      Header("Content-Type: image/jpeg");
      readfile ($path);
  }

  /*!
    \brief  Serves an existing AVI movie
    \param  $file Image file name
    \param  $dir  Directory, either 'src' or 'dest'. Default is 'dest'
    \return the binary file.
  */
  public function getMovie($file, $dir = "dest" ) {

      if ( $dir == "src" ) {
          $pdir =  $this->sourceFolder();
      } else {
          $pdir =  $this->destinationFolder();
      }

      $dirname = dirname($pdir."/".$file);
      $basename = basename($pdir."/".$file);

      $path = stripslashes($dirname."/hrm_previews/".$basename);
      if (!file_exists($path)) {
          $path = "images/no_preview.jpg";
          Header("Content-Type: image/jpeg");
          readfile ($path);
      }

      $size = filesize($path);
      $type = "video/x-msvideo";

      if ($size) {
          header ("Accept-Ranges: bytes");
          header ("Connection: close");
          header ("Content-Disposition-type: attachment");
          header ("Content-Disposition: attachment; filename=\"$file\"");
          header ("Content-Length: $size");
          header ("Content-Type: $type; name=\"$file\"");
          readfile($path);
      }
  }

  /*!
    \brief  Returns true if at least one file is selected
    \return true if at least one file is selected
  */
  public function hasSelection() {
    $selection = $this->selectedFiles();
    return (count($selection)>0);
  }

  /*!
    \brief  Checks if a folder contains a given file name
    \return true if the folder contains the file name
  */
  public function folderContains($folder, $string) {
    if (!file_exists($folder)) {
      return False;
    }
    $dir = opendir($folder);
    $result = False;
    while ($name = readdir($dir)) {
      if (strstr($name, $string)) {
	$result = True;
      }
    }
    closedir($dir);
    return $result;
  }

  /*!
    \brief  Checks if a folder contains newer files than a given date
    \param  $folder Directory to be checked
    \param  $date   Date string
    \return true if at least one file is more recent than date
  */
  public function folderContainsNewerFile($folder, $date) {
    if (!file_exists($folder)) {
      return False;
    }
    $dir = opendir($folder);
    $result = False;
    $db = new DatabaseConnection();
    while ($name = readdir($dir)) {
      $filename = $folder . '/' . $name;
      if (is_dir($filename)) continue;
      $filedate = filemtime($filename);
      $filedate = $db->fromUnixTime($filedate);
      if ($filedate > $date) $result = True;
    }
    closedir($dir);
    return $result;
  }

/*
                              PRIVATE FUNCTIONS
*/

  /*!
    \brief  Checks whether an image preview is available
    \param  $image  Image file name
    \param  $dir    Destination directory, one of 'src' or 'dst'
    \param  $type   Type of thumbnail, one of 'preview_xy', 'preview_xz',
                    'preview_yz'; default is 'preview_xy'
    \return  a numeric code: 0 -> not available, 2 -> 2D, 3 -> 3D
  */
  private function imgPreviewMode ($image, $dir, $type) {
      global $genThumbnails;

      if ( $dir == "src" ) {
          $pdir =  $this->sourceFolder();
      } else {
          $pdir =  $this->destinationFolder();
      }

      $dir = dirname($pdir."/".$image);
      $base = basename($pdir."/".$image);
      // The thumbnail is saved in a subdirectory along with the image, and it
      // has a suffix indicating the thumbnail type plus the jpg extension.
      $path = stripslashes($dir."/hrm_previews/".$base.".".$type."_xy.jpg");

      # $path2 = $dir."/hrm_previews/".$base.".".$type."_xz.jpg";
      # unlink($path);
      # unlink($path2);
      # echo "Deleting $path2";
      # rmdir($dir."/hrm_previews/");
      # echo "Deleting dir";

      $ret = 0;
      if (file_exists($path)) {
          // 2D preview
          $ret = 2;
          $path2 = stripslashes($dir."/hrm_previews/".$base.".".$type."_xz.jpg");
          if (file_exists($path2)) {
          // 3D preview
              $ret = 3;
          }
      } else {
          // No preview available
          $ret = 0;
      }

      return $ret;

  }

  /*!
    \brief  Creates the list of source image files for the user. Time series are
            represented by their first image file.
            The list is stored in this->files
  */
  private function getFiles() {
    $this->files = array();
    if (!file_exists($this->sourceFolder())) return False;
    $this->getFilesFrom($this->sourceFolder(), "");
    if (count($this->files) == 0) return False;
    $extArr = $this->imageExtensions();

    // When only one file type is listed, expand subimages if they exists.

    if ( count ( $extArr ) == 1 ) {
        $ext = $extArr[0];
        if ( in_array( $ext, $this->multiImageExtensions)) {
            $this->files = $this->getSubImages($this->files);
        }
    }

    // Later adition: if multiple explicitly given types are listed, expand
    // subimages. Therefore, subimages are only NOT listed when no explicit
    // extension is given (useful to handle FILES, not IMAGES, like in the file
    // manager).
    if ( $this->expandSubImages && count ( $extArr ) > 1 ) {
        $expandfiles = array();
        foreach ($extArr as $mfext) {
            if ( !in_array( $mfext, $this->multiImageExtensions)) { continue; }
            foreach ($this->files as $key => $file) {
                $ext = substr(strrchr($file, "."),1);
                $ext = strtolower($ext);
                if ($ext != $mfext) continue;
                $expandfiles[] = $file;
                unset ( $this->files[$key] );
            }
        }
        if ( count($expandfiles) > 0 ) {
          $this->files = array_merge($this->files,
                  $this->getSubImages($expandfiles));
        }
    }
    sort($this->files);
    // TODO refactor
    //$this->condenseTimeSeries();
    // trim TIFF series to the first file in the sequence
    //$this->condenseTiffSeries();
    return True;
  }

  /*!
    \brief  Returns the file name of a strip, a file that simulates browsing
            through the planes of a 3D dataset or the time points of a time
            series directly in in the browser in a before-after view
    \param  $file   Image file name
    \param  $type   Type of thumbnail, one of 'preview_xy', 'preview_xz',
                    'preview_yz'; default is 'preview_xy'
    \param  $dir    Destination directory, one of 'src' or 'dst'
    \return file name of the strip file
  */
  private function findStrip ( $file, $type, $dir ) {
      if ( $dir == "src" ) {
          $pdir =  $this->sourceFolder();
      } else {
          $pdir =  $this->destinationFolder();
      }

      $dir = dirname($pdir."/".$file);
      $base = basename($pdir."/".$file.".".$type);
      $path = $dir."/hrm_previews/".$base;
      $path = stripslashes($path);

      $files = glob($path.".strip_*");
      return $files;

  }

  /*!
    \brief  Checks whether a restored image preview is available for comparison
            with the original one.
    \param  $image  Image file name
    \param  $dir    Destination directory, <b>must be 'dest'</b>
    \param  $type   Type of the thumbnail
    \return numeric code: 0 -> not available, 2 -> 2D, 3 -> 3D
  */
  private function imgCompareMode ($image, $dir, $type) {
      global $genThumbnails;

      if ( $dir == "src" ) {
          // Only images in the destination directory, after deconvolution, can
          // be compared with the originals.
          return 0;
      }

      $pdest =  $this->destinationFolder();

      $pdir = dirname($pdest."/".$image);
      $basename = basename($pdest."/".$image);

      // The thumbnail is saved along with the image, and it has a suffix
      // indicating the thumbnail type plus the jpg extension.
      $path = $pdir."/hrm_previews/".$basename.".".$type."_xy.jpg";
      $path = stripslashes($path);
      $opath = $pdir."/hrm_previews/".$basename.".original.".$type."_xy.jpg";
      $opath = stripslashes($opath);
      $ret = 0;
      if (file_exists($path) && file_exists($opath) ) {
          // 2D preview
          $ret = 2;
          $path2 = $pdir."/hrm_previews/".$basename.".".$type."_xz.jpg";
          $path2 = stripslashes($path2);
          $opath2 = $pdir."/hrm_previews/".$basename.".original.".$type."_xz.jpg";
          $opath2 = stripslashes($opath2);
          if (file_exists($path2) && file_exists($opath2) ) {
          // 3D preview
              $ret = 3;
          }
      } else {
          // No preview available for comparison
          $ret = 0;
      }
      return $ret;

  }

  /*!
    \brief  Creates the list of restored (result) image files of the user.
            Time series are represented by their first image file
  */
  private function getDestFiles() {
    $this->destFiles = array();
    if (!file_exists($this->destinationFolder())) return False;
    $this->getDestFilesFrom($this->destinationFolder(), "");
    if (count($this->destFiles) == 0) return False;
    sort($this->destFiles());
    // TODO refactor
    //$this->condenseTimeSeries();
    // trim TIFF series to the first file in the sequence
    //$this->condenseTiffSeries();
  }

  /*!
    \brief  Returns the basename of the file, without numeric extension. This
            is the part of the file name that is common to file series. The
            numberic extension is expected to be directly before the . of the
            file extension. Please mind that the behavior of the built-in
            PHP function basename() is different!
    \param  $filename File name
    \return basename without numeric extension
  */
  private function basename($filename) {
    $basename = preg_replace("/(\w+|\/)([^0-9])([0-9]+)(\.)(\w+)/", "$1$2$4$5", $filename);
    return $basename;
  }

  /*!
    \brief  Removes all but the first file from each time series in the files
            attribute.
  */
  private function condenseTimeSeries() {
    if (count($this->files)==0) return False;
    $time_series =  preg_grep("/\w+[0-9]+\.\w+/", $this->files);
    $lastValue = "";
    foreach ($time_series as $key => $value) {
       if ($this->basename($lastValue)==$this->basename($value)) {
           //echo $value;
	unset($this->files[$key]);
      }
      $lastValue = $value;
    }
  }

  /*!
    \brief  Removes single TIFF and TIFF series with Leica style numbering from
            the file list
    \todo Refactor
  */
  private function trimTiffSeries() {
    if (count($this->files)==0) return False;
    $tiff_series = preg_grep("/[^_]+_(T|t|Z|z|CH|ch)[0-9]+\w+\.\w+/", $this->files);
    foreach ($tiff_series as $key => $value) {
	unset($this->files[$key]);
    }
    $tiff_series = preg_grep("/\w+[0-9]+\.\w+/", $this->files, PREG_GREP_INVERT);
    foreach ($tiff_series as $key => $value) {
	unset($this->files[$key]);
    }
  }

  /*!
    \brief  Removes single TIFF and numbered TIFF series from the file list
    \todo Refactor
  */
  private function trimTiffLeica() {
    if (count($this->files)==0) return False;
    $tiff = preg_grep("/[^_]+_(T|t|Z|z|CH|ch)[0-9]+\w+\.\w+/", $this->files, PREG_GREP_INVERT);
    foreach ($tiff as $key => $value) {
	unset($this->files[$key]);
    }
  }

  /*!
    \brief  Removes numbered TIFF series and TIFF series with Leica style
            numbering from the file list
    \todo Refactor
  */
  private function trimTiff() {
    if (count($this->files)==0) return False;
    $tiff_series = preg_grep("/[^_]+_(T|t|Z|z|CH|ch)[0-9]+\w+\.\w+/", $this->files);
    foreach ($tiff_series as $key => $value) {
	unset($this->files[$key]);
    }
    /* too restrictive
    $tiff_series = preg_grep("/\w+[0-9]+\.\w+/", $this->files);
    foreach ($tiff_series as $key => $value) {
	unset($this->files[$key]);
    }*/
  }

  /*!
    \brief  Trims STK files
  */
  private function trimStk() {
    if (count($this->files)==0) return False;
    $stk = preg_grep("/[^_]+_(T|t)[0-9]+\.\w+/", $this->files, PREG_GREP_INVERT);
    foreach ($stk as $key => $value) {
	unset($this->files[$key]);
    }
  }

  /*!
    \brief  Trims STK time series
  */
 private function trimStkSeries() {
   if (count($this->files)==0) return False;
   $stk = preg_grep("/[^_]+_(T|t)[0-9]+\.\w+/", $this->files);
   foreach ($stk as $key => $value) {
       unset($this->files[$key]);
   }
 }

  /*!
    \brief  Gets the basename of Leica TIFF series
    \param  $filename  File name
    \return basename of Leica TIFF series
  */
  private function leicaStyleNumberingBasename($filename) {
    $basename = preg_replace("/([^_]+|\/)(_)(T|t|Z|z|CH|ch)([0-9]+)(\w+)(\.)(\w+)/", "$1$6$7", $filename);
    return $basename;
  }

  /*!
    \brief Condensed Leica TIFF series to the first file in the series
  */
  private function condenseTiffLeica() {
    if (count($this->files)==0) return False;
    $tiff_series =  preg_grep("/[^_]+_(T|t|Z|z|CH|ch)[0-9]+\w+\.\w+/", $this->files);
    $lastValue = "";
    foreach ($tiff_series as $key => $value) {
       if ($this->leicaStyleNumberingBasename($lastValue)==$this->leicaStyleNumberingBasename($value)) {
          //echo $value;
          unset($this->files[$key]);
      }
      $lastValue = $value;
    }
  }

  /*!
    \brief  Gets the basename of STK time series
    \param  $filename File name
    \return basename of STK time series
  */
  private function stkSeriesBasename($filename) {
    $basename = preg_replace("/([^_]+|\/)(_)(T|t)([0-9]+)(\.)(\w+)/", "$1$5$6", $filename);
    return $basename;
  }

  /*!
    \brief  Condensed STK time series series to the first file in the series
  */
  private function condenseStkSeries() {
    if (count($this->files)==0) return False;
    $stk_series =  preg_grep("/[^_]+_(T|t)[0-9]+\.\w+/", $this->files);
    $lastValue = "";
    foreach ($stk_series as $key => $value) {
       if ($this->stkSeriesBasename($lastValue)==$this->stkSeriesBasename($value)) {
          //echo $value;
          unset($this->files[$key]);
      }
      $lastValue = $value;
    }
  }

  /*!
    \brief  Since HRM 1.2, thumbnails and previews  are located in a
            subdirectory hrm_previews. When and old preview is found in
            the way, we can use this function to move it to the new location.
            This code is mostly harmless, but we can remove it after a couple
            of releases.
    \param  $dir    Path to the old preview file's directory;
    \param  $entry  File name;
  */
  private function relocateOldPreview($dir, $entry) {
      if (strstr($entry, ".jpg") || strstr($entry, ".avi")) {
          // Relocate old HRM previews to the new subdirectory.
          // Since HRM 1.2, previews are all stored in a subdirectory
          // 'hrm_previews', but old images may remain along with
          // previous results.
          if (!file_exists($dir."/hrm_previews")) {
              // We keep doing things assuming a trusted environment,
              // but real security would require making all directories
              // accessible to the deamon only, that runs all file
              // management operation after the apache queries.
              // By now, grant 777 permissions.
              @mkdir($dir."/hrm_previews", 0777);
              // The creation mask doesn't seem to work correctly, chmod
              // now:
              @chmod($dir."/hrm_previews", 0777);
          }
          # echo "mv $dir/$entry -> $dir/hrm_previews/$entry <br>";
          @rename ($dir."/".$entry, $dir."/hrm_previews/".$entry);
          @chmod($dir."/hrm_previews/".$entry, 0666);
      }
  }

  /*!
    \brief  The recursive function that collects the  image files from the
            user's source folder and its subfolders
    \param  $startDir  The folder to start from
    \param  $prefix    The actual path prefix relative to the user's image folder
    \TODO   This function fails producing an ugly bug that does not show the
    \TODO   file manager, when the same zipped folder is uploaded a number of
    \TODO   times.
  */
  private function getFilesFrom($startDir, $prefix) {
    $dir = dir($startDir);
    while ($entry = $dir->read()) {
      if ($entry != "." && $entry != ".." && $entry != "hrm_previews") {
	if (is_dir($startDir . "/" . $entry)) {
	  $newDir = $startDir . "/" . $entry;
	  if ($prefix=="") {
	    $newPrefix = $entry;
	  } else {
	    $newPrefix = $prefix . "/" . $entry;
	  }
	  $this->getFilesFrom($newDir, $newPrefix);
	} else {
            if (!$this->isValidImage($entry)) {
                $this->relocateOldPreview($startDir, $entry);
                continue;
            }
            // Skip also if the image is not of the currently selected type.
            if (!$this->isImage($entry)) continue;
            //echo $entry,$prefix,",";
	  if ($prefix=="") {
	    $this->files[] = $entry;
	  } else {
	    $this->files[] = $prefix . "/" . $entry;
	  }
	}
      }
    }
    $dir->close();
  }

  /*!
    \brief  The recursive function that collects the  image files from the
            user's destination folder and its subfolders
    \param  $startDir  The folder to start from
    \param  $prefix    The actual path prefix relative to the user's destination folder
  */
  private function getDestFilesFrom($startDir, $prefix) {
      $dir = dir($startDir);
      while ($entry = $dir->read()) {
          if ($entry != "." && $entry != ".." && $entry != "hrm_previews") {
              if (is_dir($startDir . "/" . $entry)) {
                  $newDir = $startDir . "/" . $entry;
                  if ($prefix=="") {
                      $newPrefix = $entry;
                  } else {
                      $newPrefix = $prefix . "/" . $entry;
                  }
                  $this->getDestFilesFrom($newDir, $newPrefix);
              } else {
                  if (!$this->isValidImage($entry)) {
                      $this->relocateOldPreview($startDir, $entry);
                      continue;
                  }
                  // echo $entry,$prefix," VALID,";
                  if ($prefix=="") {
                      $this->destFiles[] = $entry;
                  } else {
                      $this->destFiles[] = $prefix . "/" . $entry;
                  }
              }
          }
      }
      $dir->close();
  }

  /*!
    \brief  The recursive function that deletes all files in a directory
            that are not valid images.
    \param  $startDir The folder to start from
    \param  $prefix   The actual path prefix relative to the user's image folder
    \param  $valid    String pointer, to accumulate extracted files
    \param  $msg      String pointer, to accumulate messages
  */
  private function cleanNonImages($startDir, $prefix, &$valid, &$msg) {
    $dir = dir($startDir);
    while ($entry = $dir->read()) {
        if ($entry != "." && $entry != ".." && $entry != "hrm_previews") {
            if (is_dir($startDir . "/" . $entry)) {
                $newDir = $startDir . "/" . $entry;
                if ($prefix=="") {
                    $newPrefix = $entry;
                } else {
                    $newPrefix = $prefix . "/" . $entry;
                }
                $this->cleanNonImages($newDir, $newPrefix, $valid, $msg);
            } else {
                if ($this->isValidImage($entry, true)) {
                    $valid .= " $entry";
                } else {
                    $msg .= " $entry";
                    unlink( $startDir . "/" .$entry);
                    continue;
                }
            }
        }
    }
    // Try to delete the directory: if it is empty, we'll succeed.
    // TODO: this removing still doesn't work well, debug.  j-)
    # $msg .= " removing $startDir";

    # $answer = exec($command , $output, $result);
    $dir->close();
    if ( @rmdir($startDir) ) {
        $msg .= " (empty dir '".basename($startDir). "' deleted)";
    }
  }

  /*!
    \brief  The recursive function that collects the files with given extension
            from the user's image folder and its subfolders
    \param  $startDir The folder to start from
    \param  $prefix   The actual path prefix relative to the user's image folder
    \param  $valid    String pointer, to accumulate extracted files
    \param  $extension  File extension
    \return Array of file names with given extension
  */
  private function listFilesFrom($startDir, $prefix, $extension) {
    $files = array();
    $dir = dir($startDir);
    while ($entry = $dir->read()) {
      if ($entry != "." && $entry != "..") {
	if (is_dir($startDir . "/" . $entry)) {
	  $newDir = $startDir . "/" . $entry;
	  if ($prefix=="") {
	    $newPrefix = $entry;
	  } else {
	    $newPrefix = $prefix . "/" . $entry;
	  }
	  $files = array_merge($files, $this->listFilesFrom($newDir, $newPrefix, $extension));
	} else {
          $ext = substr(strrchr($entry, "."),1);
          $ext = strtolower($ext);
	  if ($extension != "") {
            if ($ext != $extension) continue;
          }
      //echo $entry,$prefix,",";
	  if ($prefix=="") {
	    $files[] = $entry;
	  } else {
	    $files[] = $prefix . "/" . $entry;
	  }
	}
      }
    }
    $dir->close();
    return $files;
  }

} // End of FileServer class