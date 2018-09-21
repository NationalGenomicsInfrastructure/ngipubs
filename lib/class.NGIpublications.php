<?php

class NGIpublications {
	// publist is an array of PubMed eSummary data from the PHPMed class
	public function addBatch($pub_list,$lab_data) {
		if(is_array($pub_list)) {
			foreach($pub_list as $publication) {
				$add[]=$this->addPublication($publication,$lab_data);
			}
		} else {
			$add=FALSE;
		}
		
		return $add;
	}
	
	public function updatePubStatus($publication_id,$status) {
		if($publication_id=filter_var($publication_id, FILTER_VALIDATE_INT)) {
			if($check=sql_fetch("SELECT * FROM publications WHERE id='$publication_id' LIMIT 1")) {
				$log=$this->addLog('Publication status updated to: '.$status,'update',$check['log']);
				if($update=sql_query("UPDATE publications SET status='$status', log='$log' WHERE id='$publication_id'")) {
					return TRUE;
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
	
	// $article is an array with PubMed eSummary data from the PHPMed class
	public function addPublication($article,$lab_data=FALSE) {
		global $DB;
		$publication_id=FALSE;
		
		if(trim($article['uid'])!='') {
			$found=sql_fetch("SELECT * FROM publications WHERE pmid='".$article['uid']."' LIMIT 1");
		} elseif(trim($article['doi'])!='') {
			$found=sql_fetch("SELECT * FROM publications WHERE doi='".$article['doi']."' LIMIT 1");
		} else {
			$found=FALSE;
		}

		if($found) {
			// Publication is already added!
			$publication_id=$found['id'];
			$parse_authors=$this->parseAuthors($found['id'],$lab_data);
			$status='found';
		} else {
			// Add publication to database
			$log=$this->addLog('Publication added by search for lab: '.$lab_data['lab']['lab_name'],'add');
			$add=sql_query("INSERT INTO publications SET 
				pmid='".filter_var($article['uid'],FILTER_SANITIZE_NUMBER_INT)."', 
				doi='".filter_var($this->retrieveDOI($article['articleids']),FILTER_SANITIZE_MAGIC_QUOTES)."', 
				pubdate='".filter_var(date('Y-m-d', strtotime($article['sortpubdate'])),FILTER_SANITIZE_MAGIC_QUOTES)."', 
				journal='".filter_var(trim($article['source']),FILTER_SANITIZE_MAGIC_QUOTES)."', 
				volume='".filter_var($article['volume'],FILTER_SANITIZE_NUMBER_INT)."', 
				issue='".filter_var($article['issue'],FILTER_SANITIZE_NUMBER_INT)."', 
				pages='".filter_var($article['pages'],FILTER_SANITIZE_NUMBER_INT)."', 
				title='".filter_var(trim($article['title']),FILTER_SANITIZE_MAGIC_QUOTES)."', 
				abstract='".filter_var(trim($article['abstract']),FILTER_SANITIZE_MAGIC_QUOTES)."', 
				authors='".filter_var(json_encode($article['authors'],JSON_UNESCAPED_UNICODE),FILTER_SANITIZE_MAGIC_QUOTES)."', 
				log='$log'");
			
			if($add) {
				$publication_id=$DB->insert_id;
				$parse_authors=$this->parseAuthors($publication_id,$lab_data);
				$score_pub=$this->scorePublication($publication_id);
				$status='added';
			} else {
				$errors[]='Could not add publication';
				$status='error';
			}
		}
		
		return array('data' => array('status' => $status, 'publication_id' => $publication_id, 'authors' => $parse_authors), 'errors' => $errors);
	}
	
	// Compare publication data from specific labels in the SciLifeLab publication database with the local database
	// $sources is an array of 1 or more URI's to JSON data
	// OBS, currently this is done ONLY for PMID's, papers with only DOI are not compared yet
	// Returns an array with:
	//		- mismatches: papers verified in SciLifeLab database but marked as "discarded" or "maybe" in local database
	//		- missing: papers that does not exist in local database
	public function checkDB($sources) {
		if(is_array($sources)) {
			$now=date('Y-m-d');
			
			// Fetch data from SciLifeLab publication database
			foreach($sources as $source) {
				$data[]=json_decode(file_get_contents($source),TRUE);
			}
			
			// Consolidate lists (pub db has two labels for NGI Stockholm, see above)
			// Use PMID and/or DOI as key to get rid of duplicates 
			foreach($data as $set) {
				foreach($set['publications'] as $publication) {
					if($publication['pmid']>0) {
						$remote['pmid'][$publication['pmid']]=$publication['pmid'];
					} else {
						$remote['doi'][$publication['doi']]=$publication['doi'];
					}
				}
			}
			
			// Build array with all existing papers to avoid doing hundreds of db queries
			$all=sql_query("SELECT pmid,doi,status FROM publications");
			while($paper=$all->fetch_assoc()) {
				if($paper['pmid']>0) {
					$local['pmid'][$paper['pmid']]=$paper['status'];
				} else {
					$local['doi'][$paper['doi']]=$paper['status'];
				}
			}
				
			// Check which PMID's exist in local db
			foreach($remote['pmid'] as $pmid) {
				//if($check=sql_fetch("SELECT pmid FROM publications WHERE pmid=$pmid")) {
				if(array_key_exists($pmid, $local['pmid'])) {
					// Paper already exist in local db
					if($local['pmid'][$pmid]!='') {
						// Status already set, DO NOT CHANGE!
						// Report if matches with "discarded" or "maybe"
						if($local['pmid'][$pmid]=='discarded' || $local['pmid'][$pmid]=='maybe') {
							$list['mismatch'][]=$pmid;
						}
					} else {
						// Status not set, set status to "auto". 
						// Use "auto" since it might be good to double check these, there has been some erroneously added papers in the past
						$update=sql_query("UPDATE publications SET status='auto',submitted='$now' WHERE pmid=$pmid");
					}
				} else {
					// Paper does not exist in local db
					// Auto add these, and set status to "auto"
					$list['missing'][]=$pmid;
				}
			}
			
			// Do the above for DOI once the Crossref retrieving is done...

		} else {
			$list=FALSE;
		}
		
		return $list;
	}
	
	// Keywords are defined in config.php
	public function scorePublication($publication_id) {
		global $CONFIG;
		
		if($publication_id=filter_var($publication_id,FILTER_VALIDATE_INT)) {
			if($publication_data=sql_fetch("SELECT * FROM publications WHERE id='$publication_id'")) {
				$publication=$this->publicationData($publication_data);
				$total_researchers=count($publication['researchers']);
				
				foreach($publication['researchers'] as $email => $name) {
					$papers=sql_query("
						SELECT publications.status FROM publications_xref 
						JOIN publications ON publications_xref.publication_id=publications.id 
						WHERE email='$email'");
					
					while($paper=$papers->fetch_assoc()) {
						if($paper['status']=='verified') {
							$verified++;
						} elseif($paper['status']=='discarded') {
							$discarded++;
						}
					}
				}
				
				// Modify score based on number of rated publications
				if($verified>0 AND $discarded>0) {
					$modifier=sqrt($verified/$discarded);
				} else {
					if($verified>0) {
						$modifier=sqrt($verified);
					} elseif($discarded>0) {
						$modifier=sqrt(1/$discarded);
					} else {
						$modifier=1;
					}
				}
				
				// Set word boundaries for keywords
				foreach($CONFIG['publications']['keywords'] as $keyword) {
					$keywords[]='\b'.$keyword.'\b';
				}
				
				// Format keyword list for regex
				$keyword_list=implode('|', $keywords);
		
				$unique_keywords=array();
	
				if(trim($publication['data']['abstract'])!='') {
					if(preg_match_all("($keyword_list)", strtolower($publication['data']['abstract']),$matches)) {
						$total_matches=count($matches[0]);
						$unique_keywords=array_values(array_unique($matches[0]));
						
						if($total_researchers==0) {
							// Weight of matched keywords will be lower if there are no matched authors
							$score=0.5*$total_matches;
						} else {
							$score=(1+$total_researchers)*$total_matches;
						}
					} else {
						// No keyword hits, decrease weight of author number
						$score=0.5*$total_researchers;
					}
				} else {
					// No abstract
					$score=5*$total_researchers;
				}
				
				$score=$score*$modifier;
	
				$matched_unique_keywords=json_encode($unique_keywords);
				
				if($update=sql_query("UPDATE publications SET score=$score, keywords='$matched_unique_keywords' WHERE id=$publication_id")) {
					return TRUE;
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
	
	// Show data for single publication (extended info compared to list)
	public function showPublication($publication_id) {
		if(filter_var($publication_id,FILTER_VALIDATE_INT)) {
			if($publication=sql_fetch("SELECT * FROM publications WHERE id='$publication_id' LIMIT 1")) {
				$output=$this->formatPublication($this->publicationData($publication),TRUE);
			} else {
				$output='ERROR: could not retrieve publication data';
			}
		} else {
			$output='ERROR: invalid publication ID';
		}
		
		return $output;
	}
	
	
	public function showSelectedPublications($sql) {
		if(is_object($sql)) {
			while($publication=$sql->fetch_assoc()) {
				$output.=$this->formatPublication($this->publicationData($publication));
			}
		} else {
			$output='ERROR: no publications to show';
		}
		
		/*
		foreach($data as $id) {
			if($id=filter_var($id,FILTER_VALIDATE_INT)) {
				$verified[]=$id;
			}
		}
		
		if(count($verified)>0) {
			$verified_string=implode(',',$verified);
			
			switch($type) {
				default:
				case 'id':
					$query=sql_query("SELECT * FROM publications WHERE id IN($verified_string)");
				break;
				
				case 'pmid':
					$query=sql_query("SELECT * FROM publications WHERE pmid IN($verified_string)");
				break;
			}
	
			if($query->num_rows>0) {
				$output='';
				while($publication=$query->fetch_assoc()) {
					$output.=$this->formatPublication($this->publicationData($publication));
				}
			} else {
				// No publications...
				$output='ERROR: no publications to show';
			}
		} else {
			$output='ERROR: no publications to show';
		}
		*/
		
		return $output;
	}
	
	// Show list of publications
	public function showPublicationList($year=FALSE) {
		if(filter_var($year,FILTER_VALIDATE_INT) && strlen($year)==4) {
			// Select specific year
			$query=sql_query("SELECT * FROM publications WHERE pubdate>='$year-01-01' AND pubdate<='$year-12-31' ORDER BY score DESC");
		} else {
			// List all publications
			$query=sql_query("SELECT * FROM publications");
		}
		
		if($query->num_rows>0) {
			$output='';
			while($publication=$query->fetch_assoc()) {
				$output.=$this->formatPublication($this->publicationData($publication));
			}
		} else {
			// No publications...
			$output='ERROR: no publications to show';
		}
		
		return $output;
	}
	
	// Fetch additional metadata
	public function publicationData($publication) {
		$authors=json_decode($publication['authors'],TRUE);
		foreach($authors as $author) {
			$author_data[]=$author['name'];
		}

		$xref=sql_query("
			SELECT * 
			FROM publications_xref 
				JOIN researchers ON publications_xref.email=researchers.email 
			WHERE publication_id=".$publication['id']);
		
		$researcher_list=array();
		if($xref) {
			while($researcher=$xref->fetch_assoc()) {
				$researcher_list[$researcher['email']]=trim($researcher['first_name']).' '.trim($researcher['last_name']);
			}
		}
		
		return array('data' => $publication, 'authors' => $author_data, 'researchers' => $researcher_list);
	}
	
	public function formatPublication($publication,$details=FALSE) {
		$container=new htmlElement('div');

		if(is_array($publication)) {
			$volume=empty($publication['data']['volume']) ? '' : $publication['data']['volume'];
			$issue=empty($publication['data']['issue']) ? ' (-)' : ' ('.$publication['data']['issue'].')';
			$pages=empty($publication['data']['pages']) ? '' : ', pp '.$publication['data']['pages'];
			$reference=$volume.$issue.$pages;
			
			switch($publication['data']['status']) {
				default:
					$publication_status='<span class="label" id="status_label">Pending</span> ';
					$container->set('class','callout secondary');
				break;
				
				case 'verified':
					$publication_status='<span class="label success" id="status_label">Verified</span> ';
					$container->set('class','callout success');
				break;
				
				case 'auto':
					$publication_status='<span class="label warning" id="status_label">Auto</span> ';
					$container->set('class','callout warning');
				break;
				
				case 'maybe':
					$publication_status='<span class="label warning" id="status_label">Maybe</span> ';
					$container->set('class','callout warning');
				break;
				
				case 'discarded':
					$publication_status='<span class="label alert" id="status_label">Discarded</span> ';
					$container->set('class','callout alert');
				break;
			}
			
			$researcher_string='';
			foreach($publication['researchers'] as $researcher) {
				$researcher_string.='<span class="label secondary">'.$researcher.'</span> ';
			}
			
			$keyword_string='';
			$keyword_array=json_decode($publication['data']['keywords'],TRUE);
			foreach($keyword_array as $keyword) {
				$keyword_string.='<span class="label secondary">'.$keyword.'</span> ';
			}

			$row=new htmlElement('div');
			$row->set('class','row');

			$main=new htmlElement('div');
			$main->set('class','large-10 columns');

			$tools=new htmlElement('div');
			$tools->set('class','large-2 columns');

			$title=new htmlElement('h5');
			$title->set('text',$publication_status.'<span class="label">'.$publication['data']['score'].'</span> '.html_entity_decode($publication['data']['title']));

			$ref=new htmlElement('p');
			if($details) {
				$ref->set('text',implode(', ', $publication['authors']).'<br>'.date('Y',strtotime($publication['data']['pubdate'])).', '.$publication['data']['journal'].', '.$reference);
			} else {
				$ref->set('text',$publication['authors'][0].' et. al. '.date('Y',strtotime($publication['data']['pubdate'])).', '.$publication['data']['journal'].', '.$reference);
			}

			$researchers=new htmlElement('p');
			$researchers->set('text','Matched authors: '.$researcher_string.'<br>Matched keywords: '.$keyword_string);

			$tools_read=new htmlElement('a');
			$tools_read->set('href','publications.php?id='.$publication['data']['id']);
			$tools_read->set('class','tiny button expanded');
			$tools_read->set('text','Read');

			$tools_pubmed=new htmlElement('a');
			$tools_pubmed->set('href','https://www.ncbi.nlm.nih.gov/pubmed/'.$publication['data']['pmid']);
			$tools_pubmed->set('class','tiny button expanded');
			$tools_pubmed->set('text','Pubmed');

			$tools_verify=new htmlElement('span');
			$tools_verify->set('class','tiny success button expanded verify_button');
			$tools_verify->set('id','verify-'.$publication['data']['id']);
			$tools_verify->set('text','Verify');

			$tools_maybe=new htmlElement('span');
			$tools_maybe->set('class','tiny warning button expanded maybe_button');
			$tools_maybe->set('id','maybe-'.$publication['data']['id']);
			$tools_maybe->set('text','Maybe');

			$tools_discard=new htmlElement('span');
			$tools_discard->set('class','tiny alert button expanded discard_button');
			$tools_discard->set('id','discard-'.$publication['data']['id']);
			$tools_discard->set('text','Discard');

			$main->inject($title);
			$main->inject($ref);
			$main->inject($researchers);
			
			if($details) {
				$abstract=new htmlElement('p');
				$abstract->set('text',$publication['data']['abstract']);
	
				$main->inject($abstract);
			}
			
			if($details) {
				$tools->inject($tools_pubmed);
				$tools->inject($tools_verify);
				$tools->inject($tools_maybe);
				$tools->inject($tools_discard);
			} else {
				$tools->inject($tools_read);
			}

			$row->inject($main);
			$row->inject($tools);
			$container->inject($row);
		} else {
			$container->set('class','callout alert');
			$error=new htmlElement('p');
			$error->set('text','ERROR: No publication data');
			$container->inject($error);
		}
		
		return $container->output();
	}

	private function retrieveDOI($id_array) {
		foreach($id_array as $id_set) {
			if($id_set['idtype']=='doi') {
				return trim($id_set['value']);
			}
		}
		
		return FALSE;
	}
	
	/*
	OBS! This must be done after the publication has been added and received an ID -- xref table use publication ID (not pmid or doi)
	
	$authors is the author section from a PubMed eSummary
	
	[authors] => Array(
		[0] => Array(
			[name] => Romano R
			[authtype] => Author
			[clusterid] => 
		) ...
		
	The script will match author list with registered lab members and add to xref table
	*/
	
	private function parseAuthors($publication_id,$lab_data) {
		if(is_array($lab_data)) {
			if($publication_id=filter_var($publication_id,FILTER_VALIDATE_INT)) {
				if($publication=sql_fetch("SELECT * FROM publications WHERE id=$publication_id")) {
					$authors=json_decode($publication['authors'],TRUE);
					if(is_array($authors)) {
						foreach($authors as $author) {
							// Check if authors match with any registered lab members
							foreach($lab_data['query']['terms']['all'] as $email => $member) {
								if($member==$author['name']) {
									$matched[]=array('publication_id' => $publication_id, 'researcher_name' => $member);
									// We have a match, add this to xref table
									// Add if link doesn't already exist
									if(!$check=sql_fetch("SELECT * FROM publications_xref WHERE publication_id=$publication_id AND email='$email'")) {
										if($add=sql_query("INSERT INTO publications_xref SET publication_id=$publication_id, email='$email'")) {
											$added[]=array('publication_id' => $publication_id, 'researcher_name' => $member);
										} else {
											$errors[]="Error when adding author-publication reference to database [publ: $publication_id, author: $member]";
										}
									}
								}
							}
						}
					} else {
						$errors[]='Invalid author data';
					}
				} else {
					$errors[]='Publication not found';
				}
			} else {
				$errors[]='Invalid publication ID';
			}
		} else {
			$errors[]='Invalid lab data';
		}
		
		return array('data' => array('total' => count($authors), 'matched' => $matched, 'added' => $added), 'errors' => $errors);
	}
	
	// IN PROGRESS!!!
	// ------------------------
	private function formatLog($log_json) {
		$log=json_decode($log_json);
		$container=new htmlElement('div');
		$container->set('class','log');
		foreach($log as $entry) {
			
		}
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
	
	private function getLastLog($json) {
		$log=json_decode($json,TRUE);
		if(count($log)) {
			return array_pop($log);
		} else {
			return FALSE;
		}
	}
}