<?php

class DOExport extends DataObject implements PermissionProvider {

    private static $db = array(
        'ExportClass'   => 'Varchar(255)',
        'Info'          => 'Text',
        'Status'        => "Enum('new,processing,processed','new')",
        'Success'       => 'Boolean',
    );

    private static $has_one = array(
        'Member'    => 'Member'
    );

    private static $summary_fields = array(
        'ExportClass',
        'Status',
        'MemberName',
        'Created',
    );

    private static $default_sort = "Created DESC";

    public function getCMSFields() {
        $fields = parent::getCMSFields();

        // set up the fields for new records
        if (!$this->ID) {
            $fields->removeByName('Info');
            $fields->removeByName('Status');
            $fields->removeByName('Success');
            $fields->removeByName('Message');
            $fields->removeByName('MemberID');
            $fields->addFieldToTab(
                'Root.Main',
                new DropdownField('ExportClass', 'ExportClass', ExportImportUtils::DataClassesForDD())
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

    /**
     * Processes the Export
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
                $list = new DataList($this->ExportClass);

                // get the package and save it somewhere
                $package = CTAAPIHelper::get_package($this->Revision);
                $tmpFile = sys_get_temp_dir() . '/' . $this->Revision . '.tar.gz';
                file_put_contents($tmpFile, $package);

                // extract it
                $phar = new PharData($tmpFile);
                $phar->extractTo(ASSETS_PATH . '/ctas', null, true);

                // yay
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
            "ACCESS_DO_EXPORT" => "Access DO Export Utility"
        );
    }

    public function canCreate($member = false) {
        return Permission::check('ACCESS_DO_EXPORT');
    }

    public function canView($member = false) {
        return Permission::check('ACCESS_DO_EXPORT');
    }

    public function canEdit($member = false) {
        return $this->ID ? false : Permission::check('ACCESS_DO_EXPORT');
    }

    public function canDelete($member = false) {
        return false;
    }

    public function StatusMessage() {
        if ($this->Status == 'new')
            return 'Scheduled - should begin processing within 2 minutes';

        if ($this->Status == 'processing')
            return 'Export in progress';

       if ($this->Status == 'processed')
           return $this->Success ? 'Export Complete' : 'Import Failed';
    }

    public function MemberName() {

        if ($user = $this->Member())
            if ($user->ID)
                return $user->FirstName . ' ' . $user->Surname . '(#' . $user->ID . ')';

        return null;
    }
}
