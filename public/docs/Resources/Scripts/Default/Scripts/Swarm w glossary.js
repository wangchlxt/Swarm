function GetCurrentTopicLink() {

   var link = parent.frames["topic"].location.href; prompt("Copy the URL for this topic:", link.replace("Content/","default_CSH.htm#"));
}

$(document).ready(function(){
	$("#header>a").after('<div id="header-title"></div>');
	$("#header-title").text(document.title);
});


$(document).ready(function(){
	$("#responsiveHeader>a").after('<div id="responsive-header-title"></div>');
	$("#responsive-header-title").text(document.title);
});


$('<link>')
	.appendTo($('head'))
	.attr({type: 'text/css', rel: 'stylesheet'})
	.attr('href', 'Content/Resources/Stylesheets/skin_swarm.css');

$('<link>')
	.appendTo($('head'))
	.attr({type: 'text/css', rel: 'stylesheet'})
	.attr('href', 'content/resources/stylesheets/skin_swarm.css');

$('head')
	.append(' <!-- Google Tag Manager --><script src="content/resources/google-analytics/google-tag-manager.js"></script><!-- End Google Tag Manager -->');