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

        // zip the package
        $dir = preg_replace('/\/' . $export->ID . '.*$/', '', $export->FilePath); // support legacy FilePaths
        $basePath = ASSETS_PATH . '/' . $dir . '/' . $export->ID;
        $filename = $basePath . '.zip';

        if (!is_file($filename)) {

            // get all the files we want to add
            $files = glob($basePath . '*');

            // init the zip
            $zip = new ZipArchive;

            // hmm - sad
            if ($zip->open($filename, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                exit('cannot open ' . $filename);
            }

            // add them
            foreach ($files as $file) {
                $zip->addFile($file, str_replace($basePath, $export->ID, $file));
            }

            // check the status
            if (!$zip->status == ZipArchive::ER_OK) {
                exit('error occured generating package' . $filename);
            }

            // close the file
            $zip->close();
        }

        // everything is JSON
        $this->response->addHeader('Content-Type', 'application/zip');
        return file_get_contents($filename);
    }
}
