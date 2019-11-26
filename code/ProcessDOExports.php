<?php

namespace CatchDesign\SSBGExportImport;

use CLIController;
use CronTask;
use DataObject;
use CatchDesign\SSBGExportImport\DOExport;



class ProcessDOExports extends CLIController implements CronTask {

    /**
     * Init cron schedule
     * @return string with the cron time schedule
     */
    public function getSchedule() {
        return '* * * * *';
    }

    /**
     * @return void
     */
    public function process() {

        // eol
        $eol = php_sapi_name() == 'cli' ? "\n" : '<br>';

        // get all the unprocessed CTA Imports
        $objs = DataObject::get(DOExport::class, "Status='new'")->sort('Created');

        // process them
        foreach ($objs as $obj) {

            // process the job
            $obj->process();

            // status
            echo 'DO Export #' . $obj->ID . ' has been processed. ' . $eol .
                 'Status: ' . $obj->Status . $eol .
                 'Success: ' . ($obj->Success ? 'Yes' : 'No') . $eol . $eol;
        }

    }
}
