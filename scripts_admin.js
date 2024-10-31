(function($){

	/**************************************************************************************************************************
	*	Fetch Submissions
	**************************************************************************************************************************/
	var maxid = -1;
	function playmeFetchSubmissions(){

		//don't allow duplicate refreshes
		$("#PlayMe_refresh").attr("disabled","disabled");
		
		//show table as pending
		var table = $("#PlayMe_submissions");
			table.css("opacity",".3").css("backgroundColor","#d8d3d1");
			
		//hide the progress bar
		$("#playme_timer > span").css("visibility", "hidden").css("width", "0px");
			
		//fetch
		var jqxhr = $.post(ajax_object.ajaxurl, { action:"playme_fetchrequests", maxid:maxid }, function(submissions) {
			
			if(submissions.count > 0){
				//remove placeholder
				$("tr.PlayMe_placeholder").remove();
			
				//iterate & render
				for(s in submissions.songs){
					subm = submissions.songs[s];
					if(parseInt(subm.id)>maxid) maxid = parseInt(subm.id);
					var row = "<tr id='request"+subm.id+"'>";
						row+= "<td><a title='Lookup IP Address: "+subm.ip+"' data-ipaddress='"+subm.ip+"'>"+subm.submittedby+"</a></td>";
						row+= "<td>"+subm.artistname+"</td>";
						row+= "<td>"+subm.songname+"</td>";
						row+= "<td>"+subm.datehr+"</td>";
						row+= "<td>"+subm.comments+"</td>";
						row+= "<td><button type='button' data-id='"+subm.id+"' class='deleteRequest'>Delete</button></td>";
					table.find("tbody").prepend(row);
				}
				
				//apply delete/IP look-up listeners
				playmeApplyListeners();
				
			} else {
				
				//nothing to show
				if(submissions.count < 1 && $("#PlayMe_submissions tbody tr").length < 1){
					table.find("tbody").prepend("<tr class='PlayMe_placeholder'><td colspan='6'>No new song requests.</td></tr>");
				}
			}
			//show table no longer pending
			table.css("opacity","1").css("backgroundColor","#fcfcfc");
			
			//show the progress bar again
			setTimeout( function(){ $("#playme_timer > span").css("visibility", "visible"); }, 100 ); 
			
			//start the refresh timer
			$("#PlayMe_refresh").data("seconds", 60).html("Refreshing in "+60).attr("disabled", false);
			playmeRunCountdown();

		}).fail(function(data, status, jqxhr) {
			//on failure
			console.log("error",data,status,jqxhr);
			
			//from PHP
			response = JSON.parse(data.responseText);
			console.log(response.textStatus);
		});
	}
	
	//attach listener to refresh button
	$("#PlayMe_refresh").on("click", function(){ playmeFetchSubmissions(); });

	//do initial fetch
	playmeFetchSubmissions();

	/**************************************************************************************************************************
	*	Countdown
	**************************************************************************************************************************/
	function playmeRunCountdown(){
		//collision detection
		if( $("#PlayMe_refresh").attr("disabled")=="disabled" ) return(false);
		//continue
		var secsLeft = parseInt($("#PlayMe_refresh").data("seconds"));
		if(secsLeft > 1){
			secsLeft-=1;
			$("#PlayMe_refresh").data("seconds", secsLeft).html("Refreshing in "+secsLeft);
			$("#playme_timer > span").css("width", (100-((secsLeft/60)*100))+"%");
			setTimeout(function(){ playmeRunCountdown(); }, 1000);
		} else {
			playmeFetchSubmissions();
		}
	}
	
	/**************************************************************************************************************************
	*	Listeners on Song Requests
	**************************************************************************************************************************/
	function playmeApplyListeners(){
		
		/**************************************************************************************************************************
		*	... Delete Song Requests
		**************************************************************************************************************************/
		$("#PlayMeAdmin button.deleteRequest").off("click").on("click", function(){
			var requestRow = $(this).closest("tr");
			var requestId = $(this).data("id");
			var jqxhr = $.post(ajax_object.ajaxurl, { action:"playme_deleterequest", requestId:requestId }, function(data) {
				//mark it deleted
				requestRow.closest("tr").addClass("deleted");

			}).fail(function(data, status, jqxhr) {
				//from PHP
				response = JSON.parse(data.responseText);
				alert(response.statustext);
			});
		});
		/**************************************************************************************************************************
		*	... Provide IP/Location Look-up for Song Request
		**************************************************************************************************************************/
		$("#PlayMeAdmin a[data-ipaddress]").on("click", function(){
			var ipLocationURL = ajax_object.ip_location_service.replace(/{ip}/g, $(this).data("ipaddress"));
			window.open(ipLocationURL);
		});
	}
	
})( jQuery );