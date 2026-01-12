BX.namespace('BX.Brs.Exchange1c.AccountingEntry');
BX.Brs.Exchange1c.AccountingEntry.resetAttempts = function(entryId) {
    if (!entryId) {
        return;
    }

    var gridId = 'exchange1c_accounting_entry';
    var grid = BX.Main.gridManager.getById(gridId);
    
    if (!grid) {
        return;
    }

    BX.ajax({
        url: '/local/components/brs.exchange1c/deal.accounting-entry.list/lazyload.ajax.php',
        method: 'POST',
        dataType: 'json',
        data: {
            action: 'reset_attempts',
            entry_id: entryId,
            sessid: BX.bitrix_sessid()
        },
        onsuccess: function(response) {
            if (response && response.status === 'success') {
                BX.UI.Notification.Center.notify({
                    content: response.message || 'Попытки успешно сброшены',
                    autoHide: true,
                    autoHideDelay: 3000
                });
                
                // Обновляем grid
                if (grid) {
                    grid.reload();
                }
            } else {
                BX.UI.Notification.Center.notify({
                    content: response.message || 'Ошибка при сбросе попыток',
                    autoHide: true,
                    autoHideDelay: 5000
                });
            }
        },
        onfailure: function() {
            BX.UI.Notification.Center.notify({
                content: 'Ошибка при выполнении запроса',
                autoHide: true,
                autoHideDelay: 5000
            });
        }
    });
};

BX.namespace('BX.Brs.Exchange1c.AccountingEntry.List');
BX.Brs.Exchange1c.AccountingEntry.List = class {
    constructor(params) {
        this.id = params.id;
        this.loaderParams = params.loader;

        BX.addCustomEvent('Grid::beforeRequest', BX.delegate(this.prepareRequest, this));
    }

    prepareRequest(grid, params) {
        if (params.gridId !== this.id) {
            return;
        }
        params.url = this.loaderParams.url;
        params.method = this.loaderParams.method;
        params.data = Object.assign(params.data, this.loaderParams.data);
    }
}
