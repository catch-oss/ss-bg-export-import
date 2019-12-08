<?php

namespace CatchDesign\SSBGExportImport;


use CatchDesign\SSBGExportImport\DOImport;
use CatchDesign\SSBGExportImport\DOExport;
use CatchDesign\SSBGExportImport\DOPurge;
use SilverStripe\Admin\ModelAdmin;




class ExportImportAdmin extends ModelAdmin {

    /**
     * List off all the Form Submission DataObjects to be included in the ModelAdmin Manage Models section
     * @var array
     */
    private static $managed_models = array(
        DOImport::class,
        DOExport::class,
        DOPurge::class
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
