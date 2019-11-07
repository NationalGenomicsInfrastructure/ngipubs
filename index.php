<?php
require 'lib/global.php';

if($USER->auth>0) {
	$publications=new NGIpublications();
	$year=$CONFIG['publications']['current_year'];

	$user_score=$publications->getScoreboard($year,$USER->data['user_email']);
	$user_score_table=new htmlTable();
	$user_score_table->addData($user_score);

	$user_score_total=$publications->getScoreboard(FALSE,$USER->data['user_email']);
	$user_score_total_table=new htmlTable();
	$user_score_total_table->addData($user_score_total);

	$score_total=$publications->getScoreboard($year,FALSE);
	$score_total_table=new htmlTable();
	$score_total_table->addData($score_total);

	$remaining_publications=$publications->remainingPublications($year, 8);
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
		<br>
		<div class="large-12 columns">
		<ul class="stats-list">
			<li>
				<?php echo $remaining_publications["total"]?> <span class="stats-list-label">Publications</span>
			</li>
			<li class="stats-list-maybe">
				<?php echo $remaining_publications["maybe"]?> <span class="stats-list-label">Maybe</span>
			</li>
			<li class="stats-list-pending">
				<?php echo $remaining_publications["pending"]?> <span class="stats-list-label">Pending</span>
			</li>
		</ul>
		</div>
	</div>
	<div class="row">
		<div class="large-6 columns">
			<div class="card">
				<div class="card-divider">
					Your score for <?php echo $year; ?>
				</div>
				<div class="card-section">
					<?php echo $user_score_table->render(); ?>
					<a href="publications_verify.php" class="button">Go get some!</a>
				</div>
			</div>
		</div>
		<div class="large-6 columns">
			<div class="card">
				<div class="card-divider">
					Your total score
				</div>
				<div class="card-section">
					<?php echo $user_score_total_table->render(); ?>
				</div>
			</div>
		</div>
	</div>

	<div class="row">
		<br>
		<div class="large-12 columns">
			<div class="card">
				<div class="card-divider">
					Global scoreboard for <?php echo $year; ?>
				</div>
				<div class="card-section">
					<?php echo $score_total_table->render(); ?>
				</div>
			</div>
		</div>
	</div>

	<script src="js/vendor/jquery.js"></script>
	<script src="js/vendor/what-input.js"></script>
	<script src="js/vendor/foundation.js"></script>
	<script src="js/app.js"></script>
</body>

</html>
