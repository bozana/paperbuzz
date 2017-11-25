<?php

/**
 * @file plugins/generic/alm/SettingsForm.inc.php
 *
 * Copyright (c) 2013-2017 Simon Fraser University Library
 * Copyright (c) 2003-2017 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class SettingsForm
 * @ingroup plugins_generic_alm
 *
 * @brief Form for journal managers to modify ALM plugin settings
 */


import('lib.pkp.classes.form.Form');

class SettingsForm extends Form {

	/** @var $journalId int */
	var $journalId;

	/** @var $plugin object */
	var $plugin;

	/**
	 * Constructor
	 * @param $plugin object
	 * @param $journalId int
	 */
	function SettingsForm(&$plugin, $journalId) {
		$this->journalId = $journalId;
		$this->plugin =& $plugin;

		parent::Form($plugin->getTemplatePath() . 'settingsForm.tpl');
		$this->addCheck(new FormValidatorPost($this));
	}

	/**
	 * @copydoc Form::initData()
	 */
	function initData() {
		$journalId = $this->journalId;
		$plugin =& $this->plugin;
		$this->setData('apiEmail', $plugin->getSetting($journalId, 'apiEmail'));
	}

	/**
	 * @copydoc Form::readInputData()
	 */
	function readInputData() {
		$this->readUserVars(array('apiEmail'));
	}

	/**
	 * Save settings.
	 * @copydoc Form::execute()
	 */
	function execute() {
		$plugin =& $this->plugin;
		$journalId = $this->journalId;

		$plugin->updateSetting($journalId, 'apiEmail', $this->getData('apiEmail'));
	}
}

?>
