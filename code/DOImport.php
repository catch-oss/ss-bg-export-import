<?php

class DOImport extends DataObject implements PermissionProvider {

    private static $db = array(
        'Info'              => 'Text',
        'Status'            => 'Enum(\'new,processing,processed\',\'new\')',
        'Success'           => 'Boolean',
        'JobSize'           => 'Int',
        'JobProgress'       => 'Int',
        'JobMemoryUse'      => 'Int',
    );

    private static $has_one = array(
        'Member'        => 'Member',
        'ImportFile'    => 'File'
    );

    private static $summary_fields = array(
        'Status',
        'MemberName',
        'JobSize',
        'JobProgress',
        'Created',
        'LastEdited',
    );

    private static $defaults = array(
        'Depth'
    );

    private static $default_sort = 'Created DESC';

    public function getCMSFields() {
        $fields = parent::getCMSFields();

        // set up the fields for new records
        if (!$this->ID) {

            $fields->removeByName('Info');
            $fields->removeByName('Status');
            $fields->removeByName('Success');
            $fields->removeByName('MemberID');
            $fields->removeByName('JobSize');
            $fields->removeByName('JobProgress');
            $fields->removeByName('JobMemoryUse');

            $fileField = new UploadField('ImportFile', 'Import File');
            $fileField->getValidator()->setAllowedExtensions(['txt','json','csv']);

            $fields->addFieldsToTab(
                'Root.Main',
                [$fileField]
            );
        }

        return $fields;
    }

    public function onBeforeWrite() {

        parent::onBeforeWrite();

        // save this for audit purposes
        if (!$this->MemberID)
            if ($user = Member::currentUser())
                $this->MemberID = $user->ID;
    }

    protected function importData($data, $format) {

        // create the object
        $cls = $data['ClassName'];
        if (!$obj = DataObject::get_by_id($cls, $data['ID'])) {

             // it's a new record - we need to let SS give it an ID
            unset($data['ID']);
            $obj =  new $cls;
        }

        // get the fields we are exporting
        $fields = ExportImportUtils::all_fields($cls);

        // loop the fields
        foreach ($fields as $type => $fData) {

            // always import the local columns
            if ($type == 'db') {
                foreach ($fData as $name => $conf) {

                    // dont carry forward the version number
                    if ($name != 'Version') {
                        $obj->$name = empty($data[$name])
                            ? null
                            : $data[$name];
                    }
                }
            }

            // did we want to include any related columns
            else {

                // loop through the field data
                foreach ($fData as $name => $conf) {

                    if (isset($data[$name])) {

                        // get the relation data
                        $rel = $data[$name];

                        // unpack the relation data if it was a csv import
                        if ($format == 'CSV' && is_string($rel)) {
                            $rel = json_decode($rel, true);
                        }

                        // is it a to many?
                        if ($type == 'many_many' || $type == 'has_many') {

                            // accumulate items
                            foreach ($rel as $item) {
                                $obj->$name()->add($this->importData($item, $format));
                            }
                        }

                        // it's a to one
                        else if ($rel) {
                            $imported = $this->importData($rel, $format);
                            $obj->{$name . 'ID'} = $imported->ID;
                        }
                    }
                }
            }
        }

        // write the data
        $w = $r = $p = $obj->write();

        // handle versioned objects
        if (
            $obj->hasExtension('Versioned') ||
            $obj->hasExtension('VersionedDataObject')
        ) {
            $r = $obj->doRestoreToStage();
            $p = $obj->doPublish();
        }

        // helpful output
        echo 'Updated Record ' . $obj->ClassName . ' #' . $obj->ID .
            ' write: ' . ($w ? ' Success' : ' fail') .
            ' restore: ' . ($r ? ' Success' : ' fail') .
            ' publish: ' . ($p ? ' Success' : ' fail') .
            "\n";

        // return the obj
        return $obj;
    }

    protected function parseCSV($path) {

        // init some vars
        $out = [];

        // parse the csv into an array of arrays
        if (($handle = fopen($path, 'r')) !== false) {
            while (($data = fgetcsv($handle)) !== false) {
                $num = count($data);
                $rowData = [];
                for ($c=0; $c < $num; $c++) {
                    $rowData[] = $data[$c];
                }
                $out[] = $rowData;
            }
            fclose($handle);
        }

        // convert to associative array
        array_walk($out, function(&$a) use ($out) {
            $a = array_combine($out[0], $a);
        });

        // remove headers
        array_shift($out);

        // return structured assoiative array
        return $out;
    }

    /**
     * Processes the Import
     * @return [type] [description]
     */
    public function process() {

        // we don't want to try this more than once
        if ($this->Status == 'new') {

            // Let everyone know this is being processed
            $this->Status = 'processing';
            $this->write();

            // helpful output
            echo 'processing import #' . $this->ID . "\n";

            // try to get the package
            try {

                // get the source data
                $type = strtoupper($this->ImportFile()->getExtension());
                $path = str_replace('assets/assets', 'assets', ASSETS_PATH . '/' . $this->ImportFile()->Filename);

                // if there's no type we can't processes
                if (!$type) throw new Exception('Unable to process - did you upload a file?');

                // parse the data
                switch ($type) {

                    case 'CSV':
                        $data = $this->parseCSV($path);
                        break;

                    case 'JSON':
                        $raw = file_get_contents($path);
                        $data = json_decode($raw, true);
                        break;

                    case 'TXT':
                        $raw = file_get_contents($path);
                        $data = unserialize($raw);
                        break;
                }

                // helpful output
                echo 'processing ' . count($data) . ' root level records' . "\n";

                // update record
                $this->JobSize = count($data);
                $this->JobMemoryUse = memory_get_peak_usage(true);
                $this->write();

                // loop the loop
                foreach ($data as $item) {

                    // import
                    if ($item) $this->importData($item, $type);

                    // update the progress
                    $this->JobProgress++;
                    $this->JobMemoryUse = memory_get_peak_usage(true);
                    $this->write();
                }

                $this->Status = 'processed';
                $this->JobMemoryUse = memory_get_peak_usage(true);
                $this->Success = true;
                $this->write();
            }

            // something went wrong
            catch (Exception $e) {

                // deliver the bad news
                $this->Status = 'processed';
                $this->JobMemoryUse = memory_get_peak_usage(true);
                $this->Info = $e->getMessage();
                $this->write();
            }
        }
    }

    /**
     * create the permissions required
     * @return array
     */
    public function providePermissions() {
        return array(
            "ACCESS_DO_IMPORT" => "Access Data Object Import Utility"
        );
    }

    public function canCreate($member = false) {
        return Permission::check('ACCESS_DO_IMPORT');
    }

    public function canView($member = false) {
        return Permission::check('ACCESS_DO_IMPORT');
    }

    public function canEdit($member = false) {
        return $this->ID ? false : Permission::check('ACCESS_DO_IMPORT');
    }

    public function canDelete($member = false) {
        return false;
    }

    public function StatusMessage() {
        if ($this->Status == 'new')
            return 'Scheduled - should begin processing within 2 minutes';

        if ($this->Status == 'processing')
            return 'Import in progress';

       if ($this->Status == 'processed')
           return $this->Success ? 'Import Complete' : 'Import Failed';
    }

    public function MemberName() {

        if ($user = $this->Member())
            if ($user->ID)
                return $user->FirstName . ' ' . $user->Surname . '(#' . $user->ID . ')';

        return null;
    }
}
