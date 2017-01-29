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
     * Processes the Purge
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

                // get some versioning info
                $isVersioned = (
                    singleton($this->PurgeClass)->hasExtension('Versioned') ||
                    singleton($this->PurgeClass)->hasExtension('VersionedDataObject')
                );
                $stages = ['Stage', 'Live'];

                // treat versioned  classes differently
                if ($isVersioned) {

                    foreach ($stages as $stage) {

                        // make a list
                        $list = Versioned::get_by_stage($this->PurgeClass, $stage);

                        // do we want to filter them
                        if ($this->Filter) $list->where($this->Filter);

                        // helpful output
                        echo 'Removing ' . $list->count() . ' items from ' . $stage . "\n";

                        // delete stuff
                        foreach ($list as $item) {

                            // oddly some things dont have IDs?
                            if ($item->ID) $item->deleteFromStage($stage);
                            if ($item->ID) $item->delete();
                        }
                    }
                }
                else {

                    // make a list
                    $list = new DataList($this->PurgeClass);

                    // do we want to filter them
                    if ($this->Filter) $list->where($this->Filter);

                    // helpful output
                    echo 'Removing ' . $list->count() . ' items' . "\n";

                    // delete stuff
                    foreach ($list as $item) $item->delete();
                }

                // update the record
                $this->Success = true;
                $this->write();
            }

            // something went wrong
            catch (Exception $e) {

                // deliver the bad news
                $this->Info .= $e->getMessage();
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
            "ACCESS_DO_PURGE" => "Access Data Object Purge Utility"
        );
    }

    public function canCreate($member = false) {
        return Permission::check('ACCESS_DO_PURGE');
    }

    public function canView($member = false) {
        return Permission::check('ACCESS_DO_PURGE');
    }

    public function canEdit($member = false) {
        return $this->ID ? false : Permission::check('ACCESS_DO_PURGE');
    }

    public function canDelete($member = false) {
        return false;
    }

    public function StatusMessage() {
        if ($this->Status == 'new')
            return 'Scheduled - should begin processing within 2 minutes';

        if ($this->Status == 'processing')
            return 'Purge in progress';

       if ($this->Status == 'processed')
           return $this->Success ? 'Purge Complete' : 'Purge Failed';
    }

    public function MemberName() {

        if ($user = $this->Member())
            if ($user->ID)
                return $user->FirstName . ' ' . $user->Surname . '(#' . $user->ID . ')';

        return null;
    }
}
