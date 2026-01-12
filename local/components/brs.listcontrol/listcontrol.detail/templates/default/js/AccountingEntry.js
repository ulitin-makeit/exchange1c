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
			BX.closeWait();
			
			if (response && response.status === 'success') {
				BX.UI.Notification.Center.notify({
					content: response.data && response.data.message ? response.data.message : 'Проводка успешно отправлена повторно',
					autoHide: true,
					autoHideDelay: 3000
				});
				
				this.gridReload();
			} else {
				var errorMessage = 'Ошибка при отправке проводки';
				if (response && response.errors && response.errors.length > 0) {
					errorMessage = response.errors[0].message || errorMessage;
				}
				BX.UI.Notification.Center.notify({
					content: errorMessage,
					autoHide: true,
					autoHideDelay: 5000
				});
			}
		}).catch((error) => {
			BX.closeWait();
			var errorMessage = 'Ошибка при выполнении запроса';
			if (error && error.errors && error.errors.length > 0) {
				errorMessage = error.errors[0].message || errorMessage;
			}
			BX.UI.Notification.Center.notify({
				content: errorMessage,
				autoHide: true,
				autoHideDelay: 5000
			});
		});
		
	}
	
	/**
	 * Сбрасывает количество попыток отправки.
	 * 
	 * @param {type} id идентификатор проводки
	 * @returns {undefined}
	 */
	resetAttempts(id){
		
		BX.showWait();
		
		let request = BX.ajax.runAction('brs:exchange1c.api.AccountingEntryController.resetAttempts', {
			data: {
				accountingEntryId: id
			}
		});

		request.then((response) => {
			BX.closeWait();
			
			if (response && response.status === 'success') {
				BX.UI.Notification.Center.notify({
					content: response.data && response.data.message ? response.data.message : 'Попытки успешно сброшены',
					autoHide: true,
					autoHideDelay: 3000
				});
				
				this.gridReload();
			} else {
				var errorMessage = 'Ошибка при сбросе попыток';
				if (response && response.errors && response.errors.length > 0) {
					errorMessage = response.errors[0].message || errorMessage;
				}
				BX.UI.Notification.Center.notify({
					content: errorMessage,
					autoHide: true,
					autoHideDelay: 5000
				});
			}
		}).catch((error) => {
			BX.closeWait();
			var errorMessage = 'Ошибка при выполнении запроса';
			if (error && error.errors && error.errors.length > 0) {
				errorMessage = error.errors[0].message || errorMessage;
			}
			BX.UI.Notification.Center.notify({
				content: errorMessage,
				autoHide: true,
				autoHideDelay: 5000
			});
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
