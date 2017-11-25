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

				$d3import = $scriptImportString . $baseImportPath .
					'js/d3.v3.min.js"></script>';
				$controllerImport = $scriptImportString . $baseImportPath .
					'js/alm.js"></script>';

				$templateMgr->assign('additionalHeadData', $additionalHeadData . "\n" . $d3import . "\n" . $controllerImport);

				$templateMgr->addStyleSheet($baseImportPath . 'css/bootstrap.tooltip.min.css');
				$templateMgr->addStyleSheet($baseImportPath . 'css/almviz.css');
				$templateMgr->addStyleSheet('https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css');
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

		$downloadStats = $this->_getDownloadStats($request, $articleId);
		// We use a helper method to aggregate stats instead of retrieving the needed
		// aggregation directly from metrics DAO because we need a custom array format.
		list($totalHtml, $totalPdf, $byMonth, $byYear) = $this->_aggregateDownloadStats($downloadStats);
		$downloadJsonDecoded = $this->_buildDownloadStatsJsonDecoded($totalHtml, $totalPdf, $byMonth, $byYear);

		$almStatsJson = $this->_getAlmStats($article);
		$almStatsJsonDecoded = @json_decode($almStatsJson);
		/* TO-DO: error handling in Paperbuzz */

		if ($downloadJson || $almStatsJson) {
			$almStatsJsonPrepared = $this->_buildRequiredJson($article, $almStatsJsonDecoded, $downloadJsonDecoded);
			$smarty->assign('almStatsJson', $almStatsJsonPrepared);

			$baseImportPath = Request::getBaseUrl() . DIRECTORY_SEPARATOR . $this->getPluginPath() .
				DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR;
			$jqueryImportPath = $baseImportPath . 'jquery-1.10.2.min.js';
			$tooltipImportPath = $baseImportPath . 'bootstrap.tooltip.min.js';

			$smarty->assign('jqueryImportPath', $jqueryImportPath);
			$smarty->assign('tooltipImportPath', $tooltipImportPath);

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
		$resultJson =& $this->_callWebService(PAPERBUZZ_API_URL . 'doi/' . $article->getPubId('doi'), $searchParams);
		// For teting use the following line instead of the line above and do not forget to clear the cache
		//$resultJson =& $this->_callWebService(PAPERBUZZ_API_URL . 'doi/' . '10.1787/180d80ad-en', $searchParams);
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
	 * @return array MetricsDAO::getMetrics() result.
	 */
	function _getDownloadStats(&$request, $articleId) {
		// Pull in download stats for each article galley.
		$request =& Application::getRequest();
		$router =& $request->getRouter();
		$context =& $router->getContext($request); /* @var $context Journal */

		$metricsDao =& DAORegistry::getDAO('MetricsDAO'); /* @var $metricsDao MetricsDAO */

		// Load the metric type constant.
		PluginRegistry::loadCategory('reports');

		// Always merge the old timed views stats with default metrics.
		$metricTypes = array(OJS_METRIC_TYPE_TIMED_VIEWS, $context->getDefaultMetricType());
		$columns = array(STATISTICS_DIMENSION_MONTH, STATISTICS_DIMENSION_FILE_TYPE);
		$filter = array(STATISTICS_DIMENSION_ASSOC_TYPE => ASSOC_TYPE_GALLEY, STATISTICS_DIMENSION_SUBMISSION_ID => $articleId);
		$orderBy = array(STATISTICS_DIMENSION_MONTH => STATISTICS_ORDER_ASC);

		return $metricsDao->getMetrics($metricTypes, $columns, $filter, $orderBy);
	}

	/**
	 * Aggregate stats and return data in a format
	 * that can be used to build the statistics JSON response
	 * for the article page.
	 * @param $stats array A _getDownloadStats return value.
	 * @return array
	 */
	function _aggregateDownloadStats($stats) {
		$totalHtml = 0;
		$totalPdf = 0;
		$byMonth = array();
		$byYear = array();

		if (!is_array($stats)) $stats = array();

		foreach ($stats as $record) {
			$views = $record[STATISTICS_METRIC];
			$fileType = $record[STATISTICS_DIMENSION_FILE_TYPE];
			switch($fileType) {
				case STATISTICS_FILE_TYPE_HTML:
					$totalHtml += $views;
					break;
				case STATISTICS_FILE_TYPE_PDF:
					$totalPdf += $views;
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

		return array($totalHtml, $totalPdf, $byMonth, $byYear);
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
			case 'month':
				$isMonthDimension = true;
				break;
			case 'year':
				$isMonthDimension = false;
				break;
			default:
				return null;
		}

		if (count($data)) {
			$byTime = array();
			foreach ($data as $date => $fileTypes) {
				if ($isMonthDimension) {
					$dateIndex = date('Y-m', strtotime($date));
				} else {
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
	 * @param $byMonth array
	 * @param $byYear array
	 * @return array ready for JSON encoding
	 */
	function _buildDownloadStatsJsonDecoded($totalHtml, $totalPdf, $byMonth, $byYear) {
		$eventPdf = new stdClass();
		$eventPdf->events = null;
		$eventPdf->events_count = $totalPdf;
		$eventPdf->events_count_by_day = null;
		$eventPdf->events_count_by_month = $this->_getDownloadStatsByTime($byMonth, 'month', STATISTICS_FILE_TYPE_PDF);;
		$eventPdf->events_count_by_year = $this->_getDownloadStatsByTime($byYear, 'year', STATISTICS_FILE_TYPE_PDF);;
		$eventPdf->source_id = 'pdf';

		$eventHtml = new stdClass();
		$eventHtml->events = null;
		$eventHtml->events_count = $totalHtml;
		$eventHtml->events_count_by_day = null;
		$eventHtml->events_count_by_month = $this->_getDownloadStatsByTime($byMonth, 'month', STATISTICS_FILE_TYPE_HTML);;
		$eventHtml->events_count_by_year = $this->_getDownloadStatsByTime($byYear, 'year', STATISTICS_FILE_TYPE_HTML);;
		$eventHtml->source_id = 'html';

		$response = array(
			'pdf' => array($eventPdf),
			'html' =>  array($eventHtml)
		);
		return $response;
	}

	/**
	 * Build the required article information for the
	 * metrics visualization.
	 * @param $article PublishedArticle
	 * @param $eventsData array (optional) Decoded JSON result from Paperbuzz
	 * @param $downloadData array (optional) Download stats data ready for JSON encoding
	 * @return string JSON response
	 */
	function _buildRequiredJson($article, $eventsData = null, $downloadData = null) {
		if ($article->getDatePublished()) {
			$datePublished = $article->getDatePublished();
		} else {
			// Sometimes there is no article getDatePublished, so fallback on the issue's
			$issueDao =& DAORegistry::getDAO('IssueDAO');  /* @var $issueDao IssueDAO */
			$issue =& $issueDao->getIssueByArticleId($article->getId(), $article->getJournalId());
			$datePublished = $issue->getDatePublished();
		}
		$metadata = array(
			'publication_date' => date('c', strtotime($datePublished)),
			'doi' => $article->getPubId('doi'),
			'title' => $article->getLocalizedTitle(),
		);

		$events = array();
		if ($eventsData) {
			foreach ($eventsData->altmetrics_sources as $source) {
				$eventsByDate = $source->events_count_by_day;
				$byMonth = array();
				$byYear = array();
				foreach ($eventsByDate as $eventByDate) {
					$date = $eventByDate->date;
					$month = date('Y-m', strtotime($date));
					$year = date('Y', strtotime($date));
					$byMonth[$month] += $eventByDate->count;
					$byYear[$year] += $eventByDate->count;
				}
				foreach ($byMonth as $date => $count) {
					$event = new stdClass();
					$event->count = $count;
					$event->date = $date;
					$source->events_count_by_month[] = $event;
				}
				foreach ($byYear as $date => $count) {
					$event = new stdClass();
					$event->count = $count;
					$event->date = $date;
					$source->events_count_by_year[] = $event;
				}
			}
			$events = array('events' => $eventsData->altmetrics_sources);
		}

		$allData = array_merge($metadata, $events, $downloadData);
		$response = array($allData);

		$jsonManager = new JSONManager();
		return $jsonManager->encode($response);
	}
}

?>
