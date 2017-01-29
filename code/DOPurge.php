<?php

class DOPurge extends DataObject implements PermissionProvider {

    private static $db = array(
        'PurgeClass'        => 'Varchar(255)',
        'Filter'            => 'Text',
        'Info'              => 'Text',
        'Status'            => 'Enum(\'new,processing,processed\',\'new\')',
        'Success'           => 'Boolean',
    );

    private static $has_one = array(
        'Member'            => 'Member',
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
            $fields->addFieldsToTab(
                'Root.Main',
                [
                    new DropdownField('PurgeClass', 'PurgeClass', ExportImportUtils::data_classes_for_dd()),
                    new TextareaField('Filter', 'Filter SQL (Optional)'),
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

                // find the things
                $list = new DataList($this->PurgeClass);

                // handle versioned objects
                if (singleton($this->PurgeClass)->hasExtension('Versioned'))
                    $listV = Versioned::get_by_stage($this->PurgeClass, 'Stage');

                // do we want to filter them
                if ($this->Filter) {
                    $list = $list->where($this->Filter);
                    $listV = $listV->where($this->Filter);
                }

                // delete the live stuff
                foreach ($list as $item) {
                    $item->delete();
                }

                // delete from stage
                foreach ($listV as $item) {
                    $item->deleteFromStage('Stage');
                }

                // update the record
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
