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
<h1>Paperbuzz Visualizations</h1>
<div id="paperbuzz"><div id="loading">Metrics Loading...</div></div>
<script type="text/javascript" src="{$paperbuzzvizPath}"></script>	
<script type="text/javascript">
	var options = {ldelim}
		paperbuzzStatsJson: $.parseJSON('{$almStatsJson|escape:"javascript"}'),
		minItemsToShowGraph: {ldelim}
			minEventsForYearly: 1,
			minEventsForMonthly: 1,
			minEventsForDaily: 1,
			minYearsForYearly: 1,
			minMonthsForMonthly: 1,
			minDaysForDaily: 1 //first 30 days only
		{rdelim},
		graphheight: 150,
		graphwidth:  300,
		showTitle: true,
		showMini: true,
	{rdelim}

	var paperbuzzviz = undefined;
	var doi = '10.1371/journal.pmed.0020124';		
	// var doi = '10.1007/s00266-017-0820-4';
	// var doi = '10.7287/peerj.preprints.3119v1';
		
	paperbuzzviz = new PaperbuzzViz(options);
	paperbuzzviz.initViz();
</script>
<div id="built-with"><p>Built with <a href="http://d3js.org/">d3.js</a></p></div>