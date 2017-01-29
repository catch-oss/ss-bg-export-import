<?php

class ExportImportAdmin extends ModelAdmin {

    /**
     * List off all the Form Submission DataObjects to be included in the ModelAdmin Manage Models section
     * @var array
     */
    private static $managed_models = array(
        'DOImport',
        'DOExport',
        'DOPurge'
    );

    /**
     * URL config
     * @var string
     */
    private static $url_segment = 'bg-export-import';

    /**
     * Menu config
     * @var string
     */
    private static $menu_title = 'Data Export/Import';
}
