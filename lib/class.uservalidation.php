<?php
/*
--------------------------------------------------------------------------------------
class.uservalidation.php
--------------------------------------------------------------------------------------
Based on example at http://www.wikihow.com/Create-a-Secure-Login-Script-in-PHP-and-MySQL
@github https://github.com/peredurabefrog/phpSecureLogin

Components
==========

js/uservalidation.js
	This script takes care of submitting hashed passwords over POST instead of the actual password

js/sha512.js
	Source: http://pajhome.org.uk/crypt/md5/sha512.html

config.php

index.php

confirm.php

signup.php

database.sql
	Required database schema, two tables called users and login_attempts

*/

class Uservalidation {
	public function __construct() {
		$this->data=FALSE;
		$this->auth=0;

		$this->loginCheck();
	}

	// login function is provided a hashed password directly from the browser (see uservalidation.js)
	public function login($email,$hash) {
		global $DB,$CONFIG;
		if($email=filter_var($email,FILTER_VALIDATE_EMAIL)) {
			$email=$DB->real_escape_string($email);
			if($user=sql_fetch("SELECT * FROM users WHERE user_email='$email' AND user_status>1 LIMIT 1")) {
				// Check for brute force attack
				if($this->checkBrute($email)) {
					// Possible brute force attack
					// Block user
					$update=sql_query("UPDATE users SET user_status=0 WHERE user_email='$email' LIMIT 1");
					$this->addLog($user['uid'],'alert','Possible brute force attack - user blocked');
					// Send alert to admin
					$subject='['.$CONFIG['site']['name'].'] Warning - possible brute force attack';
					$message='Possible brute force attack detected on '.date('Y-m-d H:i:s').'. Please check logs for user '.$email;
					mail($CONFIG['site']['admin'],$subject,$message);
					return FALSE;
				} else {
					$hash=hash('sha512',$hash.$CONFIG['uservalidation']['salt']);
					if(hash_equals($user['user_hash'],$hash)) {
						// Successful login
						$user_browser=$_SERVER['HTTP_USER_AGENT'];
						$_SESSION['session_email']=$user['user_email'];
						$_SESSION['session_hash']=hash('sha512',$user['user_hash'].$user_browser);
						$this->data=$user;
						$this->auth=$user['user_auth'];
						$this->addLog($user['uid'],'info','Successful login');
						return TRUE;
					} else {
						// Email and hash does not match
						// Record attempt in login_attempts table
						$now=time();
						$user_ip=$_SERVER['REMOTE_ADDR'];
						$add=sql_query("INSERT INTO login_attempts SET email='$email', time='$now', ip='$user_ip'");
						return FALSE;
					}
				}
			} else {
				// User does not exist
				return FALSE;
			}
		} else {
			// Not a valid email address
			return FALSE;
		}
	}

	public function logout() {
		$this->addLog($this->data['uid'],'info','Log out');
		$this->destroySession();
		$this->data=FALSE;
		$this->auth=0;
	}

	// Validate user session
	private function loginCheck() {
		if($user=$this->getUser($_SESSION['session_email'])) {
			if(hash_equals(hash('sha512',$user['user_hash'].$_SERVER['HTTP_USER_AGENT']),$_SESSION['session_hash'])) {
				// Successful match
				$this->data=$user;
				$this->auth=$user['user_auth'];
				return TRUE;
			} else {
				// Hashes does not match
				$this->clearSession();
				return FALSE;
			}
		} else {
			// User doesn't exist
			$this->clearSession();
			return FALSE;
		}
	}

	private function addLog($uid,$action,$message) {
		global $DB;
		if(trim($message)!="") {
			if(filter_var($uid,FILTER_VALIDATE_INT)) {
				$uid=$DB->real_escape_string($uid);
				$action=$DB->real_escape_string($action);
				$message=$DB->real_escape_string($message);
				if($user=sql_fetch("SELECT log FROM users WHERE uid=$uid LIMIT 1")) {
					$log=json_decode($user['log'],TRUE);
					if(!is_array($log)) {
						$log=array();
					}
					$log[]=array(
						'timestamp'	=> time(),
						'ip'		=> $_SERVER['REMOTE_ADDR'],
						'action'	=> $action,
						'message'	=> $message
					);
					if($update=sql_query("UPDATE users SET log='".json_encode($log)."' WHERE uid=$uid LIMIT 1")) {
						return $log;
					} else {
						return FALSE;
					}
				} else {
					return FALSE;
				}
			} else {
				return FALSE;
			}
		} else {
			return FALSE;
		}
	}

	// Get data for specific user (either by email, uid or hash)
	public function getUser($string) {
		global $DB;
		$string=$DB->real_escape_string($string);
		if($email=filter_var($string,FILTER_VALIDATE_EMAIL)) {
			$checkuser=sql_fetch("SELECT * FROM users WHERE user_email='$email' LIMIT 1");
		} elseif($id=filter_var($string,FILTER_VALIDATE_INT)) {
			$checkuser=sql_fetch("SELECT * FROM users WHERE uid='$id' LIMIT 1");
		} else {
			$checkuser=sql_fetch("SELECT * FROM users WHERE user_hash='$string' LIMIT 1");
		}
		return $checkuser;
	}

	// Reset password for users
	public function resetPassword($email) {
		global $DB,$CONFIG;
		if($email) {
			$email=$DB->real_escape_string($email);
			// Check if user exist
			if($this->getUser($email)) {
				$hash=md5($email.time());
				if($add=sql_query("UPDATE users SET user_hash='$hash', user_auth=0, user_status=1 WHERE user_email='$email'")) {
					$uid=$DB->insert_id;
					return TRUE;
				} else { return FALSE; }
			} else { return FALSE; }
		} else { return FALSE; }
	}

	// Add a new user from signup form
	public function addUser($email) {
		global $DB,$CONFIG;

		if($CONFIG['uservalidation']['allowed']) {
			$email=$this->isAllowed($email);
		} else {
			$email=filter_var($email,FILTER_VALIDATE_EMAIL);
		}

		if($email) {
			$email=$DB->real_escape_string($email);
			// Check if user exist
			if(!$this->getUser($email)) {
				// Generate MD5 sum from email and timestamp
				$hash=md5($email.time());
				if($add=sql_query("INSERT INTO users SET user_email='$email', user_hash='$hash', user_auth=0, user_status=1")) {
					$uid=$DB->insert_id;
					$this->addLog($uid,'add','User added');

					// Send confirmation email
					$confirm_url=$CONFIG['site']['url']."/confirm.php?code=$hash";
					$subject='Registration at '.$CONFIG['site']['name'];
					$message="Welcome to ".$CONFIG['site']['name']."! \n\nPlease follow this link to confirm your registration: $confirm_url \n\nIf you didn't register please contact the site administrator (".$CONFIG['site']['admin'].").";
					if(mail($email,$subject,$message)) {
						$this->addLog($uid,'info','Confirmation email sent: '.$hash);
					} else {
						$this->addLog($uid,'error','Confirmation email was not sent: '.$hash);
					}

					return TRUE;
				} else {
					// Could not add user
					return FALSE;
				}
			} else {
				// User already exist
				return FALSE;
			}
		} else {
			// Email did not validate
			return FALSE;
		}
	}

	// Confirm a user who signed up, check the signup code and then replace it with the hashed password
	public function confirmUser($code,$hash) {
		global $DB,$CONFIG;
		$code=$DB->real_escape_string($code);
		$hash=$DB->real_escape_string($hash);
		$hash=hash('sha512',$hash.$CONFIG['uservalidation']['salt']);
		if($user=sql_fetch("SELECT * FROM users WHERE user_hash='$code' AND user_status=1 LIMIT 1")) {
			if($update=sql_query("UPDATE users SET user_hash='$hash', user_auth=1, user_status=2 WHERE uid=".$user['uid'])) {
				$this->addLog($user['uid'],'info','User confirmed');
				return TRUE;
			} else {
				return FALSE;
			}
		} else {
			// Hash string does not exist
			return FALSE;
		}
	}

	// Update user information
	// $data = $_POST or any array with keys that correspond with table field names
	public function editUser($uid,$data) {
		global $DB;

		// Clean input
		foreach($data as $key => $value) {
			$$key=$DB->real_escape_string($value);
		}
		$uid=filter_var($uid,FILTER_SANITIZE_NUMBER_INT);

		if($userdata=$this->getUser($uid)) {
			// Review of changes
			foreach($userdata as $key => $value) {
				// Make sure that the variables exist in database, check if it has changed, and don't count the log field!
				if($key!='log' && $$key!=$value && array_key_exists($key,$data)) {
					$updates[]=$key.'='.$$key;
				}
			}

			// Only updated if there were any changes
			if(count($updates)) {
				$update=sql_query("UPDATE users SET
					user_fname='$user_fname',
					user_lname='$user_lname',
					user_auth='$user_auth'
					WHERE uid=$uid");

				if($update) {
					// Great success!
					$this->addLog($uid,'edit',implode(', ',$updates));
					return TRUE;
				} else {
					// Could not update
					return FALSE;
				}
			} else {
				// No changes
				return FALSE;
			}
		} else {
			// User not found
			return FALSE;
		}
	}

	public function listUsers() {
		$query=sql_query('SELECT * FROM users');
		if($query->num_rows>0) {
			while($user=$query->fetch_assoc()) {
				$users[$user['user_email']]=$user;
			}
			return $users;
		} else {
			return FALSE;
		}
	}

	// Check for brute force attacks, if a specfic emails have done more than 5 logins during the last hour the user will be blocked
	private function checkBrute($email) {
		global $DB;
		$email=$DB->real_escape_string($email);
		$now=time();
		$limit=$now-3600; // Check attempts during the past hour
		$check=sql_query("SELECT * FROM login_attempts WHERE email='$email' AND time>'$limit'");
		if($DB->num_rows>5) {
			return TRUE;
		} else {
			return FALSE;
		}
	}

	private function clearSession() {
		// Unset all session variables related to user validation
		unset($_SESSION['session_email']);
		unset($_SESSION['session_hash']);
	}

	private function destroySession() {
		$this->clearSession();

		// If it's desired to kill the session, also delete the session cookie.
		// Note: This will destroy the session, and not just the session data!
		if(ini_get("session.use_cookies")) {
		    $params=session_get_cookie_params();
		    setcookie(session_name(), '', time() - 42000,
		        $params["path"], $params["domain"],
		        $params["secure"], $params["httponly"]
		    );
		}

		// Finally, destroy the session.
		session_destroy();
	}

	// --- Optional: check external source for list of allowed users (in this case StatusDB)

	// Get list of allowed users
	// This function can be modified to retrieve users from any source, output must be an array with all allowed email adresses as values
	private function getAllowedUsers() {
		global $CONFIG;
		$couch=new Couch($CONFIG['couch']['host'],$CONFIG['couch']['port'],$CONFIG['couch']['user'],$CONFIG['couch']['pass']);
		$json=$couch->getView($CONFIG['couch']['views']['users']);

	    foreach($json->rows as $object) {
		    $users[]=$object->key;
	    }

	    return $users;
	}

	// Check if email exists in list of allowed users
	private function isAllowed($email) {
		$users=$this->getAllowedUsers();
	    if(in_array($email,$users)) {
		    return $email;
	    } else {
		    return FALSE;
	    }
	}
}
