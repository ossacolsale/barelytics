# Shared Barelytics administration UI

`index.html`, `app.js`, `admin.css`, and `brand-mark.png` are the canonical zero-build admin interface and product mark. The files use only browser-native HTML, CSS, and JavaScript. They make no external requests and do not add visitor tracking.

Runtime adapters mount these assets at their admin base path and implement the versioned route contract in [`../../../spec/admin/http-api.md`](../../../spec/admin/http-api.md). `app.js` resolves its API relative to the current admin URL, so adapters can mount it below any base path. Each adapter must authorize requests before serving admin data and must apply CSRF checks to mutations.
