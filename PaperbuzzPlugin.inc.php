<?php

/**
 * @file plugins/generic/paperbuzz/PaperbuzzPlugin.inc.php
 *
 * Copyright (c) 2013-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class PaperbuzzPlugin
 * @ingroup plugins_generic_paperbuzz
 *
 * @brief Paperbuzz plugin class
 */

use APP\core\Application;
use APP\core\Services;
use APP\statistics\StatisticsHelper;
use APP\template\TemplateManager;
use PKP\cache\CacheManager;
use PKP\cache\FileCache;
use PKP\config\Config;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\HookRegistry;


class PaperbuzzPlugin extends GenericPlugin {

	public const PAPERBUZZ_API_URL = 'https://api.paperbuzz.org/v0/';

	private FileCache $_paperbuzzCache;
	private FileCache $_downloadsCache;
	private \APP\submission\Submission $_article;

	/**
	 * @copydoc Plugin::register()
	 */
	function register($category, $path, $mainContextId = null)
	{
		$success = parent::register($category, $path, $mainContextId);
		if (!Config::getVar('general', 'installed')) return false;

		$request = $this->getRequest();
		$context = $request->getContext();
		if ($success && $this->getEnabled($mainContextId)) {
			$this->_registerTemplateResource();
			if ($context && $this->getSetting($context->getId(), 'apiEmail')) {
				// Add visualization to article view page
				HookRegistry::register('Templates::Article::Main', array($this, 'articleMainCallback'));
				// Add visualization to preprint view page
				HookRegistry::register('Templates::Preprint::Main', array(&$this, 'preprintMainCallback'));
				// Add JavaScript and CSS needed, when the article template is displyed
				HookRegistry::register('TemplateManager::display', array(&$this, 'templateManagerDisplayCallback'));
			}
		}
		return $success;
	}

	/**
	 * @copydoc Plugin::getName()
	 */
	function getName()
	{
		return 'PaperbuzzPlugin';
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	function getDisplayName()
	{
		return __('plugins.generic.paperbuzz.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	function getDescription()
	{
		return __('plugins.generic.paperbuzz.description');
	}

	/**
	 * @copydoc Plugin::getActions()
	 */
	public function getActions($request, $actionArgs)
	{
		$actions = parent::getActions($request, $actionArgs);
		// Settings are only context-specific
		if (!$this->getEnabled()) {
			return $actions;
		}
		$router = $request->getRouter();
		import('lib.pkp.classes.linkAction.request.AjaxModal');
		$linkAction = new LinkAction(
			'settings',
			new AjaxModal(
				$router->url(
					$request,
					null,
					null,
					'manage',
					null,
					array(
						'verb' => 'settings',
						'plugin' => $this->getName(),
						'category' => 'generic'
					)
				),
				$this->getDisplayName()
			),
			__('manager.plugins.settings'),
			null
		);
		array_unshift($actions, $linkAction);
		return $actions;
	}

	/**
	 * @copydoc Plugin::manage()
	 */
	public function manage($args, $request)
	{
		switch ($request->getUserVar('verb')) {
			case 'settings':
				$this->import('PaperbuzzSettingsForm');
				$form = new PaperbuzzSettingsForm($this);
				if ($request->getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute($request);
						return new JSONMessage(true);
					}
				}
				$form->initData($request);
				return new JSONMessage(true, $form->fetch($request));
		}
		return parent::manage($args, $request);
	}

	/**
	 * Template manager hook callback.
	 * Add JavaScript and CSS required for the visualization.
	 *
	 * @param string $hookName
	 * @param array $params
	 */
	function templateManagerDisplayCallback(string $hookName, array $params)
	{
		$templateMgr =& $params[0];
		$template =& $params[1];
		$application = Application::get();
		$applicationName = $application->getName();
		($applicationName == 'ops' ? $publication = 'preprint' : $publication = 'article');
		if ($template == 'frontend/pages/' . $publication . '.tpl') {
			$request = $this->getRequest();
			$baseImportPath = $request->getBaseUrl() . '/' . $this->getPluginPath() . '/' . 'paperbuzzviz' . '/';
			$templateMgr = TemplateManager::getManager($request);
			$templateMgr->addJavaScript('d3', 'https://d3js.org/d3.v4.min.js', array('context' => 'frontend-'.$publication.'-view'));
			$templateMgr->addJavaScript('d3-tip', 'https://cdnjs.cloudflare.com/ajax/libs/d3-tip/0.9.1/d3-tip.min.js', array('context' => 'frontend-'.$publication.'-view'));
			$templateMgr->addJavaScript('paperbuzzvizJS', $baseImportPath . 'paperbuzzviz.js', array('context' => 'frontend-'.$publication.'-view'));
			$templateMgr->addStyleSheet('paperbuzzvizCSS', $baseImportPath . 'assets/css/paperbuzzviz.css', array('context' => 'frontend-'.$publication.'-view'));
		}
	}

	/**
	 * Adds the visualization of the preprint level metrics.
	 *
	 * @param string $hookName
	 * @param array $params
	 * @return bool
	 */
	function preprintMainCallback(string $hookName, array $params): bool
	{
		$smarty = &$params[1];
		$output = &$params[2];

		$preprint = $smarty->getTemplateVars('preprint');
		$this->_article = $preprint;

		$publishedPublications = (array) $preprint->getPublishedPublications();
		$firstPublication = reset($publishedPublications);

		$request = $this->getRequest();
		$context = $request->getContext();

		$paperbuzzJsonDecoded = $this->_getPaperbuzzJsonDecoded();
		$downloadJsonDecoded = array();
		if (!$this->getSetting($context->getId(), 'hideDownloads')) {
			$downloadJsonDecoded = $this->_getDownloadsJsonDecoded();
		}

		if (!empty($downloadJsonDecoded) || !empty($paperbuzzJsonDecoded)) {
			$allStatsJson = $this->_buildRequiredJson($paperbuzzJsonDecoded, $downloadJsonDecoded);
			$smarty->assign('allStatsJson', $allStatsJson);

			if (!empty($firstPublication->getData('datePublished'))) {
				$datePublishedShort = date('[Y, n, j]', strtotime($firstPublication->getData('datePublished')));
				$smarty->assign('datePublished', $datePublishedShort);
			}

			$showMini = $this->getSetting($context->getId(), 'showMini') ? 'true' : 'false';
			$smarty->assign('showMini', $showMini);
			$metricsHTML = $smarty->fetch($this->getTemplateResource('output.tpl'));
			$output .= $metricsHTML;
		}

		return false;
	}

	/**
	 * Adds the visualization of the article level metrics.
	 *
	 * @param string $hookName
	 * @param array $params
	 * @return bool
	 */
	function articleMainCallback(string $hookName, array $params): bool
	{
		$smarty =& $params[1];
		$output =& $params[2];

		$article = $smarty->getTemplateVars('article');
		$this->_article = $article;

		$publishedPublications = (array) $article->getPublishedPublications();
		$firstPublication = reset($publishedPublications);

		$request = $this->getRequest();
		$context = $request->getContext();

		$paperbuzzJsonDecoded = $this->_getPaperbuzzJsonDecoded();
		$downloadJsonDecoded = array();
		if (!$this->getSetting($context->getId(), 'hideDownloads')) {
			$downloadJsonDecoded = $this->_getDownloadsJsonDecoded();
		}

		if (!empty($downloadJsonDecoded) || !empty($paperbuzzJsonDecoded)) {
			$allStatsJson = $this->_buildRequiredJson($paperbuzzJsonDecoded, $downloadJsonDecoded);
			$smarty->assign('allStatsJson', $allStatsJson);

			if (!empty($firstPublication->getData('datePublished'))) {
				$datePublishedShort = date('[Y, n, j]', strtotime($firstPublication->getData('datePublished')));
				$smarty->assign('datePublished', $datePublishedShort);
			}

			$showMini = $this->getSetting($context->getId(), 'showMini') ? 'true' : 'false';
			$smarty->assign('showMini', $showMini);
			$metricsHTML = $smarty->fetch($this->getTemplateResource('output.tpl'));
			$output .= $metricsHTML;
		}

		return false;
	}

	//
	// Private helper methods.
	//
	/**
	 * Get Paperbuzz events for the article.
	 *
	 * @return array JSON decoded paperbuzz result or an empty array
	 */
	function _getPaperbuzzJsonDecoded(): ?array
	{
		if (!isset($this->_paperbuzzCache)) {
			$cacheManager = CacheManager::getManager();
			$this->_paperbuzzCache = $cacheManager->getCache('paperbuzz', $this->_article->getId(), array(&$this, '_paperbuzzCacheMiss'));
		}
		if (time() - $this->_paperbuzzCache->getCacheTime() > 60 * 60 * 24) {
			// Cache is older than one day, erase it.
			$this->_paperbuzzCache->flush();
		}
		$cacheContent = $this->_paperbuzzCache->getContents();
		return $cacheContent;
	}

	/**
	* Cache miss callback.
	*
	* @param FileCache $cache
	* @return array JSON decoded paperbuzz result or an empty array
	*/
	function _paperbuzzCacheMiss(FileCache $cache): ?array
	{
		$request = $this->getRequest();
		$context = $request->getContext();
		$apiEmail = $this->getSetting($context->getId(), 'apiEmail');

		$url = self::PAPERBUZZ_API_URL . 'doi/' . $this->_article->getStoredPubId('doi') . '?email=' . urlencode($apiEmail);
		// For teting use one of the following two lines instead of the line above and do not forget to clear the cache
		// $url = self::PAPERBUZZ_API_URL . 'doi/10.1787/180d80ad-en?email=' . urlencode($apiEmail);
		//$url = self::PAPERBUZZ_API_URL . 'doi/10.1371/journal.pmed.0020124?email=' . urlencode($apiEmail);

		$paperbuzzStatsJsonDecoded = array();
		$httpClient = Application::get()->getHttpClient();
		try {
			$response = $httpClient->request('GET', $url);
		} catch (GuzzleHttp\Exception\RequestException $e) {
			return $paperbuzzStatsJsonDecoded;
		}
		$resultJson = $response->getBody()->getContents();
		if ($resultJson) {
			$paperbuzzStatsJsonDecoded = @json_decode($resultJson, true);
		}
		$cache->setEntireCache($paperbuzzStatsJsonDecoded);
		return $paperbuzzStatsJsonDecoded;
	}

	/**
	 * Get OJS download stats for the article.
	 *
	 * @return array
	 */
	function _getDownloadsJsonDecoded(): ?array
	{
		if (!isset($this->_downloadsCache)) {
			$cacheManager = CacheManager::getManager();
			$this->_downloadsCache = $cacheManager->getCache('paperbuzz-downloads', $this->_article->getId(), array(&$this, '_downloadsCacheMiss'));
		}
		if (time() - $this->_downloadsCache->getCacheTime() > 60 * 60 * 24) {
			// Cache is older than one day, erase it.
			$this->_downloadsCache->flush();
		}
		$cacheContent = $this->_downloadsCache->getContents();
		return $cacheContent;
	}

	/**
	 * Callback to fill cache with data, if empty.
	 *
	 * @param FileCache $cache
	 * @return array
	 */
	function _downloadsCacheMiss(FileCache $cache): array
	{
		$downloadStatsByMonth = $this->_getDownloadStats();
		$downloadStatsByDay = $this->_getDownloadStats(true);

		// We use a helper method to aggregate stats instead of retrieving the needed
		// aggregation directly from metrics_submission table because we need a custom array format.
		list($totalHtml, $totalPdf, $totalOther, $byDay, $byMonth, $byYear) = $this->_aggregateDownloadStats($downloadStatsByMonth, $downloadStatsByDay);
		$downloadsArray = $this->_buildDownloadStatsJsonDecoded($totalHtml, $totalPdf, $totalOther, $byDay, $byMonth, $byYear);

		$cache->setEntireCache($downloadsArray);
		return $downloadsArray;
	}

	/**
	 * Get download stats for the passed article id.
	 *
	 * @param bool $byDay
	 * @return array Metrics result array.
	 */
	function _getDownloadStats(bool $byDay = false): array
	{
		$context = $this->getRequest()->getContext();

		// Only consider the journal's default metric type, mostly ojs::counter
		$dateColumn = $byDay ? StatisticsHelper::STATISTICS_DIMENSION_DAY : StatisticsHelper::STATISTICS_DIMENSION_MONTH;

		$columns = array(StatisticsHelper::STATISTICS_DIMENSION_FILE_TYPE, $dateColumn);
		$filters = [
			StatisticsHelper::STATISTICS_DIMENSION_CONTEXT_ID => [$context->getId()],
			StatisticsHelper::STATISTICS_DIMENSION_SUBMISSION_ID => [$this->_article->getId()],
			StatisticsHelper::STATISTICS_DIMENSION_ASSOC_TYPE => [Application::ASSOC_TYPE_SUBMISSION_FILE],
		];
		$orderBy = array($dateColumn => StatisticsHelper::STATISTICS_ORDER_ASC);

		if ($byDay) {
			// Consider only the first 30 days after the article publication
			$datePublished = $this->_article->getDatePublished();
			if (empty($datePublished)) {
				$issueDao = DAORegistry::getDAO('IssueDAO'); /* @var $issueDao IssueDAO */
				$issue = $issueDao->getById($this->_article->getIssueId());
				$datePublished = $issue->getDatePublished();
			}
			$startDate = date('Ymd', strtotime($datePublished));
			$endDate = date('Ymd', strtotime('+30 days', strtotime($datePublished)));
			// This would be for the last 30 days:
			//$startDate = date('Ymd', strtotime('-30 days'));
			//$endDate = date('Ymd');
			$filter[StatisticsHelper::STATISTICS_DIMENSION_DAY]['from'] = $startDate;
			$filter[StatisticsHelper::STATISTICS_DIMENSION_DAY]['to'] = $endDate;
		}
		$statsService = Services::get('publicationStats');
		$args = $statsService->prepareStatsArgs($filters);
		$metrics = $statsService->getMetrics($columns, $orderBy, $args)->toArray();
		return $metrics;
	}

	/**
	 * Aggregate stats and return data in a format
	 * that can be used to build the statistics JSON response
	 * for the article page.
	 *
	 * @param array|null $statsByMonth A _getDownloadStats return value.
	 * @param array|null $statsByDay A _getDownloadStats return value.
	 * @return array
	 */
	function _aggregateDownloadStats(?array $statsByMonth, ?array $statsByDay): array
	{
		$totalHtml = 0;
		$totalPdf = 0;
		$totalOther = 0;
		$byMonth = array();
		$byYear = array();

		if ($statsByMonth) foreach ($statsByMonth as $record) {
			$record = json_decode(json_encode($record), true);
			$views = $record[StatisticsHelper::STATISTICS_METRIC];
			$fileType = $record[StatisticsHelper::STATISTICS_DIMENSION_FILE_TYPE];
			switch($fileType) {
				case StatisticsHelper::STATISTICS_FILE_TYPE_HTML:
					$totalHtml += $views;
					break;
				case StatisticsHelper::STATISTICS_FILE_TYPE_PDF:
					$totalPdf += $views;
					break;
				case StatisticsHelper::STATISTICS_FILE_TYPE_OTHER:
					$totalOther += $views;
					break;
				default:
					// switch is considered a loop for purposes of continue
					continue 2;
			}
			$year = date('Y', strtotime($record[StatisticsHelper::STATISTICS_DIMENSION_MONTH]));
			$month = date('n', strtotime($record[StatisticsHelper::STATISTICS_DIMENSION_MONTH]));
			$yearMonth = date('Y-m', strtotime($record[StatisticsHelper::STATISTICS_DIMENSION_MONTH]));

			if (!isset($byYear[$year])) $byYear[$year] = array();
			if (!isset($byYear[$year][$fileType])) $byYear[$year][$fileType] = 0;
			$byYear[$year][$fileType] += $views;

			if (!isset($byMonth[$yearMonth])) $byMonth[$yearMonth] = [];
			if (!isset($byMonth[$yearMonth][$fileType])) $byMonth[$yearMonth][$fileType] = 0;
			$byMonth[$yearMonth][$fileType] += $views;
		}

		// Get daily download statistics
		$byDay = array();
		if ($statsByDay) foreach ($statsByDay as $recordByDay) {
			$recordByDay = json_decode(json_encode($recordByDay), true);
			$views = $recordByDay[StatisticsHelper::STATISTICS_METRIC];
			$fileType = $recordByDay[StatisticsHelper::STATISTICS_DIMENSION_FILE_TYPE];
			$yearMonthDay = $recordByDay[StatisticsHelper::STATISTICS_DIMENSION_DAY];
			if (!isset($byDay[$yearMonthDay])) $byDay[$yearMonthDay] = [];
			if (!isset($byDay[$yearMonthDay][$fileType])) $byDay[$yearMonthDay][$fileType] = 0;
			$byDay[$yearMonthDay][$fileType] += $views;
		}

		return array($totalHtml, $totalPdf, $totalOther, $byDay, $byMonth, $byYear);
	}

	/**
	 * Get statistics by time dimension (month or year) for JSON response.
	 *
	 * @param array $data Download statistics in an array (date => file type)
	 * @param string $dimension day | month | year
	 * @param int $fileType STATISTICS_FILE_TYPE_PDF | STATISTICS_FILE_TYPE_HTML | STATISTICS_FILE_TYPE_OTHER
	 * @return array|null
	 */
	function _getDownloadStatsByTime(array $data, string $dimension, int $fileType): ?array
	{
		switch ($dimension) {
			case 'day':
				$isDayDimension = true;
				break;
			case 'month':
				$isMonthDimension = true;
				break;
			case 'year':
				$isYearDimension = false;
				break;
			default:
				return null;
		}

		if (count($data)) {
			$byTime = array();
			foreach ($data as $date => $fileTypes) {
				if ($isDayDimension) {
					$dateIndex = date('Y-m-d', strtotime($date));
				} elseif ($isMonthDimension) {
					$dateIndex = date('Y-m', strtotime($date));
				} elseif ($isYearDimension) {
					$dateIndex = date('Y', strtotime($date));
				}
				if (isset($fileTypes[$fileType])) {
					$event = array();
					$event['count'] = $fileTypes[$fileType];
					$event['date'] = $dateIndex;

					$byTime[] = $event;
				}
			}
		} else {
			$byTime = null;
		}
		return $byTime;
	}

	/**
	 * Build article statistics JSON response based
	 * on parameters returned from _aggregateStats().
	 *
	 * @param integer $totalHtml
	 * @param integer $totalPdf
	 * @param integer $totalOther
	 * @param array $byDay
	 * @param array $byMonth
	 * @param array $byYear
	 * @return array Ready for JSON encode
	 */
	function _buildDownloadStatsJsonDecoded(int $totalHtml, int $totalPdf, int $totalOther, array $byDay, array $byMonth, array $byYear): array
	{
		$response = [];
		$eventPdf = [];
		if ($totalPdf > 0) {
			$eventPdf['events'] = null;
			$eventPdf['events_count'] = $totalPdf;
			$eventPdf['events_count_by_day'] = $this->_getDownloadStatsByTime($byDay, 'day', StatisticsHelper::STATISTICS_FILE_TYPE_PDF);
			$eventPdf['events_count_by_month'] = $this->_getDownloadStatsByTime($byMonth, 'month', StatisticsHelper::STATISTICS_FILE_TYPE_PDF);
			$eventPdf['events_count_by_year'] = $this->_getDownloadStatsByTime($byYear, 'year', StatisticsHelper::STATISTICS_FILE_TYPE_PDF);
			$eventPdf['source']['display_name'] = __('plugins.generic.paperbuzz.sourceName.pdf');
			$eventPdf['source_id'] = 'pdf';
			$response[] = $eventPdf;
		}

		$eventHtml = array();
		if ($totalHtml > 0) {
			$eventHtml['events'] = null;
			$eventHtml['events_count'] = $totalHtml;
			$eventHtml['events_count_by_day'] = $this->_getDownloadStatsByTime($byDay, 'day', StatisticsHelper::STATISTICS_FILE_TYPE_HTML);
			$eventHtml['events_count_by_month'] = $this->_getDownloadStatsByTime($byMonth, 'month', StatisticsHelper::STATISTICS_FILE_TYPE_HTML);
			$eventHtml['events_count_by_year'] = $this->_getDownloadStatsByTime($byYear, 'year', StatisticsHelper::STATISTICS_FILE_TYPE_HTML);
			$eventHtml['source']['display_name'] = __('plugins.generic.paperbuzz.sourceName.html');
			$eventHtml['source_id'] = 'html';
			$response[] = $eventHtml;
		}

		$eventOther = array();
		if ($totalOther > 0) {
			$eventOther['events'] = null;
			$eventOther['events_count'] = $totalOther;
			$eventOther['events_count_by_day'] = $this->_getDownloadStatsByTime($byDay, 'day', StatisticsHelper::STATISTICS_FILE_TYPE_OTHER);
			$eventOther['events_count_by_month'] = $this->_getDownloadStatsByTime($byMonth, 'month', StatisticsHelper::STATISTICS_FILE_TYPE_OTHER);
			$eventOther['events_count_by_year'] = $this->_getDownloadStatsByTime($byYear, 'year', StatisticsHelper::STATISTICS_FILE_TYPE_OTHER);
			$eventOther['source']['display_name'] = __('plugins.generic.paperbuzz.sourceName.other');
			$eventOther['source_id'] = 'other';
			$response[] = $eventOther;
		}

		return $response;
	}

	/**
	 * Build the required article information for the
	 * metrics visualization.
	 *
	 * @param array $eventsData (optional) Decoded JSON result from Paperbuzz
	 * @param array $downloadData (optional) Download stats data ready for JSON encoding
	 * @return string JSON response
	 */
	function _buildRequiredJson(array $eventsData = [], array $downloadData = []): string
	{
		if (empty($eventsData['altmetrics_sources'])) $eventsData['altmetrics_sources'] = [];
		$allData = array_merge($downloadData, $eventsData['altmetrics_sources']);
		$eventsData['altmetrics_sources'] = $allData;
		return json_encode($eventsData);
	}
}
/*
if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\PaperbuzzPlugin', '\PaperbuzzPlugin');
    define('PAPERBUZZ_API_URL', \PaperbuzzPlugin::PAPERBUZZ_API_URL);
}
*/