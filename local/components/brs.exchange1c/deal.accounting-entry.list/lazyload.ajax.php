<?php
define('NO_KEEP_STATISTIC', 'Y');
define('NO_AGENT_STATISTIC','Y');
define('NO_AGENT_CHECK', true);
define('PUBLIC_AJAX_MODE', true);
define('DisableEventsCheck', true);

require_once($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php');
if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true || !check_bitrix_sessid()) {
	die();
}

Header('Content-Type: text/html; charset='.LANG_CHARSET);
$GLOBALS['APPLICATION']->ShowAjaxHead();

// Force AJAX mode
$componentParams['AJAX_MODE'] = 'Y';
$componentParams['AJAX_OPTION_JUMP'] = 'N';
$componentParams['AJAX_OPTION_HISTORY'] = 'N';
$componentParams['DEAL_ID'] = Bitrix\Main\Application::getInstance()->getContext()->getRequest()->get('DEAL_ID');
$componentParams['AJAX_LOADER'] = [
	'url' => '/local/components/brs.exchange1c/deal.accounting-entry.list/lazyload.ajax.php?&site=' . SITE_ID
		. '&' . bitrix_sessid_get() . '&DEAL_ID=' . $componentParams['DEAL_ID'],
	'method' => 'POST',
	'dataType' => 'ajax',
	'data' => []
];

$APPLICATION->IncludeComponent(
	'brs.exchange1c:deal.accounting-entry.list',
	'',
	$componentParams,
	[],
	false,
	['HIDE_ICONS' => 'Y', 'ACTIVE_COMPONENT' => 'Y']
);

require_once($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_after.php');
