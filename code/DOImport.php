<?php

class DOImport extends DataObject implements PermissionProvider {

    private static $db = array(
        'Info'              => 'Text',
        'Status'            => 'Enum(\'new,processing,processed\',\'new\')',
        'Success'           => 'Boolean',
    );

    private static $has_one = array(
        'Member'        => 'Member',
        'ImportFile'    => 'File'
    );

    private static $summary_fields = array(
        'Status',
        'MemberName',
        'Created',
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

        if (empty($data['ClassName'])) {
            print_r($data);
        }

        // create the object
        $cls = $data['ClassName'];
        if (!$obj = DataObject::get_by_id($cls, $data['ID'])) {
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
                    if ($name != 'Version')
                        $obj->$name = $data[$name];
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
                        else {
                            $imported = $this->importData($rel, $format);
                            $obj->{$name . 'ID'} = $imported->ID;
                        }
                    }
                }
            }
        }

        // write the data
        $obj->write();

        // handle versioned objects
        if ($obj->hasExtension('Versioned')) {
            $obj->doRestoreToStage();
            $obj->doPublish();
        }

        // return the obj
        return $obj;
    }

    /**
     * Processes the Import
     * @return [type] [description]
     */
    public function process() {

        // we don't want to try this more than once
        if ($this->Status == 'new') {

            // // Let everyone know this is being processed
            // $this->Status = 'processing';
            // $this->write();
            //
            // // if it goes bad here we don't want to end up back in this place
            // $this->Status = 'processed';

            // try to get the package
            try {

                // get the source data
                $type = strtoupper($this->ImportFile()->getExtension());
                $raw = file_get_contents(
                    str_replace('assets/assets', 'assets', ASSETS_PATH . '/' . $this->ImportFile()->Filename)
                );

                // echo $this->ImportFile()->Filename . ' // ' .
                // $this->ImportFile()->getExtension() . ' // ' .$type . "<br>\n";

                // if there's no type we can't processes
                if (!$type) throw new Exception('Unable to process - did you upload a file?');

                // parse the data
                switch ($type) {

                    case 'CSV':
                        $data = str_getcsv($raw);
                        $headers = array_shift($data);
                        break;

                    case 'JSON':
                        $data = json_decode($raw, true);
                        break;

                    case 'TXT':
                        $data = unserialize($raw);
                        break;
                }


                // loop the loop
                foreach ($data as $item) {

                    // parse the "row data"
                    if ($type == 'CSV') {
                        $parsed = [];
                        // print_r($headers);
                        foreach ($headers as $idx => $field) {
                            $parsed[$field] = $item[$idx];
                        }
                    }
                    else $parsed = $item;

                    // import
                    $this->importData($parsed, $type);
                }

                $this->Success = true;
                $this->write();
            }

            // something went wrong
            catch (Exception $e) {

                // deliver the bad news
                $this->Info = $e->getMessage();
                $this->Status = 'processed';
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
            "ACCESS_DO_IMPORT" => "Access DO Import Utility"
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
