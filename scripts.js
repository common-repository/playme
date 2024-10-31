(function($) {

	/**************************************************************************************************************************
	*	Serialize Object > Form Submissions to Objects
	**************************************************************************************************************************/
	$.fn.serializeObject = function() {
	    var o = {},
	        a = this.serializeArray();
	    $.each(a, function() {
	        if (o[this.name] !== undefined) {
	            if (!o[this.name].push) { o[this.name] = [o[this.name]]; }
	            o[this.name].push(this.value || '');
	        } else { o[this.name] = this.value || ''; }
	    });
	    return o;
	};
	/**************************************************************************************************************************
	*	Serialize Object > Form Submissions to Objects
 	**************************************************************************************************************************/
	var playme_form = $("#PlayMe");
	var playme_stat = $("#PlayMe_status");
	$("#PlayMe_submit").on("click", function(){
		var vals = playme_form.serializeObject();
		for(v in vals){
			vals[v] = cleanup(vals[v]);
			thislab = $("#"+v).closest("label");
			if(thislab.hasClass("required") && vals[v].length<3){
				thislab.addClass("flagged");
				alert(thislab.data("label")+" is required");
				return; //one alert is enough
			} else {
				thislab.removeClass("flagged");
			}
		}

		
		//proceed
		if(playme_form.find(" label.flagged").length<1){
			vals.action = "playme_submitrequest";
			var jqxhr = $.post(ajax_object.ajaxurl, vals, function(data) {
				//on success
				playme_form.find("input[type='text'], textarea").val("");
				playme_stat.removeClass("notice-error").addClass("notice-success").html(JSON.parse(data).statustext);

			}).fail(function(data, status, jqxhr){
				//on failure
				response = JSON.parse(data.responseText);
				statustext = (typeof(response.statustext)!="undefined" ? response.statustext : "Bad Request.");
				playme_stat.removeClass("notice-success").addClass("notice-error").html(statustext);
			});
		}
		
	});

})( jQuery );
/**************************************************************************************************************************
 *	More universal trim function
 **************************************************************************************************************************/
function cleanup(x) { return x.replace(/^\s+|\s+$/gm, ''); }