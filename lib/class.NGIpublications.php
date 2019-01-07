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

	public function updatePubStatus($publication_id,$status,$user) {
		global $DB;
		if($publication_id=filter_var($publication_id, FILTER_VALIDATE_INT)) {
			if($check=sql_fetch("SELECT * FROM publications WHERE id='$publication_id' LIMIT 1")) {
				if($update=sql_query("UPDATE publications SET status='$status' WHERE id='$publication_id'")) {
					// Reset reservation if status is set to maybe so others can pick it up
					if($status=='maybe') {
						$reset=sql_query("UPDATE publications SET reservation_user=NULL, reservation_timestamp=NULL WHERE id='$publication_id'");
					}
					$this->addLog($publication_id,$status,"Status updated to '$status'",'status_updated');

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

	public function commentPublication($publication_id,$user,$comment) {
		global $DB;
		if($publication_id=filter_var($publication_id, FILTER_VALIDATE_INT)) {
			if($check=sql_fetch("SELECT * FROM publications WHERE id='$publication_id' LIMIT 1")) {
				$this->addLog($publication_id,'',$comment,'comment');
				return TRUE;
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

			try {
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
					authors='".filter_var(json_encode($article['authors'],JSON_UNESCAPED_UNICODE),FILTER_SANITIZE_MAGIC_QUOTES)."'");
			}
			catch (Exception $e) {
				$add = false;
				error_log("ERROR: '$e'");
			}
			if($add) {
				$publication_id=$DB->insert_id;
				$parse_authors=$this->parseAuthors($publication_id,$lab_data);
				$full_text = $this->addFullText($publication_id);
				$score_pub=$this->scorePublication($publication_id);
				$status='added';
				$errors[]='';

				$this->addLog($publication_id,'','Publication added by search for lab: '.$lab_data['lab']['lab_name'],'added');

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

			// Consolidate lists (pub db has two labels for NGI Stockholm, see _sync_db
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
				$list['total'][] = $pmid;
				//if($check=sql_fetch("SELECT pmid FROM publications WHERE pmid=$pmid")) {
				if(array_key_exists($pmid, $local['pmid'])) {
					// Paper already exist in local db
					if($local['pmid'][$pmid]!='') {
						// Status already set
						// If verified, note that it is also added
						if ($local['pmid'][$pmid]=='verified') {
							//$update=sql_query("UPDATE publications SET status='verified_and_added' WHERE pmid=$pmid");
							$list['verified_and_added'][]=$pmid;
						} elseif ($local['pmid'][$pmid]=='discarded' || $local['pmid'][$pmid]=='maybe') {
							// Report if matches with "discarded" or "maybe"
							$list['mismatch'][]=$pmid;
						} elseif ($local['pmid'][$pmid]=='auto' || $local['pmid'][$pmid]=='verified_and_added') {
							$list['no_change'][]=$pmid;
						} else {
							$list['other_unknown_status'][] = $pmid;
						}
					} else {
						// This should be a quite rare case
						// Status not set, set status to "auto".
						// Use "auto" since it might be good to double check these, there has been some erroneously added papers in the past
						$update=sql_query("UPDATE publications SET status='auto',submitted='$now' WHERE pmid=$pmid");
						$list['auto'][]=$pmid;
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

	public function addFullText($publication_id) {
		global $CONFIG;
		global $DB;

		if($publication_id=filter_var($publication_id,FILTER_VALIDATE_INT)) {
			$pmidq=sql_fetch("SELECT pmid FROM publications WHERE id='$publication_id'");
			if($pmidq) {
				try {
					$pmid = $pmidq['pmid'];
					$key_conf = $CONFIG['publications']['keywords'];
					$keywords = http_build_query(array('key' => implode(',', $key_conf)));
			  	$parse_url = $CONFIG['publications']['parse_url'].'/annotate/'.$pmid.'?';
					$check_publis = file_get_contents($parse_url.$keywords);
					$result = json_decode($check_publis);
					$status = $result[0]->{'found_text'};
					$matches = $result[0]->{'matches'};
				}
				catch (Exception $e) {
					error_log("ERROR: '$e'");
					return false;
				}
				if($ret_var > 0) {return false;}
				if($existing_text=sql_fetch("SELECT * from publications_text WHERE publication_id='$publication_id'")) {
					if($existing_text['status'] == "error" and $status != "error") {

						$out=sql_query("UPDATE publications_text SET status='$status',
						text='".filter_var(json_encode($matches,JSON_UNESCAPED_UNICODE),FILTER_SANITIZE_MAGIC_QUOTES)."'");
					}
					else {
						return false;
					}
				}
				else {
					$out=sql_query("INSERT INTO publications_text SET
					publication_id=$publication_id,
					status='$status',
					text='".filter_var(json_encode($matches,JSON_UNESCAPED_UNICODE),FILTER_SANITIZE_MAGIC_QUOTES)."'");
				}
				return true;
			}
			return false;
		}
	}

	// Keywords are defined in config.php
	public function scorePublication($publication_id) {
		global $CONFIG;

		if($publication_id=filter_var($publication_id,FILTER_VALIDATE_INT)) {
			if($publication_data=sql_fetch("SELECT * FROM publications WHERE id='$publication_id'")) {
				$publication=$this->publicationData($publication_data);
				$total_researchers=count($publication['researchers']);
				$verified=0; $discarded=0;
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
					$text = $publication['data']['abstract'];
					if(is_array($publication['matches']) && count($publication['matches']) > 0) {
						foreach($publication['matches'] as $mt) {
							// Matches needs word boundaries. I'll fix this ugliness later.
							$text .= '\n we have '.$mt.' a match. \n';
						}
					}
					if(preg_match_all("($keyword_list)", strtolower($text),$matches)) {
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

	// Reserve a list of publications for verification
	// Each user will get a list of publications selected randomly from unverified and 'maybe'
	// When the list has been finished a new will be generated.
	// User must verify all records, use 'maybe' if unsure, before a new one is generated
	public function reservePublications($user_email,$year,$score=5,$limit=10) {
		if($user_email=filter_var($user_email,FILTER_VALIDATE_EMAIL)) {
			$year=filter_var($year,FILTER_VALIDATE_INT);
			$score=filter_var($score,FILTER_VALIDATE_INT);
			$limit=filter_var($limit,FILTER_VALIDATE_INT);
			if($year && $score && $limit) {
				// Check if user has already reserved papers
				if(!$check=sql_fetch("SELECT * FROM publications WHERE reservation_user='$user_email' AND status IS NULL")) {
					// Only reserve new ones if the old list is empty
					$timestamp=time();
					$reserve=sql_query("UPDATE publications
						SET
							reservation_user='$user_email',
							reservation_timestamp='$timestamp'
						WHERE
							pubdate>='$year-01-01' AND
							pubdate<='$year-12-31' AND
							score>='$score' AND
							(status IS NULL OR status='maybe') AND
							reservation_user IS NULL
						ORDER BY RAND() LIMIT $limit");
					// Update log on the reserved papers
					if($updated=sql_query("SELECT * FROM publications WHERE reservation_user='$user_email' AND reservation_timestamp=$timestamp")) {
						while($publication=$updated->fetch_assoc()) {
							$log=$this->addLog($publication['id'],'', '','reserved');
						}
					}
				}

				// Fetch all reserved un-verified and 'maybe' papers
				if($query=sql_query("SELECT * FROM publications WHERE reservation_user='$user_email' AND (status IS NULL OR status='maybe')")) {
					return $query;
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

	// Summarize verified publications
	public function getScoreboard($year=FALSE,$user=FALSE) {
		$result=array();
		if($user=filter_var($user,FILTER_VALIDATE_EMAIL)) {
			if($year=filter_var($year,FILTER_VALIDATE_INT)) {
				// Get user score for specified year
				$query=sql_query("SELECT status,COUNT(*) AS count FROM publications
					WHERE
						(status='verified' OR status='discarded') AND
						pubdate>='$year-01-01' AND
						pubdate<='$year-12-31' AND
						reservation_user='$user'
					GROUP BY status
					ORDER BY status DESC");
			} else {
				// Get total user score
				$query=sql_query("SELECT status,COUNT(*) AS count FROM publications
					WHERE
						(status='verified' OR status='discarded') AND
						reservation_user='$user'
					GROUP BY status
					ORDER BY status DESC");
			}

			if($query) {
				while($data=$query->fetch_assoc()) {
					$result[]=array("status" => $data['status'], "count" => $data['count']);
				}
			}
			return $result;
		} else {
			// Get global scoreboard
			if($year=filter_var($year,FILTER_VALIDATE_INT)) {
				// Get user score for specified year
				$query=sql_query("SELECT reservation_user,status,COUNT(*) AS count FROM publications
					WHERE
						(status='verified' OR status='discarded') AND
						pubdate>='$year-01-01' AND
						pubdate<='$year-12-31' AND
						reservation_user IS NOT NULL
					GROUP BY reservation_user,status
					ORDER BY reservation_user,status DESC");
			} else {
				// Get total user score
				$query=sql_query("SELECT reservation_user,status,COUNT(*) AS count FROM publications
					WHERE
						(status='verified' OR status='discarded') AND
						reservation_user IS NOT NULL
					GROUP BY reservation_user,status
					ORDER BY reservation_user,status DESC");
			}

			if($query) {
				while($data=$query->fetch_assoc()) {
					$result[$data['reservation_user']]['name']=$data['reservation_user'];
					$result[$data['reservation_user']][$data['status']]=$data['count'];
				}
			}

			// Calculate total score (sum of verified/discarded papers)
			foreach($result as $key => $row) {
				$order[$key]=array_sum($row);
			}
			arsort($order);

			// Format output
			foreach($order as $key => $score) {
				$final[]=array('name' => $result[$key]['name'], 'verified' => $result[$key]['verified'], 'discarded' => $result[$key]['discarded'], 'total' => $score);
			}

			return $final;
		}
	}

	public function showPublicationList($sql,$page,$limit=10) {
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
				while($publication=$sql->fetch_assoc()) {
					if($n>=$show_first && $n<=$show_last) {
						$output.=$this->formatPublication($publication);
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

	// Fetch additional metadata
	private function publicationData($publication) {
		$authors=json_decode($publication['authors'],TRUE);
		foreach($authors as $author) {
			$author_data[]=$author['name'];
		}

		$ptext = sql_fetch("SELECT * FROM publications_text WHERE publication_id=".$publication['id']);
		$matches = [];
		if($ptext) {
			$text = json_decode($ptext['text']);
			if(is_array($text) && count($text)>0) {
				$m = [];
				foreach($text as $t) {
					$m[$t[0]] = $t[0];
				}
				// Only return the matching keywords once
				$matches = array_keys($m);
			}
		}

		$xref=sql_query("SELECT * FROM publications_xref JOIN researchers ON publications_xref.email=researchers.email WHERE publication_id=".$publication['id']);

		$pis=sql_query("SELECT publications_xref.email FROM publications_xref "
					   ."JOIN researchers ON publications_xref.email=researchers.email "
					   ."JOIN labs ON publications_xref.email=labs.lab_pi "
					   ." WHERE publication_id=".$publication['id']);

		$pi_list=array();
		if($pis) {
			while($pi=$pis->fetch_assoc()) {
				$pi_list[]=$pi['email'];
			}
		}

		$researcher_list=array();
		if($xref) {
			while($researcher=$xref->fetch_assoc()) {
				if (in_array($researcher['email'], $pi_list)) {
					$pi_string = ' (PI)';
				} else {
					$pi_string = '';
				}
				$researcher_list[$researcher['email']]=trim($researcher['first_name']).' '.trim($researcher['last_name']).$pi_string;
			}
		}

		return array('data' => $publication, 'authors' => $author_data, 'researchers' => $researcher_list, 'matches' => $matches, 'fulltext' => $ptext);
	}

	// Format and display details of a publication from the database
	public function formatPublication($publication) {
		global $CONFIG;
		$publication=$this->publicationData($publication);

		$container=new htmlElement('div');
		$container->set('id','publ-'.$publication['data']['id']);

		if(is_array($publication)) {
			$volume=empty($publication['data']['volume']) ? '' : $publication['data']['volume'];
			$issue=empty($publication['data']['issue']) ? ' (-)' : ' ('.$publication['data']['issue'].')';
			$pages=empty($publication['data']['pages']) ? '' : ', pp '.$publication['data']['pages'];
			$reference=$volume.$issue.$pages;

			switch($publication['data']['status']) {
				default:
					$publication_status='<span class="label" id="status_label-'.$publication['data']['id'].'">Pending</span> ';
					$container->set('class','callout secondary');
				break;

				case 'verified':
					$publication_status='<span class="label success" id="status_label-'.$publication['data']['id'].'">Verified</span> ';
					$container->set('class','callout success');
				break;

				case 'auto':
					$publication_status='<span class="label warning" id="status_label-'.$publication['data']['id'].'">Auto</span> ';
					$container->set('class','callout warning');
				break;

				case 'maybe':
					$publication_status='<span class="label warning" id="status_label-'.$publication['data']['id'].'">Maybe</span> ';
					$container->set('class','callout warning');
				break;

				case 'discarded':
					$publication_status='<span class="label alert" id="status_label-'.$publication['data']['id'].'">Discarded</span> ';
					$container->set('class','callout alert');
				break;
			}

			$researcher_string='';
			foreach($publication['researchers'] as $researcher_email => $researcher) {
				$researcher_string.='<span class="label secondary"><a class="publication_label_link" href="/publications.php?author_email='.$researcher_email.'">'.$researcher.'</a></span> ';
			}

			$keyword_string='';
			$keyword_array=json_decode($publication['data']['keywords'],TRUE);
			foreach($keyword_array as $keyword) {
				$keyword_string.='<span class="label secondary"><a class="publication_label_link" href="/publications.php?keyword='.$keyword.'">'.$keyword.'</a></span> ';
			}

			// Set up containers
			$row=new htmlElement('div');
			$row->set('class','row');

			$main=new htmlElement('div');
			$main->set('class','large-10 columns');

			$tools=new htmlElement('div');
			$tools->set('class','large-2 columns');

			//Content
			$title=new htmlElement('h5');
			$title->set('text',$publication_status.'<span class="label">'.$publication['data']['score'].'</span> '
					.html_entity_decode($publication['data']['title']).' '
					.'(<a href="https://www.ncbi.nlm.nih.gov/pubmed/'.$publication['data']['pmid'].'" target="_blank">Pubmed</a>'
					.' | <a href="'.$CONFIG['site']['URL'].'/publications.php?id='.html_entity_decode($publication['data']['id']).'">Permalink</a>)');

			$ref=new htmlElement('p');
			$ref->set('text',$publication['authors'][0].' et. al. '.date('Y',strtotime($publication['data']['pubdate'])).', '.$publication['data']['journal'].', '.$reference);

			$authors=new htmlElement('p');
			$authors->set('text',implode(', ', $publication['authors']).'<br>');

			$abstract=new htmlElement('p');
			$abstract->set('text',$publication['data']['abstract']);

			$researchers=new htmlElement('p');
			#$researchers->set('text', 'My version: '.count($publication['researchers']));
			$researchers->set('text','Matched authors: '.$researcher_string.'<br>Matched keywords in abstract: '.$keyword_string);

			// Fulltext keyword matches
			$fulltext = new htmlElement('div');
			// TODO: fix this ugliness
			if($matches = json_decode($publication['fulltext']['text'])) {}
			else {
				$matches = json_decode(utf8_decode($publication['fulltext']['text']));
			}
			$amatches = [];
			foreach($matches as $match) {
				$amatches[$match[0]][] = $match[1];
			}
			$bmatches = array_map(function($ar){ return implode("...<br/><br/>",$ar); }, $amatches);
			if($bmatches) {
				$fulltext_keywords=new zurbAccordion(TRUE,TRUE);
				foreach($bmatches as $key => $match){
					$mtext = preg_replace('/'.$key.'/i', '<mark>${0}</mark>', $match);
					$fulltext_keywords->addAccordion($key,$mtext);
				}
				$fulltext->set('text','<strong>Managed to retrieve fulltext of this paper, see matched keywords in list below!</strong>'.$fulltext_keywords->render());
			} else {
				$fulltext->set('text','<strong>No matches in fulltext</strong>');
			}


			$detailed_content=new htmlElement('div');
			$detailed_content->inject($researchers);
			$detailed_content->inject($authors);
			$detailed_content->inject($abstract);
			$detailed_content->inject($fulltext);

			$accordion=new zurbAccordion(TRUE,TRUE);
			$accordion->addAccordion('Details',$detailed_content->output());

			$details=new htmlElement('div');
			$details->set('class','pub_details');
			$details->set('text',$accordion->render());

			$log_rows=sql_query("SELECT * FROM publications_logs JOIN publications ON publications_logs.publication_id=publications.id WHERE publication_id=".$publication['data']['id']);
			$log_list=array();
			if($log_rows) {
				while($log_item=$log_rows->fetch_assoc()) {
					$log_list[]=$log_item;
					#$researcher_list[$researcher['email']]=trim($researcher['first_name']).' '.trim($researcher['last_name']);
				}
			}

			$log_details=$this->formatLog($log_list);

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

			$tools_comment=new htmlElement('textarea');
			$tools_comment->set('type', 'text');
			$tools_comment->set('class', 'comment-input');
			$tools_comment->set('id','comment-'.$publication['data']['id']);
			$tools_comment->set('rows', "2");
			$tools_comment->set('placeholder','Comment');

			$tools_comment_btn=new htmlElement('span');
			$tools_comment_btn->set('class','primary button tiny expanded comment_button');
			$tools_comment_btn->set('id','comment-'.$publication['data']['id']);
			$tools_comment_btn->set('text','Add Comment');

			$main->inject($title);
			$main->inject($ref);
			$main->inject($details);
			$main->inject($log_details);

			$tools->inject($tools_verify);
			$tools->inject($tools_maybe);
			$tools->inject($tools_discard);
			$tools->inject($tools_comment);
			$tools->inject($tools_comment_btn);

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
		$errors=[]; $added=[]; $matched=[];
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

	private function formatLog($log_list) {
		$table=new htmlElement('div');
		$table->set('class','log');

		foreach(array_reverse($log_list) as $entry) {
			$log_div = new htmlElement('div');
			$log_div->set('class', 'log_item '.$entry['type']);

			$log_user=new htmlElement('span');
			$log_user->set('class', 'log_user');
			$user_name = substr($entry['user_email'], 0, strpos($entry['user_email'], "@"));
			$user_name = str_replace("."," ",$user_name);
			$user_name = ucwords($user_name);
			$log_user->set('text', $user_name);

			$log_timestamp=new htmlElement('span');
			$log_timestamp->set('class', 'log_timestamp');
			$log_timestamp->set('text', gmdate('Y-m-d H:i:s', $entry['timestamp']));

 			if ($entry['type'] == 'status_updated') {
				$log_status_change = new htmlElement('span');
				$log_status_icon = new htmlElement('i');
				switch($entry['status_set']){
					case 'verified':
						$log_status_icon->set('class', "fi-target");
					break;
					case 'maybe':
						$log_status_icon->set('class', "fi-alert");
					break;
					case 'discarded':
						$log_status_icon->set('class', "fi-prohibited");
					break;
				}
				$log_status_title = new htmlElement('span');
				if ($entry['status_set'] == 'maybe') {
					$log_status_title->set('text', $entry['status_set']." set by ");
				} else {
					$log_status_title->set('text', $entry['status_set']." by ");
				}

				$log_user_timestamp = new htmlElement('span');
				$log_user_timestamp->inject($log_user);
				$log_user_timestamp->inject($log_timestamp);

				$log_status_change->inject($log_status_icon);
				$log_status_change->inject($log_status_title);
				$log_status_change->inject($log_user_timestamp);


				$log_div->inject($log_status_change);
			}

			if (in_array($entry['type'], ['comment', 'status_updated'])) {

				if($entry['type'] == 'comment'){
					$log_header = new htmlElement('div');

					$log_header->inject($log_user);
					$log_header->inject($log_timestamp);

					$log_div->inject($log_header);
				}
				if ($entry['comment'] != ''){
					$log_comment=new htmlElement('div');
					$log_comment->set('class', 'log_comment');
					$log_comment->set('text', $entry['comment']);

					$log_div->inject($log_comment);
				}

			}

			if (in_array($entry['type'], ['added', 'reserved'])) {
				$log_div->set('class', 'log_item status_update '.$entry['type']);

				$log_header = new htmlElement('span');
				$log_header->set('class', 'log_status_update');
				$log_text = '';
				if($entry['type'] == 'reserved') {
					$log_text .= 'Reserved by '.$user_name;
				}
				$log_text .= $entry['comment'];

				$log_header->set('text', $log_text);

				$log_header->inject($log_timestamp);

				$log_div->inject($log_header);
			}

			// else {
			// 	$log_header = new htmlElement('pre');
			// 	$log_header->set('text', print_r($entry, true));
			// 	$log_div->inject($log_header);
			// }
			$table->inject($log_div);
		}


		return $table;

	}


	private function addLog($publication_id,$status,$message,$type) {
		global $USER;
		global $DB;
		$timestamp = time();

		$query_string = "INSERT INTO publications_logs SET
				publication_id='".filter_var($publication_id,FILTER_SANITIZE_NUMBER_INT)."',
				user_uid='".filter_var($USER->data['uid'],FILTER_SANITIZE_NUMBER_INT)."',
				user_email='".filter_var($USER->data['user_email'],FILTER_SANITIZE_EMAIL)."',
				status_set='".trim($DB->real_escape_string( $status ))."',
				comment='".trim($DB->real_escape_string( $message ))."',
				type='".$type."',
				timestamp='".$timestamp."'";

		$comment_set=sql_query($query_string);
		return TRUE;
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
