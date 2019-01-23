<?php
/* -----------------------------------------------------------------

Dependencies: class.clarity.php

config/site
config/clarity

----------------------------------------------------------------- */

class NGIresearchers {
	public function __construct() {
		global $CONFIG;

		$cache['labs']=$_SERVER['DOCUMENT_ROOT'].rtrim($CONFIG['site']['subdir'],'/').'/cache/clarity_labs.json';
		$cache['researchers']=$_SERVER['DOCUMENT_ROOT'].rtrim($CONFIG['site']['subdir'],'/').'/cache/clarity_researchers.json';

		$this->cache=$cache;
		$this->dbstatus=$_SERVER['DOCUMENT_ROOT'].rtrim($CONFIG['site']['subdir'],'/').'/logs/db_status.json';

		$this->types=array('labs','researchers');
	}

	// Load cached data produced by Clarity download script
	// Download via Clarity API is done as a discrete step separate from adding to local DB since it's very slow and initially led to script timeout
	public function loadCached($type) {
		if(file_exists($this->cache[$type])) {
			$result['data']=json_decode(file_get_contents($this->cache[$type]),TRUE);
			$result['meta']=array('version' => filemtime($this->cache[$type]), 'total' => count($result['data']), 'error' => FALSE);
		} else {
			// Cache file does not exist
			// This takes A LONG TIME TO LOAD FROM LIMS - do not generate cache files automatically...
			$result['data']=FALSE;
			$result['meta']=array('version' => FALSE, 'total' => 0, 'error' => 'File not found');
		}

		return $result;
	}

	// Get Clarity-DB update log
	/*
	array(
		'labs' => array(
			'meta' => array(
				'version' 			=> timestamp,
				'source_version' 	=> cache timestamp,
				'total' 			=> total number of processed
			),
			'details' => array(
				'added' 			=> array(),
				'updated'		 	=> array(),
				'errors' 			=> array()
			)
		),

	)

	array(
		'labs' => array(
			'cache' => array(
				'version' 	=> timestamp,
				'total' 	=> total number of fetched,
			),
			'database' => array(
				'version' 	=> timestamp,
				'total' 	=> total number,
				'errors' 	=> array()
			),
		),

	)

	*/
	public function getDBStatus() {
		if($statusfile=file_get_contents($this->dbstatus)) {
			$data=json_decode($statusfile,TRUE);
		} else {
			// File missing
			$data=array();
		}
		return $data;
	}

	private function setDBStatus($data) {
		if(is_array($data)) {
			if(file_put_contents($this->dbstatus, json_encode($data))) {
				return $data;
			} else {
				return FALSE;
			}
		} else {
			return FALSE;
		}
	}

	public function getClarityData($type) {
		$error=FALSE;
		if(in_array($type, $this->types)) {
			$db_status=$this->getDBStatus();
			$clarity_data=new Clarity();
			if($list=$clarity_data->getList($type)) {
				foreach($list as $item) {
					$data[]=$clarity_data->getEntity($item['uri']);
				}

				if($result=file_put_contents($this->cache[$type], json_encode($data))) {
					$db_status[$type]['cache']['version']=date('Y-m-d H:i:s', filemtime($this->cache[$type]));
					$db_status[$type]['cache']['total']=count($data);
					if($this->setDBStatus($db_status)) {
						$message="Finished caching $type";
					} else {
						$error=TRUE;
						$message="Finished caching $type. Could not write to log file: ".$this->dbstatus;
					}
				} else {
					$error=TRUE;
					$message='Could not write to cache file: '.$this->cache[$type];
				}
			} else {
				$error=TRUE;
				$message='Could not connect to Clarity API';
			}
		} else {
			$error=TRUE;
			$message='Wrong data type: '.$type;
		}

		return array('status' => $db_status, 'message' => $message, 'error' => $error);
	}

	public function updateDB($type) {
		$db_status=$this->getDBStatus();
		$error=FALSE;

		if(in_array($type, $this->types)) {
			if(file_exists($this->cache[$type])) {
				$cached=json_decode(file_get_contents($this->cache[$type]),TRUE);
				if(is_array($cached)) {
					foreach($cached as $data) {
						switch($type) {
							case 'labs':
								$result=$this->updateLab($data);
							break;

							case 'researchers':
								$result=$this->updateResearcher($data);
							break;
						}

						if(!$result) {
							$db_errors[]=$data;
						}
					}

					$prev_total=$db_status[$type]['database']['total'];
					$total_query=sql_query("SELECT * FROM $type");
					$new_total=$total_query->num_rows;
					$added=$new_total-$prev_total;
					$db_status[$type]['database']=array('version' => date('Y-m-d H:i:s'), 'total' => $new_total, 'errors' => $db_errors);

					if($update=$this->setDBStatus($db_status)) {
						$message="Updated database for $type ($added added)";
					} else {
						$error=TRUE;
						$message='Failed to update log file';
					}
				} else {
					// Failed to load cache
					$error=TRUE;
					$message='Failed to load cache';
				}
			} else {
				// Cache file does not exist
				// This takes A LONG TIME TO LOAD FROM LIMS - do not generate cache files automatically...
				$error=TRUE;
				$message='Cache does not exist';
			}
		} else {
			// Unknown type
			$error=TRUE;
			$message='Unknown type';
		}

		return array('status' => $db_status, 'message' => $message, 'error' => $error);
	}

	private function updateLab($data) {
		if($lab_uri=filter_var($data['uri'],FILTER_VALIDATE_URL)) {
			$lab_name=filter_var(utf8_decode($data['name']),FILTER_SANITIZE_MAGIC_QUOTES);
			$lab_affiliation=filter_var(utf8_decode($data['udf']['Affiliation']),FILTER_SANITIZE_MAGIC_QUOTES);

			if($found=sql_fetch("SELECT * FROM labs WHERE lab_clarity_uri='$lab_uri'")) {
				// Lab already in db
				if($lab_affiliation!=$found['lab_affiliation'] || $lab_name!=$found['lab_name']) {
					// Check if something has changed
					$log=$this->addLog('Updated lab from sync with Clarity API','Update',$found['log']);
					$result=sql_query("UPDATE labs SET
						lab_name='$lab_name',
						lab_affiliation='$lab_affiliation',
						log='$log'
						WHERE lab_clarity_uri='$lab_uri'");

					// Check/update errors
					$this->checkLabErrors($lab_uri,$found['lab_status']);
					return TRUE;
				} else {
					return TRUE;
				}
			} else {
				// Lab doesn't exist in db, add it
				$log=$this->addLog('Added lab from sync with Clarity API','Add');
				$result=sql_query("INSERT INTO labs SET
					lab_name='$lab_name',
					lab_status='active',
					lab_affiliation='$lab_affiliation',
					lab_clarity_uri='$lab_uri',
					log='$log'");

				// Check/update errors
				$this->checkLabErrors($lab_uri,NULL);
				return TRUE;
			}
		} else {
			return FALSE;
		}
	}

	// Check lab for errors and update status accordingly
	private function checkLabErrors($lab_clarity_uri,$current_status) {
		if($lab_clarity_uri=filter_var($lab_clarity_uri,FILTER_VALIDATE_URL)) {
			$lab_data=$this->getLab($lab_clarity_uri);
			if($current_status=='error') {
				// Lab already existed and had an error in it, check if this has been corrected
				if(!$lab_data['errors']) {
					// No errors, update DB
					$result=sql_query("UPDATE labs SET
						lab_status='active'
						WHERE lab_clarity_uri='$lab_clarity_uri'");
				}
			} elseif($current_status!='disabled') {
				// No errors reported, check if we have an error
				// Don't update disabled labs
				if($lab_data['errors']) {
					// We found errors, update DB
					$log=$this->addLog("Errors found! ".implode(', ', $lab_data['errors']),"Error",$lab_data['lab']['log']);
					$result=sql_query("UPDATE labs SET
						lab_status='error',
						log='$log'
						WHERE lab_clarity_uri='$lab_clarity_uri'");
				}
			}

			return TRUE;
		} else {
			return FALSE;
		}
	}

	private function updateResearcher($data) {
		if($email=filter_var($data['email'],FILTER_VALIDATE_EMAIL)) {
			$first_name=filter_var($data['first-name'],FILTER_SANITIZE_MAGIC_QUOTES);
			$last_name=filter_var($data['last-name'],FILTER_SANITIZE_MAGIC_QUOTES);

			if($found=sql_fetch("SELECT * FROM researchers WHERE email='$email'")) {
				// Skip updating names, just maintain this DB...
				// TODO: EDIT RESEARCHER INFO
				$result=TRUE;
			} else {
				$log=$this->addLog('Added researcher from sync with Clarity API','Add');
				$add=sql_query("INSERT INTO researchers SET email='$email', first_name='$first_name', last_name='$last_name', log='$log'");

				if($add) {
					$result=TRUE;
				} else {
					$result=FALSE;
				}
			}

			$this->updateGroups($email,$data['lab']['uri']);
			$this->setPI($data['lab']['uri']);
			return $result;
		} else {
			return FALSE;
		}
	}

	public function updateGroups($email,$clarity_lab_uri) {
		if($email=filter_var($email,FILTER_VALIDATE_EMAIL)) {
			if($clarity_lab_uri=filter_var($clarity_lab_uri,FILTER_VALIDATE_URL)) {
				if(!$found=sql_fetch("SELECT * FROM groups WHERE email='$email' AND clarity_lab_uri='$clarity_lab_uri'")) {
					$add=sql_query("INSERT INTO groups SET email='$email', clarity_lab_uri='$clarity_lab_uri'");
				}
			}
		}
	}

	public function setPI($lab_uri,$pi_email=FALSE) {
		if($lab=$this->getLab($lab_uri)) {
			if($pi_email=filter_var($pi_email,FILTER_VALIDATE_EMAIL)) {
				// Manual update
				if(array_key_exists($pi_email, $lab['query']['terms']['all'])) {
					$log=$this->addLog('Manual update of lab PI: '.$pi_email,'Update',$lab['lab']['log']);
					$update=sql_query("UPDATE labs SET lab_pi='$pi_email', log='$log' WHERE lab_clarity_uri='".$lab['lab']['lab_clarity_uri']."'");

					// Check/update errors
					$this->checkLabErrors($lab_uri,$lab['lab']['lab_status']);

					return $pi_email;
				} else {
					return FALSE;
				}
			} else {
				// Automatic update only if PI field is empty
				if($lab['lab']['lab_pi']=='') {
					foreach($lab['group_data'] as $researcher) {
						$fname=$this->normalize(trim($researcher['first_name']));
						$lname=$this->normalize(trim($researcher['last_name']));

						// Identifiy PI with lab name (format: A.Andersson)
						if(strtolower($lab['lab']['lab_name'])==$fname[0].'.'.$lname) {
							$log=$this->addLog('Updated lab PI based on match between researcher and lab name: '.$researcher['email'],'Update',$lab['lab']['log']);
							$update=sql_query("UPDATE labs SET lab_pi='".$researcher['email']."', log='$log' WHERE lab_clarity_uri='$lab_uri'");

							// Check/update errors
							$this->checkLabErrors($lab_uri,$lab['lab']['lab_status']);

							return $researcher['email'];
						} else {
							return FALSE;
						}
					}
				}
			}
		} else {
			return FALSE;
		}
	}

	private function labData($lab) {
		$errors=FALSE;
		if(is_array($lab)) {
			$lab_affiliation=filter_var($lab['lab_affiliation'],FILTER_SANITIZE_MAGIC_QUOTES);
			if(!$affiliation=sql_fetch("SELECT * FROM affiliations WHERE id='$lab_affiliation'")) {
				$errors[]='Affiliation missing!';
			}

			if($lab_clarity_uri=filter_var($lab['lab_clarity_uri'],FILTER_VALIDATE_URL)) {
				$group_query=sql_query("SELECT researchers.email,researchers.last_name,researchers.first_name,researchers.query_name FROM groups LEFT JOIN researchers ON groups.email=researchers.email WHERE groups.clarity_lab_uri='$lab_clarity_uri'");
				if($group_query) {
					$lab_pi=filter_var($lab['lab_pi'],FILTER_SANITIZE_MAGIC_QUOTES);
					if($pi=sql_fetch("SELECT * FROM researchers WHERE email='$lab_pi'")) {
						$terms['pi']=$this->pmName($pi);
					} else {
						$errors[]='No PI set';
					}

					while($researcher=$group_query->fetch_assoc()) {
						if($researcher['email']!=$pi['email']) {
							$terms['collaborators'][]=$this->pmName($researcher);
						}
						$group_data[$researcher['email']]=$researcher; // Added email as key on 2017-11-27... Shouldn't break anything...
						$terms['all'][$researcher['email']]=$this->pmName($researcher);
					}

					if(array_key_exists('pi', $terms)) {
						if($affiliation) {
							$terms['affiliation']=explode(',', $affiliation['search']);
						}

						if(count($terms['affiliation'])>0) {
							$querystring['pi']=$terms['pi'].' AND '.$this->pmAffiliation($terms['affiliation']);
						} else {
							$querystring['pi']=$terms['pi'];
						}

						if(count($terms['collaborators'])>0) {
							$querystring['all_authors']=$terms['pi'].' AND ('.implode(' OR ', $terms['collaborators']).')';
						}
					} else {
						//Error, PI must be defined
						$querystring=FALSE;
					}
				} else {
					$errors[]='No members';
					$group_data=FALSE;
					$terms=FALSE;
					$querystring=FALSE;
				}
			} else {
				$errors[]='Invalid lab identifier';
				$group_data=FALSE;
				$terms=FALSE;
				$querystring=FALSE;
			}
		} else {
			$errors[]='Invalid input';
			$group_data=FALSE;
			$terms=FALSE;
			$querystring=FALSE;
		}

		return array('lab' => $lab, 'affiliation' => $affiliation, 'group_data' => $group_data, 'query' => array('terms' => $terms, 'query_string' => $querystring), 'errors' => $errors);
	}

	// Get lab data from SQL database, query using either Clarity URL, Clarity ID or lab name
	public function getLab($query) {
		global $DB;
		$query=$DB->real_escape_string($query);

		if(filter_var($query,FILTER_VALIDATE_URL)) {
			$lab=sql_fetch("SELECT * FROM labs WHERE lab_clarity_uri='$query' LIMIT 1");
		} else {
			if(filter_var($query, FILTER_VALIDATE_INT)) {
				$lab_uri='https://genologics.scilifelab.se/api/v2/labs/'.$query;
				$lab=sql_fetch("SELECT * FROM labs WHERE lab_clarity_uri='$lab_uri' LIMIT 1");
			} else {
				$lab=sql_fetch("SELECT * FROM labs WHERE lab_name='$query' LIMIT 1");
			}
		}

		if($lab) {
			return $this->labData($lab);
		} else {
			return FALSE;
		}
	}

	public function getResearcher($email) {
		if($email=filter_var($email,FILTER_VALIDATE_EMAIL)) {
			if($researcher=sql_fetch("SELECT * FROM researchers WHERE email='$email'")) {
				$labs=sql_query("SELECT * FROM groups LEFT JOIN labs ON groups.clarity_lab_uri=labs.lab_clarity_uri WHERE groups.email='$email'");
				if($labs->num_rows>0) {
					while($lab=$labs->fetch_assoc()) {
						$lab_data[$lab['lab_name']]=$lab;
					}
				} else {
					$errors[]='Researcher is not member of any lab';
				}

				$papers=sql_query("SELECT * FROM publications_xref LEFT JOIN publications ON publications_xref.publication_id=publications.id WHERE publications_xref.email='$email'");
				if($papers->num_rows>0) { // BUG: Trying to get property 'num_rows' of non-object
					while($paper=$papers->fetch_assoc()) {
						$paper_list[$paper['publication_id']]=$paper;
					}
				} else {
					$paper_list=array();
				}
			} else {
				$errors[]='Researcher not found';
			}
		} else {
			$errors[]='Query string must be a valid email address';
			$researcher=FALSE;
			$lab_data=FALSE;
		}

		return array('data' => $researcher, 'lab_data' => $lab_data, 'publications' => $paper_list, 'errors' => $errors);
	}

	public function showLabList($sql,$page,$limit=10) {
		$output='';
		$pagination_string='';
		if(!$page=filter_var($page,FILTER_VALIDATE_INT)) {
			$page=1;
		}
		$total=$sql->num_rows;
		if($total>0) {
			$pages=ceil($total/$limit);
			$show_first=($page-1)*$limit+1;
			$show_last=$page*$limit;
			if($page>0 && $page<=$pages) {
				$pagination=new zurbPagination();
				$pagination_string=$pagination->paginate($page,$pages,$_GET);

				$n=1;
				while($lab=$sql->fetch_assoc()) {
					if($n>=$show_first && $n<=$show_last) {
						$output.=$this->formatLab($lab);
					}
					$n++;
				}
			} else {
				$output='ERROR: page out of range';
			}
		} else {
			$output='No records found';
		}

		return array('list' => $output, 'pagination' => $pagination_string);
	}

	public function showResearcherList($sql,$page,$limit=10) {
		$output='';
		$pagination_string='';
		if(!$page=filter_var($page,FILTER_VALIDATE_INT)) {
			$page=1;
		}
		$total=$sql->num_rows;
		if($total>0) {
			$pages=ceil($total/$limit);
			$show_first=($page-1)*$limit+1;
			$show_last=$page*$limit;
			if($page>0 && $page<=$pages) {
				$pagination=new zurbPagination();
				$pagination_string=$pagination->paginate($page,$pages,$_GET);

				$n=1;
				while($researcher=$sql->fetch_assoc()) {
					if($n>=$show_first && $n<=$show_last) {
						$researcher_data=$this->getResearcher($researcher['email']);
						$output.=$this->formatResearcher($researcher_data, $researcher['email']);
					}
					$n++;
				}
			} else {
				$output='ERROR: page out of range';
			}
		} else {
			$output='No records found';
		}

		return array('list' => $output, 'pagination' => $pagination_string);
	}


	public function formatResearcher($researcher, $email) {
		$email=$researcher->email;

		$labs=array();
		$error_list=array();

		$container=new htmlElement('div');

		$error_string=new htmlElement('p');
		if(count($researcher['errors'])>0) {
			$container_class='alert';
			foreach($researcher['errors'] as $error) {
				$error_list[]='<span class="label warning">'.$error.'</span>';
			}
			$error_string->set('text', implode(' ', $error_list));
		} else {
			$container_class='primary';
			$error_string='';
		}

		$lab_string=new htmlElement('p');
		if(count($researcher['lab_data'])>0) {
			foreach($researcher['lab_data'] as $lab) {
				if(!is_null($lab['lab_pi']) and ($lab['lab_pi']==$email)) {
					$labs[]='<span class="label primary">'.$lab['lab_name'].$lab['lab_pi'].', PI</span>';
				} else {
					$labs[]='<span class="label secondary">'.$lab['lab_name'].'</span>';
				}
			}
			$lab_string->set('text', implode(' ',$labs));
		}

		$papers=new htmlElement('p');
		$link_to_papers=' (<a href="/publications.php?author_email='.$researcher['data']['email'].'">view papers</a>)';
		$papers->set('text', 'Listed in '.count($researcher['publications']).' papers.'.$link_to_papers);

		$container->set('class',"callout $container_class");
		$title=new htmlElement('strong');
		$title->set('text',$researcher['data']['first_name'].' '.$researcher['data']['last_name'].' ('.$researcher['data']['email'].')');
		$container->inject($title);
		$container->inject($lab_string);
		$container->inject($papers);
		$container->inject($error_string);
		$output.=$container->output();
		return $output;
	}

	public function formatLab($lab_data) {
		$lab_data=$this->labData($lab_data);

		$groupmembers=array();
		$affiliation='';
		$error_list=array();

		if(is_array($lab_data['affiliation'])) {
			$affiliation=' ('.$lab_data['affiliation']['name'].')';
		}

		if(is_array($lab_data['group_data'])) {
			foreach($lab_data['group_data'] as $member) {
				$name_string=$member['first_name'].' '.$member['last_name'];
				if($member['email']==$lab_data['lab']['lab_pi']) {
					$name_string.=', PI';
					$name_class='success';
				} else {
					$name_class='secondary';
				}
				$groupmembers[]='<span class="label '.$name_class.'">'.$name_string.'</span>';
			}
		} else {
			$groupmembers[]='No members, add lab members in LIMS and sync again';
		}

		$error_string=new htmlElement('span');
		if($lab_data['errors']) {
			$container_class='alert';
			foreach($lab_data['errors'] as $error) {
				$error_list[]='<span class="label warning">'.$error.'</span>';
			}
			$error_string->set('text', implode(' ', $error_list));
		} else {
			$container_class='primary';
			$error_string='';
		}

		$lims_id=$this->getClarityID($lab_data['lab']['lab_clarity_uri']);

		$tools=new htmlElement('p');
		$publications=new htmlElement('span');
		$publications->set('class','small button find_pub');
		$publications->set('id','lab-'.$lims_id);
		$publications->set('text','Find publications');
		if(!$lab_data['errors']) {
			$tools->inject($publications);
		}
		$edit_lab=new htmlElement('a');
		$edit_lab->set('class','small button edit_lab');
		$edit_lab->set('href','lab_edit.php?id='.$lims_id);
		$edit_lab->set('text','Edit lab');
		$tools->inject($edit_lab);

		$limiter=new htmlElement('hr');

		$container=new htmlElement('div');
		$container->set('class',"callout $container_class");
		$title=new htmlElement('strong');
		$title->set('text',$lab_data['lab']['lab_name'].$affiliation);
		$members=new htmlElement('p');
		$members->set('text', implode(' ', $groupmembers));
		$container->inject($title);
		$container->inject($error_string);
		$container->inject($limiter);
		$container->inject($members);
		$container->inject($tools);

		return $container->output();
	}

	// Return the numerical identifier of the URL
	public function getClarityID($url) {
		$id=array_pop(explode('/',$url));
		if(filter_var($id,FILTER_VALIDATE_INT)) {
			return $id;
		} else {
			return FALSE;
		}
	}

	// Returns name formatted for querying PubMed (researcher table contain alternative field for special cases)
	private function pmName($researcher) {
		if(is_array($researcher)) {
			if($researcher['query_name']!='') {
				return $researcher['query_name'];
			} else {
				return $researcher['last_name'].' '.$researcher['first_name'][0];
			}
		} else {
			return FALSE;
		}
	}

	// Returns affiliation formatted for querying PubMed
	private function pmAffiliation($array) {
		if(is_array($array)) {
			foreach($array as $affiliation_name) {
				if(strpos($affiliation_name, ' ') !== FALSE) {
					$parsed[]='('.$affiliation_name.')';
				} else {
					$parsed[]=$affiliation_name;
				}
			}

			$querystring=implode(' OR ', $parsed);
			if(count($array)>1) {
				return '('.$querystring.')';
			} else {
				return $querystring;
			}
		} else {
			return FALSE;
		}
	}

	private function normalize ($string) {
		/* Stolen from php.net. Not fool-proof since there is no direct
		translation between udf-8 and ascii */
		$table = array(
			'Š'=>'S', 'š'=>'s', 'Đ'=>'Dj', 'đ'=>'dj', 'Ž'=>'Z', 'ž'=>'z', 'Č'=>'C', 'č'=>'c', 'Ć'=>'C', 'ć'=>'c',
			'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'A', 'Ç'=>'C', 'È'=>'E', 'É'=>'E',
			'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O',
			'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O', 'Ù'=>'U', 'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ý'=>'Y', 'Þ'=>'B', 'ß'=>'Ss',
			'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'a', 'ç'=>'c', 'è'=>'e', 'é'=>'e',
			'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'o', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o',
			'ô'=>'o', 'õ'=>'o', 'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ý'=>'y', 'ý'=>'y', 'þ'=>'b',
			'ÿ'=>'y', 'Ŕ'=>'R', 'ŕ'=>'r', 'ü'=>'u', 'ė'=>'e', 'ą'=>'a', 'ū'=>'u', 'Ł'=>'L', 'ś'=>'s', 'ł'=>'l',
			'ş'=>'s', 'ń'=>'n', 'ğ'=>'g', 'ę'=>'e', '’'=>"'", 'ő'=>'o', 'ı'=>'l',
		);

		$string = strtr($string, $table);
		$string = strtolower($string);
		$string = preg_replace('/\s+/', '', $string);
		$string = str_replace('-', '', $string);
		return $string;
	}

	private function addLog($message,$action,$json=FALSE) {
		global $USER;

		if(trim($message)!="") {
			if($json) {
				$log=json_decode($json,TRUE);
			} else {
				$log=array();
			}

			$entry=array(
				'timestamp' => time(),
				'user'		=> $USER->data['user_email'],
				'action'	=> $action,
				'message'	=> $message);

			$log[]=$entry;
			return json_encode($log);
		} else {
			return FALSE;
		}
	}
}
