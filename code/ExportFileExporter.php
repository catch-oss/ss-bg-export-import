<?php

class ExportFileExporter extends Controller {

    private static $allowed_actions = array(
        'index',
        'export'
    );

    public function init() {

        // make sure this doesn't get cached
        HTTP::set_cache_age(0);

        // call init on the parent
        parent::init();

        // security check
        if (!Permission::check('ACCESS_DO_EXPORT')) Security::permissionFailure();
    }

    public function export() {

        // get the ID
        $id = (int) $this->request->param('ID');

        // find the export
        if (!$export = DOExport::get()->filter(['ID' => $id])->first()) {
            $this->httpError(404);
            exit;
        }

        // make the right mime type
        switch ($export->Format) {

            case 'TXT':
                $mime = 'text/plain';
                break;

            case 'JSON':
                $mime = 'application/json';
                break;

            case 'CSV':
                $mime = 'text/csv';
                break;
        }

        // everything is JSON
        $this->response->addHeader('Content-Type', $mime);
        return file_get_contents(ASSETS_PATH . '/' . $export->FilePath);
    }
}
