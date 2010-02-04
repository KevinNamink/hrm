<?php

// php page: file_management.php

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

require_once("./inc/User.inc");
require_once("./inc/Fileserver.inc");

session_start();

if (isset($_GET['home'])) {
  header("Location: " . "home.php"); exit();
}

if (!isset($_SESSION['user']) || !$_SESSION['user']->isLoggedIn()) {
  $req = $_SERVER['REQUEST_URI'];
  $_SESSION['request'] = $req;
  header("Location: " . "login.php"); exit();
}

if (isset($_SERVER['HTTP_REFERER']) && !strstr($_SERVER['HTTP_REFERER'], 'job_queue')) {
  $_SESSION['referer'] = $_SERVER['HTTP_REFERER'];
}

if (isset($_GET['ref'])) {
    $_SESSION['referer'] = $_GET['ref'];
} else if (isset($_POST['ref'])) {
    $_SESSION['referer'] = $_POST['ref']; 
} else {
    $_SESSION['referer'] = $_SERVER['HTTP_REFERER'];
}

if (!isset($_SESSION['fileserver'])) {
    # session_register("fileserver");
    $name = $_SESSION['user']->name();
    $_SESSION['fileserver'] = new Fileserver($name);
}

if (isset($_GET['getThumbnail'])) {
    if (isset($_GET['dir'])) {
        $dir = $_GET['dir'];
    } else {
        $dir = "dest";
    }
    $_SESSION['fileserver']->getThumbnail( rawurldecode($_GET['getThumbnail']),
         $dir );
    exit;
}

if (isset($_GET['getMovie'])) {
    if (isset($_GET['dir'])) {
        $dir = $_GET['dir'];
    } else {
        $dir = "dest";
    }
    $_SESSION['fileserver']->getMovie( rawurldecode($_GET['getMovie']),
         $dir );
    exit;
}


if (isset($_GET['genPreview'])) {
    if (isset($_GET['size'])) {
        $size = $_GET['size'];
    } else {
        $size = "preview";
    }
    if (isset($_GET['src'])) {
        $src = $_GET['src'];
    } else {
        $src = "dest";
    }
    if (isset($_GET['dest'])) {
        $dest = $_GET['dest'];
    } else {
        $dest = $src;
    }
    if (isset($_GET['data'])) {
        $data = $_GET['data'];
    } else {
        $data = 0;
    }
    if (isset($_GET['index'])) {
        $index = $_GET['index'];
    } else {
        $index = 0;
    }


    $_SESSION['fileserver']->genPreview( $_GET['genPreview'],
                                         $src, $dest, $index, $size, $data );
    exit;
}

if (isset($_GET['compareResult'])) {
    if (isset($_GET['size'])) {
        $size = $_GET['size'];
    } else {
        $size = "400";
    }
    if (isset($_GET['op'])) {
        $op = $_GET['op'];
    } else {
        $op = "home";
    }
    if (isset($_GET['mode'])) {
        $mode = $_GET['mode'];
    } else {
        $mode = "MIP";
    }



    $_SESSION['fileserver']->compareResult(rawurldecode($_GET['compareResult']),
                             $size, $op, $mode);
    exit;
}


if ($allowHttpTransfer) {
    $message = "            <p class=\"warning\">&nbsp;<br />&nbsp;</p>\n";
    if (isset($_POST['download'])) {
        if (isset($_POST['userfiles']) && is_array($_POST['userfiles'])) {
           $message = $_SESSION['fileserver']->downloadResults($_POST['userfiles']);
        }
    } else if (isset($_GET['download']) ) {
        $downloadArr = array ( $_GET['download'] );
           $message = $_SESSION['fileserver']->downloadResults($downloadArr);
           exit;
    }
}

if (isset($_POST['delete'])) {
    if (isset($_POST['userfiles']) && is_array($_POST['userfiles'])) {
        $message = $_SESSION['fileserver']->deleteFiles($_POST['userfiles']);
        $_SESSION['fileserver']->updateAvailableDestFiles();
    }
} else if (isset($_GET['delete']) ) {
    $deleteArr = array ( $_GET['delete'] );
    $message = $_SESSION['fileserver']->deleteFiles($deleteArr);
    exit;
}

# $browse_folder can be 'src' or 'dest'.
$browse_folder = "dest";

if (isset($_GET['folder']) ) {
    $browse_folder = $_GET['folder'];
}

// To (re)generate the thumbnails, don't use template data, as it is not present
// here.

$useTemplateData = 0;
$file_buttons = array();

if ( $browse_folder == "dest" ) {
    // Number of displayed files.
    $size = 10;
    $multiple_files = true;
    $page_title = "Result files management";
    $form_title = "available restored images on server";
    $fileBrowserLinks = '<li><a href="'.getThisPageName().'?folder=src">'.
        'Originals</a></li><li>Results</li>';

    if ($allowHttpTransfer) {
    $info = "<h3>Quick help</h3>
            <p>This is a list of the images in your destination directory.</p>
            <p>Click on a file to see (or create) a preview.</p>
            <p>Select the files you want to download (you can <b>SHIFT-</b> and
            <b>CTRL-click</b> for multiple selection) and press the
            <b>download</b> icon to compress the files into an archive and start
            the download process. (Please mind that large files may take a
            <b>long time </b>to be packaged before downloading.)</p>
            <p> Use the <b>delete</b> icon to delete the selected files
            instead.</p>";
    $file_buttons[] = "download";
    }


} else {
    $browse_folder = "src";
    $size = 10;
    $multiple_files = true;
    $page_title = "Original files management";
    $form_title = "available raw images on server";
    $fileBrowserLinks = '<li>Originals</li>'.
        '<li><a href="'.getThisPageName().'?folder=dest">'.
        'Results</a></li>';

    if ($allowHttpTransfer) {
    $info = "<h3>Quick help</h3>
            <p>This is a list of the images in your source directory.</p>
            <p>Click on a file to see (or create) a preview.</p>
            <p>Select the files you want to delete (you can <b>SHIFT-</b> and
            <b>CTRL-click</b> for multiple selection) and press the
            <b>delete</b> icon to delete them.
            </p>";
    $file_buttons[] = "upload";
    }
}

$top_navigation = '
            <ul>
            <li>'.$_SESSION['user']->name().'</li>
            '.$fileBrowserLinks.'
            <li><a href="'.getThisPageName().'?home=home"><img src="images/home.png" alt="home" />&nbsp;Home</a></li>
            <li><a href="javascript:openWindow(\'http://support.svi.nl/wiki/style=hrm&amp;help=HuygensRemoteManagerHelpFileManagement\')"><img src="images/help.png" alt="help" />&nbsp;Help</a></li>
        </ul>
 ';

$file_buttons[] = "delete";
$file_buttons[] = "update";

$control_buttons = '
<input name="ref" type="hidden" value="<?php echo $_SESSION[\'referer\']; ?>" />
<input name="OK" type="hidden" /> 
';



include("./inc/FileBrowser.inc");

include("footer.inc.php");

?>
