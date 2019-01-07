$(document).foundation();


$(document).ready(function() {
	updateStatus();

	// Sync with Clarity LIMS
	$("#load_clarity").on("click", function() {
		$(this).prop("disabled",true).text("Working...");
		clarityLoad('labs');
	});

	function clarityLoad(type) {
		$.ajax({
			url: '_clarity_load.php',
			data: { task: 'load', type: type },
			dataType: 'json',
			beforeSend: function() {
				$("#clarity_status_message").append('Loading ' + type + '...<br>');
			},
			success: function(json) {
				$("#clarity_status_message").append(json.message + '<br>');
				updateDB(type);
			}
		});
	}

	function updateDB(type) {
		$.ajax({
			url: '_clarity_load.php',
			data: { task: 'update', type: type },
			dataType: 'json',
			beforeSend: function() {
				$("#clarity_status_message").append('Updating database for ' + type + '...<br>');
			},
			success: function(json) {
				$("#clarity_status_message").append(json.message + '<br>');
				if(type == 'labs') {
					clarityLoad('researchers');
				} else {
					$("#clarity_status_message").append('Updating process finished!<br>');
					$("#load_clarity").prop("disabled",false).text("Update");
					updateStatus();
				}
			}
		});
	}

	function updateStatus() {
		$.ajax({
			url: '_clarity_status.php',
			dataType: 'html',
			success: function(html) {
				$("#clarity_status").html(html);
			}
		});
	}

	// Find publications for single lab
	$('.find_pub').on('click', function() {
		var button = this;
		var id = $(this).attr('id').split('-')[1];
 		$.ajax({
			url: '_publication_add.php',
			data: { lab_id: id },
			dataType: 'json',
			beforeSend: function() {
				$(button).text('Processing...');
			},
			success: function(json) {
				if(json.found>0) {
					foundstring = '(found ' + json.found + ' already in database)';
				} else {
					foundstring = '';
				}

				$(button).text('Added ' + json.added + ' publications ' + foundstring);
			}
		});
	});

	// Pubtrawl
	// https://stackoverflow.com/questions/4785724/queue-ajax-requests-using-jquery-queue#4785886
	var ajaxManager = (function() {
	     var requests = [];

	     return {
	        addReq:  function(opt) {
	            requests.push(opt);
	        },
	        removeReq:  function(opt) {
	            if( $.inArray(opt, requests) > -1 )
	                requests.splice($.inArray(opt, requests), 1);
	        },
	        run: function() {
	            var self = this,
	                oriSuc;

	            if( requests.length ) {
	                oriSuc = requests[0].complete;

	                requests[0].complete = function() {
	                     if( typeof(oriSuc) === 'function' ) oriSuc();
	                     requests.shift();
	                     self.run.apply(self, []);
	                };

	                $.ajax(requests[0]);
	            } else {
	              self.tid = setTimeout(function() {
	                 self.run.apply(self, []);
	              }, 1000);
	            }
	        },
	        stop:  function() {
	            requests = [];
	            clearTimeout(this.tid);
	        }
	     };
	}());

	if($('.start_trawl').length) {
		items = "";
		if (sessionStorage.trawl_list) {
			items = JSON.parse(sessionStorage.trawl_list);
		}
		else {
			$.ajax({
				url: "_publication_batch_add.php",
				async: false,
				success: function(json) {
					items = json;
				}
			});
			sessionStorage.setItem("trawl_list", items);
			items = JSON.parse(items);
		}
		$.each(items, function( id, lab ) {
			var text = "Pending";
			var label = "";
			if(lab['session'] == 'done') {
				text = "Done"; label = "success";
			}
			if(lab['session'] == 'error') {
				text = "Error"; label = "alert";
			}
			var out='<span class="secondary label '+label+'" id="status-'+id+'">'+text+'</span> <span id="lab-'+id+'">'+lab['lab_name']+': </span><span id="result-'+id+'"></span><br>';
			$('#trawl_labs').append(out);
		});
	};

	$(function() {
		$('.start_trawl').on('click', function() {
				ajaxManager.run();
				$('.start_trawl').addClass('disabled');
				var total = 0
				$.each(items, function(id,lab) {
					if(lab['session'] != 'done') {
						total += 1;
					}
				});
				var i = 0;
				$.each( items, function( id, lab ) {
						if(lab['session'] == 'done') {return 'done';};
						var trawl = ajaxManager.addReq({
							url: '_publication_add.php',
							data: { lab_id: id },
							dataType: 'json',
							timeout: 600000,
							beforeSend: function() {
								$('#status-' + id).text('Processing...');
							},
							success: function(json) {
								if(json.found>0) {
									foundstring = '(found ' + json.found + ' already in database)';
								} else {
									foundstring = '';
								}

								$('#result-' + id).text('Added ' + json.added + ' publications ' + foundstring);
								$('#status-' + id).addClass('success').text('Done');
								items[id].session = 'done';
							},
							error: function() {
								$('#status-' + id).addClass('alert').text('Error');
								items[id].session = 'error';
							},
							complete: function() {
								sessionStorage.setItem('trawl_list', JSON.stringify(items));
								i += 1;
								var pct = i / total * 100;
								$('#trawltext').html(i+"/"+total);
								$('#trawlbar').css("width", pct+"%");
							}
						});
				});

		});
		$('.pause_trawl').on('click', function() {
			ajaxManager.stop()
		});
	});

	// Syncing with SciLifeLab publication database
	$('.start_sync').on('click', function() {
		$.ajax({
			url: '_sync_db.php',
			beforeSend: function() {
				$("#sync_status_message").append(
					'<div class="callout primary", id="syncing_ongoing">(imagine a rolling circle) Syncing database against publications.scilifelab.se</div>'
				);
			},
			success: function(json_str) {
				json = JSON.parse(json_str);
				$("#syncing_ongoing").remove();
				if (json["mismatch"] != null && json["mismatch"].length > 0){
					$("#sync_status_message").append(
						'<div class="callout alert"> Warning: These publications from publications.scilifelab.se are marked as "maybe" or "discarded": ' + json["mismatch"] + '</br> - Consider to remove these from publications.scilifelab.se </div>'
					);
				};
				if (json["other_unknown_status"] != null && json["other_unknown_status"].length > 0) {
					$("#sync_status_message").append(
						'<div class="callout alert"> Warning: These publications from publications.scilifelab.se have unknown statuses in the local database: ' + json["other_unknown_status"] + '</div>'
					);
				};
				if (json["missing"] != null && json["missing"].length > 0) {
					$("#sync_status_message").append(
						'<div class="callout alert"> Warning: These publications from publications.scilifelab.se are missing in the local database: ' + json["missing"] + '</div>'
					);
				};
				$("#sync_status_message").append(
					'<div class="callout success"><ul>' +
						'<li>' + json['total'] + ' papers fetched in total</li>' +
						'<li>' + json['verified_and_added'] + ' papers were verified and also added.</li>' +
						'<li>' + json['auto'] + ' papers were added but not verified (auto)</li>' +
						'<li>' + json['no_change'] + ' papers were already noted as auto or added in database</li></ul>' +
					'</div>'
				);
			},
			error: function(xhr, error){
        		console.debug(xhr); console.debug(error);
				$("#sync_status_message").append(
					'<div class="callout alert">ERROR: Syncing database failed!</div>'
				);
 			}
		})
	});

	// Verifying publications
	$('.verify_button').on('click', function() {
		var button = this;
		var id = $(this).attr('id').split('-')[1];
		$.ajax({
			url: '_publication_verify.php',
			data: { publication_id: id, type: 'verify'},
			dataType: 'json',
			success: function(json) {
				$('#status_label-' + id).text('Verified').addClass('success');
				$('#publ-' + id).addClass('success');
			}
		});
	});

	$('.discard_button').on('click', function() {
		var button = this;
		var id = $(this).attr('id').split('-')[1];
		$.ajax({
			url: '_publication_verify.php',
			data: { publication_id: id, type: 'discard'},
			dataType: 'json',
			success: function(json) {
				$('#status_label-' + id).text('Discarded').addClass('alert');
				$('#publ-' + id).addClass('alert');
			}
		});
	});

	$('.maybe_button').on('click', function() {
		var button = this;
		var id = $(this).attr('id').split('-')[1];
		$.ajax({
			url: '_publication_verify.php',
			data: { publication_id: id, type: 'maybe'},
			dataType: 'json',
			success: function(json) {
				$('#status_label-' + id).text('Maybe').addClass('warning');
				$('#publ-' + id).addClass('warning');
			}
		});
	});

	$('.comment_button').on('click', function() {
		var button = this;
		var id = $(this).attr('id').split('-')[1];
		var user_comment = $('#comment-' + id).val();
		$.ajax({
			url: '_publication_comment.php',
			data: { publication_id: id, comment: user_comment},
			dataType: 'json',
			success: function(json) {
				$('#comment-' + id).val("");
			}
		});
	});

	function run_test(test,queue) {
		$(document).queue(queue, function() {
			$.ajax({
				url: "_selftest.php?test="+test,
				success: function(out) {
					if(out.includes("OK")) {
						$('#test_results').html($('#test_results').html()+test+": OK<br/>");
					} else {
						$('#test_results').html($('#test_results').html()+test+": FAIL<br/>"+out+"<br/>");
					}
					$(document).dequeue(queue);
				}
			});
		});
	}

	$('#selftest_button').on('click', function() {
		$(this).addClass('disabled');
		$.ajax({
			url: "_selftest.php",
			success: function(out) {
				var test = jQuery.parseJSON(out);
				$('#test_results').addClass('callout secondary')
				$('#test_results').html("");
				for(var i=0; i<test.length; i++){
					run_test(test[i],"sfq")
				}
				$(document).queue("sfq",function(){$('#selftest_button').removeClass('disabled');});
				$(document).dequeue("sfq");
			}
		});
	});
});
