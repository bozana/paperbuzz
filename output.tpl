{**
 * plugins/generic/alm/output.tpl
 *
 * Copyright (c) 2013-2017 Simon Fraser University Library
 * Copyright (c) 2003-2017 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * ALM plugin settings
 *
 *}

<div class="separator"></div>
<a name="alm"></a>
<h4>{translate key="plugins.generic.alm.title"}</h4>
<div id="alm" class="alm"><div id="loading">{translate key="plugins.generic.alm.loading"}</div></div>
<br />

<script type="text/javascript">
	options = {ldelim}
		almStatsJson: $.parseJSON('{$almStatsJson|escape:"javascript"}'),
		minItemsToShowGraph: {ldelim}
			minEventsForYearly: 0,
			minEventsForMonthly: 0,
			minEventsForDaily: 0,
			minYearsForYearly: 0,
			minMonthsForMonthly: 0,
			minDaysForDaily: 0
		{rdelim},
		categories: [{ldelim} name: "html", display_name: '{translate key="plugins.generic.alm.categories.html"}', tooltip_text: '{translate key="plugins.generic.alm.categories.html.description"|escape:"jsparam"}' {rdelim},
			{ldelim} name: "pdf", display_name: '{translate key="plugins.generic.alm.categories.pdf"}', tooltip_text: '{translate key="plugins.generic.alm.categories.pdf.description"|escape:"jsparam"}' {rdelim},
			{ldelim} name: "events", display_name: '{translate key="plugins.generic.alm.categories.events"}', tooltip_text: '{translate key="plugins.generic.alm.categories.events.description"|escape:"jsparam"}' {rdelim}],
		vizDiv: "#alm"
	{rdelim}

	// Import JQuery 1.10 version, needed for the tooltip plugin
	// that we use below. jQuery.noConflict puts the old $ back.
	$.getScript('{$jqueryImportPath}', function() {ldelim}
		$.getScript('{$tooltipImportPath}', function() {ldelim}
			// Assign the last inserted JQuery version to a new variable, to avoid
			// conflicts with the current version in $ variable.
			options.jQuery = $;
			var almviz = new AlmViz(options);
			almviz.initViz();
			jQuery.noConflict(true);
		{rdelim});
	{rdelim});

</script>