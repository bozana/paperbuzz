<?php

/**
 * @file plugins/generic/alm/AlmPlugin.inc.php
 *
 * Copyright (c) 2013-2017 Simon Fraser University Library
 * Copyright (c) 2003-2017 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class AlmPlugin
 * @ingroup plugins_generic_alm
 *
 * @brief Alm plugin class
 */

import('lib.pkp.classes.plugins.GenericPlugin');
import('lib.pkp.classes.webservice.WebService');
import('lib.pkp.classes.core.JSONManager');

DEFINE('PAPERBUZZ_API_URL', 'https://api.paperbuzz.org/v0/');

class AlmPlugin extends GenericPlugin {

	/** @var $apiKey string */
	var $_apiEmail;


	/**
	 * @see LazyLoadPlugin::register()
	 */
	function register($category, $path) {
		$success = parent::register($category, $path);
		if (!Config::getVar('general', 'installed')) return false;

		$application =& Application::getApplication();
		$request =& $application->getRequest();
		$router =& $request->getRouter();
		$context = $router->getContext($request);

		if ($success && $context) {
			$this->_apiEmail = $this->getSetting($context->getId(), 'apiEmail');
			HookRegistry::register('TemplateManager::display',array(&$this, 'templateManagerCallback'));
			HookRegistry::register('Templates::Article::MoreInfo',array(&$this, 'articleMoreInfoCallback'));
		}
		return $success;
	}

	/**
	 * @see LazyLoadPlugin::getName()
	 */
	function getName() {
		return 'almplugin';
	}

	/**
	 * @see PKPPlugin::getDisplayName()
	 */
	function getDisplayName() {
		return __('plugins.generic.alm.displayName');
	}

	/**
	 * @see PKPPlugin::getDescription()
	 */
	function getDescription() {
		return __('plugins.generic.alm.description');
	}

	/**
	* @see GenericPlugin::getManagementVerbs()
	*/
	function getManagementVerbs() {
		$verbs = array();
		if ($this->getEnabled()) {
			$verbs[] = array('settings', __('plugins.generic.alm.settings'));
		}
		return parent::getManagementVerbs($verbs);
	}

	/**
	 * @see GenericPlugin::manage()
	 */
	function manage($verb, $args, &$message, &$messageParams) {
		if (!parent::manage($verb, $args, $message, $messageParams)) return false;
		switch ($verb) {
			case 'settings':
				$journal =& Request::getJournal();

				$templateMgr =& TemplateManager::getManager();
				$templateMgr->register_function('plugin_url', array(&$this, 'smartyPluginUrl'));

				$this->import('SettingsForm');
				$form = new SettingsForm($this, $journal->getId());

				if (Request::getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
						$message = NOTIFICATION_TYPE_SUCCESS;
						$messageParams = array('contents' => __('plugins.generic.alm.settings.saved'));
						return false;
					} else {
						$form->display();
					}
				} else {
					$form->initData();
					$form->display();
				}
				return true;
			default:
				// Unknown management verb
				assert(false);
			return false;
		}
	}

	/**
	 * Template manager hook callback.
	 * @param $hookName string
	 * @param $params array
	 */
	function templateManagerCallback($hookName, $params) {
		if ($this->getEnabled()) {
			$templateMgr =& $params[0];
			$template = $params[1];
			if ($template == 'article/article.tpl') {
				$additionalHeadData = $templateMgr->get_template_vars('additionalHeadData');
				$baseImportPath = Request::getBaseUrl() . DIRECTORY_SEPARATOR . $this->getPluginPath() . DIRECTORY_SEPARATOR;
				$scriptImportString = '<script language="javascript" type="text/javascript" src="';

				$jQueryImport = $scriptImportString . 'https://code.jquery.com/jquery-1.11.3.min.js" integrity="sha256-7LkWEzqTdpEfELxcZZlS6wAx5Ff13zZ83lYO2/ujj7g=" crossorigin="anonymous"></script>';

				$d3import = $scriptImportString . 'https://d3js.org/d3.v4.min.js"></script>';
				$d3tipImport = $scriptImportString . $baseImportPath .
					'd3-tip/index.js"></script>';

				$templateMgr->assign('additionalHeadData', $additionalHeadData . "\n" . $jQueryImport . "\n" . $d3import . "\n" . $d3tipImport);

				$templateMgr->addStyleSheet($baseImportPath . 'css/paperbuzzviz.css');
				//$templateMgr->addStyleSheet('https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css');
			}
		}
	}

	/**
	 * Template manager filter callback. Adds the article
	 * level metrics markup, if any stats.
	 * @param $hookName string
	 * @param $params array
	 * @return boolean
	 */
	function articleMoreInfoCallback($hookName, $params) {
		$smarty =& $params[1];
		$output =& $params[2];

		$article =& $smarty->get_template_vars('article');
		assert(is_a($article, 'PublishedArticle'));
		$articleId = $article->getId();

		$downloadStatsByMonth = $this->_getDownloadStats($request, $articleId);
		$downloadStatsByDay = $this->_getDownloadStats($request, $articleId, true);

		// We use a helper method to aggregate stats instead of retrieving the needed
		// aggregation directly from metrics DAO because we need a custom array format.
		list($totalHtml, $totalPdf, $totalOther, $byDay, $byMonth, $byYear) = $this->_aggregateDownloadStats($downloadStatsByMonth, $downloadStatsByDay);
		$downloadJsonDecoded = $this->_buildDownloadStatsJsonDecoded($totalHtml, $totalPdf, $totalOther, $byDay, $byMonth, $byYear);

		$almStatsJson = $this->_getAlmStats($article);
		$almStatsJsonDecoded = @json_decode($almStatsJson);
		/* TO-DO: error handling in Paperbuzz */

		if ($downloadJsonDecoded || $almStatsJsonDecoded) {
			$almStatsJsonPrepared = $this->_buildRequiredJson($almStatsJsonDecoded, $downloadJsonDecoded);

			$smarty->assign('almStatsJson', $almStatsJsonPrepared);

			$baseImportPath = Request::getBaseUrl() . DIRECTORY_SEPARATOR . $this->getPluginPath() . DIRECTORY_SEPARATOR;
			$paperbuzzvizPath = $baseImportPath . 'paperbuzzviz.js';
			$smarty->assign('paperbuzzvizPath', $paperbuzzvizPath);

			$metricsHTML = $smarty->fetch($this->getTemplatePath() . 'output.tpl');
			$output .= $metricsHTML;
		}

		return false;
	}

	/**
	 * @see PKPPlugin::getInstallSitePluginSettingsFile()
	 */
	function getInstallSitePluginSettingsFile() {
		return $this->getPluginPath() . '/settings.xml';
	}

	//
	// Private helper methods.
	//
	/**
	* Call web service with the given parameters
	* @param $url string
	* @param $params array GET or POST parameters
	* @param $method string (optional)
	* @return JSON or null in case of error
	*/
	function &_callWebService($url, &$params, $method = 'GET') {
		// Create a request
		if (!is_array($params)) {
			$params = array();
		}

		$webServiceRequest = new WebServiceRequest($url, $params, $method);
		// Can't strip slashes from the result, we have a JSON
		// response with escaped characters.
		$webServiceRequest->setCleanResult(false);

		// Configure and call the web service
		$webService = new WebService();
		$result =& $webService->call($webServiceRequest);

		return $result;
	}

	/**
	* Cache miss callback.
	* @param $cache Cache
	* @return JSON
	*/
	function _cacheMiss(&$cache) {
		$articleId = $cache->getCacheId();
		$articleDao =& DAORegistry::getDAO('ArticleDAO'); /* @var $articleDao ArticleDAO */
		$article =& $articleDao->getArticle($articleId);

		// Construct the parameters to send to the web service
		$searchParams = array(
			'email' => $this->_apiEmail,
		);

		// Call the web service (URL defined at top of this file)
		//$resultJson =& $this->_callWebService(PAPERBUZZ_API_URL . 'doi/' . $article->getPubId('doi'), $searchParams);
		// For teting use the following line instead of the line above and do not forget to clear the cache
		//$resultJson =& $this->_callWebService(PAPERBUZZ_API_URL . 'doi/' . '10.1787/180d80ad-en', $searchParams);
		$resultJson =& $this->_callWebService(PAPERBUZZ_API_URL . 'doi/' . '10.1371/journal.pmed.0020124', $searchParams);
		if (!$resultJson) $resultJson = false;

		$cache->setEntireCache($resultJson);
		return $resultJson;
	}

	/**
	 * Get ALM metrics for the passed
	 * article object.
	 * @param $article Article
	 * @return string JSON message
	 */
	function _getAlmStats($article) {
		$articleId = $article->getId();
		$articlePublishedDate = $article->getDatePublished();
		$cacheManager =& CacheManager::getManager();
		$cache  =& $cacheManager->getCache('alm', $articleId, array(&$this, '_cacheMiss'));

		// If the cache is older than a 1 day in first 30 days, or a week in first 6 months, or older than a month
		$daysSincePublication = floor((time() - strtotime($articlePublishedDate)) / (60 * 60 * 24));
		if ($daysSincePublication <= 30) {
			$daysToStale = 1;
		} elseif ( $daysSincePublication <= 180 ) {
			$daysToStale = 7;
		} else {
			$daysToStale = 29;
		}

		$cachedJson = false;
		// if cache is stale, save the stale results and flush the cache
		if (time() - $cache->getCacheTime() > 60 * 60 * 24 * $daysToStale) {
			$cachedJson = $cache->getContents();
			$cache->flush();
		}

		$resultJson = $cache->getContents();

		// In cases where server is down (we get a false response)
		// it is better to show an old (successful) response than nothing
		if (!$resultJson && $cachedJson) {
			$resultJson = $cachedJson;
			$cache->setEntireCache($cachedJson);
		} elseif (!$resultJson) {
			$cache->flush();
		}

		return $resultJson;
	}

	/**
	 * Get download stats for the passed article id.
	 * @param $request PKPRequest
	 * @param $articleId int
	 * @param $byDay boolean
	 * @return array MetricsDAO::getMetrics() result.
	 */
	function _getDownloadStats(&$request, $articleId, $byDay = false) {
		// Pull in download stats for each article galley.
		$request =& Application::getRequest();
		$router =& $request->getRouter();
		$context =& $router->getContext($request); /* @var $context Journal */

		$metricsDao =& DAORegistry::getDAO('MetricsDAO'); /* @var $metricsDao MetricsDAO */

		// Load the metric type constant.
		PluginRegistry::loadCategory('reports');

		// Always merge the old timed views stats with default metrics.
		// TO-DO: Should we maybe consider only ojs::counter ?
		$dateColumn = $byDay ? STATISTICS_DIMENSION_DAY : STATISTICS_DIMENSION_MONTH;
		$metricTypes = array(OJS_METRIC_TYPE_TIMED_VIEWS, $context->getDefaultMetricType());
		$columns = array($dateColumn, STATISTICS_DIMENSION_FILE_TYPE);
		$filter = array(STATISTICS_DIMENSION_ASSOC_TYPE => ASSOC_TYPE_GALLEY, STATISTICS_DIMENSION_SUBMISSION_ID => $articleId);
		$orderBy = array($dateColumn => STATISTICS_ORDER_ASC);

		// TO-DO: Consider only the last 30 days
		/*
		if ($byDay) {
			$startDate = date('Ymd', strtotime('-30 days'));
			$endDate = date('Ymd');
			$filter[STATISTICS_DIMENSION_DAY]['from'] = $startDate;
			$filter[STATISTICS_DIMENSION_DAY]['to'] = $endDate;
		}
		*/

		return $metricsDao->getMetrics($metricTypes, $columns, $filter, $orderBy);
	}

	/**
	 * Aggregate stats and return data in a format
	 * that can be used to build the statistics JSON response
	 * for the article page.
	 * @param $statsByMonth null|array A _getDownloadStats return value.
	 * @param $statsByDay null|array A _getDownloadStats return value.
	 * @return array
	 */
	function _aggregateDownloadStats($statsByMonth, $statsByDay) {
		$totalHtml = 0;
		$totalPdf = 0;
		$totalOther = 0;
		$byMonth = array();
		$byYear = array();

		if (!is_array($stats)) $stats = array();

		if ($statsByMonth) foreach ($statsByMonth as $record) {
			$views = $record[STATISTICS_METRIC];
			$fileType = $record[STATISTICS_DIMENSION_FILE_TYPE];
			switch($fileType) {
				case STATISTICS_FILE_TYPE_HTML:
					$totalHtml += $views;
					break;
				case STATISTICS_FILE_TYPE_PDF:
					$totalPdf += $views;
					break;
				case STATISTICS_FILE_TYPE_OTHER:
					$totalOther += $views;
					break;
				default:
					// switch is considered a loop for purposes of continue
					continue 2;
			}
			$year = date('Y', strtotime($record[STATISTICS_DIMENSION_MONTH]. '01'));
			$month = date('n', strtotime($record[STATISTICS_DIMENSION_MONTH] . '01'));
			$yearMonth = date('Y-m', strtotime($record[STATISTICS_DIMENSION_MONTH] . '01'));

			if (!isset($byYear[$year])) $byYear[$year] = array();
			if (!isset($byYear[$year][$fileType])) $byYear[$year][$fileType] = 0;
			$byYear[$year][$fileType] += $views;

			if (!isset($byMonth[$yearMonth])) $byMonth[$yearMonth] = array();
			if (!isset($byMonth[$yearMonth][$fileType])) $byMonth[$yearMonth][$fileType] = 0;
			$byMonth[$yearMonth][$fileType] += $views;
		}

		// Get daily download statistics
		$byDay = array();
		if ($statsByDay) foreach ($statsByDay as $recordByDay) {
			$views = $recordByDay[STATISTICS_METRIC];
			$fileType = $recordByDay[STATISTICS_DIMENSION_FILE_TYPE];
			$yearMonthDay = date('Y-m-d', strtotime($recordByDay[STATISTICS_DIMENSION_DAY]));
			if (!isset($byDay[$yearMonthDay])) $byDay[$yearMonthDay] = array();
			if (!isset($byDay[$yearMonthDay][$fileType])) $byDay[$yearMonthDay][$fileType] = 0;
			$byDay[$yearMonthDay][$fileType] += $views;
		}

		return array($totalHtml, $totalPdf, $totalOther, $byDay, $byMonth, $byYear);
	}


	/**
	 * Get statistics by time dimension (month or year)
	 * for JSON response.
	 * @param $data array the download statistics in an array (date => file type)
	 * @param $dimension string month | year
	 * @param $fileType STATISTICS_FILE_TYPE_PDF | STATISTICS_FILE_TYPE_HTML
	 */
	function _getDownloadStatsByTime($data, $dimension, $fileType) {
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
					$event = new stdClass();
					$event->count = $fileTypes[$fileType];
					$event->date = $dateIndex;
					$byTime[] = $event;
				}
			}
		} else {
			$byTime = null;
		}
		return $byTime;
	}

	/**
	 * Build article stats JSON response based
	 * on parameters returned from _aggregateStats().
	 * @param $totalHtml array
	 * @param $totalPdf array
	 * @param $totalOther array
	 * @param $byMonth array
	 * @param $byYear array
	 * @return array ready for JSON encoding
	 */
	function _buildDownloadStatsJsonDecoded($totalHtml, $totalPdf, $totalOther, $byDay, $byMonth, $byYear) {
		$eventPdf = new stdClass();
		$eventPdf->events = null;
		$eventPdf->events_count = $totalPdf;
		$eventPdf->events_count_by_day = $this->_getDownloadStatsByTime($byDay, 'day', STATISTICS_FILE_TYPE_PDF);
		$eventPdf->events_count_by_month = $this->_getDownloadStatsByTime($byMonth, 'month', STATISTICS_FILE_TYPE_PDF);
		$eventPdf->events_count_by_year = $this->_getDownloadStatsByTime($byYear, 'year', STATISTICS_FILE_TYPE_PDF);
		$eventPdf->source_id = 'pdf';

		$eventHtml = new stdClass();
		$eventHtml->events = null;
		$eventHtml->events_count = $totalHtml;
		$eventHtml->events_count_by_day = $this->_getDownloadStatsByTime($byDay, 'day', STATISTICS_FILE_TYPE_HTML);
		$eventHtml->events_count_by_month = $this->_getDownloadStatsByTime($byMonth, 'month', STATISTICS_FILE_TYPE_HTML);
		$eventHtml->events_count_by_year = $this->_getDownloadStatsByTime($byYear, 'year', STATISTICS_FILE_TYPE_HTML);
		$eventHtml->source_id = 'html';

		$eventOther = new stdClass();
		$eventOther->events = null;
		$eventOther->events_count = $totalOther;
		$eventOther->events_count_by_day = $this->_getDownloadStatsByTime($byDay, 'day', STATISTICS_FILE_TYPE_OTHER);
		$eventOther->events_count_by_month = $this->_getDownloadStatsByTime($byMonth, 'month', STATISTICS_FILE_TYPE_OTHER);
		$eventOther->events_count_by_year = $this->_getDownloadStatsByTime($byYear, 'year', STATISTICS_FILE_TYPE_OTHER);
		$eventOther->source_id = 'other';

		$response = array($eventPdf, $eventHtml, $eventOther);
		return $response;
	}

	/**
	 * Build the required article information for the
	 * metrics visualization.
	 * @param $eventsData array (optional) Decoded JSON result from Paperbuzz
	 * @param $downloadData array (optional) Download stats data ready for JSON encoding
	 * @return string JSON response
	 */
	function _buildRequiredJson($eventsData = null, $downloadData = null) {
		// TO-DO: if there is no eventsData
		$allData = array_merge($downloadData, $eventsData->altmetrics_sources);
		$eventsData->altmetrics_sources = $allData;
		$jsonManager = new JSONManager();
		return $jsonManager->encode($eventsData);
	}
}

?>
