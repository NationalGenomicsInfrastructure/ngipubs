<?php
require 'lib/global.php';

if($USER->auth>0) {
	$researchers=new NGIresearchers();

	$affiliation_select[0]="All";
	$affiliations=sql_query("SELECT id,name FROM affiliations ORDER BY name");
	while($affiliation=$affiliations->fetch_assoc()) {
		$affiliation_select[$affiliation['id']]=$affiliation['name'];
	}

	$filterform=new htmlForm("researchers.php","get",4);
	$filterform->addSelect("Order by","order_by",array('last_name' => 'Last name', 'first_name' => 'First name'),$_GET);
	$filterform->addSelect("Sort","sort",array('asc' => 'Ascending', 'desc' => 'Descending'),$_GET);
	$filterform->addInput("Search by Last Name",array('type' => 'search', 'name' => 'last_name'), $_GET);
	$filterform->addInput("<br/>",array('type' => 'submit', 'name' => 'submit', 'value' => 'Filter search', 'class' => 'button'));

	$query_string="SELECT * FROM researchers";

	switch($_GET['order_by']) {
		default:
		case 'last_name':
			$order_string=" ORDER BY last_name";
		break;

		case 'first_name':
			$order_string=" ORDER BY first_name";
		break;
	}

	switch($_GET['sort']) {
		default:
		case 'asc':
			$order_string.=" ASC";
		break;

		case 'desc':
			$order_string.=" DESC";
		break;
	}
	if ($_GET['last_name']) {
		$last_name = trim($DB->real_escape_string( $_GET['last_name'] ));
		$filters[]="last_name='".$last_name."'";
	}

	if(count($filters)>0) {
		$query_string.=' WHERE '.implode(' AND ',$filters);
	}

	$query=sql_query($query_string.$order_string);
	/* $researcher_list=$researchers->listResearchers($query,$_GET['page']); */
	$researcher_formatted=$researchers->showResearcherList($query, $_GET['page']);
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
	<link rel="stylesheet" href="css/foundation.css">
	<link rel="stylesheet" href="css/app.css">
	<link rel="stylesheet" href="css/icons/foundation-icons.css" />
</head>

<body>
<?php require '_menu.php'; ?>

<div class="row">
	<div class="large-12 columns">
		<?php echo $filterform->render(); ?>
	</div>
	<div class="large-12 columns">
		<?php echo $researcher_formatted['list']; ?>
	</div>
	<div class="large-12 columns">
		<?php echo $researcher_formatted['pagination']; ?>
	</div>
</div>

<script src="js/vendor/jquery.js"></script>
<script src="js/vendor/what-input.js"></script>
<script src="js/vendor/foundation.js"></script>
<script src="js/app.js"></script>
</body>

</html>
