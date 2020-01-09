<?php
require 'lib/global.php';

if($USER->auth>1) {
	$pubmed=new PHPMed();
	$publications=new NGIpublications();
	//----------------------------------------------------
	// "Sync" with SciLifeLab Publications database


	// JSON sources for Production and Applications papers in the SciLifeLab database
	$sources=array(
		'https://publications.scilifelab.se/label/NGI%20Stockholm%20%28Genomics%20Production%29.json',
		'https://publications.scilifelab.se/label/NGI%20Stockholm%20%28Genomics%20Applications%29.json'
	);
	$list=$publications->checkDB($sources);

	// Auto add missing papers
	/* $autoadd_data=$pubmed->summary($list['missing']);

	// OBS! This will NOT parse authors since no lab is given, however this will be done if the PMID shows up in searches
	// Not a big issue since this is likely only a one-time event and only for old papers
	$add_papers=$publications->addBatch($autoadd_data,FALSE);
	*/
	$errors[]='None';

} else {
	// Not logged in
	$errors[]='Not logged in';
}

echo json_encode(array('missing' => count($list['missing']),
					   'mismatch' => $list['mismatch'],
					   'auto' => count($list['auto']),
					   'no_change' => count($list['no_change']),
					   'other_unknown_status' => $list['other_unkown_status'],
					   'total' => count($list['total']),
					   'errors' => implode(', ', $errors)));
