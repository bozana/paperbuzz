{**
 * plugins/generic/alm/settingsForm.tpl
 *
 * Copyright (c) 2013-2017 Simon Fraser University Library
 * Copyright (c) 2003-2017 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * ALM plugin settings
 *
 *}
{strip}
{assign var="pageTitle" value="plugins.generic.alm.displayName"}
{include file="common/header.tpl"}
{/strip}
<div id="almPlugin">
<div id="description">{translate key="plugins.generic.alm.description"}</div>

<div class="separator">&nbsp;</div>

<form class="pkp_form" method="post" action="{plugin_url path="settings"}">
{include file="common/formErrors.tpl"}

{fbvFormArea id="almSettingsFormArea"}
	{fbvFormSection label="plugins.generic.alm.settings.apiEmail" description="plugins.generic.alm.settings.apiEmail.description" for="name" inline=true size=$fbvStyles.size.MEDIUM}
		{fbvElement type="text" name="apiEmail" id="apiEmail" value=$apiEmail}
	{/fbvFormSection}
{/fbvFormArea}

<br/>
<input type="submit" name="save" class="button defaultButton" style="width:auto" value="{translate key="common.save"}"/> <input type="button" class="button" style="width:auto" value="{translate key="common.cancel"}" onclick="history.go(-1)"/>
</form>

<p><span class="formRequired">{translate key="common.requiredField"}</span></p>
</div>
{include file="common/footer.tpl"}
