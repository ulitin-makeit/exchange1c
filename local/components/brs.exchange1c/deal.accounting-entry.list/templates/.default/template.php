<?php

	if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

	/**
	 * @var array $arResult
	 * @var array $arParams
	 */

	global $APPLICATION;

	$this->addExternalJs('/local/components/brs.listcontrol/listcontrol.detail/templates/default/js/AccountingEntry.js');
	$this->addExternalJs($this->__folder . '/script.js');

	$APPLICATION->IncludeComponent('bitrix:main.ui.grid', '', [
		'GRID_ID' => $arResult['LIST_ID'],
		'COLUMNS' => $arResult['COLUMNS'],
		'ROWS' => $arResult['ROWS'],
		'SHOW_ROW_CHECKBOXES' => false,
		'NAV_OBJECT' => $arResult['NAV_OBJECT'],
		'AJAX_MODE' => $arParams['AJAX_MODE'],
		'AJAX_ID' => $arParams['AJAX_ID'],
		'AJAX_OPTION_JUMP' => $arParams['AJAX_OPTION_JUMP'],
		'AJAX_OPTION_HISTORY' => $arParams['AJAX_OPTION_HISTORY'],
		'PAGE_SIZES' => [
			['NAME' => '20', 'VALUE' => '20'],
			['NAME' => '50', 'VALUE' => '50'],
			['NAME' => '100', 'VALUE' => '100']
		],
		'TOTAL_ROWS_COUNT' => $arResult['NAV_OBJECT']->getRecordCount(),
		'SHOW_CHECK_ALL_CHECKBOXES' => false,
		'SHOW_ROW_ACTIONS_MENU' => true,
		'SHOW_GRID_SETTINGS_MENU' => true,
		'SHOW_NAVIGATION_PANEL' => true,
		'SHOW_PAGINATION' => true,
		'SHOW_SELECTED_COUNTER' => false,
		'SHOW_TOTAL_COUNTER' => true,
		'SHOW_PAGESIZE' => true,
		'SHOW_ACTION_PANEL' => true,
		'ALLOW_COLUMNS_SORT' => true,
		'ALLOW_COLUMNS_RESIZE' => true,
		'ALLOW_HORIZONTAL_SCROLL' => true,
		'ALLOW_SORT' => true,
		'ALLOW_PIN_HEADER' => true,
	]);

?>

<script>
	BX.ready(function () {
		
		new BX.Brs.Exchange1c.AccountingEntry.List({
			id: '<?=$arResult['LIST_ID']?>',
			loader: <?=CUtil::PhpToJSObject($arParams['AJAX_LOADER']);?>,
		});
		
		// формируем объект грида
		gridObject = BX.Main.gridManager.getById('<?=$arResult['LIST_ID']?>');
		
	});
</script>
