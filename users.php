<?php
require 'lib/global.php';

if($USER->auth>0) {
	// Logged in
	if(isset($_POST['submit'])) {
		// Form submitted - edit user data
		if($USER->editUser($_POST['uid'],$_POST)) {
			// User updated
			header('Location:users.php?uid='.$_POST['uid']);
		} else {
			// Update failed
			header('Location:users.php');
		}
	} elseif(isset($_POST['cancel'])) {
		// Form cancelled
		header('Location:users.php?uid='.$_POST['uid']);
	} else {
		// View and edit users
		if(isset($_GET['uid'])) {
			$uid=filter_var($_GET['uid'],FILTER_SANITIZE_NUMBER_INT);
			if($userdata=$USER->getUser($uid)) {
				// Selected user exist
				if($_GET['action']=='edit') {
					// Edit user
					// ================================================================================
					// Only allow managers or admins to edit other users
					if($USER->data['uid']==$uid || $USER->auth>1) {
						$usercard=new zurbCard();
						$usercard->divider($userdata['user_email'],'card-divider');
						$userform=new htmlForm('users.php');
						$userform->addInput(FALSE,array('type' => 'hidden', 'name' => 'uid', 'value' => $uid));
						$userform->addInput('First name',array('type' => 'text', 'name' => 'user_fname', 'value' => $userdata['user_fname']));
						$userform->addInput('Last name',array('type' => 'text', 'name' => 'user_lname', 'value' => $userdata['user_lname']));
						$userform->addSelect('Role','user_auth',$CONFIG['uservalidation']['roles'],array($userdata['user_auth']));
						$userform->addInput(FALSE,array('type' => 'submit', 'name' => 'submit', 'value' => 'Edit', 'class' => 'button'));
						$userform->addInput(FALSE,array('type' => 'submit', 'name' => 'cancel', 'value' => 'Cancel', 'class' => 'secondary button'));
						$usercard->section($userform->render());
						$html=$usercard->render();
					} else {
						// Not authorized
						header('Location:users.php');
					}
			  } elseif($_GET['action']=='reset') {
					// Reset password
					// ================================================================================
					// Only allow managers or admins to edit other users.
					if($USER->data['uid']==$uid || $USER->auth>1) {

						if($USER->resetPassword($userdata['user_email'])){
							$html .= "<p>Password for ".$userdata['user_email']." is now reset. User needs to be confirmed again</p>";
						} else {
							$html .= "Could not reset the password";
						}
						$html .= "<button onclick='window.history.go(-1);'>Go back</button>";
					}
				} else {
					// View specific user
					// ================================================================================
					// Only allow managers or admins to view details of specific users
					if($USER->data['uid']==$uid || $USER->auth>1) {
						$usercard=new zurbCard();
						$usercard->divider($userdata['user_email'],'card-divider');
						$list=new htmlList();
						$list->listItem("Name: ".$userdata['user_fname']." ".$userdata['user_lname']);
						$list->listItem("Role: ".$CONFIG['uservalidation']['roles'][$userdata['user_auth']]);
						$list->listItem("Status: ".$CONFIG['uservalidation']['status'][$userdata['user_status']]);
						$usercard->section($list->render());
						if($userdata['user_auth'] == 0) {
							$usercard->section("User confirmation link: ".$CONFIG['site']['url']."/confirm.php?code=".$userdata['user_hash']);
						}
						$usercard->section('
						<div class="button-group">
							<a href="users.php?uid='.$uid.'&action=edit" class="small button right">Edit</a>
							<button onclick="var answer = confirm(\'Do you really want to reset the password for this user?\'); answer ? window.location.assign(\'users.php?uid='.$uid.'&action=reset\') : false;" class="small button right">Reset Password</button>
						</div>
						');
						$html=$usercard->render();

						// Get log
						$userlog=json_decode($userdata['log'],TRUE);
						// Format log for table
						foreach($userlog as $logdata) {
							$row['time']=date('Y-m-d H:i:s',$logdata['timestamp']);
							$row['action']=$logdata['action'];
							$row['IP']=$logdata['ip'];
							$row['message']=$logdata['message'];
							$tabledata[]=$row;
						}
						// Create table
						$logtable=new htmlTable('Log',array('class' => 'log'));
						$logtable->addData($tabledata);
						$html.=$logtable->render();
					} else {
						// Not authorized
						header('Location:users.php');
					}
				}
			} else {
				$html='<p>User does not exist</p>';
			}
		} else {
			// List all users
			// ================================================================================
			$userlist=$USER->listUsers();
			// Format data for table
			foreach($userlist as $email => $userdata) {
				if($USER->data['user_email']==$email || $USER->auth>1) {
					// Only allow managers or admins to edit other users
					$row['email']='<a href="users.php?uid='.$userdata['uid'].'">'.$email.'</a>';
				} else {
					$row['email']=$email;
				}
				$row['role']=$CONFIG['uservalidation']['roles'][$userdata['user_auth']];
				$row['status']=$CONFIG['uservalidation']['status'][$userdata['user_status']];
				$tabledata[]=$row;
			}
			// Create table
			$usertable=new htmlTable('Users');
			$usertable->addData($tabledata);
			$html=$usertable->render();
		}
	}
} else {
	// Not logged in content
	header('Location:index.php');
}

// Render Page
//=================================================================================================
?>

<!doctype html>
<html class="no-js" lang="en" dir="ltr">

<head>
	<meta charset="utf-8">
	<meta http-equiv="x-ua-compatible" content="ie=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo $CONFIG['site']['name']; ?></title>
	<link rel="stylesheet" href="css/foundation.css">
	<link rel="stylesheet" href="css/app.css">
	<link rel="stylesheet" href="css/icons/foundation-icons.css" />
</head>

<body>
<?php require '_menu.php'; ?>

<div class="row">
<br>
<div class="large-12 columns">
<?php echo $html; ?>
</div>
</div>

<script src="js/vendor/jquery.js"></script>
<script src="js/vendor/what-input.js"></script>
<script src="js/vendor/foundation.js"></script>
<script src="js/app.js"></script>
</body>

</html>
