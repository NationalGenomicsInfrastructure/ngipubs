<?php
require 'lib/global.php';

if($USER->auth>0) {
	$publications=new NGIpublications();
	if(isset($_REQUEST['publication_id'])) {
		if(isset($_REQUEST['comment'])) {
			if($publications->commentPublication($_REQUEST['publication_id'], $USER->data['uid'], $_REQUEST['comment'])) {
				echo json_encode(array('comment_result' => 'Success'));
			}
		}
	}
}
