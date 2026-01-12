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
