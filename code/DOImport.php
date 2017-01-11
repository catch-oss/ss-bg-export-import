<?php

class DOImport extends DataObject implements PermissionProvider {

    private static $db = array(
        'ImportClass'   => 'Varchar(255)',
        'CSVPath'       => 'Varchar(255)',
        'Depth'         => 'Int',
        'Info'          => 'Text',
        'Status'        => 'Enum(\'new,processing,processed\',\'new\')',
        'Success'       => 'Boolean',
    );

    private static $has_one = array(
        'Member'    => 'Member'
    );

    private static $summary_fields = array(
        'ImportClass',
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
            $fields->removeByName('Message');
            $fields->removeByName('MemberID');
            $fields->addFieldsToTab(
                'Root.Main',
                [
                    new DropdownField('ImportClass', 'ImportClass', ExportImportUtils::data_classes_for_dd()),
                    new DropdownField('Depth', 'Import Related Data to Depth', [
                        '0' => 'Don\'t export related data',
                        '1' => '1',
                        '2' => '2',
                        '3' => '3',
                        '4' => '4',
                        '5' => '5',
                        '6' => '6',
                        '7' => '7',
                        '8' => '8',
                        '9' => '9',
                    ]),
                ]
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

    protected function extractData($obj, $depth = 0) {

        // are we in too deep?
        if ($this->Depth > $depth) return null;

        // get the fields we are exporting
        $fields = ExportImportUtils::all_fields($this->ImportClass);

        // init the collector
        $out = [];

        // loop the fields
        foreach ($fields as $type => $fields) {

            // always export the local columns
            if ($type == 'db') {
                foreach ($fields as $name => $conf) {
                    $out[$name] = $this->$name;
                }
            }

            // did we want to include any related columns
            else if ((int) $this->Depth > 0) {

                // loop through the field data
                foreach ($fields as $name => $conf) {

                    // get the relation data
                    $rel = $obj->$name();

                    // is it a to many?
                    if (is_a($rel, 'DataList')) {

                        // init the collector
                        $out[$name] = [];

                        // accumulate items
                        foreach ($rel as $item) {
                            $out[$name][] = $this->extractData($item, $depth + 1);
                        }
                    }

                    // it's a to one
                    else {
                        $out[$name] = $this->extractData($rel, $depth + 1);
                    }
                }
            }
        }
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

            // if it goes bad here we don't want to end up back in this place
            $this->Status = 'processed';

            // try to get the package
            try {

                // get the source data
                $list = new DataList($this->ImportClass);

                // get the fields we are exporting
                $fields = ExportImportUtils::all_fields($this->ImportClass);

                // init the collector
                $out = [];

                // loop the loop
                foreach ($list as $item) {

                    // extract the data from the object
                    $data = $this->extractData($item);

                    // init the row recievers
                    $row = [];
                    $hRow = [];

                    // loop the fields
                    foreach ($fields as $type => $fields) {

                        // do we need to create header row
                        if (empty($out)) {

                            // always export the local column headings
                            if ($type == 'db') {
                                foreach ($fields as $name => $conf) {
                                    $hRow[] = $name;
                                }
                            }

                            // did we want to include any related column headings
                            else if ((int) $this->Depth > 0) {
                                foreach ($fields as $name => $conf) {
                                    $hRow[] = $name;
                                }
                            }
                        }

                        // always export the local columns
                        if ($type == 'db') {
                            foreach ($fields as $name => $conf) {
                                $row[] = $data[$name];
                            }
                        }

                        // did we want to include any related columns
                        else if ((int) $this->Depth > 0) {
                            foreach ($fields as $name => $conf) {
                                $row[] = serialize($data[$name]);
                            }
                        }
                    }

                    // append the datas
                    $out[] = $row;
                }

                // what CSV are we looking at here
                $fPath = 'data-exports/' . $this->ID . '.csv';

                // write the CSV data
                $fp = fopen(ASSETS_PATH . '/' . $fPath, 'w');
                fputcsv($fp, $out, ',');
                fclose($fp);

                // yay
                $this->CSVPath = $fPath;
                $this->Success = true;
                $this->write();

            }

            // something went wrong
            catch (Exception $e) {

                // deliver the bad news
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
