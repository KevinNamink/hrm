<?php

// php page: about.php

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

include("header.inc.php");

?>

    <div id="nav">
        <ul>
            <li><a href="login.php">back</a></li>
        </ul>
    </div>
    
    <div id="content">
    
        <h2>About HRM version <?php readfile("version"); ?></h2>
        
        <pre>
 Copyright: Montpellier RIO Imaging (CNRS) 

 contributors : 
 	     Pierre Travo	(concept)	     
 	     Volker Baecker	(concept, implementation)

 email:
 	pierre.travo@crbm.cnrs.fr
 	volker.baecker@crbm.cnrs.fr

 Web:     www.mri.cnrs.fr

 huygens remote manager is a software that has been developed at 
 Montpellier Rio Imaging (mri) in 2004 by Pierre Travo and Volker 
 Baecker. It allows running image restoration jobs that are processed 
 by 'Huygens professional' from SVI. Users can create and manage parameter 
 settings, apply them to multiple images and start image processing 
 jobs from a web interface. A queue manager component is responsible for 
 the creation and the distribution of the jobs and for informing the user 
 when jobs finished.

 This software is governed by the CeCILL license under French law and
 abiding by the rules of distribution of free software.
        </pre>
        
    </div> <!-- content -->
    
<?php

include("footer.inc.php");

?>
