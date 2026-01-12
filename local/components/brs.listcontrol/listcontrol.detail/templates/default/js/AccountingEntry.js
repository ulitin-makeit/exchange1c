class BrsListControlAccountingEntry {
	
	/**
	 * Отправляем проводку повторно.
	 * 
	 * @param {type} id идентификатор проводки
	 * @returns {undefined}
	 */
	push(id){
		
		BX.showWait();
		
		let request = BX.ajax.runAction('brs:exchange1c.api.AccountingEntryController.retry', {
			data: {
				accountingEntryId: id
			}
		});

		request.then((response) => {
			
			this.gridReload();
			
			BX.closeWait();
			
		});
		
	}
	
	/**
	 * Обновляем грид с учётом текущей пагинации.
	 * 
	 * @returns {undefined}
	 */
	gridReload(){
		
		var reloadParams = { apply_filter: 'Y', clear_nav: 'Y' };

		if(typeof window.gridObject == 'undefined'){
			return;
		}

		if(!window.gridObject.hasOwnProperty('instance')){
			return;
		}
		
		var currentPage = $('#' + window.gridObject.instance.getId() + ' .main-ui-pagination-active').text();
		var addPage = {};

		addPage[window.gridObject.id] = 'page-' + currentPage;

		window.gridObject.instance.baseUrl = BX.Grid.Utils.addUrlParams(window.gridObject.instance.baseUrl, addPage);

		window.gridObject.instance.reloadTable('POST', reloadParams);
		
	}
	
}
