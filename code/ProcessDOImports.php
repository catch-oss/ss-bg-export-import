<?php

class ProcessDOImports extends CLIController implements CronTask {

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
        $objs = DataObject::get('DOImport', "Status='new'")->sort('Created');

        // process them
        foreach ($objs as $obj) {

            // process the job
            $obj->process();

            // status
            echo 'DO Import #' . $obj->ID . ' has been processed. ' . $eol .
                 'Status: ' . $obj->Status . $eol .
                 'Success: ' . ($obj->Success ? 'Yes' : 'No') . $eol . $eol;
        }

    }
}
