<?php
require 'lib/global.php';


if($USER->auth>0) {
	global $CONFIG;
	$config_year = $CONFIG['publications']['current_year'];
	$publications=new NGIpublications();

	$years=range(2010, date('Y')+1);
	$years_select=array_combine($years,$years);
	$years_select[0]="All";
	asort($years_select);

	$filterform=new htmlForm("publications.php","get",4);
	$filterform->addSelect("Status","status",array('all' => 'All', 'verified' => 'Verified', 'discarded' => 'Discarded', 'maybe' => 'Maybe', 'auto' => 'Auto', 'pending' => 'Pending'),$_GET);
	$filterform->addSelect("Year","year",$years_select,  (empty($_GET) ? array($config_year => $config_year) : $_GET));
	$filterform->addSelect("Order by","order_by",array('score' => 'Score', 'pubdate' => 'Publication date'),$_GET);
	$filterform->addSelect("Sort","sort",array('desc' => 'Descending', 'asc' => 'Ascending'),$_GET);

	$filterform->addInput("Search Term", array('type' => 'search', 'name' => 'search_term'), $_GET);
	$filterform->addSelect("Search Type", "search_type", array('pubmedid' => 'PubMed ID', 'title' => 'Title', 'author_email' => 'Author E-mail', 'keyword' => 'Keyword', 'doi' => 'DOI'), $_GET);

	$filterform->addInput("<br/>",array('type' => 'submit', 'name' => 'submit', 'value' => 'Apply Filter', 'class' => 'button'));

	switch($_GET['order_by']) {
		default:
		case 'score':
			$order_string=" ORDER BY score";
		break;

		case 'pubdate':
			$order_string=" ORDER BY pubdate";
		break;
	}

	switch($_GET['sort']) {
		default:
		case 'desc':
			$order_string.=" DESC";
		break;

		case 'asc':
			$order_string.=" ASC";
		break;
	}

	switch($_GET['status']) {
		default:
		case 'all':
			$filters=array();
		break;

		case 'verified':
			$filters[]="status='verified'";
		break;

		case 'discarded':
			$filters[]="status='discarded'";
		break;

		case 'maybe':
			$filters[]="status='maybe'";
		break;

		case 'auto':
			$filters[]="status='auto'";
		break;

		case 'pending':
			$filters[]="status IS NULL";
		break;
	}

	$email_filtering_string="publications.id IN (SELECT publication_id FROM publications_xref WHERE email='";
	if ($_GET['id']) {
		$pub_id=trim($DB->real_escape_string( $_GET['id'] ));
		$filters[]="id=".$pub_id;
	} elseif ($_GET['pubmedid']) {
		$pubmed_id=trim($DB->real_escape_string( $_GET['pubmedid'] ));
		$filters[]="pmid=".$pubmed_id;
	} elseif ($_GET['author_email']) {
		$author_email=trim($DB->real_escape_string( $_GET['author_email'] ));
		$filters[]=$email_filtering_string.$author_email."') ";
	} elseif ($_GET['keyword']) {
		$keyword=trim($DB->real_escape_string( $_GET['keyword'] ));
		$filters[]="keywords LIKE '%".$keyword."%' ";
	}

	if($year=filter_input(INPUT_GET,'year',FILTER_VALIDATE_INT,array('min_range' => 2000,'max_range' => 2100))) {
		$filters[]="pubdate>='$year-01-01' AND pubdate<='$year-12-31'";
	}
	elseif(empty($_GET['id'])) {
		$filters[]="pubdate>='$config_year-01-01' AND pubdate<='$config_year-12-31'";
	}

	if ($_GET['search_term']) {
		$search_string = trim($DB->real_escape_string( $_GET['search_term']));
		switch($_GET['search_type']) {
			default:
			case 'pubmedid':
				$filters[]="pmid='".$search_string."'";
			break;

			case 'title':
				$filters[]="title LIKE '%".$search_string."%' ";
			break;

			case 'author_email':
				$author_email=$search_string;
				$filters[]=$email_filtering_string.$author_email."') ";
			break;

			case 'keyword':
				$filters[]="keywords LIKE '%".$search_string."%' ";
			break;

			case 'doi':
				$filters[]="doi='".$search_string."' ";
			break;
		}
	}

	$query_string="SELECT * FROM publications ";
	if (count($filters)>0) {
		$query_string.=' WHERE '.implode(' AND ', $filters);
	}

	$query=sql_query($query_string.$order_string);

	$publication_list=$publications->showPublicationList($query,$_GET['page']);
} else {
	// Not logged in
	header('Location:login.php');
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
	<link rel="stylesheet" href="css/foundation.min.css">
	<link rel="stylesheet" href="css/app.css">
	<link rel="stylesheet" href="css/icons/foundation-icons.css" />
</head>

<body>
<?php require '_menu.php'; ?>

<div class="row">
	<br>
	<div class="large-12 columns">
		<?php echo $filterform->render(); ?>
	</div>
	<div class="large-12 columns">
		<?php if (isset($author_email)) {
			echo '<h4>Filtering on author email: '.$author_email.'</h4>';
		}
		?>
		<?php echo $publication_list['list']; ?>
	</div>
	<div class="large-12 columns">
		<?php echo $publication_list['pagination']; ?>
	</div>
</div>

<script src="js/vendor/jquery.js"></script>
<script src="js/vendor/what-input.js"></script>
<script src="js/vendor/foundation.min.js"></script>
<script src="js/app.js"></script>
</body>

</html>
